<?php
/** WooCommerce > Çıkış Kuponu: settings screen. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_EIC_Admin {

	const CAP = 'manage_woocommerce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_eic_save', array( __CLASS__, 'save' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Çıkış Kuponu', 'exit-intent-coupons-for-woocommerce' ), __( 'Çıkış Kuponu', 'exit-intent-coupons-for-woocommerce' ), self::CAP, 'eru-eic', array( __CLASS__, 'render' ) );
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'exit-intent-coupons-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_eic_save' );
		$raw = isset( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in Eru_EIC_Settings::sanitize().
		Eru_EIC_Settings::save( Eru_EIC_Settings::sanitize( $raw ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'eru-eic', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	private static function field( string $label, string $html, string $help = '' ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . $html . ( '' !== $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput -- $html is built from escaped parts below.
	}

	private static function input( string $key, array $s, string $type = 'text', string $class = 'regular-text', string $extra = '' ): string {
		return sprintf( '<input type="%s" class="%s" name="s[%s]" value="%s" %s>', esc_attr( $type ), esc_attr( $class ), esc_attr( $key ), esc_attr( (string) $s[ $key ] ), $extra ); // phpcs:ignore WordPress.Security.EscapeOutput
	}

	private static function check( string $key, array $s, string $label ): string {
		return sprintf( '<label><input type="checkbox" name="s[%s]" value="1" %s> %s</label>', esc_attr( $key ), checked( ! empty( $s[ $key ] ), true, false ), esc_html( $label ) );
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s     = Eru_EIC_Settings::get();
		$stats = Eru_EIC_Coupon::stats();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Çıkış Niyeti Kuponu', 'exit-intent-coupons-for-woocommerce' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ayarlar kaydedildi.', 'exit-intent-coupons-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<?php if ( 'fixed' === $s['mode'] && '' !== $s['code'] && ! wc_get_coupon_id_by_code( $s['code'] ) ) : ?>
				<div class="notice notice-warning eru-keep"><p><?php esc_html_e( 'Yazdığınız kupon kodu WooCommerce’de bulunamadı. Önce Pazarlama → Kuponlar bölümünden oluşturun.', 'exit-intent-coupons-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Ziyaretçi sayfadan ayrılmak üzereyken (masaüstünde fare sayfanın üstünden çıkınca, mobilde hızlı yukarı kaydırınca) bir kupon penceresi gösterir. Eklenti kapalıyken sitede hiçbir değişiklik olmaz.', 'exit-intent-coupons-for-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_eic_save">
				<?php wp_nonce_field( 'eru_eic_save' ); ?>

				<h2><?php esc_html_e( 'Genel', 'exit-intent-coupons-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::field( __( 'Durum', 'exit-intent-coupons-for-woocommerce' ), self::check( 'enabled', $s, __( 'Çıkış kuponu açık', 'exit-intent-coupons-for-woocommerce' ) ) );
					self::field(
						__( 'Kupon türü', 'exit-intent-coupons-for-woocommerce' ),
						'<label><input type="radio" name="s[mode]" value="fixed" ' . checked( $s['mode'], 'fixed', false ) . '> ' . esc_html__( 'Sabit kod (mevcut bir WooCommerce kuponu)', 'exit-intent-coupons-for-woocommerce' ) . '</label><br>' .
						'<label><input type="radio" name="s[mode]" value="generated" ' . checked( $s['mode'], 'generated', false ) . '> ' . esc_html__( 'Her ziyaretçiye özel, tek kullanımlık ve süreli kupon üret', 'exit-intent-coupons-for-woocommerce' ) . '</label>'
					);
					self::field( __( 'Sabit kod', 'exit-intent-coupons-for-woocommerce' ), self::input( 'code', $s, 'text', 'regular-text', 'placeholder="DEMO10"' ), __( 'Yalnızca “Sabit kod” seçiliyken kullanılır.', 'exit-intent-coupons-for-woocommerce' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Üretilen kupon', 'exit-intent-coupons-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::field(
						__( 'İndirim', 'exit-intent-coupons-for-woocommerce' ),
						'<select name="s[discount_type]"><option value="percent" ' . selected( $s['discount_type'], 'percent', false ) . '>' . esc_html__( 'Yüzde (%)', 'exit-intent-coupons-for-woocommerce' ) . '</option><option value="fixed_cart" ' . selected( $s['discount_type'], 'fixed_cart', false ) . '>' . esc_html__( 'Sabit sepet tutarı', 'exit-intent-coupons-for-woocommerce' ) . '</option></select> ' .
						self::input( 'amount', $s, 'text', 'small-text' )
					);
					self::field( __( 'Geçerlilik (saat)', 'exit-intent-coupons-for-woocommerce' ), self::input( 'valid_hours', $s, 'number', 'small-text', 'min="1" max="720"' ) );
					self::field( __( 'Asgari sepet tutarı', 'exit-intent-coupons-for-woocommerce' ), self::input( 'min_spend', $s, 'text', 'small-text' ), __( '0 = sınır yok.', 'exit-intent-coupons-for-woocommerce' ) );
					self::field( __( 'Kod öneki', 'exit-intent-coupons-for-woocommerce' ), self::input( 'prefix', $s, 'text', 'small-text', 'maxlength="8"' ) );
					self::field(
						__( 'Günlük bütçe', 'exit-intent-coupons-for-woocommerce' ),
						self::input( 'daily_limit', $s, 'number', 'small-text', 'min="0"' ),
						sprintf(
							/* translators: 1: coupons issued today, 2: coupons issued in total. */
							__( 'Günde en fazla kaç kupon üretilsin (0 = sınırsız). Bugün: %1$d, toplam: %2$d.', 'exit-intent-coupons-for-woocommerce' ),
							(int) $stats['used'],
							(int) $stats['total']
						)
					);
					self::field( __( 'IP başına saatlik sınır', 'exit-intent-coupons-for-woocommerce' ), self::input( 'ip_hourly', $s, 'number', 'small-text', 'min="1" max="100"' ), __( 'Aynı IP adresinden bir saatte en fazla kaç kupon istenebilir.', 'exit-intent-coupons-for-woocommerce' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Pencere metni ve görünüm', 'exit-intent-coupons-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::field( __( 'Başlık', 'exit-intent-coupons-for-woocommerce' ), self::input( 'title', $s ) );
					self::field( __( 'Metin', 'exit-intent-coupons-for-woocommerce' ), self::input( 'text', $s ) );
					self::field( __( 'Koşul notu', 'exit-intent-coupons-for-woocommerce' ), self::input( 'note', $s ), __( 'İsteğe bağlı; örneğin geçerlilik koşulu.', 'exit-intent-coupons-for-woocommerce' ) );
					self::field( __( 'Kapatma düğmesi', 'exit-intent-coupons-for-woocommerce' ), self::input( 'button', $s ) );
					self::field( __( 'Vurgu rengi', 'exit-intent-coupons-for-woocommerce' ), self::input( 'accent', $s, 'text', 'small-text', 'placeholder="#1f6feb" style="width:110px"' ) );
					?>
				</table>

				<h2><?php esc_html_e( 'Ne zaman gösterilsin', 'exit-intent-coupons-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<?php
					self::field( __( 'Ziyaretçi başına sıklık (gün)', 'exit-intent-coupons-for-woocommerce' ), self::input( 'cookie_days', $s, 'number', 'small-text', 'min="1" max="365"' ), __( 'Pencere gösterildikten sonra bu kadar gün aynı ziyaretçiye tekrar gösterilmez.', 'exit-intent-coupons-for-woocommerce' ) );
					self::field( __( 'Masaüstü: en az bekleme (sn)', 'exit-intent-coupons-for-woocommerce' ), self::input( 'min_seconds', $s, 'number', 'small-text', 'min="0"' ) );
					self::field( __( 'Mobil', 'exit-intent-coupons-for-woocommerce' ), self::check( 'mobile', $s, __( 'Dokunmatik cihazlarda da göster', 'exit-intent-coupons-for-woocommerce' ) ) . '<br>' . esc_html__( 'Bekleme (sn):', 'exit-intent-coupons-for-woocommerce' ) . ' ' . self::input( 'mobile_delay', $s, 'number', 'small-text', 'min="0"' ) . ' ' . esc_html__( 'En az kaydırma (px):', 'exit-intent-coupons-for-woocommerce' ) . ' ' . self::input( 'mobile_depth', $s, 'number', 'small-text', 'min="0"' ) );
					self::field(
						__( 'Sayfa koşulları', 'exit-intent-coupons-for-woocommerce' ),
						self::check( 'hide_checkout', $s, __( 'Ödeme sayfasında gösterme', 'exit-intent-coupons-for-woocommerce' ) ) . '<br>' .
						self::check( 'hide_cart', $s, __( 'Sepet sayfasında gösterme', 'exit-intent-coupons-for-woocommerce' ) ) . '<br>' .
						self::check( 'need_cart', $s, __( 'Yalnızca sepeti dolu ziyaretçilere göster', 'exit-intent-coupons-for-woocommerce' ) )
					);
					?>
				</table>
				<?php submit_button( __( 'Kaydet', 'exit-intent-coupons-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}
}
