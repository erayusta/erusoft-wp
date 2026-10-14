<?php
/**
 * wp eru-cos list|add|update|delete|migrate|export|import
 *
 * @package custom-order-statuses-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage custom order statuses.
 */
class Eru_COS_CLI {

	const FIELDS = array( 'label', 'slug', 'color', 'group', 'is_paid', 'reduces_stock', 'editable', 'downloads_permitted', 'bulk_action', 'show_action_button', 'action_from', 'email_customer', 'email_admin', 'email_subject', 'email_heading', 'email_body' );

	/**
	 * Lists the custom statuses with their order counts.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : table, csv, json, yaml. Default: table.
	 *
	 * @subcommand list
	 */
	public function list_( $args, $assoc ) {
		$rows = array();
		foreach ( Eru_COS_Statuses::raw() as $slug => $s ) {
			$rows[] = array(
				'slug'    => $slug,
				'label'   => $s['label'],
				'color'   => $s['color'],
				'group'   => $s['group'],
				'paid'    => $s['is_paid'],
				'stock'   => $s['reduces_stock'],
				'orders'  => Eru_COS_Statuses::order_count( $slug ),
			);
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'slug', 'label', 'color', 'group', 'paid', 'stock', 'orders' ) );
	}

	/** Allowed status fields from CLI flags; --bg sets the colour (WP-CLI reserves --color). */
	private static function fields( $assoc ) {
		if ( isset( $assoc['bg'] ) ) {
			$assoc['color'] = $assoc['bg'];
		}
		return array_intersect_key( $assoc, array_flip( self::FIELDS ) );
	}

	/**
	 * Adds a status.
	 *
	 * ## OPTIONS
	 *
	 * <label>
	 * : Status name.
	 *
	 * [--<field>=<value>]
	 * : slug, bg (badge colour; --color is taken by WP-CLI itself), group, is_paid, reduces_stock, editable, downloads_permitted, bulk_action, show_action_button, action_from, email_customer, email_admin, email_subject, email_heading, email_body.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eru-cos add "Paketlendi" --bg=#2271b1 --email_customer=1
	 */
	public function add( $args, $assoc ) {
		$r = Eru_COS_Statuses::add( array( 'label' => $args[0] ) + self::fields( $assoc ) );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( 'wc-' . $r['slug'] );
	}

	/**
	 * Updates a status.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Status slug (without wc-).
	 *
	 * [--<field>=<value>]
	 * : Any field accepted by add, including label.
	 */
	public function update( $args, $assoc ) {
		$r = Eru_COS_Statuses::update( $args[0], self::fields( $assoc ) );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( 'wc-' . $r['slug'] );
	}

	/**
	 * Deletes a status. Refused while orders use it unless --to is given; the orders are moved first.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : Status slug.
	 *
	 * [--to=<slug>]
	 * : Status the orders are moved to (any order status, e.g. processing).
	 */
	public function delete( $args, $assoc ) {
		$r = Eru_COS_Statuses::delete( $args[0], (string) ( $assoc['to'] ?? '' ), true );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI::success( 'Silindi: wc-' . $args[0] );
	}

	/**
	 * Moves every order from one status to another.
	 *
	 * ## OPTIONS
	 *
	 * --from=<slug>
	 * : Source status.
	 *
	 * --to=<slug>
	 * : Target status.
	 */
	public function migrate( $args, $assoc ) {
		$to = preg_replace( '/^wc-/', '', (string) $assoc['to'] );
		if ( ! isset( wc_get_order_statuses()[ 'wc-' . $to ] ) ) {
			WP_CLI::error( 'Geçersiz hedef durum.' );
		}
		$from   = preg_replace( '/^wc-/', '', (string) $assoc['from'] );
		$before = Eru_COS_Statuses::order_count( $from );
		$ok     = Eru_COS_Statuses::migrate_all( $from, $to );
		$left   = Eru_COS_Statuses::order_count( $from );
		$total  = $before - $left;
		if ( ! $ok ) {
			WP_CLI::error( sprintf( 'eru_cos_migrate_failed: %d sipariş taşındı, %d sipariş taşınamadı (sipariş notlarına bakın).', $total, $left ) );
		}
		WP_CLI::success( sprintf( '%d sipariş taşındı.', $total ) );
	}

	/**
	 * Prints statuses, groups and settings as JSON.
	 */
	public function export( $args, $assoc ) {
		WP_CLI::line(
			wp_json_encode(
				array(
					'statuses' => array_values( array_map( fn( $s ) => array_diff_key( $s, array( 'pending_delete' => 1 ) ), Eru_COS_Statuses::raw() ) ),
					'groups'   => Eru_COS_Statuses::groups(),
					'settings' => Eru_COS_Statuses::settings(),
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
			)
		);
	}

	/**
	 * Imports a JSON file made by export. Existing slugs are updated, new ones added.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : JSON file.
	 */
	public function import( $args, $assoc ) {
		$data = json_decode( (string) file_get_contents( $args[0] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( ! is_array( $data ) || ! isset( $data['statuses'] ) || ! is_array( $data['statuses'] ) ) {
			WP_CLI::error( 'Geçersiz dosya.' );
		}
		if ( isset( $data['groups'] ) && is_array( $data['groups'] ) ) {
			$groups = Eru_COS_Statuses::groups();
			foreach ( $data['groups'] as $id => $label ) {
				$id = sanitize_key( (string) $id );
				if ( '' !== $id && is_string( $label ) ) {
					$groups[ $id ] = sanitize_text_field( $label );
				}
			}
			update_option( Eru_COS_Statuses::GROUPS_OPTION, $groups );
		}
		if ( isset( $data['settings'] ) && is_array( $data['settings'] ) ) {
			update_option(
				Eru_COS_Statuses::SETTINGS_OPTION,
				array(
					'group_tabs' => empty( $data['settings']['group_tabs'] ) ? 0 : 1,
					'webhooks'   => empty( $data['settings']['webhooks'] ) ? 0 : 1,
				)
			);
		}
		$done = 0;
		foreach ( $data['statuses'] as $row ) {
			if ( ! is_array( $row ) || empty( $row['slug'] ) ) {
				continue;
			}
			$row = array_intersect_key( $row, array_flip( self::FIELDS ) );
			$r   = isset( Eru_COS_Statuses::raw()[ $row['slug'] ] ) ? Eru_COS_Statuses::update( $row['slug'], $row ) : Eru_COS_Statuses::add( $row );
			if ( is_wp_error( $r ) ) {
				WP_CLI::warning( $row['slug'] . ': ' . $r->get_error_message() );
				continue;
			}
			++$done;
		}
		WP_CLI::success( sprintf( '%d durum içe aktarıldı.', $done ) );
	}
}
