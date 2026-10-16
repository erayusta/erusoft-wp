<?php
/**
 * Settings: one option with defaults, sanitised on write and clamped on read. No screen here; the admin
 * UI writes through update().
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keys:
 * - enabled           yes|no      master switch (off: no commission, no cookie, no job).
 * - trigger_status    completed|processing  the order status that earns the commission ("processing"
 *                                  also counts orders that go straight to completed).
 * - include_shipping  yes|no      add the order's ex-tax shipping to the lines, split by line total.
 * - max_levels        1–5         how far up the hierarchy a sale pays (level 1 = the customer's agent).
 * - hold_days         0–365       days between earning and approval.
 * - refund_policy     prorata|void  refunds shrink the refunded line's rows, or void them.
 * - assignment_mode   referral|first_touch|round_robin|manual  how customers get their agent.
 * - cookie_days       1–365       lifetime of the referral cookie.
 * - min_payout        decimal     smallest balance an agent can claim (0 = no minimum).
 * - payout_details_form yes|no    agents enter holder / IBAN / TCKN in My Account (encrypted; needs
 *                                  ERU_STC_ENCRYPTION_KEY).
 * - self_registration yes|no      logged-in customers may apply to become an agent (admin approves).
 * - customer_invites  yes|no      agents may invite existing customers by e-mail (the customer confirms).
 * - endpoint_*        slug        My Account addresses: panel, team, commissions, payouts, details.
 * - rewrite_pending   0|1         internal: rewrite rules are flushed once on the next request.
 */
class Eru_STC_Settings {

	const OPTION = 'eru_stc_settings';

	const TRIGGERS = array( 'completed', 'processing' );
	const POLICIES = array( 'prorata', 'void' );
	const MODES    = array( 'referral', 'first_touch', 'round_robin', 'manual' );

	/** My Account endpoint keys → default slugs. */
	const ENDPOINTS = array(
		'panel'       => 'temsilci-paneli',
		'team'        => 'ekibim',
		'commissions' => 'komisyonlar',
		'payouts'     => 'odemeler',
		'details'     => 'odeme-bilgileri',
	);

	/** Addresses WooCommerce already uses under My Account and checkout. */
	const RESERVED = array( 'orders', 'view-order', 'downloads', 'edit-account', 'edit-address', 'payment-methods', 'add-payment-method', 'delete-payment-method', 'set-default-payment-method', 'lost-password', 'customer-logout', 'order-pay', 'order-received' );

	/** @var array|null In-process copy. */
	private static $cache = null;

	/** @return array Default values. */
	public static function defaults() {
		return array(
			'enabled'              => 'no',
			'trigger_status'       => 'completed',
			'include_shipping'     => 'no',
			'max_levels'           => 2,
			'hold_days'            => 14,
			'refund_policy'        => 'prorata',
			'assignment_mode'      => 'referral',
			'cookie_days'          => 30,
			'min_payout'           => '0',
			'payout_details_form'  => 'yes',
			'self_registration'    => 'no',
			'customer_invites'     => 'yes',
			'endpoint_panel'       => self::ENDPOINTS['panel'],
			'endpoint_team'        => self::ENDPOINTS['team'],
			'endpoint_commissions' => self::ENDPOINTS['commissions'],
			'endpoint_payouts'     => self::ENDPOINTS['payouts'],
			'endpoint_details'     => self::ENDPOINTS['details'],
			'rewrite_pending'      => 0,
		);
	}

	/** @param string $key panel | team | commissions | payouts | details. @return string Endpoint slug. */
	public static function endpoint( $key ) {
		return (string) self::get( 'endpoint_' . $key );
	}

	/**
	 * One value, or every value when $key is null.
	 *
	 * @param string|null $key Setting key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		if ( null === self::$cache ) {
			$stored      = get_option( self::OPTION, array() );
			self::$cache = self::sanitize( is_array( $stored ) ? $stored : array() );
		}
		if ( null === $key ) {
			return self::$cache;
		}
		return self::$cache[ $key ] ?? null;
	}

	/** @return bool Whether the module is switched on. */
	public static function enabled() {
		return 'yes' === self::get( 'enabled' );
	}

	/**
	 * Merges and stores values (unknown keys dropped, every value clamped).
	 *
	 * @param array $values New values.
	 * @return array The stored settings.
	 */
	public static function update( array $values ) {
		$stored = get_option( self::OPTION, array() );
		$clean  = self::sanitize( array_merge( is_array( $stored ) ? $stored : array(), $values ) );
		update_option( self::OPTION, $clean, true );
		self::$cache = $clean;
		return $clean;
	}

	/** Drops the in-process copy (after a direct option write). */
	public static function flush() {
		self::$cache = null;
	}

	/**
	 * @param array $in Raw values.
	 * @return array Clean values over the defaults.
	 */
	public static function sanitize( array $in ) {
		$d   = self::defaults();
		$out = array();
		foreach ( array( 'enabled', 'include_shipping', 'payout_details_form', 'self_registration', 'customer_invites' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) && in_array( $in[ $k ], array( 'yes', '1', 1, true ), true ) ? 'yes' : ( isset( $in[ $k ] ) ? 'no' : $d[ $k ] );
		}
		$out['trigger_status']  = in_array( $in['trigger_status'] ?? '', self::TRIGGERS, true ) ? $in['trigger_status'] : $d['trigger_status'];
		$out['refund_policy']   = in_array( $in['refund_policy'] ?? '', self::POLICIES, true ) ? $in['refund_policy'] : $d['refund_policy'];
		$out['assignment_mode'] = in_array( $in['assignment_mode'] ?? '', self::MODES, true ) ? $in['assignment_mode'] : $d['assignment_mode'];
		$out['max_levels']      = max( 1, min( 5, (int) ( $in['max_levels'] ?? $d['max_levels'] ) ) );
		$out['hold_days']       = max( 0, min( 365, (int) ( $in['hold_days'] ?? $d['hold_days'] ) ) );
		$out['cookie_days']     = max( 1, min( 365, (int) ( $in['cookie_days'] ?? $d['cookie_days'] ) ) );
		$min                    = Eru_STC_Money::to_minor( $in['min_payout'] ?? $d['min_payout'] );
		$out['min_payout']      = Eru_STC_Money::from_minor( max( 0, $min ) );
		$used                   = array();
		foreach ( self::ENDPOINTS as $key => $default ) {
			$slug = isset( $in[ 'endpoint_' . $key ] ) ? substr( sanitize_title( (string) $in[ 'endpoint_' . $key ] ), 0, 40 ) : $default;
			if ( '' === $slug || in_array( $slug, self::RESERVED, true ) || isset( $used[ $slug ] ) ) {
				$slug = isset( $used[ $default ] ) ? $default . '-' . $key : $default;
			}
			$used[ $slug ]             = true;
			$out[ 'endpoint_' . $key ] = $slug;
		}
		$out['rewrite_pending'] = empty( $in['rewrite_pending'] ) ? 0 : 1;
		return $out;
	}
}
