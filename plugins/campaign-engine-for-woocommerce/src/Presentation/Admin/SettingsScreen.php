<?php
/**
 * Kampanyalar → Ayarlar: how campaigns combine, usage holds, rewards, cache purging, the shop-front
 * modules (all off by default) and the addresses of the campaign pages and of Hesabım → Ödüllerim.
 *
 * Saved through admin-post with the `manage_eru_ce_settings` meta capability and a nonce. Engine
 * values go through Options::update (the engine's own readers); a page address that an existing page,
 * the product base or the category base already uses is refused, because the page would disappear
 * behind the campaign archive.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Admin;

use Erusoft\CampaignEngine\Engine\Money;
use Erusoft\CampaignEngine\Infrastructure\Settings\Options;
use Erusoft\CampaignEngine\Infrastructure\Settings\StorefrontOptions;
use WP_Error;

final class SettingsScreen {

	public const ACTION = 'eru_ce_settings';
	public const NONCE  = 'eru_ce_settings';

	/** Engine/store keys posted as plain values. */
	private const ENGINE_KEYS = array( 'stacking', 'max_cart_discount_percent', 'with_coupons', 'reward_maturation_days', 'reward_coupon_days', 'reward_refund_policy', 'reservation_mode', 'reservation_ttl_hours', 'cache_adaptor', 'retention_days' );

	public static function hooks(): void {
		add_action( 'admin_post_' . self::ACTION, array( self::class, 'handle' ) );
	}

	/** admin-post handler. */
	public static function handle(): void {
		$nonce = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- every key is validated in save().
		$input  = isset( $_POST['eru_ce_settings'] ) && is_array( $_POST['eru_ce_settings'] ) ? wp_unslash( $_POST['eru_ce_settings'] ) : array();
		$result = self::save( $input, $nonce );

		if ( is_wp_error( $result ) && 'eru_ce_forbidden' === $result->get_error_code() ) {
			wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 403 ) );
		}

		if ( is_wp_error( $result ) ) {
			$data = (array) $result->get_error_data();
			set_transient( 'eru_ce_settings_error_' . get_current_user_id(), TraceText::error( (string) ( $data['field'] ?? '' ), (string) ( $data['reason'] ?? 'invalid' ) ), 5 * MINUTE_IN_SECONDS );
		}

		wp_safe_redirect( Menu::url( 'eru-ce-settings', array( 'eru_ce_saved' => is_wp_error( $result ) ? '0' : '1' ) ) );
		exit;
	}

	/**
	 * Validates and saves posted settings. Keys that are absent keep their stored value.
	 *
	 * @param array<string,mixed> $input
	 * @return true|WP_Error error data: field, reason
	 */
	public static function save( array $input, string $nonce ) {
		if ( ! wp_verify_nonce( $nonce, self::NONCE ) || ! current_user_can( Menu::SETTINGS_CAP ) ) {
			return new WP_Error( 'eru_ce_forbidden', __( 'Bu işlem için yetkiniz yok ya da oturum süresi doldu.', 'campaign-engine-for-woocommerce' ), array( 'field' => '', 'reason' => 'forbidden' ) );
		}

		$engine = array();

		foreach ( self::ENGINE_KEYS as $key ) {
			if ( array_key_exists( $key, $input ) && is_scalar( $input[ $key ] ) ) {
				$engine[ $key ] = trim( (string) $input[ $key ] );
			}
		}

		if ( isset( $engine['max_cart_discount_percent'] ) ) {
			$engine['max_cart_discount_percent'] = str_replace( ',', '.', $engine['max_cart_discount_percent'] );
		}

		if ( array_key_exists( 'min_unit_price', $input ) ) {
			$errors = array();
			$minor  = WizardForm::money( is_scalar( $input['min_unit_price'] ) ? (string) $input['min_unit_price'] : 'x', 'min_unit_price', $errors, true );

			if ( array() !== $errors ) {
				return self::error( 'min_unit_price', 'invalid' );
			}

			$engine['min_unit_price_minor'] = $minor ?? 0;
		}

		$front = array();

		foreach ( array_keys( StorefrontOptions::defaults() ) as $key ) {
			if ( 'rewrite_pending' !== $key && array_key_exists( $key, $input ) && is_scalar( $input[ $key ] ) ) {
				$front[ $key ] = trim( (string) $input[ $key ] );
			}
		}

		$next  = array_merge( StorefrontOptions::all(), $front );
		$valid = StorefrontOptions::check( $next );

		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$clash = self::slugClash( $next );

		if ( null !== $clash ) {
			return self::error( $clash, 'in_use' );
		}

		if ( array() !== $engine ) {
			$saved = Options::update( $engine );

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		if ( array() !== $front ) {
			$saved = StorefrontOptions::update( $front );

			if ( is_wp_error( $saved ) ) {
				return $saved;
			}
		}

		return true;
	}

	/**
	 * The slug field that collides with a page or with WooCommerce's product/category base, or null.
	 *
	 * @param array<string,mixed> $next
	 */
	private static function slugClash( array $next ): ?string {
		$current = StorefrontOptions::all();
		$bases   = function_exists( 'wc_get_permalink_structure' ) ? wc_get_permalink_structure() : array();
		$taken   = array_filter(
			array(
				trim( (string) ( $bases['product_rewrite_slug'] ?? '' ), '/' ),
				trim( (string) ( $bases['category_rewrite_slug'] ?? '' ), '/' ),
				trim( (string) ( $bases['tag_rewrite_slug'] ?? '' ), '/' ),
			)
		);

		foreach ( array( 'archive_slug', 'single_slug' ) as $key ) {
			$slug = (string) $next[ $key ];

			if ( $slug === $current[ $key ] && 'yes' === $current['pages'] ) {
				continue; // Unchanged and already live: nothing new to check.
			}

			if ( in_array( $slug, $taken, true ) || null !== get_page_by_path( $slug, OBJECT, array( 'page', 'post', 'product' ) ) ) {
				return $key;
			}
		}

		return null;
	}

	private static function error( string $field, string $reason ): WP_Error {
		return new WP_Error(
			'eru_ce_invalid_setting',
			TraceText::error( $field, $reason ),
			array(
				'field'  => $field,
				'reason' => $reason,
			)
		);
	}

	public static function render(): void {
		if ( ! current_user_can( Menu::SETTINGS_CAP ) ) {
			wp_die( esc_html__( 'Bu sayfayı görme yetkiniz yok.', 'campaign-engine-for-woocommerce' ), '', array( 'response' => 403 ) );
		}

		$o     = Options::all();
		$f     = StorefrontOptions::all();
		$error = get_transient( 'eru_ce_settings_error_' . get_current_user_id() );

		echo '<div class="wrap eru-ce-page"><h1>' . esc_html__( 'Kampanya ayarları', 'campaign-engine-for-woocommerce' ) . '</h1>';

		if ( is_string( $error ) && '' !== $error ) {
			delete_transient( 'eru_ce_settings_error_' . get_current_user_id() );
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Ayarlar kaydedilmedi:', 'campaign-engine-for-woocommerce' ) . ' ' . esc_html( $error ) . '</p></div>';
		} elseif ( isset( $_GET['eru_ce_saved'] ) && '1' === $_GET['eru_ce_saved'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Ayarlar kaydedildi.', 'campaign-engine-for-woocommerce' ) . '</p></div>';
		}

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '">';
		wp_nonce_field( self::NONCE );

		echo '<h2>' . esc_html__( 'Kampanyalar birlikte nasıl çalışır', 'campaign-engine-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		self::select(
			'stacking',
			__( 'Aynı ürüne birden çok kampanya uyduğunda', 'campaign-engine-for-woocommerce' ),
			(string) $o['stacking'],
			array(
				'best'  => __( 'Müşteriye en avantajlı olan uygulanır', 'campaign-engine-for-woocommerce' ),
				'first' => __( 'Önceliği en yüksek olan uygulanır', 'campaign-engine-for-woocommerce' ),
				'stack' => __( 'Hepsi toplanır (sınırlar geçerli)', 'campaign-engine-for-woocommerce' ),
			)
		);
		self::input( 'max_cart_discount_percent', __( 'Sepet indirim sınırı (%)', 'campaign-engine-for-woocommerce' ), (string) $o['max_cart_discount_percent'], __( 'Kampanyaların bir sepette verebileceği toplam indirim, ara toplamın bu yüzdesini aşamaz. Boş = sınır yok.', 'campaign-engine-for-woocommerce' ) );
		self::input( 'min_unit_price', __( 'En düşük birim fiyat', 'campaign-engine-for-woocommerce' ), Money::toDecimal( (int) $o['min_unit_price_minor'], wc_get_price_decimals() ), __( 'Hiçbir ürünün birim fiyatı kampanyayla bu tutarın altına inmez.', 'campaign-engine-for-woocommerce' ) );
		self::select(
			'with_coupons',
			__( 'Sepette kupon varken', 'campaign-engine-for-woocommerce' ),
			(string) $o['with_coupons'],
			array(
				'yes' => __( 'Kampanyalar da uygulanır', 'campaign-engine-for-woocommerce' ),
				'no'  => __( 'Kampanyalar uygulanmaz', 'campaign-engine-for-woocommerce' ),
			)
		);
		echo '</table>';

		echo '<h2>' . esc_html__( 'Kullanım sınırları', 'campaign-engine-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		self::select(
			'reservation_mode',
			__( 'Kullanım hakkı ne zaman sayılır', 'campaign-engine-for-woocommerce' ),
			(string) $o['reservation_mode'],
			array(
				'reserve'   => __( 'Sipariş verilirken ayrılır, ödenince kesinleşir', 'campaign-engine-for-woocommerce' ),
				'paid_only' => __( 'Yalnızca ödenen siparişler sayılır', 'campaign-engine-for-woocommerce' ),
			)
		);
		self::input( 'reservation_ttl_hours', __( 'Ödenmeyen siparişin hakkı kaç saat sonra bırakılır', 'campaign-engine-for-woocommerce' ), (string) $o['reservation_ttl_hours'], __( 'Havale bekleyen siparişlerin hakkı ödenene ya da iptal edilene kadar tutulur.', 'campaign-engine-for-woocommerce' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Harca kazan ödülleri', 'campaign-engine-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		self::select(
			'reward_maturation_days',
			__( 'Ödül kuponu ödemeden kaç gün sonra gönderilir', 'campaign-engine-for-woocommerce' ),
			(string) $o['reward_maturation_days'],
			array(
				'0'  => __( 'Hemen', 'campaign-engine-for-woocommerce' ),
				'7'  => __( '7 gün', 'campaign-engine-for-woocommerce' ),
				'14' => __( '14 gün', 'campaign-engine-for-woocommerce' ),
				'30' => __( '30 gün', 'campaign-engine-for-woocommerce' ),
			)
		);
		self::input( 'reward_coupon_days', __( 'Kupon geçerlilik süresi (gün)', 'campaign-engine-for-woocommerce' ), (string) $o['reward_coupon_days'], __( 'Kampanyada süre girilmemişse kullanılır.', 'campaign-engine-for-woocommerce' ) );
		self::select(
			'reward_refund_policy',
			__( 'Sipariş kısmen iade edilirse', 'campaign-engine-for-woocommerce' ),
			(string) $o['reward_refund_policy'],
			array(
				'pro_rata' => __( 'Ödül iade oranında azalır', 'campaign-engine-for-woocommerce' ),
				'full'     => __( 'Ödül iptal edilir', 'campaign-engine-for-woocommerce' ),
			)
		);
		echo '</table>';

		echo '<h2>' . esc_html__( 'Sayfa önbelleği', 'campaign-engine-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		self::select(
			'cache_adaptor',
			__( 'Kampanya değişince önbellek', 'campaign-engine-for-woocommerce' ),
			(string) $o['cache_adaptor'],
			array(
				'none'      => __( 'Temizlenmez', 'campaign-engine-for-woocommerce' ),
				'litespeed' => __( 'LiteSpeed Cache ile ilgili ürünler temizlenir', 'campaign-engine-for-woocommerce' ),
				'custom'    => __( 'eru_ce_cache_purge eylemi tetiklenir (kendi kodunuz)', 'campaign-engine-for-woocommerce' ),
			)
		);
		echo '</table>';

		echo '<h2>' . esc_html__( 'Kişisel veri saklama', 'campaign-engine-for-woocommerce' ) . '</h2><table class="form-table" role="presentation">';
		self::input( 'retention_days', __( 'Saklama süresi (gün)', 'campaign-engine-for-woocommerce' ), (string) $o['retention_days'], __( 'Bu süreden eski kapanmış ödüllerin e-postası, kullanım kayıtlarının e-posta özeti ve denetim kaydı satırları her gün parça parça silinir (30–3650, varsayılan 365).', 'campaign-engine-for-woocommerce' ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Mağaza önü', 'campaign-engine-for-woocommerce' ) . '</h2>';
		echo '<p>' . esc_html__( 'Hepsi varsayılan olarak kapalıdır. İndirimler bu modüller kapalıyken de sepette uygulanır; modüller yalnızca kampanyayı müşteriye gösterir.', 'campaign-engine-for-woocommerce' ) . '</p><table class="form-table" role="presentation">';
		$modules = array(
			'badge'            => __( 'Ürün listesinde kampanya rozeti', 'campaign-engine-for-woocommerce' ),
			'price_html'       => __( 'Tek adette indirim varsa fiyatın üstü çizili gösterimi', 'campaign-engine-for-woocommerce' ),
			'gain_box'         => __( 'Ürün sayfasında kazanç kutusu (bitiş zamanı olan kampanyada geri sayım)', 'campaign-engine-for-woocommerce' ),
			'smart_cart'       => __( 'Sepette kazanılanlar, teklifler ve ilerleme çubuğu (klasik sepet)', 'campaign-engine-for-woocommerce' ),
			'checkout_summary' => __( 'Sepet ve ödeme özetinde kampanya kazancı satırı (klasik)', 'campaign-engine-for-woocommerce' ),
			'thank_you'        => __( 'Teşekkür sayfasında kazanç ve ödül kutusu', 'campaign-engine-for-woocommerce' ),
			'pages'            => __( 'Kampanyalar sayfası ve kampanya detay sayfaları', 'campaign-engine-for-woocommerce' ),
			'account'          => __( 'Hesabım → Ödüllerim sekmesi', 'campaign-engine-for-woocommerce' ),
		);

		foreach ( $modules as $key => $label ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><input type="hidden" name="eru_ce_settings[' . esc_attr( $key ) . ']" value="no"><label><input type="checkbox" name="eru_ce_settings[' . esc_attr( $key ) . ']" value="yes"' . checked( 'yes', (string) $f[ $key ], false ) . '> ' . esc_html__( 'Açık', 'campaign-engine-for-woocommerce' ) . '</label></td></tr>';
		}

		echo '</table>';

		echo '<h2>' . esc_html__( 'Adresler', 'campaign-engine-for-woocommerce' ) . '</h2><p>' . esc_html__( 'Küçük harf, rakam ve tire. Bir adres değiştiğinde kalıcı bağlantılar bir kez kendiliğinden yenilenir.', 'campaign-engine-for-woocommerce' ) . '</p><table class="form-table" role="presentation">';
		self::input( 'archive_slug', __( 'Kampanyalar sayfası', 'campaign-engine-for-woocommerce' ), (string) $f['archive_slug'], home_url( '/' . $f['archive_slug'] . '/' ) );
		self::input( 'single_slug', __( 'Kampanya detay sayfası', 'campaign-engine-for-woocommerce' ), (string) $f['single_slug'], home_url( '/' . $f['single_slug'] . '/…/' ) );
		self::input( 'account_endpoint', __( 'Hesabım → Ödüllerim', 'campaign-engine-for-woocommerce' ), (string) $f['account_endpoint'], '' );
		echo '</table>';

		submit_button( __( 'Ayarları kaydet', 'campaign-engine-for-woocommerce' ) );
		echo '</form></div>';
	}

	private static function input( string $key, string $label, string $value, string $hint ): void {
		echo '<tr><th scope="row"><label for="eru-ce-set-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><input type="text" class="regular-text" id="eru-ce-set-' . esc_attr( $key ) . '" name="eru_ce_settings[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '">';

		if ( '' !== $hint ) {
			echo '<p class="description">' . esc_html( $hint ) . '</p>';
		}

		echo '</td></tr>';
	}

	/** @param array<string,string> $options */
	private static function select( string $key, string $label, string $value, array $options ): void {
		echo '<tr><th scope="row"><label for="eru-ce-set-' . esc_attr( $key ) . '">' . esc_html( $label ) . '</label></th><td><select id="eru-ce-set-' . esc_attr( $key ) . '" name="eru_ce_settings[' . esc_attr( $key ) . ']">';

		foreach ( $options as $option => $text ) {
			echo '<option value="' . esc_attr( (string) $option ) . '"' . selected( $value, (string) $option, false ) . '>' . esc_html( $text ) . '</option>';
		}

		echo '</select></td></tr>';
	}
}
