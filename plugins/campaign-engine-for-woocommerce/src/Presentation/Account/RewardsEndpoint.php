<?php
/**
 * Hesabım → Ödüllerim (slug from the settings, default `odullerim`): the customer's own spend-and-earn
 * rewards, with the coupon code once it is issued. Off by default. Only rows earned by the account, or
 * guest rows whose source order the merchant linked to the account, are listed; an e-mail match alone
 * never reveals a code.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Account;

use Erusoft\CampaignEngine\Infrastructure\Database\RewardRepository;
use Erusoft\CampaignEngine\Infrastructure\Settings\StorefrontOptions;

final class RewardsEndpoint {

	/** WooCommerce query var key of the endpoint (the URL part is the setting). */
	public const KEY = 'eru-ce-rewards';

	public static function hooks(): void {
		if ( ! StorefrontOptions::on( 'account' ) ) {
			return;
		}

		add_filter( 'woocommerce_get_query_vars', array( self::class, 'queryVars' ) );
		add_filter( 'woocommerce_account_menu_items', array( self::class, 'menu' ) );
		add_action( 'woocommerce_account_' . self::KEY . '_endpoint', array( self::class, 'render' ) );
		add_filter( 'woocommerce_endpoint_' . self::KEY . '_title', array( self::class, 'title' ) );
	}

	/** @param array<string,string> $vars */
	public static function queryVars( $vars ): array {
		$vars              = (array) $vars;
		$vars[ self::KEY ] = (string) StorefrontOptions::get( 'account_endpoint' );

		return $vars;
	}

	/**
	 * Adds "Ödüllerim" before "Çıkış yap" while the setting is on.
	 *
	 * @param array<string,string> $items
	 * @return array<string,string>
	 */
	public static function menu( $items ): array {
		$items = (array) $items;

		if ( ! StorefrontOptions::on( 'account' ) ) {
			return $items;
		}

		$out = array();

		foreach ( $items as $key => $label ) {
			if ( 'customer-logout' === $key ) {
				$out[ self::KEY ] = __( 'Ödüllerim', 'campaign-engine-for-woocommerce' );
			}

			$out[ $key ] = $label;
		}

		if ( ! isset( $out[ self::KEY ] ) ) {
			$out[ self::KEY ] = __( 'Ödüllerim', 'campaign-engine-for-woocommerce' );
		}

		return $out;
	}

	public static function title(): string {
		return __( 'Ödüllerim', 'campaign-engine-for-woocommerce' );
	}

	/** The endpoint content for the logged-in customer. */
	public static function render(): void {
		$user = wp_get_current_user();

		if ( ! $user || ! $user->exists() ) {
			return;
		}

		$rewards = RewardRepository::forCustomer( (int) $user->ID );
		$theme   = locate_template( array( 'campaign-engine-for-woocommerce/myaccount-rewards.php' ) );

		include '' !== $theme ? $theme : ERU_CE_PATH . 'templates/myaccount-rewards.php';
	}
}
