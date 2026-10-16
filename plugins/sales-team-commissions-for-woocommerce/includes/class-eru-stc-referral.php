<?php
/**
 * Customer attribution. A visit with ?ref=<code> of an active agent stores the code in a first-party
 * cookie (no PHP session). On WooCommerce registration and at checkout the customer gets an agent by the
 * assignment mode:
 *   referral     the cookie's agent; a later code moves an unlocked referral link (last touch);
 *                manual and round-robin links never follow a cookie;
 *   first_touch  the cookie's agent, and the link is locked (later codes are ignored, the cookie too);
 *   round_robin  the cookie's agent for a customer without one, otherwise the next active agent in turn;
 *   manual       nothing automatic: no cookie, no link.
 * The checkout stamps the order with its agent (_eru_stc_agent_id); guest orders are attributed from the
 * cookie only.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Referral {

	const COOKIE  = 'eru_stc_ref';
	const POINTER = 'eru_stc_rr_pointer';

	/** Wires capture, registration and checkout. Each handler checks the master switch. */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'capture' ), 20 );
		add_action( 'woocommerce_created_customer', array( __CLASS__, 'on_customer_created' ), 20, 1 );
		add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'attach_to_order' ), 20, 1 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'attach_to_order' ), 20, 1 );
	}

	/** @return string Query parameter carrying the code. */
	public static function param() {
		/**
		 * Query parameter of referral links.
		 *
		 * @param string $param Default "ref".
		 */
		return sanitize_key( (string) apply_filters( 'eru_stc_referral_param', 'ref' ) );
	}

	/** Stores a valid ?ref= code in the cookie (front-end requests, module on, mode not manual). */
	public static function capture() {
		$param = self::param();
		if ( '' === $param || empty( $_GET[ $param ] ) || ! Eru_STC_Settings::enabled() || 'manual' === Eru_STC_Settings::get( 'assignment_mode' ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		if ( ( is_admin() && ! wp_doing_ajax() ) || wp_doing_cron() ) {
			return;
		}
		$code  = Eru_STC_Agents::sanitize_code( wp_unslash( (string) $_GET[ $param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$agent = '' === $code ? null : Eru_STC_Agents::get_by_code( $code );
		if ( ! Eru_STC_Agents::is_active( $agent ) ) {
			return;
		}
		if ( 'first_touch' === Eru_STC_Settings::get( 'assignment_mode' ) && self::cookie_agent() ) {
			return; // The first valid code stays.
		}
		self::set_cookie( $agent->referral_code );
	}

	/** @param string $code Referral code. Sets the cookie and mirrors it for the current request. */
	public static function set_cookie( $code ) {
		$_COOKIE[ self::COOKIE ] = $code;
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$code,
			array(
				'expires'  => time() + (int) Eru_STC_Settings::get( 'cookie_days' ) * DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
	}

	/** @return object|null The active agent of the cookie's code. */
	public static function cookie_agent() {
		if ( empty( $_COOKIE[ self::COOKIE ] ) ) {
			return null;
		}
		$agent = Eru_STC_Agents::get_by_code( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised by get_by_code().
		return Eru_STC_Agents::is_active( $agent ) ? $agent : null;
	}

	/**
	 * Gives a customer an agent on registration (WooCommerce registration: checkout or My Account).
	 *
	 * @param int $customer_id New customer.
	 * @return int Agent id linked (0 = none).
	 */
	public static function on_customer_created( $customer_id ) {
		$mode = Eru_STC_Settings::get( 'assignment_mode' );
		if ( ! Eru_STC_Settings::enabled() || 'manual' === $mode || Eru_STC_Links::get( $customer_id ) ) {
			return 0;
		}
		$agent = self::cookie_agent();
		if ( $agent ) {
			$ok = Eru_STC_Links::link( $customer_id, (int) $agent->id, 'referral', array( 'lock' => 'first_touch' === $mode, 'created_by' => 0 ) );
			return true === $ok ? (int) $agent->id : 0;
		}
		return 'round_robin' === $mode ? self::assign_round_robin( $customer_id ) : 0;
	}

	/**
	 * Stamps a new checkout order with its agent (and links a logged-in customer per mode).
	 *
	 * @param WC_Order $order New order.
	 */
	public static function attach_to_order( $order ) {
		if ( ! $order instanceof WC_Order || ! Eru_STC_Settings::enabled() || (int) $order->get_meta( '_eru_stc_agent_id' ) > 0 ) {
			return;
		}
		$mode     = Eru_STC_Settings::get( 'assignment_mode' );
		$customer = (int) $order->get_customer_id();
		$link     = $customer > 0 ? Eru_STC_Links::get( $customer ) : null;
		$cookie   = 'manual' === $mode ? null : self::cookie_agent();
		$agent    = 0;
		$source   = '';
		if ( $cookie && Eru_STC_Calculator::is_own_order( $cookie, $customer, strtolower( trim( (string) $order->get_billing_email() ) ) ) ) {
			$cookie = null; // Own code (account or, for a guest, the billing e-mail): never self-attributed.
		}
		if ( $link ) {
			$agent  = (int) $link->agent_id;
			$source = 'link';
			// Only an unlocked referral link follows a newer code (last touch). Links set by an admin (manual)
			// or by round-robin are sticky, and a first-touch lock never moves.
			if ( $cookie && 'referral' === $mode && 'referral' === $link->link_type && null === $link->locked_at && (int) $cookie->id !== $agent && true === Eru_STC_Links::link( $customer, (int) $cookie->id, 'referral', array( 'created_by' => 0 ) ) ) {
				$agent  = (int) $cookie->id;
				$source = 'referral';
			}
		} elseif ( $cookie ) {
			$agent  = (int) $cookie->id;
			$source = 'referral';
			if ( $customer > 0 ) {
				Eru_STC_Links::link( $customer, $agent, 'referral', array( 'lock' => 'first_touch' === $mode, 'created_by' => 0 ) );
			}
		} elseif ( 'round_robin' === $mode && $customer > 0 ) {
			$agent  = self::assign_round_robin( $customer );
			$source = 'round_robin';
		}
		if ( $agent > 0 ) {
			$order->update_meta_data( '_eru_stc_agent_id', $agent );
			$order->update_meta_data( '_eru_stc_source', $source );
			$order->save();
		}
	}

	/**
	 * Links a customer to the next active agent in turn.
	 *
	 * @param int $customer_id Customer.
	 * @return int Agent id (0 = none available or link refused).
	 */
	public static function assign_round_robin( $customer_id ) {
		$agent = self::next_round_robin();
		if ( $agent <= 0 ) {
			return 0;
		}
		return true === Eru_STC_Links::link( $customer_id, $agent, 'round_robin', array( 'created_by' => 0 ) ) ? $agent : 0;
	}

	/**
	 * The next agent after the stored pointer (ascending id, wrapping). The pointer moves with a
	 * compare-and-swap on its option row, so two registrations at once never get the same turn.
	 *
	 * @return int Agent id, 0 when no active agent.
	 */
	public static function next_round_robin() {
		global $wpdb;
		/**
		 * Agents taking part in round-robin (active agents, ascending id).
		 *
		 * @param int[] $ids Agent ids.
		 */
		$pool = array_values( array_map( 'intval', (array) apply_filters( 'eru_stc_round_robin_agents', Eru_STC_Agents::active_ids() ) ) );
		sort( $pool );
		if ( ! $pool ) {
			return 0;
		}
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '0', 'no')", self::POINTER ) );
		for ( $try = 0; $try < 10; $try++ ) {
			$current = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::POINTER ) );
			$next    = $pool[0];
			foreach ( $pool as $id ) {
				if ( $id > (int) $current ) {
					$next = $id;
					break;
				}
			}
			if ( (string) $next === $current ) {
				return $next; // A single-agent pool: nothing to move.
			}
			$n = $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", (string) $next, self::POINTER, $current ) );
			if ( 1 === (int) $n ) {
				wp_cache_delete( self::POINTER, 'options' );
				wp_cache_delete( 'notoptions', 'options' );
				return $next;
			}
		}
		return 0;
	}
}
