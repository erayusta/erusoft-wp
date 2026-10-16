<?php
/**
 * Database schema. Runs on activation and on plugins_loaded, but only when the stored schema version differs
 * (one autoloaded option read per request).
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tables (all times UTC):
 * - eru_mpo_orders:     the order map. One row per (marketplace, remote_ref); status pending → done | error.
 *                       A pending row is inserted before an order is built (UNIQUE claim) and filled after save.
 *                       attempts / next_try_at hold the retry backoff after a failure (error column = last error).
 * - eru_mpo_log:        importer/migrator log, redacted, purged after the log retention.
 * - eru_mpo_raw:        the latest raw marketplace payload per package (identity numbers masked), purged after
 *                       the raw retention. Raw payloads never go into order meta.
 * - eru_mpo_listings:   marketplace listings (remote SKU/barcode/id ↔ WooCommerce product) used to match lines.
 * - eru_mpo_sync_queue: stock/price products waiting to be pushed (filled by the stock bridge module). seq grows on
 *                       every change, so a push only removes the row it read (a change during the push survives);
 *                       next_try_at holds the backoff after a failed push.
 */
class Eru_MPO_Installer {

	const DB_VERSION = '3';
	const OPTION     = 'eru_mpo_db_version';
	const TABLES     = array( 'orders', 'log', 'raw', 'listings', 'sync_queue' );

	/** @param string $name orders | log | raw | listings | sync_queue. @return string Table name. */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'eru_mpo_' . $name;
	}

	/** Creates or migrates the tables when the stored version differs. */
	public static function maybe_install() {
		if ( get_option( self::OPTION ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$c = $wpdb->get_charset_collate();
		dbDelta(
			'CREATE TABLE ' . self::table( 'orders' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				marketplace varchar(32) NOT NULL,
				remote_ref varchar(64) NOT NULL,
				wc_order_id bigint(20) unsigned DEFAULT NULL,
				status varchar(16) NOT NULL DEFAULT 'pending',
				review tinyint(1) unsigned NOT NULL DEFAULT 0,
				error text DEFAULT NULL,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				next_try_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY marketplace_ref (marketplace,remote_ref),
				KEY wc_order_id (wc_order_id),
				KEY status (status)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'log' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				level varchar(10) NOT NULL DEFAULT 'info',
				marketplace varchar(32) NOT NULL DEFAULT '',
				remote_ref varchar(64) NOT NULL DEFAULT '',
				message text NOT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY marketplace (marketplace,created_at),
				KEY marketplace_ref (marketplace,remote_ref)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'raw' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				marketplace varchar(32) NOT NULL,
				remote_ref varchar(64) NOT NULL,
				payload longtext NOT NULL,
				stored_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY marketplace_ref (marketplace,remote_ref),
				KEY stored_at (stored_at)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'listings' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				marketplace varchar(32) NOT NULL,
				wc_product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				wc_sku varchar(100) NOT NULL DEFAULT '',
				remote_sku varchar(100) NOT NULL DEFAULT '',
				barcode varchar(100) NOT NULL DEFAULT '',
				remote_id varchar(64) NOT NULL DEFAULT '',
				salable tinyint(1) unsigned NOT NULL DEFAULT 1,
				remote_stock int(11) DEFAULT NULL,
				remote_price decimal(12,2) DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY marketplace_sku (marketplace,wc_sku),
				KEY marketplace_remote (marketplace,remote_sku),
				KEY marketplace_barcode (marketplace,barcode),
				KEY wc_product_id (wc_product_id)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'sync_queue' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				product_id bigint(20) unsigned NOT NULL,
				marketplace varchar(32) NOT NULL,
				kind varchar(10) NOT NULL,
				queued_at datetime NOT NULL,
				seq int(10) unsigned NOT NULL DEFAULT 0,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				next_try_at datetime DEFAULT NULL,
				last_error text DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY product_marketplace_kind (product_id,marketplace,kind),
				KEY marketplace_next (marketplace,next_try_at),
				KEY queued_at (queued_at)
			) {$c};"
		);
		Eru_MPO_Migrator::detect_legacy();
		update_option( self::OPTION, self::DB_VERSION, true );
	}
}
