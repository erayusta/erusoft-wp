<?php
/** Rule storage and sanitising. Rules live in the `eru_pmd_rules` option (default: none). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PMD_Rules {

	const OPTION = 'eru_pmd_rules';

	/** @return array<int,array<string,mixed>> */
	public static function get(): array {
		$rules = get_option( self::OPTION, array() );
		return is_array( $rules ) ? array_values( $rules ) : array();
	}

	/**
	 * Validate raw (already unslashed) rule rows. Rows without a gateway or with a
	 * non-positive value are dropped.
	 *
	 * @param mixed $raw Posted rows.
	 * @return array<int,array<string,mixed>>
	 */
	public static function sanitize( $raw ): array {
		$out = array();
		if ( ! is_array( $raw ) ) {
			return $out;
		}
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$gateway = isset( $row['gateway'] ) ? sanitize_key( (string) $row['gateway'] ) : '';
			$value   = isset( $row['value'] ) ? (float) wc_format_decimal( (string) $row['value'] ) : 0.0;
			if ( '' === $gateway || $value <= 0 ) {
				continue;
			}
			$mode = isset( $row['mode'] ) && 'fixed' === $row['mode'] ? 'fixed' : 'percent';
			if ( 'percent' === $mode && $value > 100 ) {
				$value = 100.0;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '';
			$out[] = array(
				'gateway'            => $gateway,
				'type'               => isset( $row['type'] ) && 'fee' === $row['type'] ? 'fee' : 'discount',
				'mode'               => $mode,
				'value'              => $value,
				'label'              => $label,
				'min_cart'           => isset( $row['min_cart'] ) ? max( 0.0, (float) wc_format_decimal( (string) $row['min_cart'] ) ) : 0.0,
				'exclude_categories' => isset( $row['exclude_categories'] ) ? array_values( array_filter( array_map( 'absint', (array) $row['exclude_categories'] ) ) ) : array(),
				'taxable'            => ! empty( $row['taxable'] ) ? 1 : 0,
			);
		}
		return $out;
	}

	public static function save( array $rules ): void {
		update_option( self::OPTION, $rules );
	}
}
