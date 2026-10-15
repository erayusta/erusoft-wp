<?php
/**
 * Log table: write (redacted), filtered list, stats, retention purge in batches.
 *
 * @package dopigo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rows never contain credentials, e-mail addresses or phone-like numbers: everything is redacted before insert.
 */
class Eru_DPG_Logger {

	const DB_VERSION = '1';

	/** @return string Table name. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'eru_dpg_log';
	}

	/** @return array<string,string> Channels. */
	public static function channels() {
		return array(
			'auth'    => __( 'Kimlik', 'dopigo-integration-for-woocommerce' ),
			'product' => __( 'Stok/fiyat', 'dopigo-integration-for-woocommerce' ),
			'import'  => __( 'İçe aktarma', 'dopigo-integration-for-woocommerce' ),
			'order'   => __( 'Sipariş', 'dopigo-integration-for-woocommerce' ),
			'status'  => __( 'Sipariş durumu', 'dopigo-integration-for-woocommerce' ),
			'tools'   => __( 'Araçlar', 'dopigo-integration-for-woocommerce' ),
			'cron'    => __( 'Zamanlama', 'dopigo-integration-for-woocommerce' ),
		);
	}

	/** @return array<string,string> Levels. */
	public static function levels() {
		return array(
			'success' => __( 'Başarılı', 'dopigo-integration-for-woocommerce' ),
			'failed'  => __( 'Hata', 'dopigo-integration-for-woocommerce' ),
			'warning' => __( 'Uyarı', 'dopigo-integration-for-woocommerce' ),
			'skipped' => __( 'Atlandı', 'dopigo-integration-for-woocommerce' ),
			'info'    => __( 'Bilgi', 'dopigo-integration-for-woocommerce' ),
		);
	}

	/** Creates or migrates the table when the stored version differs. */
	public static function maybe_install() {
		if ( get_option( 'eru_dpg_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				level varchar(20) NOT NULL DEFAULT 'info',
				channel varchar(20) NOT NULL,
				direction varchar(20) NOT NULL DEFAULT '',
				ref varchar(190) NOT NULL DEFAULT '',
				message text NOT NULL,
				context longtext DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY channel (channel),
				KEY level (level),
				KEY ref (ref),
				KEY created_at (created_at)
			) {$charset};"
		);
		update_option( 'eru_dpg_db_version', self::DB_VERSION, true );
	}

	/**
	 * Removes credentials and personal data from text that is stored or shown.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function redact( $text ) {
		$text    = (string) $text;
		$secrets = array( (string) Eru_DPG_Settings::get( 'password' ), (string) Eru_DPG_Settings::get( 'token' ) );
		$cached  = get_option( 'eru_dpg_token' );
		if ( is_array( $cached ) && ! empty( $cached['token'] ) ) {
			$secrets[] = (string) $cached['token'];
		}
		foreach ( $secrets as $s ) {
			if ( strlen( $s ) >= 4 ) {
				$text = str_replace( $s, '[gizli]', $text );
			}
		}
		$text = preg_replace( '/\b(Token|Bearer)\s+[A-Za-z0-9._\-]+/', '$1 [gizli]', $text );
		$text = preg_replace( '/("?(?:password|secret|token|api_key|authorization)"?\s*(?:=|:)\s*"?)[^&\s",}]+/i', '$1[gizli]', $text );
		$text = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[e-posta]', $text );
		return (string) preg_replace_callback(
			'/\+?\(?\d[\d\s().\-]{8,}\d/',
			fn( $m ) => strlen( preg_replace( '/\D/', '', $m[0] ) ) >= 10 ? '[numara]' : $m[0],
			$text
		);
	}

	/**
	 * Writes a row.
	 *
	 * @param string $channel   Channel.
	 * @param string $level     success|failed|warning|skipped|info.
	 * @param string $message   Message.
	 * @param mixed  $context   Context (array, JSON-encoded).
	 * @param string $direction wc_to_dp | dp_to_wc | ''.
	 * @param string $ref       Reference (order id, SKU).
	 */
	public static function log( $channel, $level, $message, $context = null, $direction = '', $ref = '' ) {
		global $wpdb;
		if ( is_array( $context ) ) {
			foreach ( array( 'password', 'token', 'authorization', 'Authorization' ) as $k ) {
				unset( $context[ $k ] );
			}
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			self::table(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'level'      => substr( sanitize_key( $level ), 0, 20 ),
				'channel'    => substr( sanitize_key( $channel ), 0, 20 ),
				'direction'  => substr( sanitize_key( (string) $direction ), 0, 20 ),
				'ref'        => substr( self::redact( (string) $ref ), 0, 190 ),
				'message'    => self::redact( mb_substr( (string) $message, 0, 2000 ) ),
				'context'    => null === $context ? null : self::redact( (string) wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ),
			)
		);
	}

	/** @param array $f Filters. @return array [sql, args] */
	private static function where( array $f ) {
		global $wpdb;
		$w = array( '1=1' );
		$a = array();
		if ( ! empty( $f['channel'] ) ) {
			$w[] = 'channel = %s';
			$a[] = $f['channel'];
		}
		if ( ! empty( $f['level'] ) ) {
			$w[] = 'level = %s';
			$a[] = $f['level'];
		}
		if ( ! empty( $f['s'] ) ) {
			$like = '%' . $wpdb->esc_like( $f['s'] ) . '%';
			$w[]  = '(ref LIKE %s OR message LIKE %s)';
			$a[]  = $like;
			$a[]  = $like;
		}
		return array( implode( ' AND ', $w ), $a );
	}

	/** @param array $f Filters. @param int $limit Limit. @param int $offset Offset. @return array Rows. */
	public static function rows( array $f = array(), $limit = 50, $offset = 0 ) {
		global $wpdb;
		list( $w, $a ) = self::where( $f );
		$a[] = max( 1, min( 500, (int) $limit ) );
		$a[] = max( 0, (int) $offset );
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE {$w} ORDER BY id DESC LIMIT %d OFFSET %d", $a ), ARRAY_A ) ?: array(); // phpcs:ignore
	}

	/** @param array $f Filters. @return int */
	public static function count( array $f = array() ) {
		global $wpdb;
		list( $w, $a ) = self::where( $f );
		$sql = 'SELECT COUNT(*) FROM ' . self::table() . " WHERE {$w}"; // phpcs:ignore WordPress.DB.PreparedSQL
		return (int) ( $a ? $wpdb->get_var( $wpdb->prepare( $sql, $a ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore
	}

	/** @param int $hours Window. @return array<string,int> Count per level. */
	public static function stats( $hours = 24 ) {
		global $wpdb;
		$out = array_fill_keys( array_keys( self::levels() ), 0 );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT level, COUNT(*) AS c FROM ' . self::table() . ' WHERE created_at >= %s GROUP BY level', gmdate( 'Y-m-d H:i:s', time() - $hours * HOUR_IN_SECONDS ) ), ARRAY_A ) ?: array(); // phpcs:ignore
		foreach ( $rows as $r ) {
			$out[ $r['level'] ] = (int) $r['c'];
		}
		return $out;
	}

	/** @return array<string,string> "channel|direction" => last success (GMT). */
	public static function last_success() {
		global $wpdb;
		$out = array();
		foreach ( $wpdb->get_results( 'SELECT channel, direction, MAX(created_at) AS t FROM ' . self::table() . " WHERE level = 'success' GROUP BY channel, direction", ARRAY_A ) ?: array() as $r ) { // phpcs:ignore
			$out[ $r['channel'] . '|' . $r['direction'] ] = $r['t'];
		}
		return $out;
	}

	/** Deletes rows older than the retention period, at most $batches × 1000 per call. @return int Deleted. */
	public static function purge( $batches = 20 ) {
		global $wpdb;
		$days = max( 1, (int) Eru_DPG_Settings::get( 'log_retention' ) );
		$cut  = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$n    = 0;
		for ( $i = 0; $i < $batches; $i++ ) {
			$d = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s ORDER BY id ASC LIMIT 1000', $cut ) ); // phpcs:ignore
			$n += $d;
			if ( $d < 1000 ) {
				break;
			}
		}
		return $n;
	}

	/** @return bool The table has rows. */
	public static function has_rows() {
		global $wpdb;
		return (bool) $wpdb->get_var( 'SELECT 1 FROM ' . self::table() . ' LIMIT 1' ); // phpcs:ignore
	}
}
