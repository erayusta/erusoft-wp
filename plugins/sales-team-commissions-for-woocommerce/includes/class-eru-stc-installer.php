<?php
/**
 * Database schema. Runs on activation and on plugins_loaded, but only when the stored schema version
 * differs (one autoloaded option read per request).
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tables (times in UTC, money as DECIMAL(19,4) written from minor units):
 * - eru_stc_agents:      one row per agent user; parent_agent_id builds the hierarchy; settings is JSON;
 *                        payout_holder / payout_iban / payout_tckn hold ciphertext only (written by the
 *                        payout-details form, never in plain text).
 * - eru_stc_links:       customer → agent (one agent per customer); link_type referral|round_robin|manual;
 *                        locked_at set by first-touch (a locked link moves only by an explicit forced change).
 * - eru_stc_rules:       the single rule system: scope global|agent|product|category|brand|role, level 1–5,
 *                        percent|fixed, optional date range, priority, active.
 * - eru_stc_commissions: one row per (order, line, agent, level); entry_key '' for the commission itself,
 *                        'adj:<n>' for refund/cancel adjustments of an already paid row (parent_id → row);
 *                        status pending|approved|void|paid; payout_id once claimed by a payout;
 *                        void_reason refund|cancel (commission rows) or netted (adjustments folded back
 *                        into their row when a payout request is cancelled).
 * - eru_stc_payouts:     payout requests (requested|paid|cancelled).
 */
class Eru_STC_Installer {

	const DB_VERSION = '2';
	const OPTION     = 'eru_stc_db_version';

	/** @param string $name agents | links | rules | commissions | payouts. @return string Table name. */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'eru_stc_' . $name;
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
			'CREATE TABLE ' . self::table( 'agents' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL,
				parent_agent_id bigint(20) unsigned DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'pending',
				referral_code varchar(32) NOT NULL,
				settings longtext DEFAULT NULL,
				payout_holder text DEFAULT NULL,
				payout_iban text DEFAULT NULL,
				payout_tckn text DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY user_id (user_id),
				UNIQUE KEY referral_code (referral_code),
				KEY parent_agent_id (parent_agent_id),
				KEY status (status)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'links' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				customer_id bigint(20) unsigned NOT NULL,
				agent_id bigint(20) unsigned NOT NULL,
				link_type varchar(20) NOT NULL DEFAULT 'manual',
				locked_at datetime DEFAULT NULL,
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY customer_id (customer_id),
				KEY agent_id (agent_id)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'rules' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(190) NOT NULL DEFAULT '',
				scope varchar(20) NOT NULL,
				target_id bigint(20) unsigned NOT NULL DEFAULT 0,
				target_key varchar(64) NOT NULL DEFAULT '',
				level tinyint(3) unsigned NOT NULL DEFAULT 1,
				type varchar(10) NOT NULL DEFAULT 'percent',
				rate decimal(12,4) NOT NULL DEFAULT 0,
				starts_at datetime DEFAULT NULL,
				ends_at datetime DEFAULT NULL,
				priority int(11) NOT NULL DEFAULT 0,
				active tinyint(1) unsigned NOT NULL DEFAULT 1,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY lookup (active,level,scope),
				KEY target (scope,target_id)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'commissions' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				order_id bigint(20) unsigned NOT NULL,
				item_id bigint(20) unsigned NOT NULL,
				agent_id bigint(20) unsigned NOT NULL,
				level tinyint(3) unsigned NOT NULL DEFAULT 1,
				entry_key varchar(40) NOT NULL DEFAULT '',
				parent_id bigint(20) unsigned DEFAULT NULL,
				customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				rule_id bigint(20) unsigned NOT NULL DEFAULT 0,
				rule_type varchar(10) NOT NULL DEFAULT '',
				rule_rate decimal(12,4) NOT NULL DEFAULT 0,
				basis decimal(19,4) NOT NULL DEFAULT 0,
				original_amount decimal(19,4) NOT NULL DEFAULT 0,
				amount decimal(19,4) NOT NULL DEFAULT 0,
				currency char(3) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'pending',
				earned_at datetime NOT NULL,
				approved_at datetime DEFAULT NULL,
				payout_id bigint(20) unsigned DEFAULT NULL,
				note varchar(255) NOT NULL DEFAULT '',
				void_reason varchar(20) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY order_line_agent_level (order_id,item_id,agent_id,level,entry_key),
				KEY status_earned (status,earned_at),
				KEY agent_status (agent_id,status),
				KEY payout_id (payout_id)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'payouts' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				agent_id bigint(20) unsigned NOT NULL,
				amount decimal(19,4) NOT NULL DEFAULT 0,
				currency char(3) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'requested',
				method varchar(40) NOT NULL DEFAULT '',
				reference varchar(190) NOT NULL DEFAULT '',
				details text DEFAULT NULL,
				note varchar(255) NOT NULL DEFAULT '',
				created_by bigint(20) unsigned NOT NULL DEFAULT 0,
				requested_at datetime NOT NULL,
				paid_at datetime DEFAULT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY agent_status (agent_id,status)
			) {$c};"
		);
		// 1 → 2: the void reason moves from the note into its own column.
		$wpdb->query( 'UPDATE ' . self::table( 'commissions' ) . " SET void_reason = note WHERE status = 'void' AND void_reason = '' AND note IN ('refund','cancel')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		update_option( self::OPTION, self::DB_VERSION, true );
	}
}
