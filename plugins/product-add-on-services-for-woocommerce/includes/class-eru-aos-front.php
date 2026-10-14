<?php
/**
 * Product page: the add-on fieldsets, the live total and the shortcode.
 *
 * @package product-add-on-services-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Front-end rendering. Inputs are real radios/checkboxes inside form.cart, so the form works without JS.
 */
class Eru_AOS_Front {

	/**
	 * Product IDs already rendered in this request.
	 *
	 * @var array
	 */
	private static $rendered = array();

	public static function init() {
		add_action( 'init', array( __CLASS__, 'hook' ), 20 );
		add_shortcode( 'eru_addon_services', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Attaches the renderer after themes are loaded so they can filter the position.
	 */
	public static function hook() {
		$hook = 'manual' === get_option( 'eru_aos_position', 'before_button' ) ? '' : 'woocommerce_before_add_to_cart_button';
		/**
		 * Filters the action that prints the add-on services on the product page. Return '' to print them
		 * only with the [eru_addon_services] shortcode.
		 *
		 * @param string $hook Action name.
		 */
		$hook = (string) apply_filters( 'eru_aos_render_position', $hook );
		if ( '' !== $hook ) {
			add_action( $hook, array( __CLASS__, 'output' ) );
		}
	}

	public static function enqueue() {
		wp_register_style( 'eru-aos', ERU_AOS_URL . 'assets/front.css', array(), ERU_AOS_VERSION );
		wp_register_script( 'eru-aos', ERU_AOS_URL . 'assets/front.js', array( 'jquery' ), ERU_AOS_VERSION, true );
		wp_localize_script(
			'eru-aos',
			'eruAos',
			array(
				'decimals' => wc_get_price_decimals(),
				'decSep'   => wc_get_price_decimal_separator(),
				'thouSep'  => wc_get_price_thousand_separator(),
				'symbol'   => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
				'format'   => html_entity_decode( get_woocommerce_price_format(), ENT_QUOTES, 'UTF-8' ),
			)
		);
		if ( is_product() && Eru_AOS_Groups::for_product( get_queried_object_id() ) ) {
			wp_enqueue_style( 'eru-aos' );
			wp_enqueue_script( 'eru-aos' );
		}
	}

	public static function output() {
		global $product;
		echo self::render( $product ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in the template.
	}

	public static function shortcode( $atts ) {
		$atts    = shortcode_atts( array( 'product_id' => '' ), $atts, 'eru_addon_services' );
		$product = $atts['product_id'] ? wc_get_product( absint( $atts['product_id'] ) ) : ( $GLOBALS['product'] ?? null );
		if ( ! $product instanceof WC_Product && is_product() ) {
			$product = wc_get_product( get_queried_object_id() );
		}
		return self::render( $product );
	}

	/**
	 * Price label of one option, as HTML.
	 *
	 * @param array      $o       Option.
	 * @param WC_Product $product Product.
	 * @param float|null $pb      Display price the percentage is taken from (null for variable products).
	 * @return string
	 */
	public static function price_html( array $o, WC_Product $product, $pb ) {
		if ( (float) $o['amount'] <= 0 ) {
			return '';
		}
		if ( 'percent' === $o['price_type'] ) {
			$html = '+%' . wc_format_localized_decimal( $o['amount'] );
			if ( null !== $pb ) {
				$html .= ' <span class="eru-aos-option__amount">(' . wc_price( $pb * $o['amount'] / 100 ) . ')</span>';
			} else {
				$html .= ' <span class="eru-aos-option__amount"></span>';
			}
		} else {
			$html = '+' . wc_price( wc_get_price_to_display( $product, array( 'price' => $o['amount'] ) ) );
		}
		if ( 'per_line' === $o['charge'] ) {
			$html .= ' <span class="eru-aos-option__charge">' . esc_html__( 'tek sefer', 'product-add-on-services-for-woocommerce' ) . '</span>';
		}
		return $html;
	}

	/**
	 * Fieldsets for one product, or '' when no group applies.
	 *
	 * @param WC_Product|null $product Product.
	 * @return string
	 */
	public static function render( $product ) {
		if ( ! $product instanceof WC_Product || $product->is_type( array( 'external', 'grouped' ) ) ) {
			return '';
		}
		$pid = $product->get_id();
		if ( isset( self::$rendered[ $pid ] ) ) {
			return '';
		}
		$groups = Eru_AOS_Groups::for_product( $product );
		if ( ! $groups ) {
			return '';
		}
		self::$rendered[ $pid ] = true;
		if ( ! wp_style_is( 'eru-aos', 'registered' ) ) {
			self::enqueue();
		}
		wp_enqueue_style( 'eru-aos' );
		wp_enqueue_script( 'eru-aos' );

		$variable = $product->is_type( 'variable' );
		$base     = $variable ? null : (float) wc_get_price_to_display( $product );
		$regular  = $variable || '' === (string) $product->get_regular_price() ? $base : (float) wc_get_price_to_display( $product, array( 'price' => $product->get_regular_price() ) );
		$pct_reg  = 'regular' === get_option( 'eru_aos_percent_base', 'current' );
		$pb       = $pct_reg ? $regular : $base;

		$config = array(
			'product'     => $pid,
			'base'        => $base,
			'regular'     => $regular,
			'percentBase' => $pct_reg ? 'regular' : 'current',
			'live'        => 'no' !== get_option( 'eru_aos_live_total', 'yes' ),
			'options'     => array(),
		);
		foreach ( $groups as $g ) {
			foreach ( $g['options'] as $o ) {
				$config['options'][ $g['id'] . ':' . $o['id'] ] = array(
					'type'   => $o['price_type'],
					'amount' => 'percent' === $o['price_type'] ? (float) $o['amount'] : (float) wc_get_price_to_display( $product, array( 'price' => $o['amount'] ) ),
					'charge' => $o['charge'],
				);
			}
		}

		ob_start();
		wc_get_template(
			'addon-services.php',
			array(
				'product'     => $product,
				'groups'      => $groups,
				'posted'      => Eru_AOS_Cart::posted( $pid ),
				'show_prices' => 'no' !== get_option( 'eru_aos_show_prices', 'yes' ),
				'pb'          => $pb,
				'config'      => $config,
			),
			'product-add-on-services-for-woocommerce/',
			ERU_AOS_PATH . 'templates/'
		);
		return (string) ob_get_clean();
	}
}
