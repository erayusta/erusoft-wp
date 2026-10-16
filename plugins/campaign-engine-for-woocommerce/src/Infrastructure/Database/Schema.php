<?php
/**
 * Table names and definitions, created and migrated through dbDelta on a version check.
 *
 * Tables (all `{prefix}eru_ce_*`):
 *  - campaigns         compiled campaign rows, written only by CampaignWriter from the `eru_ce_campaign` posts;
 *  - targets           the include/exclude lists of each compiled campaign, for lookups and cache purges;
 *  - usage             one row per campaign and checkout (token): reserved → consumed / released;
 *  - rewards           spend-and-earn rewards: pending → available → used / expired / reversed;
 *  - audit_log         money and status changes worth explaining later;
 *  - customer_metrics  order count and spend per customer, fed by paid orders and refunds.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Database;

final class Schema {

	public const VERSION        = '2';
	public const VERSION_OPTION = 'eru_ce_db_version';

	private const NAMES = array(
		'campaigns'        => 'eru_ce_campaigns',
		'targets'          => 'eru_ce_targets',
		'usage'            => 'eru_ce_usage',
		'rewards'          => 'eru_ce_rewards',
		'audit_log'        => 'eru_ce_audit_log',
		'customer_metrics' => 'eru_ce_customer_metrics',
	);

	/** Business and audit records: uninstall keeps them unless ERU_CE_REMOVE_DATA is true. */
	public const KEPT_ON_UNINSTALL = array( 'campaigns', 'targets', 'usage', 'rewards', 'audit_log' );

	public static function table( string $name ): string {
		global $wpdb;

		if ( ! isset( self::NAMES[ $name ] ) ) {
			throw new \InvalidArgumentException( 'Unknown Campaign Engine table: ' . $name );
		}

		return $wpdb->prefix . self::NAMES[ $name ];
	}

	/** @return array<string,string> logical name => full table name */
	public static function tables(): array {
		$out = array();

		foreach ( array_keys( self::NAMES ) as $name ) {
			$out[ $name ] = self::table( $name );
		}

		return $out;
	}

	/** Creates or migrates the tables when the stored version differs. Cheap when up to date. */
	public static function maybeUpgrade(): void {
		if ( self::VERSION !== get_option( self::VERSION_OPTION ) ) {
			self::install();
		}
	}

	public static function install(): void {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		foreach ( self::statements() as $sql ) {
			dbDelta( $sql );
		}

		// Autoloaded: the version check runs on every request and must not cost a query.
		update_option( self::VERSION_OPTION, self::VERSION, true );
		wp_set_option_autoload( self::VERSION_OPTION, true );
		CampaignRepository::bump();
		self::restartBookkeeping();
	}

	/**
	 * After an uninstall that kept the data (no ERU_CE_REMOVE_DATA) and a reinstall, campaigns, usage
	 * and rewards are back but the bookkeeping start date is gone: restart it, so orders are followed
	 * again (consume / release, reward reversal on refunds) without waiting for a campaign save.
	 */
	private static function restartBookkeeping(): void {
		global $wpdb;

		if ( MetricsRepository::since() > 0 ) {
			return;
		}

		foreach ( array( 'campaigns', 'usage', 'rewards' ) as $name ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- fixed table name.
			if ( null !== $wpdb->get_var( 'SELECT 1 FROM ' . self::table( $name ) . ' LIMIT 1' ) ) {
				MetricsRepository::start();

				return;
			}
		}
	}

	/** @return string[] */
	public static function statements(): array {
		global $wpdb;

		$collate = $wpdb->get_charset_collate();
		$t       = self::tables();

		return array(
			"CREATE TABLE {$t['campaigns']} (
				campaign_id bigint(20) unsigned NOT NULL,
				status varchar(20) NOT NULL DEFAULT 'inactive',
				priority int(11) NOT NULL DEFAULT 10,
				exclusive tinyint(1) NOT NULL DEFAULT 0,
				starts_ts bigint(20) NULL DEFAULT NULL,
				ends_ts bigint(20) NULL DEFAULT NULL,
				usage_limit int(10) unsigned NULL DEFAULT NULL,
				usage_limit_per_customer int(10) unsigned NULL DEFAULT NULL,
				version int(10) unsigned NOT NULL DEFAULT 1,
				definition longtext NULL,
				updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
				PRIMARY KEY  (campaign_id),
				KEY status_end (status,ends_ts)
			) {$collate};",

			"CREATE TABLE {$t['targets']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL,
				side varchar(10) NOT NULL DEFAULT 'include',
				target_type varchar(20) NOT NULL DEFAULT '',
				target_id bigint(20) unsigned NOT NULL DEFAULT 0,
				target_key varchar(100) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY campaign (campaign_id),
				KEY lookup (target_type,target_id)
			) {$collate};",

			"CREATE TABLE {$t['usage']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL,
				token char(32) NOT NULL DEFAULT '',
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email_hash char(64) NOT NULL DEFAULT '',
				status varchar(12) NOT NULL DEFAULT 'reserved',
				discount_minor bigint(20) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
				updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
				checked_at datetime NULL DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY campaign_token (campaign_id,token),
				KEY campaign_status (campaign_id,status),
				KEY order_id (order_id),
				KEY token (token),
				KEY status_created (status,created_at)
			) {$collate};",

			"CREATE TABLE {$t['rewards']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				campaign_id bigint(20) unsigned NOT NULL,
				campaign_version int(10) unsigned NOT NULL DEFAULT 1,
				source_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email varchar(190) NOT NULL DEFAULT '',
				currency char(3) NOT NULL DEFAULT '',
				original_minor bigint(20) NOT NULL DEFAULT 0,
				amount_minor bigint(20) NOT NULL DEFAULT 0,
				min_cart_minor bigint(20) NOT NULL DEFAULT 0,
				valid_days int(10) unsigned NOT NULL DEFAULT 0,
				category_ids text NULL,
				status varchar(12) NOT NULL DEFAULT 'pending',
				coupon_id bigint(20) unsigned NOT NULL DEFAULT 0,
				code varchar(60) NOT NULL DEFAULT '',
				available_at datetime NULL DEFAULT NULL,
				expires_at datetime NULL DEFAULT NULL,
				used_order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
				updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY campaign_order (campaign_id,source_order_id),
				KEY status_available (status,available_at),
				KEY status_expires (status,expires_at),
				KEY email (email),
				KEY customer (customer_id),
				KEY code (code)
			) {$collate};",

			"CREATE TABLE {$t['audit_log']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				object_type varchar(30) NOT NULL DEFAULT '',
				object_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(40) NOT NULL DEFAULT '',
				data longtext NULL,
				created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
				PRIMARY KEY  (id),
				KEY object (object_type,object_id),
				KEY created (created_at)
			) {$collate};",

			"CREATE TABLE {$t['customer_metrics']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				customer_key varchar(70) NOT NULL DEFAULT '',
				customer_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email_hash char(64) NOT NULL DEFAULT '',
				order_count int(11) NOT NULL DEFAULT 0,
				total_spent_minor bigint(20) NOT NULL DEFAULT 0,
				first_order_at datetime NULL DEFAULT NULL,
				last_order_at datetime NULL DEFAULT NULL,
				updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY customer_key (customer_key),
				KEY email_hash (email_hash),
				KEY customer_id (customer_id)
			) {$collate};",
		);
	}
}
