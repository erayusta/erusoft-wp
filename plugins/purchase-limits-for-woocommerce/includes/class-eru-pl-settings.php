<?php
/** Option `eru_pl_settings`: order min/max, category rules, messages. Default: no limits. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PL_Settings {

	const OPTION = 'eru_pl_settings';

	public static function defaults(): array {
		return array(
			'order_min'      => 0,
			'order_max'      => 0,
			'category_rules' => array(),
			'msg_max'        => '',
			'msg_min'        => '',
			'msg_customer'   => '',
		);
	}

	public static function get(): array {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function default_message( string $key ): string {
		switch ( $key ) {
			case 'msg_min':
				return __( '“{name}” için en az {limit} adet almalısınız.', 'purchase-limits-for-woocommerce' );
			case 'msg_customer':
				return __( '“{name}” için {period} en fazla {limit} adet satın alabilirsiniz (daha önce {bought} adet aldınız).', 'purchase-limits-for-woocommerce' );
			default:
				return __( '“{name}” için bir siparişte en fazla {limit} adet alabilirsiniz.', 'purchase-limits-for-woocommerce' );
		}
	}

	/** @param mixed $raw Unslashed posted data. */
	public static function sanitize( $raw ): array {
		$raw = is_array( $raw ) ? $raw : array();
		$out = array(
			'order_min'      => absint( $raw['order_min'] ?? 0 ),
			'order_max'      => absint( $raw['order_max'] ?? 0 ),
			'category_rules' => array(),
			'msg_max'        => sanitize_text_field( (string) ( $raw['msg_max'] ?? '' ) ),
			'msg_min'        => sanitize_text_field( (string) ( $raw['msg_min'] ?? '' ) ),
			'msg_customer'   => sanitize_text_field( (string) ( $raw['msg_customer'] ?? '' ) ),
		);
		foreach ( (array) ( $raw['category_rules'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$cat  = absint( $row['category'] ?? 0 );
			$rule = array(
				'category'     => $cat,
				'min'          => absint( $row['min'] ?? 0 ),
				'max'          => absint( $row['max'] ?? 0 ),
				'customer_max' => absint( $row['customer_max'] ?? 0 ),
				'period_days'  => absint( $row['period_days'] ?? 0 ),
			);
			if ( $cat && ( $rule['min'] || $rule['max'] || $rule['customer_max'] ) ) {
				$out['category_rules'][] = $rule;
			}
		}
		return $out;
	}
}
