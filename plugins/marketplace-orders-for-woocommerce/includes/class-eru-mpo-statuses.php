<?php
/**
 * Marketplace order statuses and the forward-only rank model.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Logical statuses (key → rank): new 10, accepted 20, ready 30, shipped 40, delivered 50, returned 60,
 * cancelled 70. An order only moves to a higher rank, and never out of cancelled or returned. The logical status
 * lives in order meta _eru_mpo_status, so the rank model works in both status modes:
 *
 * - own (default): WooCommerce statuses wc-eru-mpo-<key> ("Pazaryeri: Yeni" …) are registered and used.
 * - core: the logical status is mapped to a core status (filter eru_mpo_status_core_map): new/accepted/ready →
 *   processing, shipped/delivered → completed, cancelled → cancelled, returned → refunded.
 *
 * Legacy statuses of the older public Trendyol/Hepsiburada plugins (trendyol-*, hepsiburada-*) are registered
 * read-only while legacy data exists and the legacy plugin is not active, so orders the migrator has not reached
 * yet stay visible in the order list; the migrator moves them to the statuses above.
 */
class Eru_MPO_Statuses {

	const RANK     = array(
		'new'       => 10,
		'accepted'  => 20,
		'ready'     => 30,
		'shipped'   => 40,
		'delivered' => 50,
		'returned'  => 60,
		'cancelled' => 70,
	);
	const TERMINAL = array( 'cancelled', 'returned' );
	const META     = '_eru_mpo_status';
	/** The shop's last hand move (logical key); "cancelled" keeps a hand-cancelled order from being revived. */
	const HAND     = '_eru_mpo_hand_status';
	/** Logical statuses whose eru_mpo_order_status_advanced action already fired for the order. */
	const FIRED = '_eru_mpo_status_fired';

	/** Legacy slug (without wc-) → logical key. */
	const LEGACY = array(
		'trendyol-yeni'     => 'new',
		'trendyol-alindi'   => 'accepted',
		'trendyol-hazir'    => 'ready',
		'trendyol-kargo'    => 'shipped',
		'trendyol-teslim'   => 'delivered',
		'trendyol-iptal'    => 'cancelled',
		'trendyol-iade'     => 'returned',
		'hepsiburada-yeni'  => 'new',
		'hepsiburada-hazir' => 'ready',
		'hepsiburada-iptal' => 'cancelled',
	);

	/** @var bool Legacy statuses registered in this request. */
	private static $legacy = false;

	/** Hooks. */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ), 9 );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'add_to_list' ) );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 5, 4 );
	}

	/**
	 * Logical key for a status the shop set by hand: an own (or legacy) slug as it is, core cancelled → cancelled,
	 * core refunded → returned; in core status mode also the lowest-ranked key a core status stands for (completed →
	 * shipped, processing → new). '' = no logical meaning (e.g. on-hold): the logical status is left alone.
	 *
	 * @param string $wc WooCommerce status without "wc-".
	 * @return string
	 */
	public static function key_for_hand_status( $wc ) {
		$wc = (string) $wc;
		$k  = self::key_from_wc_status( $wc );
		if ( '' !== $k ) {
			return $k;
		}
		if ( 'cancelled' === $wc ) {
			return 'cancelled';
		}
		if ( 'refunded' === $wc ) {
			return 'returned';
		}
		if ( 'core' === Eru_MPO_Settings::status_mode() ) {
			$ranks = self::RANK;
			asort( $ranks );
			foreach ( array_keys( $ranks ) as $key ) {
				if ( self::wc_status_for( $key ) === $wc ) {
					return $key;
				}
			}
		}
		return '';
	}

	/**
	 * woocommerce_order_status_changed for marketplace orders the SHOP moves (wp-admin, bulk actions, another plugin):
	 * skipped while this plugin saves an order (its own moves already set the logical status).
	 *
	 * - The logical status (_eru_mpo_status) follows the shop's choice, so later marketplace updates and the
	 *   acknowledgement compare against what the shop sees: a hand move is never undone by an older remote status.
	 * - A hand cancel (own "Pazaryeri: İptal" or core "İptal edildi") runs after_move(): the stock this order reduced
	 *   is restored once (WooCommerce's own restore on core cancelled clears the same flag, so never twice) and the
	 *   cancel action fires once. It is remembered (_eru_mpo_hand_status = cancelled): the marketplace never reopens
	 *   such an order; an update that would move it on only flags it for review with one note.
	 * - The shop reopening a cancelled order by hand reduces the stock again in "reduce" mode (once, flag-guarded).
	 *
	 * @param int           $order_id Order id.
	 * @param string        $from     Old status.
	 * @param string        $to       New status.
	 * @param WC_Order|null $order    Order.
	 */
	public static function on_status_changed( $order_id, $from, $to, $order = null ) {
		if ( Eru_MPO_Isolated_Save::active() ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order || ! Eru_MPO_Stock::is_marketplace_order( $order ) ) {
			return;
		}
		$key = self::key_for_hand_status( (string) $to );
		if ( '' === $key ) {
			return;
		}
		$prev = self::current( $order );
		if ( $prev === $key ) {
			return;
		}
		$order->update_meta_data( self::META, $key );
		$order->update_meta_data( self::HAND, $key );
		self::save_meta( $order );
		/* translators: 1: earlier logical status, 2: new logical status */
		Eru_MPO_Logger::info( sprintf( __( 'Durum mağazada elle değiştirildi (%1$s → %2$s); pazaryeri güncellemeleri bundan geriye götürmez.', 'marketplace-orders-for-woocommerce' ), '' !== $prev ? $prev : '-', $key ), (string) $order->get_meta( '_eru_mpo_marketplace' ), (string) $order->get_meta( '_eru_mpo_remote_ref' ) );
		if ( 'cancelled' === $key ) {
			self::after_move( $order, $prev, 'cancelled' );
		} elseif ( 'cancelled' === $prev && ! in_array( $key, self::TERMINAL, true ) ) {
			Eru_MPO_Stock::reduce( $order );
		}
	}

	/** @param string $key Key. @return bool Known logical status. */
	public static function is_key( $key ) {
		return isset( self::RANK[ (string) $key ] );
	}

	/** @param string $key Key. @return int Rank (0 = unknown). */
	public static function rank( $key ) {
		return self::RANK[ (string) $key ] ?? 0;
	}

	/** @return array<string,string> Logical key → Turkish label. */
	public static function labels() {
		return array(
			'new'       => __( 'Pazaryeri: Yeni', 'marketplace-orders-for-woocommerce' ),
			'accepted'  => __( 'Pazaryeri: Onaylandı', 'marketplace-orders-for-woocommerce' ),
			'ready'     => __( 'Pazaryeri: Hazırlanıyor', 'marketplace-orders-for-woocommerce' ),
			'shipped'   => __( 'Pazaryeri: Kargoda', 'marketplace-orders-for-woocommerce' ),
			'delivered' => __( 'Pazaryeri: Teslim edildi', 'marketplace-orders-for-woocommerce' ),
			'returned'  => __( 'Pazaryeri: İade', 'marketplace-orders-for-woocommerce' ),
			'cancelled' => __( 'Pazaryeri: İptal', 'marketplace-orders-for-woocommerce' ),
		);
	}

	/** @param string $key Logical key. @return string Own slug without "wc-" (≤ 17 chars, so wc- slug ≤ 20). */
	public static function slug( $key ) {
		return 'eru-mpo-' . $key;
	}

	/** @return array<string,string> Logical key → core status. */
	public static function core_map() {
		$map = apply_filters(
			'eru_mpo_status_core_map',
			array(
				'new'       => 'processing',
				'accepted'  => 'processing',
				'ready'     => 'processing',
				'shipped'   => 'completed',
				'delivered' => 'completed',
				'returned'  => 'refunded',
				'cancelled' => 'cancelled',
			)
		);
		return is_array( $map ) ? $map : array();
	}

	/** @param string $key Logical key. @return string WooCommerce status (without wc-) for the current mode. */
	public static function wc_status_for( $key ) {
		if ( 'core' === Eru_MPO_Settings::status_mode() ) {
			$map = self::core_map();
			return isset( $map[ $key ] ) ? (string) $map[ $key ] : 'processing';
		}
		return self::slug( $key );
	}

	/** @param string $wc_status Status without wc-. @return string Logical key, or '' when not a marketplace status. */
	public static function key_from_wc_status( $wc_status ) {
		$s = (string) $wc_status;
		if ( 0 === strpos( $s, 'eru-mpo-' ) && self::is_key( substr( $s, 8 ) ) ) {
			return substr( $s, 8 );
		}
		return self::LEGACY[ $s ] ?? '';
	}

	/** @param WC_Order $order Order. @return string Current logical status ('' when unknown). */
	public static function current( $order ) {
		$k = (string) $order->get_meta( self::META );
		return self::is_key( $k ) ? $k : self::key_from_wc_status( $order->get_status() );
	}

	/** @return bool $to is a valid forward step from $from. */
	public static function can_advance( $from, $to ) {
		if ( ! self::is_key( $to ) ) {
			return false;
		}
		if ( '' === (string) $from || ! self::is_key( $from ) ) {
			return true;
		}
		if ( in_array( $from, self::TERMINAL, true ) ) {
			return false;
		}
		return self::rank( $to ) > self::rank( $from );
	}

	/**
	 * Sets the logical status and the WooCommerce status on an order that is about to be saved (no save here).
	 *
	 * @param WC_Order $order Order.
	 * @param string   $key   Logical key.
	 * @param string   $note  Status note.
	 */
	public static function prime( $order, $key, $note = '' ) {
		$order->update_meta_data( self::META, $key );
		$order->set_status( self::wc_status_for( $key ), $note );
	}

	/**
	 * Moves an order forward and saves it isolated, then runs after_move() (stock restore on cancel, the
	 * eru_mpo_order_status_advanced action once).
	 *
	 * When the save throws, the stored status decides. If the new status is already stored (a listener failed
	 * after the row was written, e.g. on woocommerce_update_order), the move counts as done: the logical status is
	 * made sure in meta and after_move() runs. Otherwise nothing else happens and false is returned. Either way
	 * $order is replaced by a fresh copy read from the database, so the caller never saves an object that still
	 * carries the failed change; callers stop working on the order when false comes back.
	 *
	 * @param WC_Order $order Order, by reference (pending meta changes are saved with it).
	 * @param string   $to    Logical key.
	 * @param string   $note  Status note.
	 * @return bool Moved.
	 */
	public static function advance( &$order, $to, $note = '' ) {
		$from = self::current( $order );
		if ( ! self::can_advance( $from, $to ) ) {
			return false;
		}
		self::prime( $order, $to, $note );
		try {
			Eru_MPO_Isolated_Save::save( $order );
		} catch ( Eru_MPO_Listener_Error $le ) {
			// Saved; a listener failed afterwards. The move stands.
			/* translators: %s: error */
			Eru_MPO_Logger::error( sprintf( __( 'Durum kaydedildi, ardından bir eklenti hata verdi: %s', 'marketplace-orders-for-woocommerce' ), $le->getMessage() ), (string) $order->get_meta( '_eru_mpo_marketplace' ), (string) $order->get_meta( '_eru_mpo_remote_ref' ) );
		} catch ( Throwable $e ) {
			$id  = (int) $order->get_id();
			$mp  = (string) $order->get_meta( '_eru_mpo_marketplace' );
			$ref = (string) $order->get_meta( '_eru_mpo_remote_ref' );
			Eru_MPO_Importer::forget_order( $id );
			$fresh = $id ? wc_get_order( $id ) : null;
			if ( $fresh && Eru_MPO_Importer::stored_status( $id ) === self::wc_status_for( $to ) ) {
				/* translators: 1: status, 2: error */
				Eru_MPO_Logger::warning( sprintf( __( 'Durum %1$s olarak kaydedildi ancak kayıt bir hatayla bitti: %2$s', 'marketplace-orders-for-woocommerce' ), $to, $e->getMessage() ), $mp, $ref );
				$order = $fresh;
				if ( (string) $order->get_meta( self::META ) !== $to ) {
					$order->update_meta_data( self::META, $to );
					self::save_meta( $order );
				}
			} else {
				/* translators: 1: status, 2: error */
				Eru_MPO_Logger::error( sprintf( __( 'Durum %1$s olarak kaydedilemedi: %2$s', 'marketplace-orders-for-woocommerce' ), $to, $e->getMessage() ), $mp, $ref );
				if ( $fresh ) {
					$order = $fresh;
				}
				return false;
			}
		}
		self::after_move( $order, $from, $to );
		return true;
	}

	/**
	 * What follows a stored move, idempotent: a move to cancelled restores the stock this order reduced (guarded by
	 * the order's "stock reduced" flag), and eru_mpo_order_status_advanced( $order, $from, $to ) fires once per
	 * logical status (remembered in _eru_mpo_status_fired). Used by advance() and by the migrator's status repair.
	 *
	 * @param WC_Order $order Saved order.
	 * @param string   $from  Previous logical status.
	 * @param string   $to    New logical status.
	 */
	public static function after_move( $order, $from, $to ) {
		if ( 'cancelled' === $to ) {
			Eru_MPO_Stock::restore( $order );
		}
		$fired = $order->get_meta( self::FIRED );
		$fired = is_array( $fired ) ? $fired : array();
		if ( in_array( $to, $fired, true ) ) {
			return;
		}
		$fired[] = $to;
		$order->update_meta_data( self::FIRED, $fired );
		self::save_meta( $order );
		do_action( 'eru_mpo_order_status_advanced', $order, $from, $to );
	}

	/** Saves pending meta only (no order save hooks of ours); a failure is logged, never thrown. */
	private static function save_meta( $order ) {
		try {
			$order->save_meta_data();
		} catch ( Throwable $e ) {
			Eru_MPO_Logger::error( $e->getMessage(), (string) $order->get_meta( '_eru_mpo_marketplace' ), (string) $order->get_meta( '_eru_mpo_remote_ref' ) );
		}
	}

	/** Registers the own statuses (and, while needed, the legacy ones). */
	public static function register() {
		foreach ( self::labels() as $key => $label ) {
			register_post_status(
				'wc-' . self::slug( $key ),
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => self::count_label( $label ),
				)
			);
		}
		if ( array() !== Eru_MPO_Migrator::legacy_active() ) {
			// The legacy plugin registers its own statuses and may put orders into them again: remember that legacy
			// data exists, so its statuses stay visible here after it is deactivated (until a migration clears it).
			if ( ! Eru_MPO_Migrator::legacy_present() ) {
				update_option( Eru_MPO_Migrator::FLAG, 'yes', true );
			}
		} elseif ( Eru_MPO_Migrator::legacy_present() ) {
			self::register_legacy_statuses();
		}
	}

	/** Registers the legacy statuses so legacy orders stay visible until migrated (idempotent). */
	public static function register_legacy_statuses() {
		if ( self::$legacy ) {
			return;
		}
		self::$legacy = true;
		foreach ( self::legacy_labels() as $slug => $label ) {
			register_post_status(
				'wc-' . $slug,
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'label_count'               => self::count_label( $label ),
				)
			);
		}
	}

	/**
	 * Nooped plural for the status list ("Label (3)"); the label is translated already.
	 *
	 * @param string $label Label.
	 * @return array
	 */
	private static function count_label( $label ) {
		$t = $label . ' <span class="count">(%s)</span>';
		return array(
			0          => $t,
			1          => $t,
			'singular' => $t,
			'plural'   => $t,
			'context'  => null,
			'domain'   => null,
		);
	}

	/** @return array<string,string> Legacy slug → label. */
	public static function legacy_labels() {
		$names = array(
			'new'       => __( 'Yeni', 'marketplace-orders-for-woocommerce' ),
			'accepted'  => __( 'Alındı', 'marketplace-orders-for-woocommerce' ),
			'ready'     => __( 'Hazırlanıyor', 'marketplace-orders-for-woocommerce' ),
			'shipped'   => __( 'Kargoda', 'marketplace-orders-for-woocommerce' ),
			'delivered' => __( 'Teslim edildi', 'marketplace-orders-for-woocommerce' ),
			'cancelled' => __( 'İptal', 'marketplace-orders-for-woocommerce' ),
			'returned'  => __( 'İade', 'marketplace-orders-for-woocommerce' ),
		);
		$out   = array();
		foreach ( self::LEGACY as $slug => $key ) {
			$who = 0 === strpos( $slug, 'trendyol-' ) ? 'Trendyol' : 'Hepsiburada';
			/* translators: 1: marketplace, 2: status */
			$out[ $slug ] = sprintf( __( '%1$s (eski): %2$s', 'marketplace-orders-for-woocommerce' ), $who, $names[ $key ] );
		}
		return $out;
	}

	/**
	 * True on the order edit screen of an order that is not in a legacy status: legacy statuses are kept out of
	 * its status dropdown (and so cannot be saved onto it); lists and legacy orders still see them.
	 *
	 * @return bool
	 */
	private static function editing_non_legacy_order() {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return false;
		}
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'shop_order' ), true ) ) {
			return false;
		}
		$action = sanitize_key( wp_unslash( $_GET['action'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( 'woocommerce_page_wc-orders' === $screen->id && ! in_array( $action, array( 'edit', 'new' ), true ) ) {
			return false; // The order list (HPOS shares the screen id with the edit screen).
		}
		$id = absint( $_GET['id'] ?? ( $_GET['post'] ?? ( $_POST['post_ID'] ?? 0 ) ) ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! $id ) {
			return true; // New order.
		}
		$status = Eru_MPO_Importer::stored_status( $id );
		return ! isset( self::LEGACY[ $status ] );
	}

	/**
	 * Adds the statuses to WooCommerce's list (after "processing").
	 *
	 * @param array $list wc-slug => label.
	 * @return array
	 */
	public static function add_to_list( $list ) {
		$add = array();
		foreach ( self::labels() as $key => $label ) {
			$add[ 'wc-' . self::slug( $key ) ] = $label;
		}
		if ( self::$legacy && ! self::editing_non_legacy_order() ) {
			foreach ( self::legacy_labels() as $slug => $label ) {
				if ( ! isset( $list[ 'wc-' . $slug ] ) ) {
					$add[ 'wc-' . $slug ] = $label;
				}
			}
		}
		$out = array();
		foreach ( (array) $list as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'wc-processing' === $k ) {
				$out += $add;
			}
		}
		return $out + $add;
	}
}
