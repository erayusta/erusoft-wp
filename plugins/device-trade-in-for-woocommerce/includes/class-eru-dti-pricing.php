<?php
/**
 * Trade-in value maths.
 *
 * @package device-trade-in-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Value = round(base x percent / 100 + bonus, price decimals), capped at the product price.
 */
class Eru_DTI_Pricing {

	/**
	 * Resolves the value for a device and condition straight from the database.
	 *
	 * @param int    $device_id     Device ID.
	 * @param string $condition_key Condition key.
	 * @return array|WP_Error {value, base, percent, bonus, device, condition}
	 */
	public static function resolve( $device_id, $condition_key ) {
		$device = Eru_DTI_DB::device_get( $device_id );
		if ( ! $device || ! (int) $device['active'] ) {
			return new WP_Error( 'eru_dti_device', __( 'Seçilen cihaz artık takas listesinde değil.', 'device-trade-in-for-woocommerce' ) );
		}
		$conds = Eru_DTI_Settings::conditions();
		if ( ! isset( $conds[ $condition_key ] ) ) {
			return new WP_Error( 'eru_dti_condition', __( 'Seçilen cihaz durumu geçerli değil.', 'device-trade-in-for-woocommerce' ) );
		}
		$percent = (float) $conds[ $condition_key ]['percent'];
		$bonus   = Eru_DTI_Settings::is_on( 'bonus_enabled' ) ? max( 0, (float) Eru_DTI_Settings::get( 'bonus_amount' ) ) : 0.0;
		$value   = round( (float) $device['base_value'] * $percent / 100 + $bonus, wc_get_price_decimals() );
		return array(
			'value'     => $value,
			'base'      => (float) $device['base_value'],
			'percent'   => $percent,
			'bonus'     => $bonus,
			'device'    => $device,
			'condition' => $conds[ $condition_key ],
		);
	}

	/** @param float $value Value. @param float $unit_ex_tax Per-unit ex-tax price. @return float */
	public static function cap( $value, $unit_ex_tax ) {
		return round( max( 0, min( (float) $value, (float) $unit_ex_tax ) ), wc_get_price_decimals() );
	}
}
