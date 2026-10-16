<?php
/**
 * Admin: WooCommerce → Pazaryerleri. This file holds the page shell, the "Genel" tab and the legacy-plugin notice;
 * other tabs register through the eru_mpo_admin_tabs filter and render on eru_mpo_admin_tab_<tab> (marketplace tabs:
 * Eru_MPO_Credentials and Eru_MPO_Admin_Pages; Stok köprüsü, Geçiş, Günlük: Eru_MPO_Admin_Pages).
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every handler checks manage_woocommerce and a nonce; values are sanitised by Eru_MPO_Settings::sanitize().
 */
class Eru_MPO_Admin {

	const PAGE = 'eru-mpo';
	const CAP  = 'manage_woocommerce';

	/** Hooks. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_eru_mpo_save_general', array( __CLASS__, 'save_general' ) );
		add_action( 'admin_notices', array( __CLASS__, 'legacy_notice' ) );
	}

	/** Submenu under WooCommerce. */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Pazaryerleri', 'marketplace-orders-for-woocommerce' ), __( 'Pazaryerleri', 'marketplace-orders-for-woocommerce' ), self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	/** @return array<string,string> Tab slug => label. */
	public static function tabs() {
		$tabs = apply_filters( 'eru_mpo_admin_tabs', array( 'genel' => __( 'Genel', 'marketplace-orders-for-woocommerce' ) ) );
		return is_array( $tabs ) && $tabs ? $tabs : array( 'genel' => __( 'Genel', 'marketplace-orders-for-woocommerce' ) );
	}

	/** Shows a warning while a legacy Trendyol/Hepsiburada plugin is active next to this one. */
	public static function legacy_notice() {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$active = Eru_MPO_Migrator::legacy_active();
		if ( ! $active ) {
			return;
		}
		$names = implode( ', ', array_map( fn( $k ) => 'trendyol' === $k ? 'Trendyol' : 'Hepsiburada', $active ) );
		echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Marketplace Orders:', 'marketplace-orders-for-woocommerce' ) . '</strong> ';
		/* translators: %s: marketplace names */
		echo esc_html( sprintf( __( 'Eski %s eklentisi hâlâ etkin. Siparişlerin iki kez aktarılmaması için önce eski eklentiyi devre dışı bırakın, sonra geçişi çalıştırın. O zamana kadar bu pazaryerinden sipariş çekilmez.', 'marketplace-orders-for-woocommerce' ), $names ) );
		echo '</p></div>';
	}

	/** Page. */
	public static function render() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'marketplace-orders-for-woocommerce' ), 403 );
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'genel'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$tab  = isset( $tabs[ $tab ] ) ? $tab : (string) array_key_first( $tabs );
		echo '<div class="wrap"><h1>' . esc_html__( 'Pazaryerleri', 'marketplace-orders-for-woocommerce' ) . '</h1><nav class="nav-tab-wrapper">';
		foreach ( $tabs as $slug => $label ) {
			$url = add_query_arg(
				array(
					'page' => self::PAGE,
					'tab'  => $slug,
				),
				admin_url( 'admin.php' )
			);
			echo '<a class="nav-tab' . ( $slug === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( isset( $_GET['updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ayarlar kaydedildi.', 'marketplace-orders-for-woocommerce' ) . '</p></div>';
		}
		Eru_MPO_Admin_Pages::notice();
		Eru_MPO_Admin_Pages::render_tab( $tab );
		echo '</div>';
	}

	/** The Genel tab's settings form. */
	public static function render_general() {
		$s   = Eru_MPO_Settings::all();
		$chk = function ( $key, $label, $help ) use ( $s ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="eru_mpo[' . esc_attr( $key ) . ']" value="yes"' . checked( 'yes', $s[ $key ], false ) . '> ' . esc_html( $help ) . '</label></td></tr>';
		};
		$num = function ( $key, $label, $help ) use ( $s ) {
			$b = Eru_MPO_Settings::BOUNDS[ $key ];
			echo '<tr><th scope="row"><label for="eru-mpo-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="number" class="small-text" id="eru-mpo-' . esc_attr( $key ) . '" name="eru_mpo[' . esc_attr( $key ) . ']" min="' . (int) $b[0] . '" max="' . (int) $b[1] . '" value="' . (int) $s[ $key ] . '"> <span class="description">' . esc_html( $help ) . '</span></td></tr>';
		};
		$sel = function ( $key, $label, array $opts, $help ) use ( $s ) {
			echo '<tr><th scope="row"><label for="eru-mpo-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="eru-mpo-' . esc_attr( $key ) . '" name="eru_mpo[' . esc_attr( $key ) . ']">';
			foreach ( $opts as $v => $l ) {
				echo '<option value="' . esc_attr( $v ) . '"' . selected( $s[ $key ], $v, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select><p class="description">' . esc_html( $help ) . '</p></td></tr>';
		};
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'eru_mpo_save_general' );
		echo '<input type="hidden" name="action" value="eru_mpo_save_general"><table class="form-table" role="presentation">';
		$chk( 'enabled', __( 'Ana anahtar', 'marketplace-orders-for-woocommerce' ), __( 'Pazaryeri siparişlerini çek (kapalıyken hiçbir istek ve zamanlanmış iş yok).', 'marketplace-orders-for-woocommerce' ) );
		$chk( 'observe', __( 'Gözlem modu', 'marketplace-orders-for-woocommerce' ), __( 'Siparişleri çek ve günlüğe "aktarılırdı" yaz; sipariş oluşturma, pazaryerine bildirim gönderme.', 'marketplace-orders-for-woocommerce' ) );
		$num( 'window_days', __( 'Tarama penceresi (gün)', 'marketplace-orders-for-woocommerce' ), __( 'Yeni siparişler için geriye bakılan gün sayısı.', 'marketplace-orders-for-woocommerce' ) );
		$num( 'lifecycle_days', __( 'Yaşam döngüsü (gün)', 'marketplace-orders-for-woocommerce' ), __( 'Kargo, teslim, iptal ve iade güncellemeleri için geriye bakılan gün sayısı.', 'marketplace-orders-for-woocommerce' ) );
		$sel(
			'stock_mode',
			__( 'İçe aktarımda stok', 'marketplace-orders-for-woocommerce' ),
			array(
				'leave'  => __( 'WooCommerce stoğuna dokunma', 'marketplace-orders-for-woocommerce' ),
				'reduce' => __( 'İçe aktarırken düş, iptalde iade et', 'marketplace-orders-for-woocommerce' ),
			),
			__( 'Stok başka bir sistemden (ERP, stok köprüsü) yönetiliyorsa dokunmayın; aksi halde stok iki kez düşebilir.', 'marketplace-orders-for-woocommerce' )
		);
		$sel(
			'status_mode',
			__( 'Sipariş durumları', 'marketplace-orders-for-woocommerce' ),
			array(
				'own'  => __( 'Pazaryeri durumları (Pazaryeri: Yeni, Kargoda …)', 'marketplace-orders-for-woocommerce' ),
				'core' => __( 'WooCommerce çekirdek durumları (Hazırlanıyor, Tamamlandı …)', 'marketplace-orders-for-woocommerce' ),
			),
			__( 'Durumlar her iki modda da yalnızca ileri gider.', 'marketplace-orders-for-woocommerce' )
		);
		$chk( 'mute_notifications', __( 'Bildirimleri sustur', 'marketplace-orders-for-woocommerce' ), __( 'Pazaryeri siparişleri kaydedilirken mağazanın yeni sipariş ve durum dinleyicilerini (e-posta, kargo, ERP …) çalıştırma (izole kayıt; önerilir).', 'marketplace-orders-for-woocommerce' ) );
		$chk( 'store_identity', __( 'e-Arşiv için TC kimlik no sakla', 'marketplace-orders-for-woocommerce' ), __( 'Alıcının kimlik numarasını sipariş verisine kopyala. Kapalıyken saklanmaz; ham veride her zaman maskelenir.', 'marketplace-orders-for-woocommerce' ) );
		$num( 'raw_days', __( 'Ham veri saklama (gün)', 'marketplace-orders-for-woocommerce' ), __( 'Pazaryerinden gelen ham sipariş verisi bu süreden sonra silinir.', 'marketplace-orders-for-woocommerce' ) );
		$num( 'log_days', __( 'Günlük saklama (gün)', 'marketplace-orders-for-woocommerce' ), __( 'Günlük kayıtları bu süreden sonra silinir.', 'marketplace-orders-for-woocommerce' ) );
		echo '</table>';
		submit_button( __( 'Kaydet', 'marketplace-orders-for-woocommerce' ) );
		echo '</form>';
	}

	/** Saves the Genel tab. */
	public static function save_general() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'marketplace-orders-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_mpo_save_general' );
		$in  = isset( $_POST['eru_mpo'] ) && is_array( $_POST['eru_mpo'] ) ? wp_unslash( $_POST['eru_mpo'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$new = array();
		foreach ( array( 'enabled', 'observe', 'mute_notifications', 'store_identity' ) as $k ) {
			$new[ $k ] = isset( $in[ $k ] ) && 'yes' === $in[ $k ] ? 'yes' : 'no';
		}
		foreach ( array_keys( Eru_MPO_Settings::BOUNDS ) as $k ) {
			$new[ $k ] = isset( $in[ $k ] ) ? absint( $in[ $k ] ) : 0;
		}
		$new['stock_mode']  = isset( $in['stock_mode'] ) ? sanitize_key( $in['stock_mode'] ) : '';
		$new['status_mode'] = isset( $in['status_mode'] ) ? sanitize_key( $in['status_mode'] ) : '';
		Eru_MPO_Settings::update( $new );
		Eru_MPO_Cron::maybe_arm();
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE,
					'tab'     => 'genel',
					'updated' => 1,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
