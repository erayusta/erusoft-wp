<?php
/**
 * Overlap with the smaller Erusoft discount plugins. When Automatic Discounts, Cart Campaigns or Payment
 * Method Discounts is active next to this engine, the same rule can discount a cart twice; shop managers
 * see a notice they can hide (POST + nonce, per user, until the set of active plugins changes). There is
 * no code coupling: both keep working, the merchant decides.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Admin;

final class ConflictNotice {

	public const ACTION = 'eru_ce_dismiss_conflict';
	public const META   = 'eru_ce_conflict_dismissed';

	/** Plugins that discount the same carts (warning). */
	public const OVERLAPS = array(
		'automatic-discounts-for-woocommerce'      => 'Automatic Discounts for WooCommerce',
		'cart-campaigns-for-woocommerce'           => 'Cart Campaigns for WooCommerce',
		'payment-method-discounts-for-woocommerce' => 'Payment Method Discounts for WooCommerce',
	);

	/** Plugins that overlap in part (shown on the status screen only). */
	public const RELATED = array(
		'bought-together-discounts-for-woocommerce' => 'Bought Together Discounts for WooCommerce',
		'campaign-pages-for-wordpress'              => 'Campaign Pages for WordPress',
		'brand-coupon-banners-for-woocommerce'      => 'Brand Coupon Banners for WooCommerce',
	);

	public static function hooks(): void {
		add_action( 'admin_notices', array( self::class, 'render' ) );
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'dismiss' ) );
	}

	/**
	 * Active plugins of a list: slug => name.
	 *
	 * @param array<string,string> $list
	 * @return array<string,string>
	 */
	public static function active( array $list = self::OVERLAPS ): array {
		$active = (array) get_option( 'active_plugins', array() );

		if ( is_multisite() ) {
			$active = array_merge( $active, array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) ) );
		}

		$out = array();

		foreach ( $list as $slug => $name ) {
			if ( in_array( $slug . '/' . $slug . '.php', $active, true ) ) {
				$out[ $slug ] = $name;
			}
		}

		return $out;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$active = self::active();

		if ( array() === $active ) {
			return;
		}

		$signature = md5( implode( ',', array_keys( $active ) ) );

		if ( get_user_meta( get_current_user_id(), self::META, true ) === $signature ) {
			return;
		}

		echo '<div class="notice notice-warning eru-ce-conflict"><p><strong>' . esc_html__( 'Campaign Engine:', 'campaign-engine-for-woocommerce' ) . '</strong> ';
		/* translators: %s: plugin names */
		echo esc_html( sprintf( __( '%s da etkin. Aynı kurallar iki eklentide birden tanımlıysa sepet iki kez indirim alabilir. Bir kuralı yalnızca bir eklentide tutun ya da küçük eklentiyi devre dışı bırakın.', 'campaign-engine-for-woocommerce' ), implode( ', ', $active ) ) );
		echo '</p><form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '"><input type="hidden" name="signature" value="' . esc_attr( $signature ) . '">';
		wp_nonce_field( self::ACTION, '_wpnonce', true, true );
		echo '<p><button type="submit" class="button-link">' . esc_html__( 'Anladım, gizle', 'campaign-engine-for-woocommerce' ) . '</button></p></form></div>';
	}

	/** admin-post: hides the notice for this user and this set of active plugins. */
	public static function dismiss(): void {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';

		if ( ! wp_verify_nonce( $nonce, self::ACTION ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok ya da oturum süresi doldu.', 'campaign-engine-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$signature = isset( $_POST['signature'] ) ? sanitize_key( wp_unslash( $_POST['signature'] ) ) : '';

		if ( 1 === preg_match( '/^[a-f0-9]{32}$/', $signature ) ) {
			update_user_meta( get_current_user_id(), self::META, $signature );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url() );
		exit;
	}
}
