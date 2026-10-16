<?php
/**
 * Admin tabs of WooCommerce → Pazaryerleri beyond "Genel": the status and action box of each marketplace tab,
 * Stok köprüsü, Geçiş (migrator) and Günlük (log viewer, raw payload view).
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Everything renders for manage_woocommerce only. Forms post to admin-post.php (Eru_MPO_Admin_Actions); buttons
 * that reach a marketplace only enqueue an Action Scheduler job. The log is redacted when written and again on
 * display. The raw payload view needs the capability and a per-package nonce, and shows only what the raw table
 * still holds (purged after the raw retention).
 */
class Eru_MPO_Admin_Pages {

	const CAP      = 'manage_woocommerce';
	const PER_PAGE = 50;

	/** @var bool */
	private static $booted = false;

	/** Hooks. */
	public static function init() {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;
		add_filter( 'eru_mpo_admin_tabs', array( __CLASS__, 'tabs' ), 30 );
		add_action( 'eru_mpo_admin_tab_stok', array( __CLASS__, 'render_bridge' ) );
		add_action( 'eru_mpo_admin_tab_gecis', array( __CLASS__, 'render_migration' ) );
		add_action( 'eru_mpo_admin_tab_gunluk', array( __CLASS__, 'render_log' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'woocommerce_screen_ids', array( __CLASS__, 'screen_ids' ) );
		foreach ( Eru_MPO_Registry::all() as $key => $a ) {
			if ( method_exists( $a, 'test_connection' ) ) {
				add_action( 'eru_mpo_admin_tab_' . $key, fn() => self::marketplace_box( $a ), 5 );
			}
		}
	}

	/** @param array $tabs Tabs. @return array Plus Stok köprüsü, Geçiş, Günlük. */
	public static function tabs( $tabs ) {
		$tabs['stok']   = __( 'Stok köprüsü', 'marketplace-orders-for-woocommerce' );
		$tabs['gecis']  = __( 'Geçiş', 'marketplace-orders-for-woocommerce' );
		$tabs['gunluk'] = __( 'Günlük', 'marketplace-orders-for-woocommerce' );
		return $tabs;
	}

	/** @param array $ids Screen ids. @return array Plus this page (WooCommerce's enhanced selects load there). */
	public static function screen_ids( $ids ) {
		$ids[] = 'woocommerce_page_' . Eru_MPO_Admin::PAGE;
		return $ids;
	}

	/** Styles on this page and the order screens; the order box script on the order screens. @param string $hook Hook. */
	public static function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? (string) $screen->id : '';
		$orders = in_array( $id, array( 'shop_order', 'edit-shop_order', 'woocommerce_page_wc-orders' ), true );
		if ( 'woocommerce_page_' . Eru_MPO_Admin::PAGE !== $id && ! $orders ) {
			return;
		}
		wp_enqueue_style( 'eru-mpo-admin', ERU_MPO_URL . 'assets/admin.css', array(), ERU_MPO_VERSION );
		if ( $orders ) {
			wp_enqueue_script( 'eru-mpo-admin', ERU_MPO_URL . 'assets/admin.js', array(), ERU_MPO_VERSION, true );
		}
	}

	/** @param string $utc UTC "Y-m-d H:i:s". @return string Site-local time ('—' when empty). */
	private static function when( $utc ) {
		return '' !== (string) $utc ? get_date_from_gmt( (string) $utc, 'd.m.Y H:i' ) : '—';
	}

	/** @param int $ts UTC timestamp. @return string */
	private static function when_ts( $ts ) {
		return $ts ? self::when( gmdate( 'Y-m-d H:i:s', (int) $ts ) ) : '—';
	}

	/**
	 * Renders one tab's content (the page shell is Eru_MPO_Admin::render()).
	 *
	 * @param string $tab Tab slug.
	 */
	public static function render_tab( $tab ) {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		if ( 'genel' === $tab ) {
			Eru_MPO_Admin::render_general();
			self::render_overview();
			return;
		}
		do_action( 'eru_mpo_admin_tab_' . sanitize_key( (string) $tab ) );
	}

	/** One-time notice left by a handler. */
	public static function notice() {
		$n = get_transient( 'eru_mpo_notice_' . get_current_user_id() );
		if ( ! is_array( $n ) ) {
			return;
		}
		delete_transient( 'eru_mpo_notice_' . get_current_user_id() );
		echo '<div class="notice notice-' . ( 'error' === ( $n['type'] ?? '' ) ? 'error' : 'success' ) . ' is-dismissible"><p>' . esc_html( (string) ( $n['text'] ?? '' ) ) . '</p></div>';
	}

	/**
	 * A small POST form to admin-post.php.
	 *
	 * @param string $action  admin-post action (without the prefix).
	 * @param string $nonce   Nonce action.
	 * @param array  $fields  Hidden fields.
	 * @param string $label   Button text.
	 * @param bool   $primary Primary button.
	 * @param bool   $enabled Enabled.
	 */
	private static function button_form( $action, $nonce, array $fields, $label, $primary = false, $enabled = true ) {
		echo '<form method="post" class="eru-mpo-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( 'eru_mpo_' . $action ) . '">';
		wp_nonce_field( $nonce );
		foreach ( $fields as $k => $v ) {
			echo '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '">';
		}
		echo '<button type="submit" class="button' . ( $primary ? ' button-primary' : '' ) . '"' . disabled( $enabled, false, false ) . '>' . esc_html( $label ) . '</button></form>';
	}

	/** @param array $s Sync state. @return string One line. */
	private static function run_line( array $s ) {
		if ( ! $s ) {
			return __( 'Henüz çalışmadı', 'marketplace-orders-for-woocommerce' );
		}
		if ( 'retry' === ( $s['result'] ?? '' ) ) {
			/* translators: 1: time, 2: HTTP status, 3: attempt, 4: next time */
			return sprintf( __( '%1$s: geçici hata (HTTP %2$d), %3$d. deneme; sonraki %4$s', 'marketplace-orders-for-woocommerce' ), self::when( $s['last'] ?? '' ), (int) ( $s['code'] ?? 0 ), (int) ( $s['fails'] ?? 0 ), self::when_ts( (int) ( $s['next'] ?? 0 ) ) );
		}
		/* translators: 1: time, 2: result, 3: fetched, 4: created, 5: updated, 6: errors, 7: next time */
		return sprintf( __( '%1$s: %2$s (alınan %3$d, yeni %4$d, güncellenen %5$d, hata %6$d); sonraki %7$s', 'marketplace-orders-for-woocommerce' ), self::when( $s['last'] ?? '' ), (string) ( $s['result'] ?? '' ), (int) ( $s['fetched'] ?? 0 ), (int) ( $s['created'] ?? 0 ), (int) ( $s['updated'] ?? 0 ), (int) ( $s['errors'] ?? 0 ), self::when_ts( (int) ( $s['next'] ?? 0 ) ) );
	}

	/** Genel tab: one status row per marketplace. */
	private static function render_overview() {
		echo '<h2>' . esc_html__( 'Durum', 'marketplace-orders-for-woocommerce' ) . '</h2><table class="widefat striped eru-mpo-table"><thead><tr><th>' . esc_html__( 'Pazaryeri', 'marketplace-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Çekim', 'marketplace-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Son sipariş çekimi', 'marketplace-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Stok köprüsü', 'marketplace-orders-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( Eru_MPO_Registry::all() as $key => $a ) {
			$q = Eru_MPO_Stock_Bridge::queue_counts( $key );
			echo '<tr><td>' . esc_html( $a->label() ) . '</td><td>' . esc_html( self::pull_state( $a ) ) . '</td><td>' . esc_html( self::run_line( Eru_MPO_Sync::state( 'orders', $key ) ) ) . '</td><td>';
			/* translators: 1: on/off, 2: due rows, 3: waiting rows */
			echo esc_html( sprintf( __( '%1$s · kuyrukta %2$d, bekleyen %3$d', 'marketplace-orders-for-woocommerce' ), Eru_MPO_Stock_Bridge::active( $key ) ? __( 'Açık', 'marketplace-orders-for-woocommerce' ) : __( 'Kapalı', 'marketplace-orders-for-woocommerce' ), $q['due'], $q['waiting'] ) );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** @param Eru_MPO_Adapter $a Adapter. @return string Pull state in words. */
	private static function pull_state( $a ) {
		if ( ! Eru_MPO_Settings::enabled() ) {
			return __( 'Kapalı (ana anahtar)', 'marketplace-orders-for-woocommerce' );
		}
		if ( in_array( $a->key(), Eru_MPO_Migrator::legacy_active(), true ) ) {
			return __( 'Durduruldu: eski eklenti etkin', 'marketplace-orders-for-woocommerce' );
		}
		if ( ! $a->is_configured() ) {
			return __( 'API bilgileri eksik', 'marketplace-orders-for-woocommerce' );
		}
		if ( ! $a->is_enabled() ) {
			return __( 'Kapalı', 'marketplace-orders-for-woocommerce' );
		}
		return $a->is_observe_mode() ? __( 'Açık, gözlem modu', 'marketplace-orders-for-woocommerce' ) : __( 'Açık', 'marketplace-orders-for-woocommerce' );
	}

	/** Status and actions above a marketplace's settings form. @param Eru_MPO_Adapter $a Adapter. */
	public static function marketplace_box( $a ) {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$key  = $a->key();
		$conn = get_option( Eru_MPO_Jobs::conn_option( $key ), array() );
		$conn = is_array( $conn ) ? $conn : array();
		echo '<div class="eru-mpo-box"><h2>' . esc_html__( 'Durum', 'marketplace-orders-for-woocommerce' ) . '</h2><table class="widefat striped eru-mpo-table"><tbody>';
		$row = function ( $label, $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		};
		$row( __( 'Çekim', 'marketplace-orders-for-woocommerce' ), self::pull_state( $a ) );
		$row( __( 'Siparişler', 'marketplace-orders-for-woocommerce' ), self::run_line( Eru_MPO_Sync::state( 'orders', $key ) ) );
		$row( __( 'Yaşam döngüsü', 'marketplace-orders-for-woocommerce' ), self::run_line( Eru_MPO_Sync::state( 'lifecycle', $key ) ) );
		$eps = get_option( 'eru_mpo_sync_endpoints_' . $key, array() );
		if ( is_array( $eps ) && $eps ) {
			$waiting = array();
			foreach ( $eps as $name => $e ) {
				if ( is_array( $e ) && (int) ( $e['until'] ?? 0 ) > time() ) {
					/* translators: 1: endpoint, 2: time */
					$waiting[] = sprintf( __( '%1$s (%2$s sonrasına kadar)', 'marketplace-orders-for-woocommerce' ), (string) $name, self::when_ts( (int) $e['until'] ) );
				}
			}
			$row( __( 'Bekleyen uçlar', 'marketplace-orders-for-woocommerce' ), $waiting ? implode( ', ', $waiting ) : __( 'Yok', 'marketplace-orders-for-woocommerce' ) );
		}
		if ( 'queued' === ( $conn['status'] ?? '' ) ) {
			$c = __( 'Kuyrukta; birkaç saniye sonra sayfayı yenileyin.', 'marketplace-orders-for-woocommerce' );
		} elseif ( 'done' === ( $conn['status'] ?? '' ) && ! empty( $conn['ok'] ) ) {
			/* translators: %s: time */
			$c = sprintf( __( 'Bağlantı başarılı (%s)', 'marketplace-orders-for-woocommerce' ), self::when( $conn['at'] ?? '' ) );
		} elseif ( 'done' === ( $conn['status'] ?? '' ) ) {
			/* translators: 1: time, 2: error */
			$c = sprintf( __( 'Bağlantı başarısız (%1$s): %2$s', 'marketplace-orders-for-woocommerce' ), self::when( $conn['at'] ?? '' ), Eru_MPO_Logger::redact( (string) ( $conn['message'] ?? '' ) ) );
		} else {
			$c = __( 'Test edilmedi', 'marketplace-orders-for-woocommerce' );
		}
		$row( __( 'Bağlantı testi', 'marketplace-orders-for-woocommerce' ), $c );
		echo '</tbody></table><p class="eru-mpo-actions">';
		self::button_form( 'test_connection', 'eru_mpo_test_' . $key, array( 'mp' => $key ), __( 'Bağlantıyı test et', 'marketplace-orders-for-woocommerce' ), false, $a->is_configured() );
		self::button_form( 'sync_now', 'eru_mpo_sync_now_' . $key, array( 'mp' => $key ), __( 'Şimdi senkronla', 'marketplace-orders-for-woocommerce' ), false, Eru_MPO_Sync::wanted( $key ) );
		echo '</p><p class="description">' . esc_html__( 'İki düğme de işi arka plana (Action Scheduler) kuyruğa alır; sayfa pazaryerine istek göndermez.', 'marketplace-orders-for-woocommerce' ) . '</p></div><h2>' . esc_html__( 'Ayarlar', 'marketplace-orders-for-woocommerce' ) . '</h2>';
	}

	/** @param string $tax Taxonomy. @return array<int,string> Term id => name with depth dashes (at most 500). */
	private static function term_options( $tax ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'number'     => 500,
				'orderby'    => 'name',
			)
		);
		$out = array();
		foreach ( is_wp_error( $terms ) ? array() : $terms as $t ) {
			$out[ (int) $t->term_id ] = str_repeat( '— ', count( get_ancestors( (int) $t->term_id, $tax, 'taxonomy' ) ) ) . $t->name;
		}
		return $out;
	}

	/** Stok köprüsü tab: warnings, one rules form and status block per marketplace. */
	public static function render_bridge() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		foreach ( Eru_MPO_Stock_Bridge::warnings() as $w ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html( $w ) . '</p></div>';
		}
		$saved = get_transient( 'eru_mpo_rules_saved_' . get_current_user_id() );
		if ( is_string( $saved ) && '' !== $saved && Eru_MPO_Registry::get( $saved ) ) {
			delete_transient( 'eru_mpo_rules_saved_' . get_current_user_id() );
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Kurallar kaydedildi. Kurallar yalnızca bundan sonra gönderilen değerlere uygulanır; mevcut ürünlere uygulamak için tam senkron çalıştırın.', 'marketplace-orders-for-woocommerce' ) . '</p><p>';
			self::button_form( 'full_sync', 'eru_mpo_full_sync', array( 'mp' => $saved ), __( 'Kuralları uygula (tam senkron)', 'marketplace-orders-for-woocommerce' ), true, Eru_MPO_Stock_Bridge::active( $saved ) );
			echo '</p></div>';
		}
		echo '<p class="description">' . esc_html__( 'WooCommerce stoğu ve fiyatı değiştiğinde ürün kuyruğa alınır ve 15 saniye sonra toplu olarak gönderilir; 10 dakikada bir güvenlik taraması, saatte bir ilan yenilemesi yapılır. Gönderilen değerler mutlaktır (fark değil). Ana anahtar kapalıyken hiçbir şey kuyruğa alınmaz.', 'marketplace-orders-for-woocommerce' ) . '</p>';
		$cats   = self::term_options( 'product_cat' );
		$btax   = Eru_MPO_Rules::brand_taxonomy();
		$brands = '' !== $btax ? self::term_options( $btax ) : array();
		foreach ( Eru_MPO_Registry::all() as $key => $a ) {
			$r   = Eru_MPO_Rules::get( $key );
			$id  = fn( $f ) => 'eru-mpo-' . $key . '-' . $f;
			$sel = function ( $name, array $opts, array $chosen, $f ) use ( $id ) {
				echo '<select multiple class="wc-enhanced-select eru-mpo-multi" id="' . esc_attr( $id( $f ) ) . '" name="' . esc_attr( 'bridge[' . $name . '][]' ) . '">';
				foreach ( $opts as $v => $l ) {
					echo '<option value="' . (int) $v . '"' . selected( in_array( (int) $v, $chosen, true ), true, false ) . '>' . esc_html( $l ) . '</option>';
				}
				echo '</select>';
			};
			echo '<div class="eru-mpo-box"><h2>' . esc_html( $a->label() ) . '</h2>';
			if ( ! $a->is_configured() ) {
				echo '<p class="description">' . esc_html__( 'API bilgileri eksik: stok köprüsü bu pazaryeri için çalışmaz.', 'marketplace-orders-for-woocommerce' ) . '</p>';
			} elseif ( method_exists( $a, 'bridge_ready' ) && ! $a->bridge_ready() ) {
				echo '<p class="description">' . esc_html__( 'Listeleme API adresi girilmedi (pazaryeri sekmesi): stok köprüsü bu pazaryeri için çalışmaz.', 'marketplace-orders-for-woocommerce' ) . '</p>';
			}
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="eru_mpo_save_bridge"><input type="hidden" name="mp" value="' . esc_attr( $key ) . '">';
			wp_nonce_field( 'eru_mpo_save_bridge_' . $key );
			echo '<table class="form-table" role="presentation">';
			foreach ( array(
				'push_stock' => array( __( 'Stok gönder', 'marketplace-orders-for-woocommerce' ), __( 'WooCommerce stoğunu bu pazaryerine gönder.', 'marketplace-orders-for-woocommerce' ) ),
				'push_price' => array( __( 'Fiyat gönder', 'marketplace-orders-for-woocommerce' ), __( 'Satış fiyatını (ve liste fiyatını) kurallarla gönder.', 'marketplace-orders-for-woocommerce' ) ),
			) as $f => $l ) {
				echo '<tr><th scope="row">' . esc_html( $l[0] ) . '</th><td><label><input type="checkbox" name="' . esc_attr( 'bridge[' . $f . ']' ) . '" value="yes"' . checked( 'yes', Eru_MPO_Settings::mp_get( $key, $f, 'no' ), false ) . '> ' . esc_html( $l[1] ) . '</label></td></tr>';
			}
			echo '<tr><th scope="row"><label for="' . esc_attr( $id( 'excluded_cats' ) ) . '">' . esc_html__( 'Hariç kategoriler', 'marketplace-orders-for-woocommerce' ) . '</label></th><td>';
			$sel( 'excluded_cats', $cats, $r['excluded_cats'], 'excluded_cats' );
			echo '<p class="description">' . esc_html__( 'Bu kategorilerdeki (ve alt kategorilerindeki) ürünler gönderilmez.', 'marketplace-orders-for-woocommerce' ) . '</p></td></tr>';
			if ( '' !== $btax ) {
				echo '<tr><th scope="row"><label for="' . esc_attr( $id( 'excluded_brands' ) ) . '">' . esc_html__( 'Hariç markalar', 'marketplace-orders-for-woocommerce' ) . '</label></th><td>';
				$sel( 'excluded_brands', $brands, $r['excluded_brands'], 'excluded_brands' );
				echo '</td></tr>';
			}
			echo '<tr><th scope="row"><label for="' . esc_attr( $id( 'excluded_skus' ) ) . '">' . esc_html__( 'Hariç SKU\'lar', 'marketplace-orders-for-woocommerce' ) . '</label></th><td><textarea class="large-text code" rows="3" id="' . esc_attr( $id( 'excluded_skus' ) ) . '" name="bridge[excluded_skus]">' . esc_textarea( implode( "\n", $r['excluded_skus'] ) ) . '</textarea><p class="description">' . esc_html__( 'Her satıra bir SKU. Sonda * olan satır o önekle başlayan tüm SKU\'ları kapsar.', 'marketplace-orders-for-woocommerce' ) . '</p></td></tr>';
			$num = function ( $f, $label, $help ) use ( $r, $id ) {
				echo '<tr><th scope="row"><label for="' . esc_attr( $id( $f ) ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" min="0" class="small-text" id="' . esc_attr( $id( $f ) ) . '" name="' . esc_attr( 'bridge[' . $f . ']' ) . '" value="' . (int) $r[ $f ] . '"> <span class="description">' . esc_html( $help ) . '</span></td></tr>';
			};
			$num( 'stock_floor', __( 'Stok alt sınırı', 'marketplace-orders-for-woocommerce' ), __( 'Stok bunun altındaysa 0 gönderilir (0 = kapalı).', 'marketplace-orders-for-woocommerce' ) );
			$num( 'stock_ceiling', __( 'Stok üst sınırı', 'marketplace-orders-for-woocommerce' ), __( 'En fazla bu kadar gönderilir (0 = kapalı).', 'marketplace-orders-for-woocommerce' ) );
			$num( 'untracked_qty', __( 'Stok takibi olmayan ürün', 'marketplace-orders-for-woocommerce' ), __( 'Stok takibi kapalı ve stokta olan ürün için gönderilen adet.', 'marketplace-orders-for-woocommerce' ) );
			foreach ( array(
				'price_multiplier' => array( __( 'Fiyat çarpanı', 'marketplace-orders-for-woocommerce' ), __( 'Örnek: 1,15 fiyatı %15 artırır.', 'marketplace-orders-for-woocommerce' ) ),
				'price_add'        => array( __( 'Sabit ekleme', 'marketplace-orders-for-woocommerce' ), __( 'Çarpandan sonra eklenen tutar (eksi olabilir).', 'marketplace-orders-for-woocommerce' ) ),
			) as $f => $l ) {
				echo '<tr><th scope="row"><label for="' . esc_attr( $id( $f ) ) . '">' . esc_html( $l[0] ) . '</label></th><td><input type="text" inputmode="decimal" class="small-text" id="' . esc_attr( $id( $f ) ) . '" name="' . esc_attr( 'bridge[' . $f . ']' ) . '" value="' . esc_attr( (string) $r[ $f ] ) . '"> <span class="description">' . esc_html( $l[1] ) . '</span></td></tr>';
			}
			echo '<tr><th scope="row"><label for="' . esc_attr( $id( 'rounding' ) ) . '">' . esc_html__( 'Yuvarlama', 'marketplace-orders-for-woocommerce' ) . '</label></th><td><select id="' . esc_attr( $id( 'rounding' ) ) . '" name="bridge[rounding]">';
			foreach ( array(
				'none' => __( 'Yuvarlama yok', 'marketplace-orders-for-woocommerce' ),
				'90'   => __( 'En yakın ,90', 'marketplace-orders-for-woocommerce' ),
				'99'   => __( 'En yakın ,99', 'marketplace-orders-for-woocommerce' ),
			) as $v => $l ) {
				echo '<option value="' . esc_attr( $v ) . '"' . selected( $r['rounding'], $v, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select></td></tr></table>';
			submit_button( __( 'Kaydet', 'marketplace-orders-for-woocommerce' ), 'primary', 'submit', false );
			echo '</form>';
			$q    = Eru_MPO_Stock_Bridge::queue_counts( $key );
			$last = Eru_MPO_Stock_Bridge::state( 'bridge', $key );
			$ls   = Eru_MPO_Stock_Bridge::state( 'listings', $key );
			$full = Eru_MPO_Stock_Bridge::state( 'full', $key );
			echo '<table class="widefat striped eru-mpo-table eru-mpo-status"><tbody>';
			/* translators: 1: due rows, 2: rows in backoff, 3: rows waiting for a listing, 4: given-up rows */
			echo '<tr><th scope="row">' . esc_html__( 'Kuyruk', 'marketplace-orders-for-woocommerce' ) . '</th><td>' . esc_html( sprintf( __( '%1$d gönderilecek, %2$d yeniden denenecek, %3$d ilan bekliyor, %4$d durduruldu', 'marketplace-orders-for-woocommerce' ), $q['due'], $q['waiting'], $q['unlisted'], $q['given_up'] ) ) . '</td></tr>';
			/* translators: 1: time, 2: stock items, 3: price items, 4: failed */
			echo '<tr><th scope="row">' . esc_html__( 'Son gönderim', 'marketplace-orders-for-woocommerce' ) . '</th><td>' . esc_html( $last ? sprintf( __( '%1$s: %2$d stok, %3$d fiyat, %4$d hata', 'marketplace-orders-for-woocommerce' ), self::when( $last['last'] ?? '' ), (int) ( $last['stock'] ?? 0 ), (int) ( $last['price'] ?? 0 ), (int) ( $last['failed'] ?? 0 ) ) : __( 'Henüz yok', 'marketplace-orders-for-woocommerce' ) ) . '</td></tr>';
			$lst = array(
				'complete' => __( 'tam', 'marketplace-orders-for-woocommerce' ),
				'running'  => __( 'sürüyor', 'marketplace-orders-for-woocommerce' ),
				'error'    => __( 'eksik kaldı', 'marketplace-orders-for-woocommerce' ),
			);
			/* translators: 1: time, 2: state, 3: listings, 4: matched */
			echo '<tr><th scope="row">' . esc_html__( 'İlanlar', 'marketplace-orders-for-woocommerce' ) . '</th><td>' . esc_html( $ls ? sprintf( __( '%1$s (%2$s): %3$d ilan, %4$d ürünle eşleşti', 'marketplace-orders-for-woocommerce' ), self::when( $ls['last'] ?? '' ), $lst[ $ls['status'] ?? '' ] ?? '—', (int) ( $ls['listings'] ?? 0 ), (int) ( $ls['matched'] ?? 0 ) ) : __( 'Henüz yenilenmedi', 'marketplace-orders-for-woocommerce' ) ) . '</td></tr>';
			$fs = array(
				'running' => __( 'Sürüyor', 'marketplace-orders-for-woocommerce' ),
				'done'    => __( 'Tamamlandı', 'marketplace-orders-for-woocommerce' ),
				'stopped' => __( 'Durduruldu', 'marketplace-orders-for-woocommerce' ),
			);
			/* translators: 1: state, 2: queued products */
			echo '<tr><th scope="row">' . esc_html__( 'Tam gönderim', 'marketplace-orders-for-woocommerce' ) . '</th><td>' . esc_html( $full ? sprintf( __( '%1$s, %2$d ürün kuyruğa alındı', 'marketplace-orders-for-woocommerce' ), $fs[ $full['status'] ?? '' ] ?? '—', (int) ( $full['marked'] ?? 0 ) ) : '—' ) . '</td></tr>';
			echo '</tbody></table><p class="eru-mpo-actions">';
			self::button_form( 'full_sync', 'eru_mpo_full_sync', array( 'mp' => $key ), __( 'Tüm stok ve fiyatları gönder', 'marketplace-orders-for-woocommerce' ), false, Eru_MPO_Stock_Bridge::active( $key ) );
			echo '</p></div>';
		}
	}

	/** Geçiş tab: dry-run report, run state and the start button. */
	public static function render_migration() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$scan   = Eru_MPO_Migrator::scan();
		$state  = get_option( Eru_MPO_Migrator::STATE );
		$state  = is_array( $state ) ? $state : array();
		$active = Eru_MPO_Migrator::legacy_active();
		echo '<p>' . esc_html__( 'Eski Erusoft Trendyol ve Hepsiburada eklentilerinin siparişlerini bu eklentinin durumlarına ve alanlarına taşır. Eski alanlar, durumlar ve tablolar silinmez; geçiş tekrar çalıştırılabilir.', 'marketplace-orders-for-woocommerce' ) . '</p>';
		if ( $active ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Eski eklenti hâlâ etkin: geçiş başlatılamaz. Önce eski eklentiyi devre dışı bırakın.', 'marketplace-orders-for-woocommerce' ) . '</p></div>';
		}
		echo '<h2>' . esc_html__( 'Kuru çalıştırma', 'marketplace-orders-for-woocommerce' ) . '</h2><p class="description">' . esc_html__( 'Sayılar yalnızca okunarak hesaplandı; hiçbir sipariş değiştirilmedi.', 'marketplace-orders-for-woocommerce' ) . '</p>';
		$heads = array(
			__( 'Kaynak', 'marketplace-orders-for-woocommerce' ),
			__( 'Eski sipariş', 'marketplace-orders-for-woocommerce' ),
			__( 'Taşınacak', 'marketplace-orders-for-woocommerce' ),
			__( 'Taşınmış', 'marketplace-orders-for-woocommerce' ),
			__( 'Durumu onarılacak', 'marketplace-orders-for-woocommerce' ),
			__( 'Eski tablo satırı', 'marketplace-orders-for-woocommerce' ),
			__( 'Yalnız tabloda', 'marketplace-orders-for-woocommerce' ),
			__( 'Siparişi olmayan satır', 'marketplace-orders-for-woocommerce' ),
			__( 'Taşınacakların durumları', 'marketplace-orders-for-woocommerce' ),
		);
		echo '<table class="widefat striped eru-mpo-table"><thead><tr>';
		foreach ( $heads as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		$labels = array(
			'trendyol'    => 'Trendyol',
			'hepsiburada' => 'Hepsiburada',
		);
		foreach ( $scan as $mp => $s ) {
			$st = array();
			foreach ( (array) $s['statuses'] as $slug => $n ) {
				$st[] = $slug . ': ' . (int) $n;
			}
			echo '<tr><td>' . esc_html( $labels[ $mp ] ?? $mp ) . '</td><td>' . (int) $s['orders'] . '</td><td>' . (int) $s['to_migrate'] . '</td><td>' . (int) $s['migrated'] . '</td><td>' . (int) ( $s['to_repair'] ?? 0 ) . '</td><td>' . (int) $s['table_rows'] . '</td><td>' . (int) $s['table_only'] . '</td><td>' . (int) $s['orphans'] . '</td><td>' . esc_html( $st ? implode( ', ', $st ) : '—' ) . '</td></tr>';
		}
		echo '</tbody></table>';
		echo '<h2>' . esc_html__( 'Çalıştırma', 'marketplace-orders-for-woocommerce' ) . '</h2>';
		$names = array(
			'running'       => __( 'Sürüyor', 'marketplace-orders-for-woocommerce' ),
			'done'          => __( 'Tamamlandı', 'marketplace-orders-for-woocommerce' ),
			'legacy_active' => __( 'Durdu: eski eklenti etkin', 'marketplace-orders-for-woocommerce' ),
		);
		if ( $state ) {
			$pending = (bool) Eru_MPO_Stock_Bridge::pending( Eru_MPO_Migrator::HOOK );
			/* translators: 1: state, 2: start time, 3: migrated, 4: repaired, 5: conflicts, 6: orphans, 7: errors, 8: batches */
			echo '<p><strong>' . esc_html( $names[ $state['status'] ?? '' ] ?? (string) ( $state['status'] ?? '' ) ) . '</strong> · ' . esc_html( sprintf( __( 'başlangıç %1$s · taşınan %2$d · onarılan %3$d · çakışma %4$d · sahipsiz %5$d · hata %6$d · grup %7$d', 'marketplace-orders-for-woocommerce' ), self::when( $state['started_at'] ?? '' ), (int) ( $state['migrated'] ?? 0 ), (int) ( $state['repaired'] ?? 0 ), (int) ( $state['conflicts'] ?? 0 ), (int) ( $state['orphans'] ?? 0 ), (int) ( $state['errors'] ?? 0 ), (int) ( $state['batches'] ?? 0 ) ) ) . '</p>';
			if ( 'running' === ( $state['status'] ?? '' ) ) {
				echo '<p class="description">' . esc_html( $pending ? __( 'Sıradaki grup Action Scheduler kuyruğunda; ilerlemeyi görmek için sayfayı yenileyin.', 'marketplace-orders-for-woocommerce' ) : __( 'Kuyrukta grup yok; eklenti yeniden etkinleştirildiğinde ya da geçiş yeniden başlatıldığında devam eder.', 'marketplace-orders-for-woocommerce' ) ) . '</p>';
			}
		} else {
			echo '<p>' . esc_html__( 'Geçiş henüz çalıştırılmadı.', 'marketplace-orders-for-woocommerce' ) . '</p>';
		}
		echo '<p class="eru-mpo-actions">';
		self::button_form( 'migrate', 'eru_mpo_migrate', array(), $state ? __( 'Geçişi yeniden çalıştır', 'marketplace-orders-for-woocommerce' ) : __( 'Geçişi başlat', 'marketplace-orders-for-woocommerce' ), true, ! $active );
		echo '</p><p class="description">' . esc_html__( 'Geçiş arka planda 100 siparişlik gruplarla çalışır. Eski eklenti daha sonra yeniden etkinleştirilip siparişleri eski durumlarına çekerse, onu devre dışı bırakıp geçişi yeniden çalıştırın: onarım adımı durumları düzeltir.', 'marketplace-orders-for-woocommerce' ) . '</p>';
	}

	/**
	 * Link to the raw payload view of a package (needs the capability; nonce per package).
	 *
	 * @param string $mp  Marketplace.
	 * @param string $ref Remote reference.
	 * @return string
	 */
	public static function raw_url( $mp, $ref ) {
		return add_query_arg(
			array(
				'page'     => Eru_MPO_Admin::PAGE,
				'tab'      => 'gunluk',
				'raw_mp'   => $mp,
				'raw_ref'  => $ref,
				'_wpnonce' => wp_create_nonce( 'eru_mpo_raw_' . $mp . '|' . $ref ),
			),
			admin_url( 'admin.php' )
		);
	}

	/** @return array{0:string,1:string} Requested marketplace and reference of the raw view. */
	private static function raw_request() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- verified in render_raw().
		$mp  = isset( $_GET['raw_mp'] ) ? substr( sanitize_key( wp_unslash( $_GET['raw_mp'] ) ), 0, 32 ) : '';
		$ref = isset( $_GET['raw_ref'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['raw_ref'] ) ), 0, 64 ) : '';
		// phpcs:enable
		return array( $mp, $ref );
	}

	/** Raw payload of one package (already masked when stored; masked again here). */
	private static function render_raw( $mp, $ref ) {
		$back = add_query_arg(
			array(
				'page' => Eru_MPO_Admin::PAGE,
				'tab'  => 'gunluk',
			),
			admin_url( 'admin.php' )
		);
		echo '<p><a href="' . esc_url( $back ) . '">&larr; ' . esc_html__( 'Günlüğe dön', 'marketplace-orders-for-woocommerce' ) . '</a></p>';
		$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! wp_verify_nonce( $nonce, 'eru_mpo_raw_' . $mp . '|' . $ref ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Bağlantının süresi dolmuş; ham veriyi sipariş ekranından yeniden açın.', 'marketplace-orders-for-woocommerce' ) . '</p></div>';
			return;
		}
		$raw = Eru_MPO_Raw::get( $mp, $ref );
		/* translators: %s: package reference */
		echo '<h2>' . esc_html( sprintf( __( 'Ham veri: %s', 'marketplace-orders-for-woocommerce' ), $ref ) ) . '</h2>';
		if ( null === $raw ) {
			echo '<p>' . esc_html__( 'Bu paket için ham veri yok (saklama süresi dolmuş ya da hiç saklanmamış olabilir).', 'marketplace-orders-for-woocommerce' ) . '</p>';
			return;
		}
		echo '<p class="description">' . esc_html__( 'Kimlik numaraları ve kimlik bilgisi alanları maskelenmiştir. Ham veri, Genel sekmesindeki saklama süresinden sonra silinir.', 'marketplace-orders-for-woocommerce' ) . '</p>';
		echo '<pre class="eru-mpo-raw">' . esc_html( (string) wp_json_encode( Eru_MPO_Raw::redact( $raw ), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) . '</pre>';
	}

	/** Günlük tab: filters, rows (redacted again on display), pagination, purge form; or the raw view. */
	public static function render_log() {
		global $wpdb;
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		list( $raw_mp, $raw_ref ) = self::raw_request();
		if ( '' !== $raw_mp && '' !== $raw_ref ) {
			self::render_raw( $raw_mp, $raw_ref );
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$mp    = isset( $_GET['mp'] ) ? substr( sanitize_key( wp_unslash( $_GET['mp'] ) ), 0, 32 ) : '';
		$level = isset( $_GET['level'] ) ? sanitize_key( wp_unslash( $_GET['level'] ) ) : '';
		$q     = isset( $_GET['q'] ) ? substr( sanitize_text_field( wp_unslash( $_GET['q'] ) ), 0, 100 ) : '';
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$level  = in_array( $level, Eru_MPO_Logger::LEVELS, true ) ? $level : '';
		$where  = array( '1=1' );
		$params = array();
		if ( '' !== $mp ) {
			$where[]  = 'marketplace = %s';
			$params[] = $mp;
		}
		if ( '' !== $level ) {
			$where[]  = 'level = %s';
			$params[] = $level;
		}
		if ( '' !== $q ) {
			$where[]  = '( remote_ref LIKE %s OR message LIKE %s )';
			$like     = '%' . $wpdb->esc_like( $q ) . '%';
			$params[] = $like;
			$params[] = $like;
		}
		$t     = Eru_MPO_Installer::table( 'log' );
		$sql_w = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $params ? $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE {$sql_w}", $params ) : "SELECT COUNT(*) FROM {$t}" ); // phpcs:ignore
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$sql_w} ORDER BY id DESC LIMIT %d OFFSET %d", array_merge( $params, array( self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ) ) ) ); // phpcs:ignore
		$mps   = array( '' => __( 'Tüm pazaryerleri', 'marketplace-orders-for-woocommerce' ) );
		foreach ( Eru_MPO_Registry::all() as $k => $a ) {
			$mps[ $k ] = $a->label();
		}
		$mps['migrator'] = __( 'Geçiş', 'marketplace-orders-for-woocommerce' );
		echo '<form method="get" class="eru-mpo-filters"><input type="hidden" name="page" value="' . esc_attr( Eru_MPO_Admin::PAGE ) . '"><input type="hidden" name="tab" value="gunluk">';
		echo '<label class="screen-reader-text" for="eru-mpo-log-mp">' . esc_html__( 'Pazaryeri', 'marketplace-orders-for-woocommerce' ) . '</label><select id="eru-mpo-log-mp" name="mp">';
		foreach ( $mps as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $mp, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select> <label class="screen-reader-text" for="eru-mpo-log-level">' . esc_html__( 'Düzey', 'marketplace-orders-for-woocommerce' ) . '</label><select id="eru-mpo-log-level" name="level"><option value="">' . esc_html__( 'Tüm düzeyler', 'marketplace-orders-for-woocommerce' ) . '</option>';
		foreach ( self::levels() as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $level, $k, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select> <label class="screen-reader-text" for="eru-mpo-log-q">' . esc_html__( 'Ara', 'marketplace-orders-for-woocommerce' ) . '</label><input type="search" id="eru-mpo-log-q" name="q" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Paket no veya ileti', 'marketplace-orders-for-woocommerce' ) . '"> ';
		submit_button( __( 'Süz', 'marketplace-orders-for-woocommerce' ), 'secondary', '', false );
		echo '</form>';
		echo '<table class="widefat striped eru-mpo-table eru-mpo-log"><thead><tr><th>' . esc_html__( 'Zaman', 'marketplace-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Düzey', 'marketplace-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Pazaryeri', 'marketplace-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'Paket', 'marketplace-orders-for-woocommerce' ) . '</th><th>' . esc_html__( 'İleti', 'marketplace-orders-for-woocommerce' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Kayıt yok.', 'marketplace-orders-for-woocommerce' ) . '</td></tr>';
		}
		// Which rows' packages still have a raw payload: one query for the page.
		$with_raw = array();
		$pairs    = array();
		$args     = array();
		foreach ( (array) $rows as $r ) {
			if ( '' !== (string) $r->remote_ref ) {
				$pairs[] = '( marketplace = %s AND remote_ref = %s )';
				$args[]  = (string) $r->marketplace;
				$args[]  = (string) $r->remote_ref;
			}
		}
		if ( $pairs ) {
			foreach ( (array) $wpdb->get_results( $wpdb->prepare( 'SELECT marketplace, remote_ref FROM ' . Eru_MPO_Installer::table( 'raw' ) . ' WHERE ' . implode( ' OR ', $pairs ), $args ) ) as $x ) { // phpcs:ignore
				$with_raw[ $x->marketplace . '|' . $x->remote_ref ] = true;
			}
		}
		foreach ( (array) $rows as $r ) {
			$ref = (string) $r->remote_ref;
			echo '<tr><td>' . esc_html( self::when( $r->created_at ) ) . '</td><td><span class="eru-mpo-level eru-mpo-level--' . esc_attr( $r->level ) . '">' . esc_html( self::levels()[ $r->level ] ?? $r->level ) . '</span></td><td>' . esc_html( $mps[ $r->marketplace ] ?? $r->marketplace ) . '</td><td>';
			if ( '' !== $ref && isset( $with_raw[ $r->marketplace . '|' . $ref ] ) ) {
				echo '<a href="' . esc_url( self::raw_url( $r->marketplace, $ref ) ) . '" title="' . esc_attr__( 'Ham veriyi göster', 'marketplace-orders-for-woocommerce' ) . '">' . esc_html( $ref ) . '</a>';
			} else {
				echo esc_html( $ref );
			}
			echo '</td><td class="eru-mpo-msg">' . esc_html( Eru_MPO_Logger::redact( (string) $r->message ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $paged,
						'total'   => $pages,
					)
				)
			) . '</div></div>';
		}
		echo '<h2>' . esc_html__( 'Temizle', 'marketplace-orders-for-woocommerce' ) . '</h2><form method="post" class="eru-mpo-inline-form" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '"><input type="hidden" name="action" value="eru_mpo_purge_log">';
		wp_nonce_field( 'eru_mpo_purge_log' );
		echo '<select name="scope"><option value="expired">' . esc_html__( 'Saklama süresi geçenler', 'marketplace-orders-for-woocommerce' ) . '</option><option value="all">' . esc_html__( 'Tümü (seçilen pazaryeri)', 'marketplace-orders-for-woocommerce' ) . '</option></select> <select name="mp">';
		foreach ( $mps as $k => $l ) {
			echo '<option value="' . esc_attr( $k ) . '">' . esc_html( $l ) . '</option>';
		}
		echo '</select> <label><input type="checkbox" name="confirm" value="yes"> ' . esc_html__( '"Tümü" için: seçilen kayıtların kalıcı olarak silineceğini onaylıyorum', 'marketplace-orders-for-woocommerce' ) . '</label> ';
		submit_button( __( 'Temizle', 'marketplace-orders-for-woocommerce' ), 'delete', '', false );
		/* translators: %d: days */
		echo '</form><p class="description">' . esc_html( sprintf( __( 'Temizlik arka planda çalışır. Kayıtlar zaten %d gün sonra kendiliğinden silinir; kimlik numaraları, e-posta ve telefonlar yazılırken gizlenir.', 'marketplace-orders-for-woocommerce' ), Eru_MPO_Settings::days( 'log_days' ) ) ) . '</p>';
	}

	/** @return array<string,string> Level => label. */
	private static function levels() {
		return array(
			'error'   => __( 'Hata', 'marketplace-orders-for-woocommerce' ),
			'warning' => __( 'Uyarı', 'marketplace-orders-for-woocommerce' ),
			'info'    => __( 'Bilgi', 'marketplace-orders-for-woocommerce' ),
		);
	}
}
