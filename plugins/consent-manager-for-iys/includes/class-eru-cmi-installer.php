<?php
/**
 * Database schema. Runs on activation and on plugins_loaded, but only when the stored schema version
 * differs (one autoloaded option read per request).
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tables (all times UTC):
 * - eru_cmi_consents: one row per (recipient, type); recipient is a lower-case e-mail or E.164 phone;
 *                     iys_known lists the environments where İYS holds a record (",sandbox,live,").
 * - eru_cmi_history:  append-only audit of status changes; IP and user agent as salted hashes, plus the
 *                     evidence of each change: verified (owner proven by a confirmation link), the hash of
 *                     the consent text shown (texts kept in option eru_cmi_texts) and an admin's note.
 * - eru_cmi_queue:    İYS-bound records and their state (queued|sent|accepted|rejected|skipped|failed),
 *                     per environment; batch_key is written before each POST (ambiguous-failure look-up).
 * - eru_cmi_log:      İYS API calls, redacted, bodies capped at 8 KB, kept 7 days.
 */
class Eru_CMI_Installer {

	const DB_VERSION = '5';
	const OPTION     = 'eru_cmi_db_version';

	/** @param string $name consents | history | queue | log. @return string Table name. */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'eru_cmi_' . $name;
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
			'CREATE TABLE ' . self::table( 'consents' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				recipient varchar(190) NOT NULL,
				recipient_hash char(64) NOT NULL,
				type varchar(10) NOT NULL,
				status varchar(4) NOT NULL,
				recipient_type varchar(10) NOT NULL DEFAULT 'BIREYSEL',
				source varchar(40) NOT NULL DEFAULT '',
				consent_date datetime NOT NULL,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				iys_status varchar(10) NOT NULL DEFAULT '',
				iys_synced_at datetime DEFAULT NULL,
				iys_known varchar(20) NOT NULL DEFAULT '',
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY recipient_channel (recipient,type),
				KEY recipient_hash (recipient_hash),
				KEY user_id (user_id),
				KEY status (status)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'history' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				consent_id bigint(20) unsigned NOT NULL,
				previous_status varchar(4) NOT NULL DEFAULT '',
				new_status varchar(4) NOT NULL,
				actor varchar(10) NOT NULL,
				actor_id bigint(20) unsigned NOT NULL DEFAULT 0,
				source varchar(40) NOT NULL DEFAULT '',
				ip_hash varchar(64) NOT NULL DEFAULT '',
				ua_hash varchar(64) NOT NULL DEFAULT '',
				verified tinyint(1) unsigned NOT NULL DEFAULT 0,
				text_hash varchar(64) NOT NULL DEFAULT '',
				note varchar(255) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY consent_id (consent_id),
				KEY created_at (created_at)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'queue' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				consent_id bigint(20) unsigned NOT NULL,
				env varchar(10) NOT NULL DEFAULT '',
				batch_key varchar(40) DEFAULT NULL,
				payload text NOT NULL,
				status varchar(10) NOT NULL DEFAULT 'queued',
				request_id varchar(100) DEFAULT NULL,
				sub_index int(11) DEFAULT NULL,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				requeued tinyint(1) unsigned NOT NULL DEFAULT 0,
				next_try_at datetime DEFAULT NULL,
				sent_at datetime DEFAULT NULL,
				last_error text DEFAULT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY env_due (env,status,next_try_at),
				KEY request_id (request_id),
				KEY consent_id (consent_id)
			) {$c};"
		);
		dbDelta(
			'CREATE TABLE ' . self::table( 'log' ) . " (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				env varchar(10) NOT NULL DEFAULT '',
				method varchar(8) NOT NULL DEFAULT '',
				endpoint varchar(255) NOT NULL DEFAULT '',
				http_code smallint(5) unsigned NOT NULL DEFAULT 0,
				duration_ms int(10) unsigned NOT NULL DEFAULT 0,
				request text DEFAULT NULL,
				response text DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY created_at (created_at)
			) {$c};"
		);
		// 1 → 2: rows written before queue rows carried an environment belong to the selected one.
		$env = Eru_CMI_Settings::env();
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'queue' ) . " SET env = %s WHERE env = ''", $env ) ); // phpcs:ignore
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table( 'consents' ) . " SET iys_known = %s WHERE iys_known = '' AND iys_synced_at IS NOT NULL", ',' . $env . ',' ) ); // phpcs:ignore
		// 2 → 3: the autoloaded on/off flag must exist (a missing option costs a query on every request).
		if ( null === get_option( Eru_CMI_Settings::ACTIVE_FLAG, null ) ) {
			add_option( Eru_CMI_Settings::ACTIVE_FLAG, '0', '', true );
		}
		// 3 → 4: the autoloaded capture switches (all off) must exist for the same reason.
		if ( null === get_option( Eru_CMI_Settings::FRONT_FLAG, null ) ) {
			add_option( Eru_CMI_Settings::FRONT_FLAG, Eru_CMI_Settings::front(), '', true );
		}
		Eru_CMI_Settings::sync_flag();
		update_option( self::OPTION, self::DB_VERSION, true );
	}
}
