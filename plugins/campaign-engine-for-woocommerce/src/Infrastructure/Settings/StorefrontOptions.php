<?php
/**
 * Storefront settings in the `eru_ce_storefront` option: which shop-front modules are on, the campaign
 * page slugs and the My Account endpoint slug.
 *
 * Every module is off by default, so activating the plugin changes nothing on the shop front. The option
 * is small, holds nothing secret and is autoloaded: the front end reads it on every request, and an
 * autoloaded option costs no query. Activation adds it so a missing row never costs one either.
 *
 * Keys:
 *  - badge, price_html, gain_box, smart_cart, checkout_summary, thank_you, pages, account  'yes'|'no';
 *  - archive_slug (default kampanyalar), single_slug (default kampanya), account_endpoint (default odullerim);
 *  - rewrite_pending  1 after a change that needs new rewrite rules; the next request flushes them once.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Settings;

use WP_Error;

final class StorefrontOptions {

	public const OPTION = 'eru_ce_storefront';

	public const MODULES = array( 'badge', 'price_html', 'gain_box', 'smart_cart', 'checkout_summary', 'thank_you', 'pages', 'account' );

	/** Slug keys whose change needs new rewrite rules, with the module they belong to. */
	private const REWRITE_KEYS = array( 'pages', 'archive_slug', 'single_slug', 'account', 'account_endpoint' );

	/** My Account endpoints WooCommerce already uses. */
	private const RESERVED_ENDPOINTS = array( 'orders', 'view-order', 'downloads', 'edit-account', 'edit-address', 'payment-methods', 'add-payment-method', 'delete-payment-method', 'set-default-payment-method', 'lost-password', 'customer-logout', 'order-pay', 'order-received' );

	/** @return array<string,string|int> */
	public static function defaults(): array {
		$out = array_fill_keys( self::MODULES, 'no' );

		return $out + array(
			'archive_slug'     => 'kampanyalar',
			'single_slug'      => 'kampanya',
			'account_endpoint' => 'odullerim',
			'rewrite_pending'  => 0,
		);
	}

	/** @return array<string,string|int> */
	public static function all(): array {
		$stored = get_option( self::OPTION, array() );

		return array_merge( self::defaults(), is_array( $stored ) ? array_intersect_key( $stored, self::defaults() ) : array() );
	}

	/** @return string|int|null */
	public static function get( string $key ) {
		return self::all()[ $key ] ?? null;
	}

	public static function on( string $module ): bool {
		return 'yes' === self::get( $module );
	}

	/** True when at least one shop-front module is on. */
	public static function anyOn(): bool {
		$all = self::all();

		foreach ( self::MODULES as $module ) {
			if ( 'yes' === $all[ $module ] ) {
				return true;
			}
		}

		return false;
	}

	/** Adds the option (autoloaded) when it is missing. */
	public static function ensure(): void {
		add_option( self::OPTION, self::defaults(), '', true );
	}

	/**
	 * Validates and merges $values. A change to a page slug, the account endpoint or the modules that
	 * own them marks the rewrite rules for one flush.
	 *
	 * @param array<string,mixed> $values
	 * @return true|WP_Error error data: field, reason
	 */
	public static function update( array $values ) {
		$before = self::all();
		$next   = array_merge( $before, array_intersect_key( $values, self::defaults() ) );
		$valid  = self::check( $next );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$changed = false;

		foreach ( self::REWRITE_KEYS as $key ) {
			$changed = $changed || $before[ $key ] !== $next[ $key ];
		}

		$next['rewrite_pending'] = $changed ? 1 : (int) ( array_key_exists( 'rewrite_pending', $values ) ? $values['rewrite_pending'] : $before['rewrite_pending'] );

		update_option( self::OPTION, $next, true );

		return true;
	}

	/**
	 * Validates a full set of values without saving.
	 *
	 * @param array<string,mixed> $next
	 * @return true|WP_Error
	 */
	public static function check( array $next ) {
		$next = array_merge( self::defaults(), $next );

		foreach ( self::MODULES as $module ) {
			if ( ! in_array( $next[ $module ], array( 'yes', 'no' ), true ) ) {
				return self::error( $module, 'invalid' );
			}
		}

		foreach ( array( 'archive_slug', 'single_slug', 'account_endpoint' ) as $key ) {
			if ( ! is_string( $next[ $key ] ) || 1 !== preg_match( '/^[a-z0-9][a-z0-9-]{0,39}$/', $next[ $key ] ) ) {
				return self::error( $key, 'invalid' );
			}
		}

		if ( $next['archive_slug'] === $next['single_slug'] ) {
			return self::error( 'single_slug', 'in_use' );
		}

		if ( in_array( $next['account_endpoint'], self::RESERVED_ENDPOINTS, true ) ) {
			return self::error( 'account_endpoint', 'in_use' );
		}

		return true;
	}

	/** Marks the rewrite rules for one flush (activation with page modules on). */
	public static function requestFlush(): void {
		$all                    = self::all();
		$all['rewrite_pending'] = 1;
		update_option( self::OPTION, $all, true );
	}

	private static function error( string $field, string $reason ): WP_Error {
		return new WP_Error(
			'eru_ce_invalid_setting',
			/* translators: 1: field name, 2: reason code */
			sprintf( __( 'Ayar kaydedilmedi: "%1$s" alanı geçersiz (%2$s).', 'campaign-engine-for-woocommerce' ), $field, $reason ),
			array(
				'field'  => $field,
				'reason' => $reason,
			)
		);
	}
}
