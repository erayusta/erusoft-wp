<?php
/**
 * Tables and row access.
 *
 * @package device-trade-in-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Devices, trade-ins and leads.
 */
class Eru_DTI_DB {

	const DB_VERSION = '1';

	const STATUSES = array( 'beklemede', 'teslim-alindi', 'onaylandi', 'reddedildi' );

	/** Option set while trade-in or lead rows (personal data) exist; keeps the retention job alive. */
	const DATA_FLAG = 'eru_dti_has_data';

	/** Marks that personal data was stored. */
	public static function flag_data() {
		if ( ! get_option( self::DATA_FLAG ) ) {
			update_option( self::DATA_FLAG, 1, true );
		}
	}

	/** @return bool Any trade-in or lead row exists. */
	public static function has_rows() {
		global $wpdb;
		return (bool) $wpdb->get_var( 'SELECT 1 FROM ' . self::table( 'tradeins' ) . ' LIMIT 1' ) || (bool) $wpdb->get_var( 'SELECT 1 FROM ' . self::table( 'leads' ) . ' LIMIT 1' ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @param string $name devices|tradeins|leads. */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'eru_dti_' . $name;
	}

	/** Creates or migrates the tables when the stored version differs. */
	public static function maybe_upgrade() {
		if ( get_option( 'eru_dti_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$cc = $wpdb->get_charset_collate();
		dbDelta( 'CREATE TABLE ' . self::table( 'devices' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			level1 varchar(190) NOT NULL DEFAULT '',
			level2 varchar(190) NOT NULL DEFAULT '',
			level3 varchar(190) NOT NULL DEFAULT '',
			base_value decimal(12,2) NOT NULL DEFAULT 0,
			active tinyint(1) NOT NULL DEFAULT 1,
			sort int(11) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY lookup (level1(60),level2(60),level3(60))
		) $cc;" );
		dbDelta( 'CREATE TABLE ' . self::table( 'tradeins' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			order_id bigint(20) unsigned NOT NULL DEFAULT 0,
			device_id bigint(20) unsigned NOT NULL DEFAULT 0,
			condition_key varchar(60) NOT NULL DEFAULT '',
			serial varchar(120) NOT NULL DEFAULT '',
			value decimal(12,2) NOT NULL DEFAULT 0,
			bonus decimal(12,2) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'beklemede',
			customer_email varchar(190) NOT NULL DEFAULT '',
			admin_note text NULL,
			reminded_at datetime NULL,
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY order_id (order_id),
			KEY status_created (status,created_at),
			KEY customer_email (customer_email(60))
		) $cc;" );
		dbDelta( 'CREATE TABLE ' . self::table( 'leads' ) . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL DEFAULT '',
			email varchar(190) NOT NULL DEFAULT '',
			phone varchar(60) NOT NULL DEFAULT '',
			device_text varchar(255) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY email (email(60)),
			KEY created_at (created_at)
		) $cc;" );
		update_option( 'eru_dti_db_version', self::DB_VERSION, false );
	}

	/* ---------------- Devices ---------------- */

	/** @param array $d Row data. @return array Sanitised. */
	private static function clean_device( array $d ) {
		return array(
			'level1'     => mb_substr( sanitize_text_field( $d['level1'] ?? '' ), 0, 190 ),
			'level2'     => mb_substr( sanitize_text_field( $d['level2'] ?? '' ), 0, 190 ),
			'level3'     => mb_substr( sanitize_text_field( $d['level3'] ?? '' ), 0, 190 ),
			'base_value' => round( max( 0, (float) ( $d['base_value'] ?? 0 ) ), 2 ),
			'active'     => empty( $d['active'] ) ? 0 : 1,
			'sort'       => (int) ( $d['sort'] ?? 0 ),
		);
	}

	/** @param array $d Device fields. @return int Insert ID. */
	public static function device_insert( array $d ) {
		global $wpdb;
		$wpdb->insert( self::table( 'devices' ), self::clean_device( $d ), array( '%s', '%s', '%s', '%f', '%d', '%d' ) );
		self::bump_cache();
		return (int) $wpdb->insert_id;
	}

	/** @param int $id ID. @param array $d Fields to change. */
	public static function device_update( $id, array $d ) {
		global $wpdb;
		$cur = self::device_get( $id );
		if ( ! $cur ) {
			return false;
		}
		$row = self::clean_device( array_merge( $cur, $d ) );
		$wpdb->update( self::table( 'devices' ), $row, array( 'id' => (int) $id ), array( '%s', '%s', '%s', '%f', '%d', '%d' ), array( '%d' ) );
		self::bump_cache();
		return true;
	}

	/** @param int $id ID. */
	public static function device_delete( $id ) {
		global $wpdb;
		$wpdb->delete( self::table( 'devices' ), array( 'id' => (int) $id ), array( '%d' ) );
		self::bump_cache();
	}

	/** @param int $id ID. @return array|null */
	public static function device_get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'devices' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @return array|null Exact-match row. */
	public static function device_find( $l1, $l2, $l3 ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'devices' ) . ' WHERE level1 = %s AND level2 = %s AND level3 = %s LIMIT 1', $l1, $l2, $l3 ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** Invalidates the public options cache. */
	public static function bump_cache() {
		update_option( 'eru_dti_cache_ver', time() . wp_rand( 10, 99 ), false );
	}

	/** @return string */
	public static function cache_ver() {
		return (string) get_option( 'eru_dti_cache_ver', '0' );
	}

	/** @return int */
	public static function device_count() {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table( 'devices' ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @param array $args search, per_page, page, active_only. @return array{rows:array,total:int} */
	public static function devices_list( array $args = array() ) {
		global $wpdb;
		$where = '1=1';
		$vals  = array();
		if ( ! empty( $args['search'] ) ) {
			$like  = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where .= ' AND (level1 LIKE %s OR level2 LIKE %s OR level3 LIKE %s)';
			array_push( $vals, $like, $like, $like );
		}
		$t     = self::table( 'devices' );
		$total = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $where", $vals ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE $where" ) ); // phpcs:ignore
		$per   = max( 1, (int) ( $args['per_page'] ?? 50 ) );
		$off   = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per );
		$sql   = "SELECT * FROM $t WHERE $where ORDER BY sort ASC, level1 ASC, level2 ASC, level3 ASC LIMIT %d OFFSET %d";
		$rows  = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $vals, array( $per, $off ) ) ), ARRAY_A ); // phpcs:ignore
		return array( 'rows' => $rows, 'total' => $total );
	}

	/** @return array All devices (export). */
	public static function devices_all() {
		global $wpdb;
		return $wpdb->get_results( 'SELECT * FROM ' . self::table( 'devices' ) . ' ORDER BY sort ASC, id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/* ---------------- Trade-ins ---------------- */

	/** @param array $d Fields. @return int ID. */
	public static function tradein_insert( array $d ) {
		global $wpdb;
		$status = in_array( $d['status'] ?? '', self::STATUSES, true ) ? $d['status'] : 'beklemede';
		$wpdb->insert(
			self::table( 'tradeins' ),
			array(
				'order_id'       => (int) ( $d['order_id'] ?? 0 ),
				'device_id'      => (int) ( $d['device_id'] ?? 0 ),
				'condition_key'  => sanitize_key( $d['condition_key'] ?? '' ),
				'serial'         => mb_substr( sanitize_text_field( $d['serial'] ?? '' ), 0, 120 ),
				'value'          => round( (float) ( $d['value'] ?? 0 ), 2 ),
				'bonus'          => round( (float) ( $d['bonus'] ?? 0 ), 2 ),
				'status'         => $status,
				'customer_email' => sanitize_email( $d['customer_email'] ?? '' ),
				'created_at'     => gmdate( 'Y-m-d H:i:s' ),
			),
			array( '%d', '%d', '%s', '%s', '%f', '%f', '%s', '%s', '%s' )
		);
		$id = (int) $wpdb->insert_id;
		self::flag_data(); // After reading insert_id: the option write would replace it.
		return $id;
	}

	/** @param int $id ID. @return array|null */
	public static function tradein_get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'tradeins' ) . ' WHERE id = %d', (int) $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @param int $order_id Order ID. @return array|null */
	public static function tradein_for_order( $order_id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table( 'tradeins' ) . ' WHERE order_id = %d ORDER BY id DESC LIMIT 1', (int) $order_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @param int $id ID. @param string $note Note. */
	public static function tradein_set_note( $id, $note ) {
		global $wpdb;
		$wpdb->update( self::table( 'tradeins' ), array( 'admin_note' => sanitize_textarea_field( $note ) ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
	}

	/**
	 * Changes the status; approval releases a held order.
	 *
	 * @param int    $id     Trade-in ID.
	 * @param string $status New status.
	 * @return bool
	 */
	public static function tradein_set_status( $id, $status ) {
		global $wpdb;
		$row = self::tradein_get( $id );
		if ( ! $row || ! in_array( $status, self::STATUSES, true ) ) {
			return false;
		}
		$wpdb->update( self::table( 'tradeins' ), array( 'status' => $status ), array( 'id' => (int) $id ), array( '%s' ), array( '%d' ) );
		$order = $row['order_id'] ? wc_get_order( (int) $row['order_id'] ) : null;
		if ( $order && $row['status'] !== $status ) {
			/* translators: %s: new trade-in status label. */
			$order->add_order_note( sprintf( __( 'Takas kaydı durumu: %s', 'device-trade-in-for-woocommerce' ), self::status_label( $status ) ) );
		}
		if ( 'onaylandi' === $status && $order ) {
			Eru_DTI_Hold::release( $order );
		} elseif ( 'reddedildi' === $status && $order && $row['status'] !== $status ) {
			Eru_DTI_Hold::on_rejected( $order );
		}
		return true;
	}

	/** @param string $s Status key. */
	public static function status_label( $s ) {
		$map = array(
			'beklemede'     => __( 'Beklemede', 'device-trade-in-for-woocommerce' ),
			'teslim-alindi' => __( 'Teslim alındı', 'device-trade-in-for-woocommerce' ),
			'onaylandi'     => __( 'Onaylandı', 'device-trade-in-for-woocommerce' ),
			'reddedildi'    => __( 'Reddedildi', 'device-trade-in-for-woocommerce' ),
		);
		return $map[ $s ] ?? $s;
	}

	/** @param array $args status, per_page, page. @return array{rows:array,total:int} */
	public static function tradeins_list( array $args = array() ) {
		global $wpdb;
		$t     = self::table( 'tradeins' );
		$where = '1=1';
		$vals  = array();
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where .= ' AND status = %s';
			$vals[] = $args['status'];
		}
		$total = (int) ( $vals ? $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $t WHERE $where", $vals ) ) : $wpdb->get_var( "SELECT COUNT(*) FROM $t WHERE $where" ) ); // phpcs:ignore
		$per   = max( 1, (int) ( $args['per_page'] ?? 30 ) );
		$off   = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE $where ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $vals, array( $per, $off ) ) ), ARRAY_A ); // phpcs:ignore
		return array( 'rows' => $rows, 'total' => $total );
	}

	/** @param array $device Device row. @return string "Marka / Model / Kapasite". */
	public static function device_label( $device ) {
		if ( ! $device ) {
			return '';
		}
		return implode( ' / ', array_filter( array( $device['level1'], $device['level2'], $device['level3'] ), 'strlen' ) );
	}
}
