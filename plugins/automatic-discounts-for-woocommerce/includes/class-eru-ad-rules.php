<?php
/** Rule storage and sanitising. Rules live in the `eru_ad_rules` option (default: none). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_AD_Rules {

	const OPTION = 'eru_ad_rules';

	/** @return array<int,array<string,mixed>> */
	public static function get(): array {
		$rules = get_option( self::OPTION, array() );
		return is_array( $rules ) ? array_values( $rules ) : array();
	}

	public static function defaults(): array {
		return array(
			'type'         => 'subtotal',
			'min_subtotal' => 0.0,
			'category'     => 0,
			'min_qty'      => 1,
			'mode'         => 'percent',
			'value'        => '',
			'label'        => '',
			'start'        => '',
			'end'          => '',
			'priority'     => 10,
			'stop'         => 0,
			'taxable'      => 1,
		);
	}

	private static function date( $v ): string {
		$v = is_string( $v ) ? trim( $v ) : '';
		return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) && false !== strtotime( $v ) ? $v : '';
	}

	/**
	 * Validate raw (already unslashed) rule rows. Rows with a non-positive value, or a category rule
	 * without category, are dropped.
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
			$value = isset( $row['value'] ) ? (float) wc_format_decimal( (string) $row['value'] ) : 0.0;
			if ( $value <= 0 ) {
				continue;
			}
			$type     = isset( $row['type'] ) && 'category_qty' === $row['type'] ? 'category_qty' : 'subtotal';
			$category = isset( $row['category'] ) ? absint( $row['category'] ) : 0;
			if ( 'category_qty' === $type && ! $category ) {
				continue;
			}
			$mode = isset( $row['mode'] ) && 'fixed' === $row['mode'] ? 'fixed' : 'percent';
			if ( 'percent' === $mode && $value > 100 ) {
				$value = 100.0;
			}
			$out[] = array(
				'type'         => $type,
				'min_subtotal' => isset( $row['min_subtotal'] ) ? max( 0.0, (float) wc_format_decimal( (string) $row['min_subtotal'] ) ) : 0.0,
				'category'     => $category,
				'min_qty'      => isset( $row['min_qty'] ) ? max( 1, absint( $row['min_qty'] ) ) : 1,
				'mode'         => $mode,
				'value'        => $value,
				'label'        => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '',
				'start'        => self::date( $row['start'] ?? '' ),
				'end'          => self::date( $row['end'] ?? '' ),
				'priority'     => isset( $row['priority'] ) ? (int) $row['priority'] : 10,
				'stop'         => ! empty( $row['stop'] ) ? 1 : 0,
				'taxable'      => ! empty( $row['taxable'] ) ? 1 : 0,
			);
		}
		return $out;
	}

	public static function save( array $rules ): void {
		update_option( self::OPTION, $rules );
	}
}
