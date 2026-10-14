<?php
/**
 * Order list: colour badges, bulk actions, quick action buttons, grouped tab boxes (HPOS and legacy screens).
 *
 * @package custom-order-statuses-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_COS_Orders {

	const HIDE_META = 'eru_cos_hide_groups';

	public static function init(): void {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'styles' ) );
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( __CLASS__, 'bulk_actions' ), 20 );
		add_filter( 'bulk_actions-edit-shop_order', array( __CLASS__, 'bulk_actions' ), 20 );
		add_filter( 'woocommerce_admin_order_actions', array( __CLASS__, 'quick_actions' ), 20, 2 );
		add_filter( 'views_woocommerce_page_wc-orders', array( __CLASS__, 'group_tabs' ), 99 );
		add_filter( 'views_edit-shop_order', array( __CLASS__, 'group_tabs' ), 99 );
		add_filter( 'woocommerce_shop_order_list_table_default_statuses', array( __CLASS__, 'hpos_group_filter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'legacy_group_filter' ) );
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'keep_group_field' ) );
		add_action( 'restrict_manage_posts', array( __CLASS__, 'keep_group_field' ) );
		add_action( 'admin_post_eru_cos_toggle_groups', array( __CLASS__, 'toggle_groups' ) );
	}

	private static function is_order_screen(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'edit-shop_order', 'shop_order' ), true );
	}

	public static function styles(): void {
		if ( ! self::is_order_screen() ) {
			return;
		}
		$css = '';
		foreach ( Eru_COS_Statuses::badge_colors() as $slug => $c ) {
			$bg  = sanitize_hex_color( $c[0] ?? '' );
			$fg  = sanitize_hex_color( $c[1] ?? '' );
			$cls = sanitize_html_class( $slug );
			if ( $bg && $fg && $cls ) {
				$css .= ".order-status.status-{$cls}{background:{$bg};color:{$fg}}";
				$css .= ".wc-action-button-eru-cos-{$cls}::after{content:\"\\f147\";color:{$bg}}";
			}
		}
		wp_enqueue_style( 'eru-cos-orders', ERU_COS_URL . 'assets/orders.css', array(), ERU_COS_VERSION );
		wp_add_inline_style( 'eru-cos-orders', $css );
	}

	/** "Durumu değiştir: <label>" — WooCommerce's list tables handle mark_<status> (capability, nonce, notice) themselves. */
	public static function bulk_actions( $actions ) {
		foreach ( Eru_COS_Statuses::all() as $slug => $s ) {
			if ( $s['bulk_action'] && '' === $s['pending_delete'] ) {
				/* translators: %s: status name */
				$actions[ 'mark_' . $slug ] = sprintf( __( 'Durumu değiştir: %s', 'custom-order-statuses-for-woocommerce' ), $s['label'] );
			}
		}
		return $actions;
	}

	/** Quick button on orders in the chosen source status; uses WooCommerce's nonce-checked mark-status AJAX. */
	public static function quick_actions( $actions, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $actions;
		}
		foreach ( Eru_COS_Statuses::all() as $slug => $s ) {
			if ( $s['show_action_button'] && '' === $s['pending_delete'] && $order->has_status( $s['action_from'] ) ) {
				$actions[ 'eru-cos-' . $slug ] = array(
					'url'    => wp_nonce_url( admin_url( 'admin-ajax.php?action=woocommerce_mark_order_status&status=' . rawurlencode( $slug ) . '&order_id=' . $order->get_id() ), 'woocommerce-mark-order-status' ),
					'name'   => $s['label'],
					'action' => 'eru-cos-' . $slug,
				);
			}
		}
		return $actions;
	}

	/* ---------- groups ---------- */

	/** group id => [ label, statuses[] ]; only groups that hold at least one status. */
	public static function group_map(): array {
		$groups = Eru_COS_Statuses::groups();
		$map    = array();
		foreach ( Eru_COS_Statuses::all() as $slug => $s ) {
			if ( '' !== $s['group'] && isset( $groups[ $s['group'] ] ) ) {
				$map[ $s['group'] ]['label']      = $groups[ $s['group'] ];
				$map[ $s['group'] ]['statuses'][] = $slug;
			}
		}
		return array_intersect_key( array_replace( $groups, $map ), $map );
	}

	private static function current_group(): string {
		$g = isset( $_GET['eru_cos_group'] ) ? sanitize_key( wp_unslash( $_GET['eru_cos_group'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only list filter.
		return isset( self::group_map()[ $g ] ) ? $g : '';
	}

	private static function enabled(): bool {
		return (bool) Eru_COS_Statuses::settings()['group_tabs'] && ! get_user_meta( get_current_user_id(), self::HIDE_META, true );
	}

	/** Status counts (all order statuses), cached until an order changes status. */
	public static function counts(): array {
		$counts = get_transient( Eru_COS_Statuses::COUNTS_CACHE );
		if ( ! is_array( $counts ) ) {
			$counts = array();
			foreach ( Eru_COS_Statuses::all() as $slug => $s ) {
				$counts[ $slug ] = (int) wc_orders_count( $slug, 'shop_order' );
			}
			set_transient( Eru_COS_Statuses::COUNTS_CACHE, $counts, HOUR_IN_SECONDS );
		}
		return $counts;
	}

	/** Prints the group boxes just above the status links (the views filter runs right before they are printed). */
	public static function group_tabs( $views ) {
		static $done = false;
		$map = self::group_map();
		if ( $done || ! current_user_can( 'edit_shop_orders' ) ) {
			return $views;
		}
		$done   = true;
		$hpos   = 'woocommerce_page_wc-orders' === get_current_screen()->id;
		$base   = $hpos ? admin_url( 'admin.php?page=wc-orders' ) : admin_url( 'edit.php?post_type=shop_order' );
		$toggle = wp_nonce_url( admin_url( 'admin-post.php?action=eru_cos_toggle_groups' ), 'eru_cos_toggle_groups' );
		if ( ! $map || ! Eru_COS_Statuses::settings()['group_tabs'] ) {
			return $views;
		}
		if ( ! self::enabled() ) {
			echo '<p class="eru-cos-groups-off"><a href="' . esc_url( $toggle ) . '">' . esc_html__( 'Durum gruplarını göster', 'custom-order-statuses-for-woocommerce' ) . '</a></p>';
			return $views;
		}
		$counts   = self::counts();
		$current  = self::current_group();
		$statuses = Eru_COS_Statuses::all();
		echo '<div class="eru-cos-groups">';
		foreach ( $map as $id => $g ) {
			$total = 0;
			foreach ( $g['statuses'] as $slug ) {
				$total += $counts[ $slug ] ?? 0;
			}
			echo '<div class="eru-cos-group' . ( $current === $id ? ' is-current' : '' ) . '">';
			echo '<a class="eru-cos-group__title" href="' . esc_url( add_query_arg( 'eru_cos_group', $id, $base ) ) . '">' . esc_html( $g['label'] ) . ' <span class="count">(' . esc_html( number_format_i18n( $total ) ) . ')</span></a>';
			echo '<ul>';
			foreach ( $g['statuses'] as $slug ) {
				$bg = sanitize_hex_color( $statuses[ $slug ]['color'] ) ?: '#6b7280';
				echo '<li><a href="' . esc_url( add_query_arg( $hpos ? 'status' : 'post_status', 'wc-' . $slug, $base ) ) . '"><span class="eru-cos-dot" style="background:' . esc_attr( $bg ) . '"></span>' . esc_html( $statuses[ $slug ]['label'] ) . ' <span class="count">(' . esc_html( number_format_i18n( $counts[ $slug ] ?? 0 ) ) . ')</span></a></li>';
			}
			echo '</ul></div>';
		}
		echo '<a class="eru-cos-groups__hide" href="' . esc_url( $toggle ) . '">' . esc_html__( 'Grupları gizle', 'custom-order-statuses-for-woocommerce' ) . '</a>';
		echo '</div>';
		return $views;
	}

	public static function hpos_group_filter( $statuses ) {
		$g = self::current_group();
		return $g ? array_map( fn( $s ) => 'wc-' . $s, self::group_map()[ $g ]['statuses'] ) : $statuses;
	}

	public static function legacy_group_filter( $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'shop_order' !== $query->get( 'post_type' ) || ! empty( $_GET['post_status'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$g = self::current_group();
		if ( $g ) {
			$query->set( 'post_status', array_map( fn( $s ) => 'wc-' . $s, self::group_map()[ $g ]['statuses'] ) );
		}
	}

	/** Keeps the group while searching or filtering. */
	public static function keep_group_field(): void {
		$g = self::current_group();
		if ( $g && self::is_order_screen() ) {
			echo '<input type="hidden" name="eru_cos_group" value="' . esc_attr( $g ) . '">';
		}
	}

	public static function toggle_groups(): void {
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'custom-order-statuses-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_cos_toggle_groups' );
		$uid = get_current_user_id();
		if ( get_user_meta( $uid, self::HIDE_META, true ) ) {
			delete_user_meta( $uid, self::HIDE_META );
		} else {
			update_user_meta( $uid, self::HIDE_META, 1 );
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=wc-orders' ) );
		exit;
	}
}
