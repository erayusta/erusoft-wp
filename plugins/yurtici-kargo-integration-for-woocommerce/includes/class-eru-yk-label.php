<?php
/**
 * Printable shipping label (100×150 mm or A6) with a CODE128 barcode of the cargo key, drawn by the
 * bundled JsBarcode (no CDN).
 *
 * @package yurtici-kargo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * admin-post.php?action=eru_yk_label&order=ID&_wpnonce=… — needs edit_shop_orders and a per-order nonce.
 * Read-only: printing changes nothing.
 */
class Eru_YK_Label {

	/** Hooks. */
	public static function init() {
		add_action( 'admin_post_eru_yk_label', array( __CLASS__, 'render' ) );
	}

	/** @return array{name:string,lines:string[],phone:string} Sender from the WooCommerce store settings. */
	public static function sender() {
		$raw     = (string) get_option( 'woocommerce_default_country', '' );
		$parts   = explode( ':', $raw );
		$country = $parts[0] ?? 'TR';
		$state   = $parts[1] ?? '';
		$city    = trim( (string) get_option( 'woocommerce_store_city', '' ) );
		$prov    = '' !== $state ? Eru_YK_Dispatcher::province( $country, $state ) : '';
		$lines   = array_filter(
			array(
				trim( (string) get_option( 'woocommerce_store_address', '' ) ),
				trim( (string) get_option( 'woocommerce_store_address_2', '' ) ),
				trim( implode( ' ', array_filter( array( (string) get_option( 'woocommerce_store_postcode', '' ), $city ) ) ) . ( '' !== $prov ? ' / ' . $prov : '' ), ' /' ),
			)
		);
		return array(
			'name'  => wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
			'lines' => array_values( $lines ),
		);
	}

	/** Outputs the label document. */
	public static function render() {
		$id = absint( $_GET['order'] ?? 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- verified below.
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'yurtici-kargo-integration-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_yk_label_' . $id );
		$html = self::html( wc_get_order( $id ) );
		if ( is_wp_error( $html ) ) {
			wp_die( esc_html( $html->get_error_message() ), (int) $html->get_error_data() );
		}
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput -- every value is escaped in html().
		exit;
	}

	/**
	 * Label document (every value escaped).
	 *
	 * @param WC_Order|false $order Order.
	 * @return string|WP_Error
	 */
	public static function html( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return new WP_Error( 'eru_yk_label', __( 'Sipariş bulunamadı.', 'yurtici-kargo-integration-for-woocommerce' ), 404 );
		}
		$key = (string) $order->get_meta( Eru_YK_Dispatcher::M_KEY );
		if ( '' === $key || ! in_array( (string) $order->get_meta( Eru_YK_Dispatcher::M_STATE ), Eru_YK_Dispatcher::LIVE, true ) ) {
			return new WP_Error( 'eru_yk_label', __( 'Bu siparişin Yurtiçi gönderisi yok.', 'yurtici-kargo-integration-for-woocommerce' ), 409 );
		}
		$rcv     = Eru_YK_Dispatcher::receiver( $order );
		$snd     = self::sender();
		$account = Eru_YK_Accounts::get( (string) $order->get_meta( Eru_YK_Dispatcher::M_ACCOUNT ) );
		$a6      = 'a6' === Eru_YK_Settings::get( 'label_size' );
		$w       = $a6 ? 105 : 100;
		$h       = $a6 ? 148 : 150;
		$track   = (string) $order->get_meta( Eru_YK_Dispatcher::M_TRACKING );

		$out = '';
		$out .= '<!doctype html><html lang="tr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex">';
		/* translators: %s: order number */
		$out .= '<title>' . esc_html( sprintf( __( 'Kargo etiketi — sipariş #%s', 'yurtici-kargo-integration-for-woocommerce' ), $order->get_order_number() ) ) . '</title>';
		$out .= '<style>
@page { size: ' . (int) $w . 'mm ' . (int) $h . 'mm; margin: 0; }
* { box-sizing: border-box; }
html, body { margin: 0; background: #f0f0f1; color: #000; font: 11pt/1.3 -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
.toolbar { padding: 12px; text-align: center; }
.toolbar button { font: inherit; padding: 6px 16px; cursor: pointer; }
.label { width: ' . (int) $w . 'mm; height: ' . (int) $h . 'mm; margin: 0 auto 16px; background: #fff; padding: 5mm; display: flex; flex-direction: column; gap: 3mm; overflow: hidden; border: 1px solid #ccc; }
.label h2 { margin: 0 0 1mm; font-size: 8pt; text-transform: uppercase; letter-spacing: .05em; color: #333; }
.box { border: 0.4mm solid #000; padding: 2.5mm; }
.rcv .name { font-size: 13pt; font-weight: 700; }
.rcv .city { font-size: 14pt; font-weight: 700; margin-top: 1mm; }
.meta { display: grid; grid-template-columns: 1fr 1fr; gap: 1mm 3mm; font-size: 9pt; }
.meta b { display: block; font-size: 7pt; text-transform: uppercase; color: #333; }
.bc { text-align: center; margin-top: auto; }
.bc svg { width: 100%; height: auto; max-height: 34mm; }
.carrier { display: flex; justify-content: space-between; align-items: baseline; font-weight: 700; }
@media print { html, body { background: #fff; } .toolbar { display: none; } .label { margin: 0; border: 0; } }
</style></head><body>';
		$out .= '<div class="toolbar"><button type="button" onclick="window.print()">' . esc_html__( 'Yazdır', 'yurtici-kargo-integration-for-woocommerce' ) . '</button></div>';
		$out .= '<div class="label">';
		$out .= '<div class="carrier"><span>Yurtiçi Kargo</span><span>#' . esc_html( (string) $order->get_order_number() ) . '</span></div>';
		$out .= '<div class="box snd"><h2>' . esc_html__( 'Gönderici', 'yurtici-kargo-integration-for-woocommerce' ) . '</h2><div><strong>' . esc_html( $snd['name'] ) . '</strong></div>';
		foreach ( $snd['lines'] as $l ) {
			$out .= '<div>' . esc_html( $l ) . '</div>';
		}
		if ( $account && '' !== (string) $account['customer_code'] ) {
			$out .= '<div>' . esc_html__( 'Müşteri kodu:', 'yurtici-kargo-integration-for-woocommerce' ) . ' ' . esc_html( (string) $account['customer_code'] ) . '</div>';
		}
		$out .= '</div>';
		$out .= '<div class="box rcv"><h2>' . esc_html__( 'Alıcı', 'yurtici-kargo-integration-for-woocommerce' ) . '</h2><div class="name">' . esc_html( $rcv['name'] ) . '</div><div>' . esc_html( $rcv['address'] ) . '</div>';
		$out .= '<div class="city">' . esc_html( trim( $rcv['town'] . ' / ' . $rcv['city'], ' /' ) ) . '</div>';
		if ( '' !== $rcv['phone'] ) {
			$out .= '<div>' . esc_html__( 'Tel:', 'yurtici-kargo-integration-for-woocommerce' ) . ' ' . esc_html( $rcv['phone'] ) . '</div>';
		}
		$out .= '</div>';
		$out .= '<div class="meta"><div><b>' . esc_html__( 'Kargo anahtarı', 'yurtici-kargo-integration-for-woocommerce' ) . '</b>' . esc_html( $key ) . '</div>';
		$out .= '<div><b>' . esc_html__( 'Takip no', 'yurtici-kargo-integration-for-woocommerce' ) . '</b>' . esc_html( '' !== $track ? $track : '—' ) . '</div>';
		$out .= '<div><b>' . esc_html__( 'Tarih', 'yurtici-kargo-integration-for-woocommerce' ) . '</b>' . esc_html( wp_date( 'd.m.Y' ) ) . '</div>';
		$out .= '<div><b>' . esc_html__( 'Parça', 'yurtici-kargo-integration-for-woocommerce' ) . '</b>1/1</div></div>';
		$out .= '<div class="bc"><svg id="eru-yk-barcode" data-value="' . esc_attr( $key ) . '" role="img" aria-label="' . esc_attr( $key ) . '"></svg></div>';
		$out .= '</div>';
		$out .= wp_get_script_tag( array( 'src' => esc_url( ERU_YK_URL . 'assets/vendor/jsbarcode.min.js?ver=3.11.6' ) ) );
		$out .= wp_get_inline_script_tag( '(function(){var el=document.getElementById("eru-yk-barcode");if(el&&window.JsBarcode){JsBarcode(el,el.getAttribute("data-value"),{format:"CODE128",width:2,height:80,fontSize:16,margin:0});}})();' );
		$out .= '</body></html>';
		return $out;
	}
}
