<?php
/**
 * Detects product changes (status, stock out, price) and e-mails the configured recipients.
 *
 * @package product-status-notifications-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PSN {

	const OPTION = 'eru_psn_settings';
	const TD     = 'product-status-notifications-for-woocommerce';

	/** @var array<int,array> Snapshots taken just before a product is saved. */
	private static $before = array();
	/** @var array<int,array> Queued changes per product, sent as one digest on shutdown. */
	private static $queue = array();
	/** @var bool */
	private static $hooked = false;

	/** @var array<int,bool> Product objects being created right now (spl_object_id => true). */
	private static $creating = array();

	public static function statuses() {
		return array(
			'publish' => __( 'Yayında', 'product-status-notifications-for-woocommerce' ),
			'draft'   => __( 'Taslak', 'product-status-notifications-for-woocommerce' ),
			'pending' => __( 'Beklemede', 'product-status-notifications-for-woocommerce' ),
			'private' => __( 'Özel', 'product-status-notifications-for-woocommerce' ),
			'trash'   => __( 'Çöp', 'product-status-notifications-for-woocommerce' ),
		);
	}

	public static function defaults() {
		return array(
			'events'       => array(),
			'status_from'  => 'publish',
			'status_to'    => 'draft',
			'recipients'   => '',
			'rules'        => '',
			'excluded'     => array(),
		);
	}

	public static function get_settings() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function sanitize_settings( $in ) {
		$in     = is_array( $in ) ? $in : array();
		$ev     = isset( $in['events'] ) && is_array( $in['events'] ) ? array_values( array_intersect( array_map( 'sanitize_key', $in['events'] ), array( 'status', 'stock_out', 'price' ) ) ) : array();
		$from   = isset( $in['status_from'] ) ? sanitize_key( $in['status_from'] ) : 'publish';
		$to     = isset( $in['status_to'] ) ? sanitize_key( $in['status_to'] ) : 'draft';
		$status = self::statuses();
		return array(
			'events'      => $ev,
			'status_from' => 'any' === $from || isset( $status[ $from ] ) ? $from : 'publish',
			'status_to'   => 'any' === $to || isset( $status[ $to ] ) ? $to : 'draft',
			'recipients'  => implode( ', ', self::parse_emails( isset( $in['recipients'] ) ? (string) $in['recipients'] : '' ) ),
			'rules'       => isset( $in['rules'] ) ? substr( sanitize_textarea_field( (string) $in['rules'] ), 0, 3000 ) : '',
			'excluded'    => isset( $in['excluded'] ) && is_array( $in['excluded'] ) ? array_values( array_unique( array_filter( array_map( 'absint', $in['excluded'] ) ) ) ) : array(),
		);
	}

	public static function parse_emails( $text ) {
		$out = array();
		foreach ( preg_split( '/[\s,;]+/', (string) $text ) as $e ) {
			$e = sanitize_email( trim( $e ) );
			if ( '' !== $e && is_email( $e ) ) {
				$out[ strtolower( $e ) ] = $e;
			}
		}
		return array_values( $out );
	}

	/**
	 * Rule lines: "taxonomy:term-slug = a@example.com, b@example.com". When one or more rules match a
	 * product, only those recipients are used; otherwise the default list (or the admin e-mail).
	 */
	public static function recipients_for( $product_id, $s = null ) {
		$s     = $s ? $s : self::get_settings();
		$extra = array();
		foreach ( preg_split( '/\R/', (string) $s['rules'] ) as $line ) {
			if ( false === strpos( $line, '=' ) || false === strpos( $line, ':' ) ) {
				continue;
			}
			list( $left, $mails ) = array_map( 'trim', explode( '=', $line, 2 ) );
			list( $tax, $slug )   = array_map( 'trim', explode( ':', $left, 2 ) );
			if ( '' === $tax || '' === $slug || ! taxonomy_exists( sanitize_key( $tax ) ) ) {
				continue;
			}
			if ( has_term( sanitize_title( $slug ), sanitize_key( $tax ), $product_id ) ) {
				$extra = array_merge( $extra, self::parse_emails( $mails ) );
			}
		}
		$list = $extra ? $extra : self::parse_emails( $s['recipients'] );
		if ( ! $list ) {
			$list = array( (string) get_option( 'admin_email' ) );
		}
		return array_values( array_unique( (array) apply_filters( 'eru_psn_recipients', $list, $product_id ) ) );
	}

	public static function init() {
		add_action( 'woocommerce_before_product_object_save', array( __CLASS__, 'snapshot' ) );
		add_action( 'woocommerce_before_product_variation_object_save', array( __CLASS__, 'snapshot' ) );
		add_action( 'woocommerce_update_product', array( __CLASS__, 'after_save' ), 20, 2 );
		add_action( 'woocommerce_update_product_variation', array( __CLASS__, 'after_save' ), 20, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'on_transition' ), 10, 3 );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_stock_status' ), 20, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_stock_status' ), 20, 3 );
		add_action( 'woocommerce_new_product', array( __CLASS__, 'created' ), 10, 2 );
		add_action( 'woocommerce_new_product_variation', array( __CLASS__, 'created' ), 10, 2 );
	}

	/** Creation finished: forget the marker. */
	public static function created( $id, $product = null ) {
		if ( $product instanceof WC_Product ) {
			unset( self::$creating[ spl_object_id( $product ) ] );
		}
	}

	/** Stock status flipped without a full product save (e.g. order-driven stock reduction). */
	public static function on_stock_status( $product_id, $status, $product = null ) {
		if ( 'outofstock' !== $status || ! self::enabled( 'stock_out' ) ) {
			return;
		}
		// A product created out of stock has not "run out".
		if ( $product instanceof WC_Product && isset( self::$creating[ spl_object_id( $product ) ] ) ) {
			return;
		}
		$product = $product instanceof WC_Product ? $product : wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		// Previous status: the snapshot taken before a full save, else the object's stored value
		// (the data store fires this action before apply_changes()).
		$id = $product->get_id();
		if ( isset( self::$before[ $id ] ) ) {
			$old = self::$before[ $id ]['stock'];
		} else {
			$data = $product->get_data();
			$old  = array_key_exists( 'stock_status', $product->get_changes() ) && isset( $data['stock_status'] ) ? (string) $data['stock_status'] : 'instock';
		}
		if ( 'outofstock' === $old ) {
			return;
		}
		self::queue( $product, 'stock_out', array( array( __( 'Stok durumu', 'product-status-notifications-for-woocommerce' ), self::stock_label( $old ), self::stock_label( 'outofstock' ) ) ) );
	}

	private static function enabled( $event ) {
		return in_array( $event, self::get_settings()['events'], true );
	}

	private static function state( $product ) {
		return array(
			'regular' => (string) $product->get_regular_price( 'edit' ),
			'sale'    => (string) $product->get_sale_price( 'edit' ),
			'stock'   => (string) $product->get_stock_status( 'edit' ),
		);
	}

	public static function snapshot( $product ) {
		if ( $product instanceof WC_Product && ! $product->get_id() ) {
			self::$creating[ spl_object_id( $product ) ] = true;
		}
		if ( ! ( self::enabled( 'stock_out' ) || self::enabled( 'price' ) ) || ! $product->get_id() ) {
			return;
		}
		$fresh = wc_get_product( $product->get_id() );
		if ( $fresh ) {
			self::$before[ $product->get_id() ] = self::state( $fresh );
		}
	}

	public static function after_save( $product_id, $product = null ) {
		if ( ! isset( self::$before[ $product_id ] ) ) {
			return;
		}
		$old     = self::$before[ $product_id ];
		unset( self::$before[ $product_id ] );
		$product = $product instanceof WC_Product ? $product : wc_get_product( $product_id );
		if ( ! $product ) {
			return;
		}
		$new = self::state( $product );
		if ( self::enabled( 'stock_out' ) && 'outofstock' === $new['stock'] && $old['stock'] !== $new['stock'] ) {
			self::queue( $product, 'stock_out', array( array( __( 'Stok durumu', 'product-status-notifications-for-woocommerce' ), self::stock_label( $old['stock'] ), self::stock_label( $new['stock'] ) ) ) );
		}
		if ( self::enabled( 'price' ) && ( $old['regular'] !== $new['regular'] || $old['sale'] !== $new['sale'] ) ) {
			$changes = array();
			if ( $old['regular'] !== $new['regular'] ) {
				$changes[] = array( __( 'Normal fiyat', 'product-status-notifications-for-woocommerce' ), self::price_label( $old['regular'] ), self::price_label( $new['regular'] ) );
			}
			if ( $old['sale'] !== $new['sale'] ) {
				$changes[] = array( __( 'İndirimli fiyat', 'product-status-notifications-for-woocommerce' ), self::price_label( $old['sale'] ), self::price_label( $new['sale'] ) );
			}
			self::queue( $product, 'price', $changes );
		}
	}

	public static function on_transition( $new_status, $old_status, $post ) {
		if ( 'product' !== $post->post_type || $new_status === $old_status || in_array( $old_status, array( 'auto-draft', 'new' ), true ) || ! self::enabled( 'status' ) ) {
			return;
		}
		$s = self::get_settings();
		if ( ( 'any' !== $s['status_from'] && $old_status !== $s['status_from'] ) || ( 'any' !== $s['status_to'] && $new_status !== $s['status_to'] ) ) {
			return;
		}
		$product = wc_get_product( $post->ID );
		if ( $product ) {
			$st = self::statuses();
			self::queue( $product, 'status', array( array( __( 'Yayın durumu', 'product-status-notifications-for-woocommerce' ), isset( $st[ $old_status ] ) ? $st[ $old_status ] : $old_status, isset( $st[ $new_status ] ) ? $st[ $new_status ] : $new_status ) ) );
		}
	}

	private static function stock_label( $s ) {
		$map = array( 'instock' => __( 'Stokta', 'product-status-notifications-for-woocommerce' ), 'outofstock' => __( 'Stokta yok', 'product-status-notifications-for-woocommerce' ), 'onbackorder' => __( 'Ön sipariş', 'product-status-notifications-for-woocommerce' ) );
		return isset( $map[ $s ] ) ? $map[ $s ] : (string) $s;
	}

	private static function price_label( $v ) {
		return '' === $v ? __( '(yok)', 'product-status-notifications-for-woocommerce' ) : html_entity_decode( wp_strip_all_tags( wc_price( (float) $v ) ), ENT_QUOTES, 'UTF-8' );
	}

	/** Queue one change set; nothing is sent until flush() runs on shutdown. */
	private static function queue( $product, $event, array $changes ) {
		if ( defined( 'WP_IMPORTING' ) && WP_IMPORTING && apply_filters( 'eru_psn_skip_import', true ) ) {
			return;
		}
		$id      = $product->get_id();
		$root_id = $product->is_type( 'variation' ) ? $product->get_parent_id() : $id;
		$s       = self::get_settings();
		foreach ( (array) $s['excluded'] as $term_id ) {
			if ( has_term( (int) $term_id, 'product_cat', $root_id ) ) {
				return;
			}
		}
		if ( isset( self::$queue[ $id ]['events'][ $event ] ) ) {
			return;
		}
		if ( ! isset( self::$queue[ $id ] ) ) {
			self::$queue[ $id ] = array(
				'name'    => wp_strip_all_tags( $product->get_name() ),
				'sku'     => (string) $product->get_sku(),
				'root_id' => $root_id,
				'events'  => array(),
			);
		}
		self::$queue[ $id ]['events'][ $event ] = $changes;
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush' ), 5 );
		}
	}

	/** Send the queued changes: one digest mail per distinct recipient list. */
	public static function flush() {
		$queue        = self::$queue;
		self::$queue  = array();
		// Re-arm: anything queued after this flush schedules its own shutdown run.
		remove_action( 'shutdown', array( __CLASS__, 'flush' ), 5 );
		self::$hooked = false;
		if ( ! $queue ) {
			return;
		}
		$s      = self::get_settings();
		$groups = array();
		foreach ( $queue as $item ) {
			$to  = self::recipients_for( $item['root_id'], $s );
			$key = implode( ',', $to );
			if ( $to ) {
				$groups[ $key ]['to']      = $to;
				$groups[ $key ]['items'][] = $item;
			}
		}
		$max  = max( 1, (int) apply_filters( 'eru_psn_max_items', 50 ) );
		$user = wp_get_current_user();
		foreach ( $groups as $g ) {
			$items = $g['items'];
			$extra = max( 0, count( $items ) - $max );
			$rows  = '';
			foreach ( array_slice( $items, 0, $max ) as $item ) {
				$edit = get_edit_post_link( $item['root_id'], 'raw' );
				$head = esc_html( $item['name'] ) . ( '' !== $item['sku'] ? ' <span style="color:#666">(' . esc_html( $item['sku'] ) . ')</span>' : '' );
				if ( $edit ) {
					$head = '<a href="' . esc_url( $edit ) . '" style="color:#1f2933">' . $head . '</a>';
				}
				foreach ( $item['events'] as $event => $changes ) {
					foreach ( $changes as $c ) {
						$rows .= '<tr><td style="padding:6px 10px;border-bottom:1px solid #eee">' . $head . '</td><td style="padding:6px 10px;border-bottom:1px solid #eee">' . esc_html( $c[0] ) . '</td><td style="padding:6px 10px;border-bottom:1px solid #eee">' . esc_html( $c[1] ) . '</td><td style="padding:6px 10px;border-bottom:1px solid #eee"><strong>' . esc_html( $c[2] ) . '</strong></td></tr>';
					}
				}
			}
			$th   = 'padding:6px 10px;background:#f4f4f5;text-align:left';
			$body = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222;max-width:720px">'
				. '<table style="border-collapse:collapse;width:100%"><tr><th style="' . $th . '">' . esc_html__( 'Ürün', 'product-status-notifications-for-woocommerce' ) . '</th><th style="' . $th . '">' . esc_html__( 'Alan', 'product-status-notifications-for-woocommerce' ) . '</th><th style="' . $th . '">' . esc_html__( 'Önce', 'product-status-notifications-for-woocommerce' ) . '</th><th style="' . $th . '">' . esc_html__( 'Sonra', 'product-status-notifications-for-woocommerce' ) . '</th></tr>' . $rows . '</table>'
				. ( $extra ? '<p>' . esc_html( sprintf( /* translators: %d: number of products not listed */ __( '+%d ürün daha', 'product-status-notifications-for-woocommerce' ), $extra ) ) . '</p>' : '' )
				. ( $user->exists() ? '<p style="color:#666">' . esc_html( sprintf( /* translators: %s: user display name */ __( 'Değişikliği yapan: %s', 'product-status-notifications-for-woocommerce' ), $user->display_name ) ) . '</p>' : '' ) . '</div>';
			$count   = count( $items );
			$first   = $items[0]['name'];
			$subject = 1 === $count
				/* translators: 1: site name, 2: product name */
				? sprintf( __( '[%1$s] Ürün güncellendi: %2$s', 'product-status-notifications-for-woocommerce' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $first )
				/* translators: 1: site name, 2: number of products */
				: sprintf( __( '[%1$s] %2$d üründe değişiklik', 'product-status-notifications-for-woocommerce' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ), $count );
			wp_mail( $g['to'], $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
		}
	}
}
