<?php
/**
 * The only writer of compiled campaign rows.
 *
 * A campaign is an `eru_ce_campaign` post (title = label, status) with its definition in the
 * `_eru_ce_definition` meta: actions, rules, targets, schedule, priority, exclusive, basis,
 * max_discount_minor, usage_limit, usage_limit_per_customer, paused, meta. Saving validates the
 * definition with the engine's readers (DefinitionValidator) and compiles it into the campaigns and
 * targets tables; an invalid definition is refused with its field and reason and nothing is written.
 *
 * A published, not paused post is "active"; anything else is "inactive". Trash and delete remove the
 * compiled row (usage and reward records stay). Every write bumps the campaign version and purges the
 * page cache for the products involved.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Campaign;

use Erusoft\CampaignEngine\Engine\ArgumentError;
use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Campaign\Schedule;
use Erusoft\CampaignEngine\Infrastructure\AuditLog;
use Erusoft\CampaignEngine\Infrastructure\Cache\CachePurger;
use Erusoft\CampaignEngine\Infrastructure\Clock;
use Erusoft\CampaignEngine\Infrastructure\ConfigErrors;
use Erusoft\CampaignEngine\Infrastructure\Database\CampaignRepository;
use Erusoft\CampaignEngine\Infrastructure\Database\MetricsRepository;
use Erusoft\CampaignEngine\Infrastructure\Database\Schema;
use WP_Error;

final class CampaignWriter {

	public const META = '_eru_ce_definition';

	/** Definition keys that are stored; anything else is dropped. */
	private const KEYS = array( 'actions', 'rules', 'targets', 'schedule', 'priority', 'exclusive', 'basis', 'max_discount_minor', 'usage_limit', 'usage_limit_per_customer', 'paused', 'meta' );

	private static bool $writing = false;

	public static function hooks(): void {
		add_action( 'save_post_' . PostType::NAME, array( self::class, 'onSave' ), 20, 1 );
		add_action( 'trashed_post', array( self::class, 'onRemove' ) );
		add_action( 'before_delete_post', array( self::class, 'onRemove' ) );
		add_action( 'untrashed_post', array( self::class, 'onSave' ) );
	}

	/** @return true|WP_Error error data: field, reason */
	public static function validate( array $definition ) {
		try {
			DefinitionValidator::check( $definition );
		} catch ( ArgumentError $e ) {
			return new WP_Error(
				'eru_ce_invalid_campaign',
				/* translators: 1: field name, 2: reason code */
				sprintf( __( 'Kampanya kaydedilmedi: "%1$s" alanı geçersiz (%2$s).', 'campaign-engine-for-woocommerce' ), $e->field, $e->reason ),
				array(
					'field'  => $e->field,
					'reason' => $e->reason,
				)
			);
		}

		return true;
	}

	/**
	 * Creates a campaign post and compiles it.
	 *
	 * @param array<string,mixed> $definition
	 * @return int|WP_Error post id
	 */
	public static function create( array $definition, string $title, string $status = 'publish' ) {
		$definition = self::clean( $definition );
		$valid      = self::validate( $definition );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		self::$writing = true;

		try {
			$id = wp_insert_post(
				array(
					'post_type'   => PostType::NAME,
					'post_title'  => $title,
					'post_status' => $status,
				),
				true
			);

			if ( is_wp_error( $id ) ) {
				return $id;
			}

			update_post_meta( $id, self::META, wp_slash( $definition ) ); // Meta functions unslash; keep backslashes as typed.
		} finally {
			self::$writing = false;
		}

		$compiled = self::compile( (int) $id );

		return is_wp_error( $compiled ) ? $compiled : (int) $id;
	}

	/**
	 * Updates any of definition / title / status and recompiles. Null keeps the current value.
	 *
	 * @param array<string,mixed>|null $definition
	 * @return int|WP_Error
	 */
	public static function update( int $id, ?array $definition = null, ?string $title = null, ?string $status = null ) {
		$post = get_post( $id );

		if ( ! $post || PostType::NAME !== $post->post_type ) {
			return new WP_Error( 'eru_ce_not_found', __( 'Kampanya bulunamadı.', 'campaign-engine-for-woocommerce' ) );
		}

		if ( null !== $definition ) {
			$definition = self::clean( $definition );
			$valid      = self::validate( $definition );

			if ( is_wp_error( $valid ) ) {
				return $valid;
			}
		}

		self::$writing = true;

		try {
			if ( null !== $definition ) {
				update_post_meta( $id, self::META, wp_slash( $definition ) ); // Meta functions unslash; keep backslashes as typed.
			}

			$fields = array_filter(
				array(
					'post_title'  => $title,
					'post_status' => $status,
				),
				static fn( $v ): bool => null !== $v
			);

			if ( array() !== $fields ) {
				$updated = wp_update_post( array( 'ID' => $id ) + $fields, true );

				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
			}
		} finally {
			self::$writing = false;
		}

		$compiled = self::compile( $id );

		return is_wp_error( $compiled ) ? $compiled : $id;
	}

	/**
	 * Stores a definition on an existing campaign post without compiling it; the `save_post` pass that
	 * follows compiles it. For the admin form, which saves inside the post's own save request. An invalid
	 * definition is refused with its field and reason and nothing is written.
	 *
	 * @param array<string,mixed> $definition
	 * @return true|WP_Error
	 */
	public static function store( int $id, array $definition ) {
		if ( PostType::NAME !== get_post_type( $id ) ) {
			return new WP_Error( 'eru_ce_not_found', __( 'Kampanya bulunamadı.', 'campaign-engine-for-woocommerce' ) );
		}

		$definition = self::clean( $definition );
		$valid      = self::validate( $definition );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		update_post_meta( $id, self::META, wp_slash( $definition ) ); // Meta functions unslash; keep backslashes as typed.

		return true;
	}

	/** save_post / untrash: recompile from the stored post (ignored while this class writes). */
	public static function onSave( $post_id ): void {
		if ( self::$writing || PostType::NAME !== get_post_type( (int) $post_id ) || wp_is_post_revision( (int) $post_id ) ) {
			return;
		}

		self::compile( (int) $post_id );
	}

	public static function onRemove( $post_id ): void {
		if ( PostType::NAME === get_post_type( (int) $post_id ) ) {
			self::remove( (int) $post_id );
		}
	}

	/**
	 * Compiles the stored post into its row. An invalid stored definition removes the compiled row
	 * (the campaign stops) and is reported, never half-applied.
	 *
	 * @return true|WP_Error
	 */
	public static function compile( int $id ) {
		global $wpdb;

		$post = get_post( $id );

		if ( ! $post || PostType::NAME !== $post->post_type || 'trash' === $post->post_status ) {
			self::remove( $id );

			return true;
		}

		$definition = get_post_meta( $id, self::META, true );
		$definition = is_array( $definition ) ? self::clean( $definition ) : array();
		$valid      = self::validate( $definition );

		if ( is_wp_error( $valid ) ) {
			self::remove( $id );

			// A draft that is still being written (a new campaign, a refused form) is not running, so it is
			// not reported; a published campaign that cannot run is.
			if ( 'publish' === $post->post_status ) {
				$data = (array) $valid->get_error_data();
				ConfigErrors::record( 'campaign', $id, (string) ( $data['field'] ?? '' ), (string) ( $data['reason'] ?? '' ) );
			}

			return $valid;
		}

		$previous = CampaignRepository::row( $id );
		$version  = $previous ? (int) $previous['version'] + 1 : 1;
		$active   = 'publish' === $post->post_status && empty( $definition['paused'] );
		$compiled = array_merge(
			array_intersect_key( $definition, array_flip( array( 'actions', 'rules', 'targets', 'schedule', 'priority', 'exclusive', 'basis', 'max_discount_minor', 'meta' ) ) ),
			array( 'label' => '' !== $post->post_title ? $post->post_title : '#' . $id )
		);
		$campaign = Campaign::fromArray( array_merge( $compiled, array( 'id' => $id ) ) );
		$schedule = $campaign->schedule;
		$limits   = array(
			'usage_limit'              => self::intOrNull( $definition['usage_limit'] ?? null ),
			'usage_limit_per_customer' => self::intOrNull( $definition['usage_limit_per_customer'] ?? null ),
		);
		$table    = Schema::table( 'campaigns' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace(
			$table,
			array(
				'campaign_id' => $id,
				'status'      => $active ? 'active' : 'inactive',
				'priority'    => $campaign->priority,
				'exclusive'   => $campaign->exclusive ? 1 : 0,
				'version'     => $version,
				'definition'  => (string) wp_json_encode( $compiled ),
				'updated_at'  => Clock::mysql(),
			),
			array( '%d', '%s', '%d', '%d', '%d', '%s', '%s' )
		);

		// Nullable columns are written separately so NULL stays NULL.
		$nullable = array(
			'starts_ts' => $schedule instanceof Schedule ? $schedule->starts_ts : null,
			'ends_ts'   => $schedule instanceof Schedule ? $schedule->ends_ts : null,
		) + $limits;
		$sets     = array();

		foreach ( $nullable as $col => $value ) {
			$sets[] = null === $value ? "{$col} = NULL" : $wpdb->prepare( "{$col} = %d", $value ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET " . implode( ', ', $sets ) . ' WHERE campaign_id = %d', $id ) );

		$product_ids = self::writeTargets( $id, $campaign->targets->toArray() );

		CampaignRepository::bump();
		MetricsRepository::start();
		ConfigErrors::clear( 'campaign', $id );
		AuditLog::record(
			'campaign',
			$id,
			$previous ? 'updated' : 'created',
			array(
				'version' => $version,
				'status'  => $active ? 'active' : 'inactive',
				'before'  => $previous ? array( 'status' => $previous['status'], 'version' => (int) $previous['version'] ) : null,
			)
		);
		CachePurger::purge( $product_ids );

		/**
		 * Fires after a campaign was compiled.
		 *
		 * @param int  $id     Campaign (post) id.
		 * @param bool $active Whether it is active now.
		 */
		do_action( 'eru_ce_campaign_compiled', $id, $active );

		return true;
	}

	/** Removes the compiled row and its targets; usage and reward records stay. */
	public static function remove( int $id ): void {
		global $wpdb;

		$row = CampaignRepository::row( $id );

		if ( null === $row ) {
			return;
		}

		$def     = json_decode( (string) $row['definition'], true );
		$targets = is_array( $def ) && is_array( $def['targets'] ?? null ) ? $def['targets'] : array();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'campaigns' ), array( 'campaign_id' => $id ), array( '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( Schema::table( 'targets' ), array( 'campaign_id' => $id ), array( '%d' ) );

		CampaignRepository::bump();
		AuditLog::record( 'campaign', $id, 'removed', array( 'version' => (int) $row['version'] ) );
		CachePurger::purge( self::productIds( $targets ) );
		do_action( 'eru_ce_campaign_compiled', $id, false );
	}

	/**
	 * Rewrites the targets rows; returns the product ids to purge (empty = purge everything).
	 *
	 * @param array<string,array<string,array<int,int|string>>> $targets
	 * @return int[]
	 */
	private static function writeTargets( int $id, array $targets ): array {
		global $wpdb;

		$table = Schema::table( 'targets' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'campaign_id' => $id ), array( '%d' ) );

		foreach ( array( 'include', 'exclude' ) as $side ) {
			foreach ( (array) ( $targets[ $side ] ?? array() ) as $type => $values ) {
				foreach ( (array) $values as $value ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery
					$wpdb->insert(
						$table,
						array(
							'campaign_id' => $id,
							'side'        => $side,
							'target_type' => (string) $type,
							'target_id'   => 'sku' === $type ? 0 : (int) $value,
							'target_key'  => 'sku' === $type ? substr( (string) $value, 0, 100 ) : '',
						),
						array( '%d', '%s', '%s', '%d', '%s' )
					);
				}
			}
		}

		return self::productIds( $targets );
	}

	/**
	 * Product ids named by an include list; empty when the campaign reaches beyond plain product ids
	 * (terms, SKUs, storewide), in which case the whole cache is purged.
	 *
	 * @return int[]
	 */
	private static function productIds( array $targets ): array {
		$include = (array) ( $targets['include'] ?? array() );

		foreach ( array( 'term', 'sku' ) as $wide ) {
			if ( array() !== (array) ( $include[ $wide ] ?? array() ) ) {
				return array();
			}
		}

		$ids = array_merge( (array) ( $include['product'] ?? array() ), (array) ( $include['variation'] ?? array() ) );

		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/** @param array<string,mixed> $definition */
	private static function clean( array $definition ): array {
		return array_intersect_key( $definition, array_flip( self::KEYS ) );
	}

	private static function intOrNull( $value ): ?int {
		return null === $value || '' === $value ? null : (int) $value;
	}
}
