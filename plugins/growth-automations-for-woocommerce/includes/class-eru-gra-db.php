<?php
/**
 * Tables: captured carts and the send log.
 *
 * @package growth-automations-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema and log access.
 */
class Eru_GRA_DB {

	const VERSION = '1';

	/** Hooks. */
	public static function init() {
		add_action(
			'admin_init',
			function () {
				if ( get_option( 'eru_gra_db_version' ) !== self::VERSION ) {
					self::install();
				}
			}
		);
	}

	/** @return string Carts table. */
	public static function carts() {
		global $wpdb;
		return $wpdb->prefix . 'eru_gra_carts';
	}

	/** @return string Log table. */
	public static function log_table() {
		global $wpdb;
		return $wpdb->prefix . 'eru_gra_log';
	}

	/** Create or update the tables. */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$carts   = self::carts();
		$log     = self::log_table();
		dbDelta(
			"CREATE TABLE {$carts} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email varchar(190) NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				cart longtext NOT NULL,
				token char(64) NOT NULL DEFAULT '',
				consent_source varchar(20) NOT NULL DEFAULT '',
				status varchar(12) NOT NULL DEFAULT 'open',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				sent_at datetime NULL,
				PRIMARY KEY  (id),
				KEY email (email),
				KEY status_updated (status,updated_at),
				KEY token (token)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				automation varchar(20) NOT NULL,
				email_masked varchar(190) NOT NULL,
				result varchar(40) NOT NULL,
				PRIMARY KEY  (id)
			) {$charset};"
		);
		update_option( 'eru_gra_db_version', self::VERSION, false );
	}

	/** Remember that personal data exists, so the retention job keeps running with every module off. */
	public static function flag_data() {
		if ( ! get_option( 'eru_gra_has_data' ) ) {
			update_option( 'eru_gra_has_data', 1, false );
			Eru_GRA_Settings::sync_schedules();
		}
	}

	/** @param string $email E-mail. @return string Masked form ("a***@domain"). */
	public static function mask( $email ) {
		$email = (string) $email;
		$at    = strrpos( $email, '@' );
		if ( false === $at || 0 === $at ) {
			return '***';
		}
		return mb_substr( $email, 0, 1 ) . '***' . substr( $email, $at );
	}

	/** @param string $automation Automation. @param string $email Address (stored masked). @param string $result Result code. */
	public static function log( $automation, $email, $result ) {
		global $wpdb;
		$wpdb->insert(
			self::log_table(),
			array(
				'created_at'   => gmdate( 'Y-m-d H:i:s' ),
				'automation'   => substr( sanitize_key( $automation ), 0, 20 ),
				'email_masked' => substr( self::mask( $email ), 0, 190 ),
				'result'       => substr( sanitize_key( $result ), 0, 40 ),
			)
		);
	}

	/** Keep only the newest 200 log rows. */
	public static function trim_log() {
		global $wpdb;
		$t   = self::log_table();
		$min = $wpdb->get_var( "SELECT id FROM {$t} ORDER BY id DESC LIMIT 1 OFFSET 199" ); // phpcs:ignore WordPress.DB.PreparedSQL
		if ( $min ) {
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE id < %d", (int) $min ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		}
	}

	/** @param int $n Rows. @return array[] Newest first. */
	public static function log_rows( $n = 200 ) {
		global $wpdb;
		$t = self::log_table();
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY id DESC LIMIT %d", max( 1, (int) $n ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
