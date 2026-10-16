<?php
/**
 * The one rule system. A rule pays one level (1–5) a percentage of the line basis or a fixed amount per
 * unit, for a scope:
 *   product (target_id = product id; a variation also matches its parent),
 *   category (product_cat term, ancestors included), brand (brand taxonomy term, ancestors included),
 *   role (target_key = the buyer's role slug), agent (target_id = the earning agent), global.
 * The matching rule for a line and level is chosen by scope (in that order), then the higher priority,
 * then the newest rule. Inactive rules and rules outside their date range (at the order date) are ignored.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Rules {

	/** Scope → precedence rank (lower wins). */
	const SCOPES = array(
		'product'  => 1,
		'category' => 2,
		'brand'    => 3,
		'role'     => 4,
		'agent'    => 5,
		'global'   => 6,
	);
	const TYPES  = array( 'percent', 'fixed' );

	/** @var array In-process cache of candidate rules per "level|minute". */
	private static $memo = array();

	/** @return string Table name. */
	private static function t() {
		return Eru_STC_Installer::table( 'rules' );
	}

	/**
	 * Validates and cleans rule fields.
	 *
	 * @param array       $in      Raw fields.
	 * @param object|null $current Existing row (update) or null (create).
	 * @return array|WP_Error Columns.
	 */
	public static function validate( array $in, $current = null ) {
		$base  = $current ? (array) $current : array(
			'name'       => '',
			'scope'      => 'global',
			'target_id'  => 0,
			'target_key' => '',
			'level'      => 1,
			'type'       => 'percent',
			'rate'       => '0',
			'starts_at'  => null,
			'ends_at'    => null,
			'priority'   => 0,
			'active'     => 1,
		);
		$r     = array_merge( $base, $in );
		$scope = (string) $r['scope'];
		if ( ! isset( self::SCOPES[ $scope ] ) ) {
			return new WP_Error( 'eru_stc_rule_scope', __( 'Geçersiz kural kapsamı.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$level = (int) $r['level'];
		if ( $level < 1 || $level > 5 ) {
			return new WP_Error( 'eru_stc_rule_level', __( 'Seviye 1 ile 5 arasında olmalı.', 'sales-team-commissions-for-woocommerce' ) );
		}
		if ( ! in_array( $r['type'], self::TYPES, true ) ) {
			return new WP_Error( 'eru_stc_rule_type', __( 'Geçersiz komisyon türü.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$rate = Eru_STC_Money::to_minor( $r['rate'], 4 );
		if ( $rate < 0 || ( 'percent' === $r['type'] && $rate > 1000000 ) ) {
			return new WP_Error( 'eru_stc_rule_rate', __( 'Oran 0 ile 100 arasında, tutar sıfır veya pozitif olmalı.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$target_id  = in_array( $scope, array( 'product', 'category', 'brand', 'agent' ), true ) ? absint( $r['target_id'] ) : 0;
		$target_key = 'role' === $scope ? sanitize_key( (string) $r['target_key'] ) : '';
		if ( in_array( $scope, array( 'product', 'category', 'brand', 'agent' ), true ) && 0 === $target_id ) {
			return new WP_Error( 'eru_stc_rule_target', __( 'Bu kapsam için bir hedef seçin.', 'sales-team-commissions-for-woocommerce' ) );
		}
		if ( 'role' === $scope && '' === $target_key ) {
			return new WP_Error( 'eru_stc_rule_target', __( 'Bu kapsam için bir hedef seçin.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$starts = self::datetime( $r['starts_at'] );
		$ends   = self::datetime( $r['ends_at'] );
		if ( false === $starts || false === $ends ) {
			return new WP_Error( 'eru_stc_rule_date', __( 'Geçersiz tarih.', 'sales-team-commissions-for-woocommerce' ) );
		}
		if ( $starts && $ends && $ends < $starts ) {
			return new WP_Error( 'eru_stc_rule_range', __( 'Bitiş tarihi başlangıçtan önce olamaz.', 'sales-team-commissions-for-woocommerce' ) );
		}
		return array(
			'name'       => substr( sanitize_text_field( (string) $r['name'] ), 0, 190 ),
			'scope'      => $scope,
			'target_id'  => $target_id,
			'target_key' => $target_key,
			'level'      => $level,
			'type'       => $r['type'],
			'rate'       => Eru_STC_Money::from_minor( $rate, 4 ),
			'starts_at'  => $starts,
			'ends_at'    => $ends,
			'priority'   => max( -100000, min( 100000, (int) $r['priority'] ) ),
			'active'     => empty( $r['active'] ) ? 0 : 1,
		);
	}

	/** @param mixed $v Date (UTC "Y-m-d H:i:s", or empty). @return string|null|false Clean value, null, or false when invalid. */
	private static function datetime( $v ) {
		if ( null === $v || '' === $v ) {
			return null;
		}
		$d = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $v, new DateTimeZone( 'UTC' ) );
		if ( ! $d ) {
			$d = DateTimeImmutable::createFromFormat( 'Y-m-d', (string) $v, new DateTimeZone( 'UTC' ) );
			$d = $d ? $d->setTime( 0, 0, 0 ) : false;
		}
		return $d ? $d->format( 'Y-m-d H:i:s' ) : false;
	}

	/**
	 * @param array $fields See validate().
	 * @return int|WP_Error Rule id.
	 */
	public static function create( array $fields ) {
		global $wpdb;
		$row = self::validate( $fields );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$now               = current_time( 'mysql', true );
		$row['created_at'] = $now;
		$row['updated_at'] = $now;
		if ( ! $wpdb->insert( self::t(), $row ) ) {
			return new WP_Error( 'eru_stc_rule_db', __( 'Kural kaydedilemedi.', 'sales-team-commissions-for-woocommerce' ) );
		}
		self::$memo = array();
		$id         = (int) $wpdb->insert_id;
		do_action( 'eru_stc_rule_saved', $id, $row );
		return $id;
	}

	/**
	 * @param int   $id     Rule id.
	 * @param array $fields Fields to change.
	 * @return true|WP_Error
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		$current = self::get( $id );
		if ( ! $current ) {
			return new WP_Error( 'eru_stc_rule_missing', __( 'Kural bulunamadı.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$row = self::validate( $fields, $current );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$row['updated_at'] = current_time( 'mysql', true );
		if ( false === $wpdb->update( self::t(), $row, array( 'id' => (int) $id ) ) ) {
			return new WP_Error( 'eru_stc_rule_db', __( 'Kural kaydedilemedi.', 'sales-team-commissions-for-woocommerce' ) );
		}
		self::$memo = array();
		do_action( 'eru_stc_rule_saved', (int) $id, $row );
		return true;
	}

	/** @param int $id Rule id. @return bool */
	public static function delete( $id ) {
		global $wpdb;
		self::$memo = array();
		return (bool) $wpdb->delete( self::t(), array( 'id' => (int) $id ) );
	}

	/** @param int $id Rule id. @return object|null */
	public static function get( $id ) {
		global $wpdb;
		$t = self::t();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Rules for a list screen.
	 *
	 * @param array $args scope, level, active (0|1), limit (≤ 500), offset.
	 * @return object[]
	 */
	public static function all( array $args = array() ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['scope'] ) && isset( self::SCOPES[ $args['scope'] ] ) ) {
			$where[]  = 'scope = %s';
			$params[] = $args['scope'];
		}
		if ( ! empty( $args['level'] ) ) {
			$where[]  = 'level = %d';
			$params[] = (int) $args['level'];
		}
		if ( isset( $args['active'] ) ) {
			$where[]  = 'active = %d';
			$params[] = $args['active'] ? 1 : 0;
		}
		$params[] = max( 1, min( 500, (int) ( $args['limit'] ?? 100 ) ) );
		$params[] = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$t        = self::t();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE " . implode( ' AND ', $where ) . ' ORDER BY level, scope, priority DESC, id DESC LIMIT %d OFFSET %d', $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * Matching context of a product line.
	 *
	 * @param WC_Product|int $product  Product (or id).
	 * @param int            $agent_id Earning agent.
	 * @param string[]       $roles    Buyer's roles ([] for a guest).
	 * @param int            $at       Unix time the rules are evaluated at (the order date).
	 * @return array
	 */
	public static function context_for_product( $product, $agent_id, array $roles, $at ) {
		$product = is_object( $product ) ? $product : wc_get_product( (int) $product );
		$ids     = array();
		$main    = 0;
		if ( $product ) {
			$ids[] = (int) $product->get_id();
			$main  = $product->get_parent_id() ? (int) $product->get_parent_id() : (int) $product->get_id();
			if ( $main !== (int) $product->get_id() ) {
				$ids[] = $main;
			}
		}
		/**
		 * Taxonomies treated as brands (WooCommerce's own brand taxonomy by default).
		 *
		 * @param string[] $taxonomies Taxonomy names.
		 */
		$brand_tax = (array) apply_filters( 'eru_stc_brand_taxonomies', array( 'product_brand' ) );
		$brands    = array();
		foreach ( $brand_tax as $tax ) {
			if ( $main && taxonomy_exists( $tax ) ) {
				$brands = array_merge( $brands, self::terms_with_ancestors( $main, $tax ) );
			}
		}
		return array(
			'product_ids'  => $ids,
			'category_ids' => $main ? self::terms_with_ancestors( $main, 'product_cat' ) : array(),
			'brand_ids'    => array_values( array_unique( $brands ) ),
			'roles'        => array_values( array_map( 'strval', $roles ) ),
			'agent_id'     => (int) $agent_id,
			'at'           => (int) $at,
		);
	}

	/** @return int[] Term ids of a post in a taxonomy, with their ancestors. */
	private static function terms_with_ancestors( $post_id, $taxonomy ) {
		$terms = wp_get_post_terms( $post_id, $taxonomy, array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return array();
		}
		$out = array();
		foreach ( $terms as $id ) {
			$out[] = (int) $id;
			foreach ( get_ancestors( (int) $id, $taxonomy, 'taxonomy' ) as $a ) {
				$out[] = (int) $a;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * The rule that pays $level for a line, or null.
	 *
	 * @param int   $level Level 1–5.
	 * @param array $ctx   From context_for_product().
	 * @return object|null Rule row.
	 */
	public static function find( $level, array $ctx ) {
		$best = null;
		foreach ( self::candidates( (int) $level, (int) $ctx['at'] ) as $rule ) {
			if ( ! self::matches( $rule, $ctx ) ) {
				continue;
			}
			if ( null === $best || self::beats( $rule, $best ) ) {
				$best = $rule;
			}
		}
		/**
		 * The rule chosen for a line and level (null = no commission).
		 *
		 * @param object|null $best  Rule row.
		 * @param int         $level Level.
		 * @param array       $ctx   Matching context.
		 */
		return apply_filters( 'eru_stc_rule_for_line', $best, (int) $level, $ctx );
	}

	/** @return bool Whether rule $a takes precedence over $b. */
	private static function beats( $a, $b ) {
		$ra = self::SCOPES[ $a->scope ];
		$rb = self::SCOPES[ $b->scope ];
		if ( $ra !== $rb ) {
			return $ra < $rb;
		}
		if ( (int) $a->priority !== (int) $b->priority ) {
			return (int) $a->priority > (int) $b->priority;
		}
		return (int) $a->id > (int) $b->id;
	}

	/** @return bool Whether a rule's target matches the context. */
	private static function matches( $rule, array $ctx ) {
		switch ( $rule->scope ) {
			case 'product':
				return in_array( (int) $rule->target_id, $ctx['product_ids'], true );
			case 'category':
				return in_array( (int) $rule->target_id, $ctx['category_ids'], true );
			case 'brand':
				return in_array( (int) $rule->target_id, $ctx['brand_ids'], true );
			case 'role':
				return in_array( (string) $rule->target_key, $ctx['roles'], true );
			case 'agent':
				return (int) $rule->target_id === (int) $ctx['agent_id'];
			case 'global':
				return true;
		}
		return false;
	}

	/** @return object[] Active rules of a level valid at a time (memoised per minute). */
	private static function candidates( $level, $at ) {
		global $wpdb;
		$at  = $at > 0 ? $at : time();
		$key = $level . '|' . intdiv( $at, 60 );
		if ( ! isset( self::$memo[ $key ] ) ) {
			if ( count( self::$memo ) > 50 ) {
				self::$memo = array();
			}
			$when               = gmdate( 'Y-m-d H:i:s', $at );
			$t                  = self::t();
			self::$memo[ $key ] = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$t} WHERE active = 1 AND level = %d AND ( starts_at IS NULL OR starts_at <= %s ) AND ( ends_at IS NULL OR ends_at >= %s )", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$level,
					$when,
					$when
				)
			);
		}
		return self::$memo[ $key ];
	}

	/** Drops the in-process cache (after a direct table write). */
	public static function flush() {
		self::$memo = array();
	}
}
