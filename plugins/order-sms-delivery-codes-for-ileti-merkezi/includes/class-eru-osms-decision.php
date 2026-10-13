<?php
/**
 * Pure decision layer (no WordPress calls).
 *
 * A "delivery row" is the eru_osms_delivery table row as an array. A row whose result is
 * 'pending' is LOCKED: staff can move that order to its target status only with the code.
 *
 * @package order-sms-delivery-codes-for-ileti-merkezi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OSMS_Decision {

	/** Status slug without "wc-" / "mark_" prefix, lower case. */
	public static function status( $s ): string {
		$s = strtolower( trim( (string) $s ) );
		foreach ( array( 'mark_', 'wc-' ) as $prefix ) {
			if ( str_starts_with( $s, $prefix ) ) {
				$s = substr( $s, strlen( $prefix ) );
			}
		}
		return $s;
	}

	/**
	 * @param string[] $methods Shipping method ids of the order.
	 */
	public static function rule_matches( array $rule, $from, $to, array $methods ): bool {
		if ( empty( $rule['active'] ) ) {
			return false;
		}
		$r_from = self::status( $rule['from'] ?? '*' );
		if ( '*' !== $r_from && self::status( $from ) !== $r_from ) {
			return false;
		}
		if ( self::status( $rule['to'] ?? '' ) !== self::status( $to ) ) {
			return false;
		}
		$r_method = (string) ( $rule['method'] ?? '*' );
		return '*' === $r_method || in_array( $r_method, $methods, true );
	}

	public static function has_code( array $rule ): bool {
		return isset( $rule['text'] ) && str_contains( (string) $rule['text'], Eru_OSMS_Text::CODE_PLACEHOLDER );
	}

	public static function is_locked( $row ): bool {
		return is_array( $row ) && 'pending' === ( $row['result'] ?? '' );
	}

	/**
	 * A locked order cannot reach any "delivered" status without the code: the rule's
	 * verify target and every status in $delivered (default: completed).
	 *
	 * @param string[] $delivered
	 */
	public static function is_delivery_status( $row, $new, array $delivered ): bool {
		$new    = self::status( $new );
		$target = is_array( $row ) ? self::status( $row['target_status'] ?? '' ) : '';
		return ( '' !== $target && $target === $new ) || in_array( $new, array_map( array( __CLASS__, 'status' ), $delivered ), true );
	}

	/** Should a staff change of this order to $new be blocked? */
	public static function staff_blocked( $row, $new, array $delivered ): bool {
		return self::is_locked( $row ) && self::is_delivery_status( $row, $new, $delivered );
	}

	/**
	 * Who moved a locked order to a delivery status without the code.
	 * system context (cron / Action Scheduler / CLI) > cookie REST = user > REST without cookie = api > logged-in user > system.
	 *
	 * @param array $c rest, cookie_rest, system (bool), user_id (int)
	 */
	public static function codeless_source( array $c ): string {
		if ( ! empty( $c['system'] ) ) {
			return 'system';
		}
		if ( ! empty( $c['rest'] ) && empty( $c['cookie_rest'] ) ) {
			return 'api';
		}
		return (int) ( $c['user_id'] ?? 0 ) > 0 ? 'user' : 'system';
	}

	/** REST request made with a browser session (X-WP-Nonce)? API clients use keys and send no nonce. */
	public static function is_cookie_rest( $header_nonce, $param_nonce ): bool {
		return '' !== (string) $header_nonce || '' !== (string) $param_nonce;
	}

	/** Test mode is log-only: nothing is sent to any number while it is on. */
	public static function test_allows( bool $test_mode ): bool {
		return ! $test_mode;
	}

	/**
	 * İleti Merkezi response: sent | final (SMS not sent) | uncertain (may have been sent).
	 */
	public static function response_class( bool $connection_error, int $http, $json ): string {
		if ( $connection_error || $http >= 500 || ! is_array( $json ) ) {
			return 'uncertain';
		}
		$code = isset( $json['response']['status']['code'] ) ? (int) $json['response']['status']['code'] : 0;
		return 200 === $code ? 'sent' : 'final';
	}

	/**
	 * @param int $age Seconds since the code was created (-1 = unknown).
	 * @param int $ttl Code lifetime in seconds (0 = no limit).
	 * @return array{result:string,left:int} result: ok | wrong | now_locked | locked | no_code | expired | not_pending
	 */
	public static function verify( $row, bool $code_ok, int $max, int $age = -1, int $ttl = 0 ): array {
		if ( ! self::is_locked( $row ) ) {
			return array( 'result' => 'not_pending', 'left' => 0 );
		}
		$left = max( 0, $max - (int) $row['attempts'] );
		if ( ! empty( $row['locked'] ) ) {
			return array( 'result' => 'locked', 'left' => 0 );
		}
		if ( '' === (string) $row['code_hash'] ) {
			return array( 'result' => 'no_code', 'left' => $left );
		}
		if ( $ttl > 0 && $age > $ttl ) {
			return array( 'result' => 'expired', 'left' => $left );
		}
		if ( $code_ok ) {
			return array( 'result' => 'ok', 'left' => $left );
		}
		$left = max( 0, $left - 1 );
		return array( 'result' => 0 === $left ? 'now_locked' : 'wrong', 'left' => $left );
	}

	/**
	 * Order edit form POST asks for a locked status → write the current status back (other fields still save).
	 *
	 * @return array{post:array,blocked:bool}
	 */
	public static function fix_form_post( array $post, $row, $current, array $delivered ): array {
		if ( ! isset( $post['order_status'] ) || ! self::staff_blocked( $row, $post['order_status'], $delivered ) ) {
			return array( 'post' => $post, 'blocked' => false );
		}
		$post['order_status'] = 'wc-' . self::status( $current );
		return array( 'post' => $post, 'blocked' => true );
	}

	/** status= of an order-list action URL (wp_nonce_url encodes & as &amp;). */
	public static function action_url_status( $url ): string {
		$query = (string) parse_url( html_entity_decode( (string) $url, ENT_QUOTES ), PHP_URL_QUERY ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper without WordPress.
		$q     = array();
		parse_str( $query, $q );
		return isset( $q['status'] ) ? self::status( $q['status'] ) : '';
	}

	/** Bulk action mark_X → X, otherwise null. */
	public static function bulk_action_status( $action ): ?string {
		$action = (string) $action;
		return str_starts_with( $action, 'mark_' ) ? self::status( $action ) : null;
	}

	/**
	 * @return array{keep:int[],skipped:int[]}
	 */
	public static function split_bulk( array $ids, callable $blocked ): array {
		$keep    = array();
		$skipped = array();
		foreach ( $ids as $id ) {
			if ( $blocked( (int) $id ) ) {
				$skipped[] = (int) $id;
			} else {
				$keep[] = (int) $id;
			}
		}
		return array( 'keep' => $keep, 'skipped' => $skipped );
	}
}

