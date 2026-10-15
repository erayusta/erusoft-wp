<?php
/**
 * Match/approval log table (30-day default retention) plus the masking helpers shared by notes and mail.
 *
 * @package bank-transfer-auto-approval-for-garanti-bbva
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transaction descriptions can carry payer names, so the log is personal data: IBANs and long numbers are
 * masked before a row is written, rows are tied to an order for the privacy tools, and old rows are purged
 * in batches. Repeating rows (mismatch, review, dry-run) carry a dedupe key, unique per day.
 */
class Eru_GHA_Log {

	const DB_VERSION = '1';
	const BATCH      = 1000;

	/** @return string Log table. */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'eru_gha_log';
	}

	/** Creates or migrates the log and ledger tables when the stored version differs. */
	public static function maybe_install() {
		if ( get_option( 'eru_gha_db_version' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$log     = self::table();
		$used    = Eru_GHA_Ledger::table();
		dbDelta(
			"CREATE TABLE {$log} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				level varchar(10) NOT NULL DEFAULT 'info',
				type varchar(30) NOT NULL DEFAULT '',
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				txn_ids text DEFAULT NULL,
				amount decimal(18,2) DEFAULT NULL,
				message text NOT NULL,
				context longtext DEFAULT NULL,
				dedupe char(32) DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY dedupe (dedupe),
				KEY order_id (order_id),
				KEY type (type),
				KEY created_at (created_at)
			) {$charset};
			CREATE TABLE {$used} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				txn_id varchar(100) NOT NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				amount decimal(18,2) NOT NULL DEFAULT 0,
				used_at datetime NOT NULL,
				source varchar(10) NOT NULL DEFAULT 'auto',
				active tinyint(1) DEFAULT 1,
				released_at datetime DEFAULT NULL,
				released_by bigint(20) unsigned NOT NULL DEFAULT 0,
				release_note text DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY txn_active (txn_id,active),
				KEY order_id (order_id)
			) {$charset};"
		);
		update_option( 'eru_gha_db_version', self::DB_VERSION, true );
	}

	/**
	 * Masks IBANs (last 4 kept), the configured account number, long digit runs (≥ 11, last 4 kept) and
	 * credential-looking pairs.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function mask( $text ) {
		$text = (string) $text;
		foreach ( array( Eru_GHA_Settings::get( 'client_secret' ), Eru_GHA_Settings::get( 'client_id' ) ) as $s ) {
			if ( strlen( (string) $s ) >= 4 ) {
				$text = str_replace( (string) $s, '[gizli]', $text );
			}
		}
		$text = (string) preg_replace_callback(
			'/\bTR\s?\d{2}(?:\s?\d){22}(?!\d)/i',
			fn( $m ) => self::mask_iban( $m[0] ),
			$text
		);
		$acc = (string) Eru_GHA_Settings::get( 'account_number' );
		if ( strlen( $acc ) >= 4 ) {
			$text = str_replace( $acc, self::mask_number( $acc ), $text );
		}
		$text = (string) preg_replace_callback( '/(?<!\d)\d{11,}(?!\d)/', fn( $m ) => self::mask_number( $m[0] ), $text );
		$text = (string) preg_replace( '/\bBearer\s+[A-Za-z0-9._~+\/=\-]+/', 'Bearer [gizli]', $text );
		return (string) preg_replace( '/("?(?:password|secret|token|api_key|authorization|access_token|client_secret)"?\s*(?:=|:)\s*"?)[^&\s",}<]+/i', '$1[gizli]', $text );
	}

	/**
	 * Upper-case ASCII fold for Turkish names, one output character per input character.
	 *
	 * @param string $s Text.
	 * @return string
	 */
	public static function fold( $s ) {
		$s = str_replace( array( 'ı', 'i', 'ş', 'ğ', 'ü', 'ö', 'ç', 'İ', 'Ş', 'Ğ', 'Ü', 'Ö', 'Ç' ), array( 'I', 'I', 'S', 'G', 'U', 'O', 'C', 'I', 'S', 'G', 'U', 'O', 'C' ), (string) $s );
		return mb_strtoupper( $s );
	}

	/**
	 * Replaces every word of the sender's name in a description with its initial ("DEMO MUSTERI" → "D. M.").
	 *
	 * @param string $text Description.
	 * @param string $name Sender name.
	 * @return string
	 */
	public static function strip_name( $text, $name ) {
		$chars = mb_str_split( (string) $text );
		$fold  = array_map( fn( $c ) => mb_substr( self::fold( $c ), 0, 1 ), $chars );
		$n     = count( $chars );
		$word  = fn( $c ) => (bool) preg_match( '/[\p{L}\p{N}]/u', (string) $c );
		foreach ( preg_split( '/\s+/u', trim( (string) $name ) ) as $w ) {
			if ( mb_strlen( $w ) < 2 ) {
				continue;
			}
			$fw  = array_map( fn( $c ) => mb_substr( self::fold( $c ), 0, 1 ), mb_str_split( $w ) );
			$len = count( $fw );
			$ini = mb_strtoupper( mb_substr( $w, 0, 1 ) ) . '.';
			for ( $i = 0; $i + $len <= $n; $i++ ) {
				if ( array_slice( $fold, $i, $len ) !== $fw || ( $i > 0 && $word( $chars[ $i - 1 ] ) ) || ( $i + $len < $n && $word( $chars[ $i + $len ] ) ) ) {
					continue;
				}
				$chars[ $i ] = $ini;
				$fold[ $i ]  = "\0";
				for ( $k = 1; $k < $len; $k++ ) {
					$chars[ $i + $k ] = '';
					$fold[ $i + $k ]  = "\0";
				}
			}
		}
		return implode( '', $chars );
	}

	/** @param string $iban IBAN. @return string "TR** … 1234". */
	public static function mask_iban( $iban ) {
		$iban = strtoupper( preg_replace( '/\s+/', '', (string) $iban ) );
		if ( '' === $iban ) {
			return '';
		}
		return substr( $iban, 0, 2 ) . '** … ' . substr( $iban, -4 );
	}

	/** @param string $n Number. @return string "••••1234". */
	public static function mask_number( $n ) {
		$n = (string) $n;
		return strlen( $n ) <= 4 ? '••••' : '••••' . substr( $n, -4 );
	}

	/** @param string $name Name. @return string Initials ("D. M."). */
	public static function initials( $name ) {
		$out = array();
		foreach ( preg_split( '/\s+/u', trim( (string) $name ) ) as $w ) {
			if ( '' !== $w ) {
				$out[] = mb_strtoupper( mb_substr( $w, 0, 1 ) ) . '.';
			}
		}
		return implode( ' ', array_slice( $out, 0, 4 ) );
	}

	/** @return array<string,string> Type labels. */
	public static function types() {
		return array(
			'approved'      => __( 'Onaylandı', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'would_approve' => __( 'Eşleşirdi (deneme)', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'review'        => __( 'İnceleme gerekli', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'mismatch'      => __( 'Tutar uyuşmadı', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'skipped'       => __( 'Atlandı', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'fetch_error'   => __( 'Banka hatası', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'manual'        => __( 'Elle onay', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'release'       => __( 'Serbest bırakıldı', 'bank-transfer-auto-approval-for-garanti-bbva' ),
		);
	}

	/**
	 * Writes a row. With $dedupe, the same (type, order, transactions) is written at most once per day.
	 *
	 * @param string $level    info|success|warn|error.
	 * @param string $type     Type key.
	 * @param int    $order_id Order id (0 = none).
	 * @param array  $txn_ids  Transaction ids.
	 * @param string $message  Message (masked).
	 * @param array  $context  Context (masked JSON).
	 * @param bool   $dedupe   Once per day.
	 * @param float  $amount   Amount.
	 * @return bool Written (false: duplicate of today's row).
	 */
	public static function add( $level, $type, $order_id, array $txn_ids, $message, array $context = array(), $dedupe = false, $amount = null ) {
		global $wpdb;
		sort( $txn_ids );
		$key = $dedupe ? md5( $type . '|' . (int) $order_id . '|' . implode( ',', $txn_ids ) . '|' . gmdate( 'Y-m-d' ) ) : null;
		$row = array(
			'created_at' => current_time( 'mysql', true ),
			'level'      => in_array( $level, array( 'info', 'success', 'warn', 'error' ), true ) ? $level : 'info',
			'type'       => substr( sanitize_key( $type ), 0, 30 ),
			'order_id'   => absint( $order_id ),
			'txn_ids'    => $txn_ids ? substr( implode( ',', array_map( 'strval', $txn_ids ) ), 0, 2000 ) : null,
			'amount'     => null === $amount ? null : round( (float) $amount, 2 ),
			'message'    => self::mask( (string) $message ),
			'context'    => $context ? self::mask( (string) wp_json_encode( $context, JSON_UNESCAPED_UNICODE ) ) : null,
			'dedupe'     => $key,
		);
		if ( $key ) {
			$cols = implode( ', ', array_keys( $row ) );
			$vals = array();
			foreach ( $row as $v ) {
				$vals[] = null === $v ? 'NULL' : $wpdb->prepare( is_float( $v ) ? '%f' : ( is_int( $v ) ? '%d' : '%s' ), $v );
			}
			return 1 === (int) $wpdb->query( 'INSERT IGNORE INTO ' . self::table() . " ({$cols}) VALUES (" . implode( ', ', $vals ) . ')' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		}
		return (bool) $wpdb->insert( self::table(), $row ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Paged list for the Eşleşmeler tab.
	 *
	 * @param string $type Filter ('' = all).
	 * @param int    $page Page (1-based).
	 * @param int    $per  Per page.
	 * @return array{rows:array,total:int}
	 */
	public static function page( $type, $page, $per = 50 ) {
		global $wpdb;
		$t     = self::table();
		$where = '' !== $type ? $wpdb->prepare( 'WHERE type = %s', $type ) : '';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} {$where}" ); // phpcs:ignore
		$rows  = (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", absint( $per ), max( 0, ( absint( $page ) - 1 ) * absint( $per ) ) ), ARRAY_A ); // phpcs:ignore
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	/** @return int Review rows in the last 24 hours. */
	public static function recent_reviews() {
		global $wpdb;
		$t = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT order_id) FROM {$t} WHERE type = 'review' AND created_at > %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ); // phpcs:ignore
	}

	/** @return bool The table has rows (keeps the daily purge scheduled). */
	public static function has_rows() {
		global $wpdb;
		$t = self::table();
		return (bool) $wpdb->get_var( "SELECT 1 FROM {$t} LIMIT 1" ); // phpcs:ignore
	}

	/** Retention purge in batches. */
	public static function purge() {
		global $wpdb;
		$t   = self::table();
		$cut = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) Eru_GHA_Settings::get( 'log_days' ) ) * DAY_IN_SECONDS );
		for ( $i = 0; $i < 50; $i++ ) {
			$n = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE created_at < %s LIMIT %d", $cut, self::BATCH ) ); // phpcs:ignore
			if ( $n < self::BATCH ) {
				break;
			}
		}
	}
}
