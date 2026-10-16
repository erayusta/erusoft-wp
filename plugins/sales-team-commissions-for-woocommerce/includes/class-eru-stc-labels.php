<?php
/**
 * Turkish labels for statuses, scopes and types (shared by admin, My Account, REST-free exports).
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Labels {

	/** @param string $s pending | approved | void | paid. @return string */
	public static function commission_status( $s ) {
		$l = array(
			'pending'  => __( 'Beklemede', 'sales-team-commissions-for-woocommerce' ),
			'approved' => __( 'Onaylı', 'sales-team-commissions-for-woocommerce' ),
			'void'     => __( 'İptal', 'sales-team-commissions-for-woocommerce' ),
			'paid'     => __( 'Ödendi', 'sales-team-commissions-for-woocommerce' ),
		);
		return $l[ $s ] ?? (string) $s;
	}

	/** @param string $s requested | paid | cancelled. @return string */
	public static function payout_status( $s ) {
		$l = array(
			'requested' => __( 'Talep edildi', 'sales-team-commissions-for-woocommerce' ),
			'paid'      => __( 'Ödendi', 'sales-team-commissions-for-woocommerce' ),
			'cancelled' => __( 'İptal edildi', 'sales-team-commissions-for-woocommerce' ),
		);
		return $l[ $s ] ?? (string) $s;
	}

	/** @param string $s pending | active | suspended. @return string */
	public static function agent_status( $s ) {
		$l = array(
			'pending'   => __( 'Onay bekliyor', 'sales-team-commissions-for-woocommerce' ),
			'active'    => __( 'Etkin', 'sales-team-commissions-for-woocommerce' ),
			'suspended' => __( 'Askıda', 'sales-team-commissions-for-woocommerce' ),
		);
		return $l[ $s ] ?? (string) $s;
	}

	/** @param string $s Rule scope. @return string */
	public static function scope( $s ) {
		$l = array(
			'product'  => __( 'Ürün', 'sales-team-commissions-for-woocommerce' ),
			'category' => __( 'Kategori', 'sales-team-commissions-for-woocommerce' ),
			'brand'    => __( 'Marka', 'sales-team-commissions-for-woocommerce' ),
			'role'     => __( 'Müşteri rolü', 'sales-team-commissions-for-woocommerce' ),
			'agent'    => __( 'Temsilci', 'sales-team-commissions-for-woocommerce' ),
			'global'   => __( 'Genel', 'sales-team-commissions-for-woocommerce' ),
		);
		return $l[ $s ] ?? (string) $s;
	}

	/** @param string $s Link type. @return string */
	public static function link_type( $s ) {
		$l = array(
			'referral'    => __( 'Davet linki', 'sales-team-commissions-for-woocommerce' ),
			'round_robin' => __( 'Sırayla dağıtım', 'sales-team-commissions-for-woocommerce' ),
			'manual'      => __( 'Elle', 'sales-team-commissions-for-woocommerce' ),
			'invite'      => __( 'Müşteri onayı', 'sales-team-commissions-for-woocommerce' ),
		);
		return $l[ $s ] ?? (string) $s;
	}

	/** @param string $s Void reason. @return string */
	public static function void_reason( $s ) {
		$l = array(
			'refund' => __( 'iade', 'sales-team-commissions-for-woocommerce' ),
			'cancel' => __( 'sipariş iptali', 'sales-team-commissions-for-woocommerce' ),
			'netted' => __( 'mahsup', 'sales-team-commissions-for-woocommerce' ),
			'manual' => __( 'yönetici', 'sales-team-commissions-for-woocommerce' ),
		);
		return $l[ $s ] ?? (string) $s;
	}

	/** @return array<string,string> Payout methods. */
	public static function methods() {
		/**
		 * Payout methods offered on the "ödendi" form.
		 *
		 * @param array<string,string> $methods key => label.
		 */
		return (array) apply_filters(
			'eru_stc_payout_methods',
			array(
				'bank_transfer' => __( 'Banka havalesi / EFT', 'sales-team-commissions-for-woocommerce' ),
				'cash'          => __( 'Nakit', 'sales-team-commissions-for-woocommerce' ),
				'other'         => __( 'Diğer', 'sales-team-commissions-for-woocommerce' ),
			)
		);
	}

	/** @param string $key Method key. @return string */
	public static function method( $key ) {
		$m = self::methods();
		return $m[ $key ] ?? (string) $key;
	}

	/** @param string $amount Decimal. @param string $currency Currency. @return string Plain text money. */
	public static function money( $amount, $currency = '' ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( (float) $amount, array( 'currency' => $currency ? $currency : get_woocommerce_currency() ) ) ), ENT_QUOTES, 'UTF-8' );
	}
}
