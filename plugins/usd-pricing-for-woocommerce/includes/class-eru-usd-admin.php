<?php
/** Product / variation USD fields and WooCommerce > USD Fiyatlandırma settings. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_USD_Admin {

	const CAP = 'manage_woocommerce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_usd_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_eru_usd_refresh', array( __CLASS__, 'refresh' ) );
		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product' ), 20 );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 20, 2 );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'USD Fiyatlandırma', 'usd-pricing-for-woocommerce' ), __( 'USD Fiyatlandırma', 'usd-pricing-for-woocommerce' ), self::CAP, 'eru-usd', array( __CLASS__, 'render' ) );
	}

	/* ---- product fields ----------------------------------------------------- */

	public static function product_fields(): void {
		echo '<div class="options_group">';
		woocommerce_wp_text_input( array( 'id' => '_eru_usd_price', 'label' => __( 'USD fiyat ($)', 'usd-pricing-for-woocommerce' ), 'data_type' => 'price', 'desc_tip' => true, 'description' => __( 'Doluysa mağaza para birimindeki fiyat güncel kurdan hesaplanır ve yukarıdaki fiyatın yerini alır.', 'usd-pricing-for-woocommerce' ) ) );
		woocommerce_wp_text_input( array( 'id' => '_eru_usd_sale_price', 'label' => __( 'USD indirimli fiyat ($)', 'usd-pricing-for-woocommerce' ), 'data_type' => 'price' ) );
		echo '</div>';
	}

	/** WooCommerce verifies the product-save nonce and capability before this action. */
	public static function save_product( $post_id ): void {
		foreach ( array( '_eru_usd_price', '_eru_usd_sale_price' ) as $key ) {
			self::store( (int) $post_id, $key, isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		}
	}

	public static function variation_fields( $loop, $variation_data, $variation ): void {
		foreach ( array( '_eru_usd_price' => __( 'USD fiyat ($)', 'usd-pricing-for-woocommerce' ), '_eru_usd_sale_price' => __( 'USD indirimli fiyat ($)', 'usd-pricing-for-woocommerce' ) ) as $key => $label ) {
			woocommerce_wp_text_input(
				array(
					'id'            => $key . '[' . $loop . ']',
					'name'          => $key . '[' . $loop . ']',
					'value'         => get_post_meta( $variation->ID, $key, true ),
					'label'         => $label,
					'data_type'     => 'price',
					'wrapper_class' => 'form-row form-row-' . ( '_eru_usd_price' === $key ? 'first' : 'last' ),
				)
			);
		}
	}

	public static function save_variation( $variation_id, $loop ): void {
		foreach ( array( '_eru_usd_price', '_eru_usd_sale_price' ) as $key ) {
			$raw = isset( $_POST[ $key ][ $loop ] ) ? wp_unslash( $_POST[ $key ][ $loop ] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
			self::store( (int) $variation_id, $key, $raw );
		}
	}

	private static function store( int $id, string $key, $raw ): void {
		$value = is_scalar( $raw ) ? (float) wc_format_decimal( (string) $raw ) : 0.0;
		if ( $value > 0 ) {
			update_post_meta( $id, $key, $value );
		} else {
			delete_post_meta( $id, $key );
		}
	}

	/* ---- settings ------------------------------------------------------------ */

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'usd-pricing-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_usd_save' );
		$source = isset( $_POST['source'] ) && 'tcmb' === wp_unslash( $_POST['source'] ) ? 'tcmb' : 'manual';
		$rate   = isset( $_POST['manual_rate'] ) ? max( 0.0, (float) wc_format_decimal( wp_unslash( $_POST['manual_rate'] ) ) ) : 0.0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$markup = isset( $_POST['markup'] ) ? min( 100.0, max( 0.0, (float) wc_format_decimal( wp_unslash( $_POST['markup'] ) ) ) ) : 0.0; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$before = Eru_USD_Rate::effective();
		update_option( 'eru_usd_source', $source );
		update_option( 'eru_usd_manual_rate', $rate );
		update_option( 'eru_usd_markup', $markup );
		update_option( 'eru_usd_show_usd', ! empty( $_POST['show_usd'] ) ? 1 : 0 );
		if ( abs( Eru_USD_Rate::effective() - $before ) > 0.00005 ) {
			do_action( 'eru_usd_rate_changed', Eru_USD_Rate::effective() );
		}
		wp_safe_redirect( add_query_arg( array( 'page' => 'eru-usd', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function refresh(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'usd-pricing-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_usd_refresh' );
		delete_transient( Eru_USD_Rate::CACHE_KEY );
		delete_transient( Eru_USD_Rate::BACKOFF );
		$ok = Eru_USD_Rate::fetch() > 0;
		wp_safe_redirect( add_query_arg( array( 'page' => 'eru-usd', $ok ? 'refreshed' : 'failed' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$source = Eru_USD_Rate::source();
		$last   = get_option( Eru_USD_Rate::LAST, array() );
		$eff    = Eru_USD_Rate::effective();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'USD Fiyatlandırma', 'usd-pricing-for-woocommerce' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ayarlar kaydedildi.', 'usd-pricing-for-woocommerce' ); ?></p></div>
			<?php elseif ( isset( $_GET['refreshed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'TCMB kuru güncellendi.', 'usd-pricing-for-woocommerce' ); ?></p></div>
			<?php elseif ( isset( $_GET['failed'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'TCMB kuru alınamadı; mevcut değer korundu.', 'usd-pricing-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Ürün düzenleme ekranındaki "USD fiyat" alanı dolu olan ürünlerin fiyatı, aşağıdaki kurla hesaplanır. Kur tanımlı değilse eklenti hiçbir fiyata dokunmaz.', 'usd-pricing-for-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_usd_save">
				<?php wp_nonce_field( 'eru_usd_save' ); ?>
				<table class="form-table">
					<tr><th><?php esc_html_e( 'Kur kaynağı', 'usd-pricing-for-woocommerce' ); ?></th><td>
						<label><input type="radio" name="source" value="manual" <?php checked( $source, 'manual' ); ?>> <?php esc_html_e( 'Elle girilen kur (dış bağlantı yok)', 'usd-pricing-for-woocommerce' ); ?></label><br>
						<label><input type="radio" name="source" value="tcmb" <?php checked( $source, 'tcmb' ); ?>> <?php esc_html_e( 'TCMB günlük kuru (döviz satış, 6 saat önbellek)', 'usd-pricing-for-woocommerce' ); ?></label>
					</td></tr>
					<tr><th><label for="eru-usd-rate"><?php esc_html_e( 'Elle kur (1 $ =)', 'usd-pricing-for-woocommerce' ); ?></label></th><td><input id="eru-usd-rate" type="text" class="small-text" name="manual_rate" value="<?php echo esc_attr( wc_format_localized_decimal( (string) get_option( 'eru_usd_manual_rate', '' ) ) ); ?>"></td></tr>
					<tr><th><label for="eru-usd-markup"><?php esc_html_e( 'Kur payı (%)', 'usd-pricing-for-woocommerce' ); ?></label></th><td><input id="eru-usd-markup" type="text" class="small-text" name="markup" value="<?php echo esc_attr( wc_format_localized_decimal( (string) get_option( 'eru_usd_markup', '0' ) ) ); ?>"></td></tr>
					<tr><th><?php esc_html_e( 'Gösterim', 'usd-pricing-for-woocommerce' ); ?></th><td><label><input type="checkbox" name="show_usd" value="1" <?php checked( (bool) get_option( 'eru_usd_show_usd' ) ); ?>> <?php esc_html_e( 'Fiyatın yanında dolar karşılığını da göster', 'usd-pricing-for-woocommerce' ); ?></label></td></tr>
				</table>
				<p><strong><?php esc_html_e( 'Uygulanan kur:', 'usd-pricing-for-woocommerce' ); ?></strong>
					<?php echo $eff > 0 ? esc_html( number_format_i18n( $eff, 4 ) ) : esc_html__( 'tanımsız (eklenti pasif)', 'usd-pricing-for-woocommerce' ); ?>
					<?php if ( 'tcmb' === $source && is_array( $last ) && ! empty( $last['time'] ) ) : ?>
						&middot; <?php echo esc_html( sprintf( /* translators: %s: date/time */ __( 'son TCMB güncellemesi: %s', 'usd-pricing-for-woocommerce' ), wp_date( 'd.m.Y H:i', (int) $last['time'] ) ) ); ?>
					<?php endif; ?>
				</p>
				<?php submit_button( __( 'Kaydet', 'usd-pricing-for-woocommerce' ) ); ?>
			</form>
			<?php if ( 'tcmb' === $source ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="eru_usd_refresh">
					<?php wp_nonce_field( 'eru_usd_refresh' ); ?>
					<?php submit_button( __( 'TCMB kurunu şimdi güncelle', 'usd-pricing-for-woocommerce' ), 'secondary' ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
