<?php
/**
 * Order list: a "Pazaryeri" column (badge + the marketplace's order number) and a marketplace filter, on the HPOS
 * list (wc-orders) and on the classic posts list.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The filter (?eru_mpo_mp=<key>, or _any for every marketplace order) adds a meta query on _eru_mpo_marketplace;
 * unknown values are ignored. The value is never put into SQL by hand: HPOS gets it through wc_get_orders()
 * query args, the classic list through WP_Query's meta_query.
 */
class Eru_MPO_Orders_List {

	const PARAM = 'eru_mpo_mp';
	const ANY   = '_any';

	/** Hooks (idempotent). */
	public static function init() {
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'render_cell' ), 10, 2 );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'filter_dropdown' ), 10, 2 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( __CLASS__, 'hpos_query_args' ) );
		add_filter( 'manage_edit-shop_order_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'classic_cell' ), 10, 2 );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'filter_dropdown' ), 10, 2 );
		add_filter( 'request', array( __CLASS__, 'classic_request' ) );
	}

	/** @param array $cols Columns. @return array With "Pazaryeri" after the order number. */
	public static function columns( $cols ) {
		$out = array();
		foreach ( (array) $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'order_number' === $k ) {
				$out['eru_mpo'] = __( 'Pazaryeri', 'marketplace-orders-for-woocommerce' );
			}
		}
		if ( ! isset( $out['eru_mpo'] ) ) {
			$out['eru_mpo'] = __( 'Pazaryeri', 'marketplace-orders-for-woocommerce' );
		}
		return $out;
	}

	/**
	 * @param string   $column Column.
	 * @param WC_Order $order  Order (HPOS list).
	 */
	public static function render_cell( $column, $order ) {
		if ( 'eru_mpo' !== $column ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order );
		$mp    = $order ? (string) $order->get_meta( '_eru_mpo_marketplace' ) : '';
		if ( '' === $mp ) {
			echo '<span class="eru-mpo-none" aria-hidden="true">&ndash;</span>';
			return;
		}
		$a      = Eru_MPO_Registry::get( $mp );
		$number = (string) $order->get_meta( '_eru_mpo_order_number' );
		echo '<span class="eru-mpo-badge eru-mpo-badge--' . esc_attr( sanitize_html_class( $mp ) ) . '">' . esc_html( $a ? $a->label() : $mp ) . '</span>';
		if ( '' !== $number ) {
			echo '<br><small class="eru-mpo-number">' . esc_html( $number ) . '</small>';
		}
		if ( 'yes' === (string) $order->get_meta( '_eru_mpo_review' ) ) {
			echo ' <span class="eru-mpo-review">' . esc_html__( 'İncelenmeli', 'marketplace-orders-for-woocommerce' ) . '</span>';
		}
	}

	/** Classic list. @param string $column Column. @param int $post_id Order id. */
	public static function classic_cell( $column, $post_id ) {
		if ( 'eru_mpo' === $column ) {
			self::render_cell( $column, wc_get_order( $post_id ) );
		}
	}

	/** @return string The requested filter value: a known marketplace key, "_any" or ''. */
	public static function selected() {
		$v = isset( $_GET[ self::PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter, read-only.
		if ( self::ANY === $v ) {
			return $v;
		}
		return '' !== $v && Eru_MPO_Registry::get( $v ) ? $v : '';
	}

	/** @param string $v Filter value. @return array Meta query clause. */
	private static function clause( $v ) {
		return self::ANY === $v
			? array(
				'key'     => '_eru_mpo_marketplace',
				'compare' => 'EXISTS',
			)
			: array(
				'key'   => '_eru_mpo_marketplace',
				'value' => $v,
			);
	}

	/**
	 * @param string $type  Order type (HPOS) or post type (classic).
	 * @param string $which top | bottom.
	 */
	public static function filter_dropdown( $type, $which = 'top' ) {
		if ( 'shop_order' !== $type || 'top' !== $which ) {
			return;
		}
		$cur = self::selected();
		echo '<label class="screen-reader-text" for="eru-mpo-filter">' . esc_html__( 'Pazaryerine göre süz', 'marketplace-orders-for-woocommerce' ) . '</label><select id="eru-mpo-filter" name="' . esc_attr( self::PARAM ) . '"><option value="">' . esc_html__( 'Tüm kanallar', 'marketplace-orders-for-woocommerce' ) . '</option><option value="' . esc_attr( self::ANY ) . '"' . selected( $cur, self::ANY, false ) . '>' . esc_html__( 'Tüm pazaryerleri', 'marketplace-orders-for-woocommerce' ) . '</option>';
		foreach ( Eru_MPO_Registry::all() as $k => $a ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $cur, $k, false ) . '>' . esc_html( $a->label() ) . '</option>';
		}
		echo '</select>';
	}

	/** HPOS list query. @param array $args wc_get_orders() args. @return array */
	public static function hpos_query_args( $args ) {
		$v = self::selected();
		if ( '' === $v ) {
			return $args;
		}
		$args['meta_query']   = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
		$args['meta_query'][] = self::clause( $v );
		return $args;
	}

	/** Classic list query vars. @param array $vars Query vars. @return array */
	public static function classic_request( $vars ) {
		if ( ! is_array( $vars ) || 'shop_order' !== ( $vars['post_type'] ?? '' ) ) {
			return $vars;
		}
		$v = self::selected();
		if ( '' === $v ) {
			return $vars;
		}
		$vars['meta_query']   = isset( $vars['meta_query'] ) && is_array( $vars['meta_query'] ) ? $vars['meta_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery
		$vars['meta_query'][] = self::clause( $v );
		return $vars;
	}
}
