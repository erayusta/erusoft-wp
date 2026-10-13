<?php
/**
 * Tables and schema version.
 *
 * @package order-sms-delivery-codes-for-ileti-merkezi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OSMS_Installer {

	const DB_VERSION        = '1.0.0';
	const DB_VERSION_OPTION = 'eru_osms_db_version';

	public static function log_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'eru_osms_log';
	}

	public static function delivery_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'eru_osms_delivery';
	}

	public static function install(): void {
		self::schema();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}

	public static function maybe_update(): void {
		$installed = get_option( self::DB_VERSION_OPTION );
		if ( ! $installed || version_compare( (string) $installed, self::DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	/**
	 * `text` is always stored with the {teslimat_kodu} placeholder: a plain delivery code never reaches the table.
	 * `order_rule` index: the same rule never sends twice to the same order.
	 * Delivery table: one row per order; `code_hash` = wp_hash_password(code).
	 * `result`: pending (locked) → with_code | without_code.
	 */
	private static function schema(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$log      = self::log_table();
		$delivery = self::delivery_table();
		$collate  = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$log} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			source VARCHAR(10) NOT NULL DEFAULT 'rule',
			rule_id VARCHAR(40) NOT NULL DEFAULT '',
			phone VARCHAR(20) NOT NULL DEFAULT '',
			text TEXT NOT NULL,
			has_code TINYINT(1) NOT NULL DEFAULT 0,
			segments SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			status VARCHAR(12) NOT NULL DEFAULT 'queued',
			error VARCHAR(255) NOT NULL DEFAULT '',
			api_response VARCHAR(500) NOT NULL DEFAULT '',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY order_rule (order_id, rule_id),
			KEY created_at (created_at),
			KEY status (status)
		) {$collate};"
		);

		dbDelta(
			"CREATE TABLE {$delivery} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			rule_id VARCHAR(40) NOT NULL DEFAULT '',
			target_status VARCHAR(40) NOT NULL DEFAULT '',
			code_hash VARCHAR(255) NOT NULL DEFAULT '',
			code_created_at DATETIME NULL,
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			locked TINYINT(1) NOT NULL DEFAULT 0,
			sms_status VARCHAR(12) NOT NULL DEFAULT 'queued',
			result VARCHAR(12) NOT NULL DEFAULT 'pending',
			delivered_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
			recipient_name VARCHAR(150) NOT NULL DEFAULT '',
			recipient_relation VARCHAR(10) NOT NULL DEFAULT '',
			note VARCHAR(500) NOT NULL DEFAULT '',
			codeless_source VARCHAR(10) NOT NULL DEFAULT '',
			codeless_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			previous_status VARCHAR(40) NOT NULL DEFAULT '',
			delivered_at DATETIME NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY order_id (order_id),
			KEY result (result)
		) {$collate};"
		);
	}
}
