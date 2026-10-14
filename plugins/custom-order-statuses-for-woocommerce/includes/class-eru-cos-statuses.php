<?php
/**
 * Status store, validation, registration and order semantics (paid, editable, downloads, stock).
 *
 * @package custom-order-statuses-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_COS_Statuses {

	const OPTION          = 'eru_cos_statuses';
	const GROUPS_OPTION   = 'eru_cos_groups';
	const SETTINGS_OPTION = 'eru_cos_settings';
	const COUNTS_CACHE    = 'eru_cos_group_counts';
	const MAX_SLUG        = 17; // post_status is varchar(20) and WooCommerce prefixes "wc-".
	const BATCH           = 50;
	const NOTICE          = 'eru_cos_notice';

	/** @var string[] Slugs whose per-status hooks are already attached in this request. */
	private static array $hooked = array();

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ), 10 );
		add_filter( 'wc_order_statuses', array( __CLASS__, 'wc_order_statuses' ), 20 );
		add_filter( 'woocommerce_order_is_paid_statuses', array( __CLASS__, 'paid_statuses' ) );
		add_filter( 'wc_order_is_editable', array( __CLASS__, 'is_editable' ), 10, 2 );
		add_filter( 'woocommerce_order_is_download_permitted', array( __CLASS__, 'is_download_permitted' ), 10, 2 );
		add_action( 'eru_cos_migrate_batch', array( __CLASS__, 'run_batch' ), 10, 2 );
		foreach ( array( 'woocommerce_order_status_changed', 'woocommerce_new_order', 'woocommerce_delete_order', 'woocommerce_trash_order', 'woocommerce_untrash_order' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'flush_counts' ) );
		}
	}

	/* ---------- data ---------- */

	public static function defaults(): array {
		return array(
			'slug'                => '',
			'label'               => '',
			'color'               => '#6b7280',
			'group'               => '',
			'is_paid'             => 0,
			'reduces_stock'       => 0,
			'editable'            => 0,
			'downloads_permitted' => 0,
			'bulk_action'         => 1,
			'show_action_button'  => 0,
			'action_from'         => 'processing',
			'email_customer'      => 0,
			'email_admin'         => 0,
			'email_subject'       => '',
			'email_heading'       => '',
			'email_body'          => '',
			'pending_delete'      => '',
		);
	}

	/** Saved statuses, in the saved order, keyed by slug (unfiltered). */
	public static function raw(): array {
		$saved = get_option( self::OPTION, array() );
		$out   = array();
		foreach ( is_array( $saved ) ? $saved : array() as $slug => $row ) {
			if ( is_array( $row ) && is_string( $slug ) && '' !== $slug ) {
				$out[ $slug ] = array_merge( self::defaults(), $row, array( 'slug' => $slug ) );
			}
		}
		return $out;
	}

	/** Statuses used at runtime (filterable). */
	public static function all(): array {
		$all = apply_filters( 'eru_cos_statuses', self::raw() );
		return is_array( $all ) ? $all : array();
	}

	public static function get( string $slug ): ?array {
		return self::all()[ $slug ] ?? null;
	}

	public static function groups(): array {
		$g = get_option( self::GROUPS_OPTION, array() );
		return is_array( $g ) ? array_filter( $g, 'is_string' ) : array();
	}

	public static function settings(): array {
		$s = get_option( self::SETTINGS_OPTION, array() );
		return array_merge(
			array(
				'group_tabs' => 1,
				'webhooks'   => 0,
			),
			is_array( $s ) ? $s : array()
		);
	}

	private static function save( array $statuses ): void {
		update_option( self::OPTION, $statuses, true );
		self::flush_counts();
	}

	public static function flush_counts(): void {
		delete_transient( self::COUNTS_CACHE );
	}

	/* ---------- slugs ---------- */

	/** Lower-case ASCII with Turkish letters folded (İ/ı → i, ş → s, ğ → g, ç → c, ö → o, ü → u). */
	public static function fold( string $text ): string {
		$text = strtr(
			$text,
			array(
				'İ' => 'i', 'I' => 'i', 'ı' => 'i', 'Ş' => 's', 'ş' => 's', 'Ğ' => 'g', 'ğ' => 'g',
				'Ç' => 'c', 'ç' => 'c', 'Ö' => 'o', 'ö' => 'o', 'Ü' => 'u', 'ü' => 'u',
			)
		);
		$text = strtolower( remove_accents( $text ) );
		$text = preg_replace( '/[^a-z0-9]+/', '-', $text );
		return trim( (string) $text, '-' );
	}

	/** Fits a folded slug into MAX_SLUG chars, cutting at a word boundary where possible. */
	private static function fit( string $slug, int $max = self::MAX_SLUG ): string {
		if ( strlen( $slug ) <= $max ) {
			return $slug;
		}
		$cut = substr( $slug, 0, $max );
		if ( '-' !== $slug[ $max ] ) {
			$dash = strrpos( $cut, '-' );
			if ( false !== $dash && $dash >= 3 ) {
				$cut = substr( $cut, 0, $dash );
			}
		}
		return trim( $cut, '-' );
	}

	/** Suggested unique slug for a label. */
	public static function make_slug( string $label, string $ignore = '' ): string {
		$base = self::fit( self::fold( $label ) );
		if ( '' === $base ) {
			$base = 'durum';
		}
		$slug = $base;
		for ( $i = 2; self::is_taken( $slug, $ignore ); $i++ ) {
			$suffix = '-' . $i;
			$slug   = rtrim( substr( $base, 0, self::MAX_SLUG - strlen( $suffix ) ), '-' ) . $suffix;
		}
		return $slug;
	}

	/** Core WordPress/WooCommerce statuses and any order status registered by someone else. */
	public static function reserved(): array {
		$core = array( 'pending', 'processing', 'on-hold', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft', 'draft', 'auto-draft', 'trash', 'publish', 'future', 'private', 'inherit', 'new', 'all' );
		$ours = array_keys( self::raw() );
		remove_filter( 'wc_order_statuses', array( __CLASS__, 'wc_order_statuses' ), 20 );
		$wc = function_exists( 'wc_get_order_statuses' ) ? array_keys( wc_get_order_statuses() ) : array();
		add_filter( 'wc_order_statuses', array( __CLASS__, 'wc_order_statuses' ), 20 );
		$wc = array_diff( $wc, array_map( fn( $s ) => 'wc-' . $s, $ours ) );
		foreach ( $wc as $key ) {
			$core[] = preg_replace( '/^wc-/', '', $key );
		}
		foreach ( get_post_stati( array(), 'objects' ) as $name => $obj ) {
			$s = preg_replace( '/^wc-/', '', (string) $name );
			if ( empty( $obj->eru_cos ) && ! in_array( $s, $ours, true ) ) {
				$core[] = $s;
			}
		}
		return array_values( array_unique( $core ) );
	}

	private static function is_taken( string $slug, string $ignore = '' ): bool {
		if ( $slug === $ignore ) {
			return false;
		}
		return isset( self::raw()[ $slug ] ) || in_array( $slug, self::reserved(), true );
	}

	/** @return string|WP_Error */
	public static function validate_slug( string $slug, string $ignore = '' ) {
		$slug = preg_replace( '/^wc-/', '', strtolower( trim( $slug ) ) );
		if ( '' === $slug || ! preg_match( '/^[a-z0-9]+(-[a-z0-9]+)*$/', $slug ) ) {
			return new WP_Error( 'eru_cos_slug_invalid', __( 'Kısa ad yalnızca küçük harf, rakam ve tire içerebilir.', 'custom-order-statuses-for-woocommerce' ) );
		}
		if ( strlen( $slug ) > self::MAX_SLUG ) {
			/* translators: %d: maximum number of characters */
			return new WP_Error( 'eru_cos_slug_long', sprintf( __( 'Kısa ad en çok %d karakter olabilir.', 'custom-order-statuses-for-woocommerce' ), self::MAX_SLUG ) );
		}
		if ( $slug !== $ignore && in_array( $slug, self::reserved(), true ) ) {
			return new WP_Error( 'eru_cos_slug_reserved', __( 'Bu kısa ad WooCommerce veya başka bir eklenti tarafından kullanılıyor.', 'custom-order-statuses-for-woocommerce' ) );
		}
		if ( $slug !== $ignore && isset( self::raw()[ $slug ] ) ) {
			return new WP_Error( 'eru_cos_slug_taken', __( 'Bu kısa adla bir durum zaten var.', 'custom-order-statuses-for-woocommerce' ) );
		}
		return $slug;
	}

	/* ---------- CRUD ---------- */

	private static function clean( array $in, array $base ): array {
		$out = $base;
		foreach ( array( 'is_paid', 'reduces_stock', 'editable', 'downloads_permitted', 'bulk_action', 'show_action_button', 'email_customer', 'email_admin' ) as $flag ) {
			if ( array_key_exists( $flag, $in ) ) {
				$out[ $flag ] = empty( $in[ $flag ] ) ? 0 : 1;
			}
		}
		if ( isset( $in['label'] ) ) {
			$out['label'] = sanitize_text_field( (string) $in['label'] );
		}
		if ( isset( $in['color'] ) ) {
			$out['color'] = sanitize_hex_color( (string) $in['color'] ) ?: $base['color'];
		}
		if ( isset( $in['group'] ) ) {
			$group        = sanitize_key( (string) $in['group'] );
			$out['group'] = isset( self::groups()[ $group ] ) ? $group : '';
		}
		if ( isset( $in['action_from'] ) ) {
			$out['action_from'] = sanitize_key( preg_replace( '/^wc-/', '', (string) $in['action_from'] ) );
		}
		foreach ( array( 'email_subject', 'email_heading' ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$out[ $k ] = sanitize_text_field( (string) $in[ $k ] );
			}
		}
		if ( isset( $in['email_body'] ) ) {
			$out['email_body'] = sanitize_textarea_field( (string) $in['email_body'] );
		}
		return $out;
	}

	/** @return array|WP_Error The saved status. */
	public static function add( array $in ) {
		$label = isset( $in['label'] ) ? sanitize_text_field( (string) $in['label'] ) : '';
		if ( '' === $label ) {
			return new WP_Error( 'eru_cos_label', __( 'Durum adı boş olamaz.', 'custom-order-statuses-for-woocommerce' ) );
		}
		$slug = ( isset( $in['slug'] ) && '' !== trim( (string) $in['slug'] ) ) ? self::validate_slug( (string) $in['slug'] ) : self::make_slug( $label );
		if ( is_wp_error( $slug ) ) {
			return $slug;
		}
		$row               = self::clean( $in, self::defaults() );
		$row['slug']       = $slug;
		$statuses          = self::raw();
		$statuses[ $slug ] = $row;
		self::save( $statuses );
		self::register();
		return $row;
	}

	/** @return array|WP_Error */
	public static function update( string $slug, array $in ) {
		$statuses = self::raw();
		if ( ! isset( $statuses[ $slug ] ) ) {
			return new WP_Error( 'eru_cos_missing', __( 'Durum bulunamadı.', 'custom-order-statuses-for-woocommerce' ) );
		}
		$row = self::clean( $in, $statuses[ $slug ] );
		if ( '' === $row['label'] ) {
			return new WP_Error( 'eru_cos_label', __( 'Durum adı boş olamaz.', 'custom-order-statuses-for-woocommerce' ) );
		}
		$new = isset( $in['slug'] ) && '' !== trim( (string) $in['slug'] ) ? strtolower( trim( (string) $in['slug'] ) ) : $slug;
		if ( $new !== $slug ) {
			if ( self::order_count( $slug ) > 0 ) {
				return new WP_Error( 'eru_cos_slug_locked', __( 'Bu durumda sipariş olduğu için kısa ad değiştirilemez.', 'custom-order-statuses-for-woocommerce' ) );
			}
			$new = self::validate_slug( $new, $slug );
			if ( is_wp_error( $new ) ) {
				return $new;
			}
		}
		$row['slug'] = $new;
		$out         = array();
		foreach ( $statuses as $k => $v ) { // Keep the position when re-keying.
			$out[ $k === $slug ? $new : $k ] = $k === $slug ? $row : $v;
		}
		self::save( $out );
		if ( $new !== $slug ) {
			foreach ( array( 'customer', 'admin' ) as $type ) {
				$old_opt = 'woocommerce_eru_cos_' . $type . '_' . str_replace( '-', '_', $slug ) . '_settings';
				$value   = get_option( $old_opt, null );
				if ( null !== $value ) {
					update_option( 'woocommerce_eru_cos_' . $type . '_' . str_replace( '-', '_', $new ) . '_settings', $value );
					delete_option( $old_opt );
				}
			}
			unset( $GLOBALS['wp_post_statuses'][ 'wc-' . $slug ] );
		}
		self::register();
		return $row;
	}

	/** @param string[] $slugs New order; unknown slugs are ignored, missing ones keep their relative order at the end. */
	public static function reorder( array $slugs ): void {
		$statuses = self::raw();
		$out      = array();
		foreach ( $slugs as $s ) {
			$s = (string) $s;
			if ( isset( $statuses[ $s ] ) ) {
				$out[ $s ] = $statuses[ $s ];
			}
		}
		self::save( $out + $statuses );
	}

	public static function order_count( string $slug ): int {
		$r = wc_get_orders(
			array(
				'status'   => array( 'wc-' . $slug ),
				'type'     => 'shop_order',
				'limit'    => 1,
				'return'   => 'ids',
				'paginate' => true,
			)
		);
		return (int) $r->total;
	}

	/** Orders moved per pass / per Action Scheduler batch (filter `eru_cos_migrate_batch_size`). */
	public static function batch_size(): int {
		return max( 1, (int) apply_filters( 'eru_cos_migrate_batch_size', self::BATCH ) );
	}

	/**
	 * Deletes a status. Refused while orders use it unless $to is given; then the orders are moved first
	 * (inline up to batch_size() orders, otherwise in Action Scheduler batches, or all inline when $sync).
	 * A status already being deleted in the background is refused, except with $sync, which finishes that
	 * deletion inline with its stored target.
	 *
	 * @return true|string|WP_Error true = deleted, 'scheduled' = migration queued, the status goes when it finishes.
	 */
	public static function delete( string $slug, string $to = '', bool $sync = false ) {
		$all = self::raw();
		if ( ! isset( $all[ $slug ] ) ) {
			return new WP_Error( 'eru_cos_missing', __( 'Durum bulunamadı.', 'custom-order-statuses-for-woocommerce' ) );
		}
		$pending = (string) $all[ $slug ]['pending_delete'];
		if ( '' !== $pending ) {
			if ( ! $sync ) {
				return new WP_Error( 'eru_cos_pending', __( 'Bu durum zaten siliniyor; siparişler arka planda taşınıyor.', 'custom-order-statuses-for-woocommerce' ) );
			}
			$to = $pending;
		}
		$count = self::order_count( $slug );
		if ( $count > 0 ) {
			if ( '' === $to ) {
				/* translators: %d: number of orders */
				return new WP_Error( 'eru_cos_in_use', sprintf( _n( 'Bu durumda %d sipariş var; silmeden önce siparişlerin taşınacağı durumu seçin.', 'Bu durumda %d sipariş var; silmeden önce siparişlerin taşınacağı durumu seçin.', $count, 'custom-order-statuses-for-woocommerce' ), $count ) );
			}
			$to = preg_replace( '/^wc-/', '', $to );
			if ( $to === $slug || ! isset( wc_get_order_statuses()[ 'wc-' . $to ] ) ) {
				return new WP_Error( 'eru_cos_bad_target', __( 'Geçersiz hedef durum.', 'custom-order-statuses-for-woocommerce' ) );
			}
			if ( ! $sync && $count > self::batch_size() && function_exists( 'as_enqueue_async_action' ) ) {
				$all[ $slug ]['pending_delete'] = $to;
				self::save( $all );
				as_enqueue_async_action( 'eru_cos_migrate_batch', array( $slug, $to ), 'eru-cos' );
				return 'scheduled';
			}
			if ( ! self::migrate_all( $slug, $to ) ) {
				return self::migrate_failed( $slug );
			}
		}
		if ( '' !== $pending && function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( 'eru_cos_migrate_batch', array( $slug, $pending ), 'eru-cos' );
		}
		self::remove( $slug );
		return true;
	}

	private static function migrate_failed( string $slug ): WP_Error {
		$left = self::order_count( $slug );
		/* translators: %d: number of orders left */
		return new WP_Error( 'eru_cos_migrate_failed', sprintf( _n( '%d sipariş taşınamadı; durum silinmedi. Sipariş notlarına bakın.', '%d sipariş taşınamadı; durum silinmedi. Sipariş notlarına bakın.', $left, 'custom-order-statuses-for-woocommerce' ), $left ) );
	}

	/**
	 * Moves every order, pass by pass; stops as soon as a pass moves nothing (failing orders), so it always ends.
	 *
	 * @return bool True when no order is left in $from.
	 */
	public static function migrate_all( string $from, string $to ): bool {
		while ( self::order_count( $from ) > 0 ) {
			if ( 0 === self::migrate( $from, $to, self::batch_size() ) ) {
				return false;
			}
		}
		return true;
	}

	/** Moves up to $limit orders from one status to another. Returns the number actually moved. */
	public static function migrate( string $from, string $to, int $limit = 0 ): int {
		$from = preg_replace( '/^wc-/', '', $from );
		$to   = preg_replace( '/^wc-/', '', $to );
		if ( $from === $to || ! isset( wc_get_order_statuses()[ 'wc-' . $to ] ) ) {
			return 0;
		}
		$ids   = wc_get_orders(
			array(
				'status' => array( 'wc-' . $from ),
				'type'   => 'shop_order',
				'limit'  => $limit > 0 ? $limit : self::batch_size(),
				'return' => 'ids',
			)
		);
		$moved = 0;
		foreach ( $ids as $id ) {
			$order = wc_get_order( $id );
			/* translators: %s: removed status slug */
			if ( ! $order || true !== $order->update_status( $to, sprintf( __( 'Durum taşındı ("%s" durumu kaldırılıyor).', 'custom-order-statuses-for-woocommerce' ), $from ) ) ) {
				continue;
			}
			// WC_Order::save() swallows save exceptions, so update_status() can report success without
			// writing; only an order that really left $from counts as moved.
			$fresh = wc_get_order( $id );
			if ( $fresh && $fresh->get_status() !== $from ) {
				++$moved;
			}
		}
		return $moved;
	}

	/**
	 * Action Scheduler batch: move a batch, re-queue, remove the status when none is left. Stops (and tells the
	 * admin) when the target status is gone or a batch moves nothing.
	 */
	public static function run_batch( $from, $to ): void {
		$from = (string) $from;
		$to   = (string) $to;
		$all  = self::raw();
		if ( ! isset( $all[ $from ] ) || '' === $all[ $from ]['pending_delete'] ) {
			return;
		}
		if ( ! isset( wc_get_order_statuses()[ 'wc-' . $to ] ) ) {
			/* translators: 1: status being deleted, 2: missing target status */
			self::abort_pending( $from, sprintf( __( '"%1$s" durumunun silinmesi durduruldu: hedef durum "%2$s" artık yok.', 'custom-order-statuses-for-woocommerce' ), $all[ $from ]['label'], $to ) );
			return;
		}
		$moved = self::migrate( $from, $to, self::batch_size() );
		if ( 0 === self::order_count( $from ) ) {
			self::remove( $from );
			return;
		}
		if ( 0 === $moved ) {
			/* translators: %s: status name */
			self::abort_pending( $from, sprintf( __( '"%s" durumunun silinmesi durduruldu: kalan siparişler taşınamadı (sipariş notlarına bakın). Durum silinmedi.', 'custom-order-statuses-for-woocommerce' ), $all[ $from ]['label'] ) );
			return;
		}
		as_enqueue_async_action( 'eru_cos_migrate_batch', array( $from, $to ), 'eru-cos' );
	}

	/** Clears the pending deletion and leaves a one-time admin notice plus a WooCommerce log entry. */
	private static function abort_pending( string $slug, string $message ): void {
		$all = self::raw();
		if ( isset( $all[ $slug ] ) ) {
			$all[ $slug ]['pending_delete'] = '';
			self::save( $all );
		}
		set_transient( self::NOTICE, $message, WEEK_IN_SECONDS );
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error( $message, array( 'source' => 'custom-order-statuses-for-woocommerce' ) );
		}
	}

	private static function remove( string $slug ): void {
		$statuses = self::raw();
		unset( $statuses[ $slug ] );
		self::save( $statuses );
		unset( $GLOBALS['wp_post_statuses'][ 'wc-' . $slug ] );
		$id = str_replace( '-', '_', $slug );
		delete_option( 'woocommerce_eru_cos_customer_' . $id . '_settings' );
		delete_option( 'woocommerce_eru_cos_admin_' . $id . '_settings' );
	}

	/* ---------- registration ---------- */

	public static function register(): void {
		foreach ( self::all() as $slug => $s ) {
			$label = $s['label'];
			$count = str_replace( '%', '%%', esc_html( $label ) ) . ' <span class="count">(%s)</span>'; // A literal % must not reach sprintf().
			register_post_status(
				'wc-' . $slug,
				array(
					'label'                     => $label,
					'public'                    => false,
					'exclude_from_search'       => false,
					'show_in_admin_all_list'    => true,
					'show_in_admin_status_list' => true,
					'eru_cos'                   => true,
					// A nooped plural built from the merchant's label (not a gettext literal).
					'label_count'               => array(
						0          => $count,
						1          => $count,
						'singular' => $count,
						'plural'   => $count,
						'context'  => null,
						'domain'   => null,
					),
				)
			);
			if ( ! in_array( $slug, self::$hooked, true ) ) {
				self::$hooked[] = $slug;
				add_action( 'woocommerce_order_status_' . $slug, array( __CLASS__, 'on_enter' ), 10, 2 );
			}
			do_action( 'eru_cos_status_registered', $slug, $s );
		}
	}

	/** Inserts the custom statuses after wc-processing, in the saved order. */
	public static function wc_order_statuses( $statuses ) {
		$ours = array();
		$keep = self::editing_order_status();
		foreach ( self::all() as $slug => $s ) {
			// On the order edit screen a status being deleted is offered only to orders already in it.
			if ( null !== $keep && '' !== $s['pending_delete'] && $keep !== $slug ) {
				continue;
			}
			$ours[ 'wc-' . $slug ] = $s['label'];
		}
		if ( ! $ours || ! is_array( $statuses ) ) {
			return $statuses;
		}
		$statuses = array_diff_key( $statuses, $ours );
		$pos      = array_search( 'wc-processing', array_keys( $statuses ), true );
		$pos      = false === $pos ? count( $statuses ) : $pos + 1;
		return array_slice( $statuses, 0, $pos, true ) + $ours + array_slice( $statuses, $pos, null, true );
	}

	/** Status of the order on the single-order edit screen (HPOS or legacy), or null elsewhere. */
	private static function editing_order_status(): ?string {
		$order  = $GLOBALS['theorder'] ?? null;
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $order instanceof WC_Order || ! $screen || ! in_array( $screen->id, array( 'woocommerce_page_wc-orders', 'shop_order' ), true ) ) {
			return null;
		}
		return $order->get_status();
	}

	/* ---------- semantics ---------- */

	private static function flagged( string $flag ): array {
		return array_keys( array_filter( self::all(), fn( $s ) => ! empty( $s[ $flag ] ) ) );
	}

	public static function paid_statuses( $statuses ) {
		return array_values( array_unique( array_merge( (array) $statuses, self::flagged( 'is_paid' ) ) ) );
	}

	public static function is_editable( $editable, $order ) {
		$s = $order instanceof WC_Order ? self::get( $order->get_status() ) : null;
		return $s ? (bool) $s['editable'] : $editable;
	}

	public static function is_download_permitted( $permitted, $order ) {
		$s = $order instanceof WC_Order ? self::get( $order->get_status() ) : null;
		return $s ? (bool) $s['downloads_permitted'] : $permitted;
	}

	/** Runs when an order enters one of our statuses. */
	public static function on_enter( $order_id, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$s = self::get( $order->get_status() );
		if ( ! $s ) {
			return;
		}
		if ( $s['reduces_stock'] ) {
			wc_maybe_reduce_stock_levels( $order->get_id() ); // No-op once _order_stock_reduced is set.
		}
		if ( $s['downloads_permitted'] ) {
			wc_downloadable_product_permissions( $order->get_id() );
		}
	}

	/* ---------- helpers ---------- */

	/** Black or white text for a background colour (WCAG relative luminance). */
	public static function text_color( string $hex ): string {
		$hex = ltrim( $hex, '#' );
		if ( 3 === strlen( $hex ) ) {
			$hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
		}
		if ( 6 !== strlen( $hex ) ) {
			return '#000000';
		}
		$lin = function ( $c ) {
			$c = hexdec( $c ) / 255;
			return $c <= 0.03928 ? $c / 12.92 : ( ( $c + 0.055 ) / 1.055 ) ** 2.4;
		};
		$l = 0.2126 * $lin( substr( $hex, 0, 2 ) ) + 0.7152 * $lin( substr( $hex, 2, 2 ) ) + 0.0722 * $lin( substr( $hex, 4, 2 ) );
		return ( 1.05 / ( $l + 0.05 ) ) >= ( ( $l + 0.05 ) / 0.05 ) ? '#ffffff' : '#1d2327';
	}

	/** slug => [ background, text ], filterable. */
	public static function badge_colors(): array {
		$out = array();
		foreach ( self::all() as $slug => $s ) {
			$out[ $slug ] = array( $s['color'], self::text_color( $s['color'] ) );
		}
		return (array) apply_filters( 'eru_cos_badge_colors', $out );
	}
}
