<?php
/**
 * Settings access, defaults and sanitising.
 *
 * @package device-trade-in-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads the eru_dti_settings option with defaults.
 */
class Eru_DTI_Settings {

	const OPTION = 'eru_dti_settings';
	const GROUP  = 'eru_dti';

	/** @return array Default settings. */
	public static function defaults() {
		return array(
			'l1_label'        => 'Marka',
			'l2_label'        => 'Model',
			'l3_label'        => 'Kapasite',
			'conditions'      => self::default_conditions(),
			'bonus_enabled'   => 'no',
			'bonus_amount'    => '0',
			'categories'      => array(),
			'products'        => array(),
			'discount_mode'   => 'fee',
			'serial_required' => 'yes',
			'terms_text'      => '',
			'ship_text'       => '',
			'ship_reference'  => '',
			'hold_enabled'    => 'no',
			'hold_status'     => 'wc-eru-dti-hold',
			'hold_triggers'   => array( 'processing' ),
			'reject_cancel'   => 'no',
			'overdue_days'    => '7',
			'leads_enabled'   => 'yes',
			'notify_email'    => '',
			'retention_days'  => '365',
		);
	}

	/** @return array Four generic condition levels. */
	public static function default_conditions() {
		return array(
			array( 'key' => 'cok-iyi', 'label' => 'Çok iyi', 'description' => 'Neredeyse yeni görünümde, tüm işlevleri çalışıyor.', 'percent' => 100 ),
			array( 'key' => 'iyi', 'label' => 'İyi', 'description' => 'Yakından bakınca görülen küçük izler, tüm işlevleri çalışıyor.', 'percent' => 80 ),
			array( 'key' => 'orta', 'label' => 'Orta', 'description' => 'Gözle görülür kullanım izleri, tüm işlevleri çalışıyor.', 'percent' => 60 ),
			array( 'key' => 'kotu', 'label' => 'Kötü', 'description' => 'Ağır kullanım izleri veya çalışmayan bir işlev var.', 'percent' => 35 ),
		);
	}

	/** @return array */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		$all   = array_merge( self::defaults(), is_array( $saved ) ? $saved : array() );
		if ( empty( $all['conditions'] ) || ! is_array( $all['conditions'] ) ) {
			$all['conditions'] = self::default_conditions();
		}
		return $all;
	}

	/** @param string $key Setting key. */
	public static function get( $key ) {
		$all = self::all();
		return $all[ $key ] ?? '';
	}

	/** @param string $key Setting key. */
	public static function is_on( $key ) {
		return 'yes' === self::get( $key );
	}

	/** Test helper: merge values straight into the option. @param array $values Values. */
	public static function save_for_test( array $values ) {
		$saved = get_option( self::OPTION, array() );
		update_option( self::OPTION, array_merge( is_array( $saved ) ? $saved : array(), $values ), false );
	}

	/** @return array Conditions keyed by key. */
	public static function conditions() {
		$out = array();
		foreach ( (array) self::get( 'conditions' ) as $c ) {
			if ( ! empty( $c['key'] ) ) {
				$out[ $c['key'] ] = $c;
			}
		}
		return $out;
	}

	/** Whether the third level is in use. */
	public static function uses_l3() {
		return '' !== trim( (string) self::get( 'l3_label' ) );
	}

	/** Whether any category or product is enabled. */
	public static function has_targets() {
		return ! empty( self::get( 'categories' ) ) || ! empty( self::get( 'products' ) );
	}

	/** @param int $product_id Product (or variation) ID. */
	public static function product_enabled( $product_id ) {
		$product_id = (int) $product_id;
		$parent     = (int) wp_get_post_parent_id( $product_id );
		if ( $parent && 'product_variation' === get_post_type( $product_id ) ) {
			$product_id = $parent;
		}
		if ( in_array( $product_id, array_map( 'intval', (array) self::get( 'products' ) ), true ) ) {
			return true;
		}
		$cats = array_map( 'intval', (array) self::get( 'categories' ) );
		if ( ! $cats ) {
			return false;
		}
		$terms = wp_get_post_terms( $product_id, 'product_cat', array( 'fields' => 'ids' ) );
		if ( is_wp_error( $terms ) ) {
			return false;
		}
		foreach ( $terms as $t ) {
			$chain = array_merge( array( (int) $t ), array_map( 'intval', get_ancestors( (int) $t, 'product_cat', 'taxonomy' ) ) );
			if ( array_intersect( $chain, $cats ) ) {
				return true;
			}
		}
		return false;
	}

	/** @param string $value Raw placeholder text. @param array $map Replacements. */
	public static function fill( $value, array $map ) {
		return strtr( (string) $value, $map );
	}

	/** Hooks. */
	public static function init() {
		add_filter( 'option_page_capability_' . self::GROUP, fn() => 'manage_woocommerce' );
		add_action(
			'admin_init',
			function () {
				register_setting(
					self::GROUP,
					self::OPTION,
					array(
						'type'              => 'array',
						'sanitize_callback' => array( __CLASS__, 'sanitize' ),
						'default'           => array(),
					)
				);
			}
		);
		add_action(
			'update_option_' . self::OPTION,
			function () {
				wp_set_option_autoload( self::OPTION, false );
				Eru_DTI_Overdue::maybe_schedule();
			}
		);
		add_action(
			'add_option_' . self::OPTION,
			function () {
				wp_set_option_autoload( self::OPTION, false );
				Eru_DTI_Overdue::maybe_schedule();
			}
		);
	}

	/** @param mixed $in Posted settings. @return array */
	public static function sanitize( $in ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return self::all();
		}
		$in  = is_array( $in ) ? $in : array(); // options.php already unslashed.
		$def = self::defaults();
		$out = array();

		foreach ( array( 'l1_label', 'l2_label', 'l3_label' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? sanitize_text_field( $in[ $k ] ) : $def[ $k ];
		}
		if ( '' === $out['l1_label'] ) {
			$out['l1_label'] = $def['l1_label'];
		}
		if ( '' === $out['l2_label'] ) {
			$out['l2_label'] = $def['l2_label'];
		}

		$conds = array();
		$seen  = array();
		foreach ( (array) ( $in['conditions'] ?? array() ) as $c ) {
			$label = sanitize_text_field( $c['label'] ?? '' );
			if ( '' === $label ) {
				continue;
			}
			$key = sanitize_key( $c['key'] ?? '' );
			if ( '' === $key ) {
				$key = sanitize_key( remove_accents( $label ) );
			}
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				$key = ( '' === $key ? 'durum' : $key ) . '-' . ( count( $conds ) + 1 );
			}
			$seen[ $key ] = true;
			$pct          = (float) str_replace( ',', '.', (string) ( $c['percent'] ?? 0 ) );
			$conds[]      = array(
				'key'         => $key,
				'label'       => $label,
				'description' => sanitize_text_field( $c['description'] ?? '' ),
				'percent'     => max( 0, min( 100, $pct ) ),
			);
		}
		$out['conditions'] = $conds ? $conds : self::default_conditions();

		$out['bonus_enabled'] = ! empty( $in['bonus_enabled'] ) ? 'yes' : 'no';
		$out['bonus_amount']  = (string) max( 0, (float) str_replace( ',', '.', (string) ( $in['bonus_amount'] ?? 0 ) ) );
		$out['categories']    = array_values( array_filter( array_map( 'absint', (array) ( $in['categories'] ?? array() ) ) ) );
		$ids                  = is_array( $in['products'] ?? null ) ? $in['products'] : preg_split( '/[\s,;]+/', (string) ( $in['products'] ?? '' ) );
		$out['products']      = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		$out['discount_mode'] = ( $in['discount_mode'] ?? 'fee' ) === 'line_discount' ? 'line_discount' : 'fee';
		$out['serial_required'] = ! empty( $in['serial_required'] ) ? 'yes' : 'no';
		$out['terms_text']    = wp_kses_post( $in['terms_text'] ?? '' );
		$out['ship_text']     = sanitize_textarea_field( $in['ship_text'] ?? '' );
		$out['ship_reference'] = sanitize_text_field( $in['ship_reference'] ?? '' );
		$out['hold_enabled']  = ! empty( $in['hold_enabled'] ) ? 'yes' : 'no';
		$out['reject_cancel'] = ! empty( $in['reject_cancel'] ) ? 'yes' : 'no';
		$out['hold_status']   = in_array( $in['hold_status'] ?? '', array( 'wc-eru-dti-hold', 'wc-on-hold' ), true ) ? $in['hold_status'] : 'wc-eru-dti-hold';
		$valid                = array_keys( wc_get_order_statuses() );
		$trig                 = array();
		foreach ( (array) ( $in['hold_triggers'] ?? array() ) as $s ) {
			$s = 'wc-' . preg_replace( '/^wc-/', '', sanitize_key( $s ) );
			if ( in_array( $s, $valid, true ) && $s !== $out['hold_status'] ) {
				$trig[] = substr( $s, 3 );
			}
		}
		$out['hold_triggers']  = $trig ? $trig : array( 'processing' );
		$out['overdue_days']   = (string) max( 0, absint( $in['overdue_days'] ?? 7 ) );
		$out['leads_enabled']  = ! empty( $in['leads_enabled'] ) ? 'yes' : 'no';
		$email                 = sanitize_email( $in['notify_email'] ?? '' );
		$out['notify_email']   = is_email( $email ) ? $email : '';
		$out['retention_days'] = (string) max( 1, absint( $in['retention_days'] ?? 365 ) );
		return $out;
	}
}
