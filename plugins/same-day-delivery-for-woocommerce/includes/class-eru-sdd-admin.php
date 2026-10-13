<?php
/** WooCommerce > Aynı Gün Teslimat settings. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SDD_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Aynı Gün Teslimat', 'same-day-delivery-for-woocommerce' ), __( 'Aynı Gün Teslimat', 'same-day-delivery-for-woocommerce' ), 'manage_woocommerce', 'eru-sdd', array( __CLASS__, 'render' ) );
	}

	public static function register(): void {
		// Saved through options.php: let the menu's capability (not manage_options) save it.
		add_filter( 'option_page_capability_eru_sdd', fn() => 'manage_woocommerce' );
		register_setting(
			'eru_sdd',
			Eru_SDD_Schedule::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Eru_SDD_Schedule::defaults(),
			)
		);
	}

	public static function sanitize( $in ): array {
		$in   = is_array( $in ) ? $in : array();
		$out  = array();
		$time = isset( $in['cutoff'] ) ? sanitize_text_field( $in['cutoff'] ) : '14:00';
		$out['cutoff']        = preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', $time ) ? $time : '14:00';
		$out['show_messages'] = empty( $in['show_messages'] ) ? 0 : 1;
		$out['working_days']  = array_values( array_unique( array_filter( array_map( 'intval', (array) ( $in['working_days'] ?? array() ) ), static fn( $d ) => $d >= 1 && $d <= 7 ) ) );
		foreach ( array( 'holidays', 'locations' ) as $k ) {
			$rows = array();
			foreach ( preg_split( '/\r\n|\r|\n/', (string) ( $in[ $k ] ?? '' ) ) as $line ) {
				$line = sanitize_text_field( $line );
				if ( 'holidays' === $k && ! preg_match( '/^(\d{4}-)?\d{2}-\d{2}$/', $line ) ) {
					continue;
				}
				if ( '' !== $line ) {
					$rows[] = $line;
				}
			}
			$out[ $k ] = implode( "\n", array_unique( $rows ) );
		}
		foreach ( array( 'msg_today', 'msg_tomorrow', 'msg_later' ) as $k ) {
			$out[ $k ] = isset( $in[ $k ] ) ? sanitize_text_field( $in[ $k ] ) : '';
		}
		return $out;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s    = Eru_SDD_Schedule::settings();
		$opt  = Eru_SDD_Schedule::OPTION;
		$days = array(
			1 => __( 'Pazartesi', 'same-day-delivery-for-woocommerce' ),
			2 => __( 'Salı', 'same-day-delivery-for-woocommerce' ),
			3 => __( 'Çarşamba', 'same-day-delivery-for-woocommerce' ),
			4 => __( 'Perşembe', 'same-day-delivery-for-woocommerce' ),
			5 => __( 'Cuma', 'same-day-delivery-for-woocommerce' ),
			6 => __( 'Cumartesi', 'same-day-delivery-for-woocommerce' ),
			7 => __( 'Pazar', 'same-day-delivery-for-woocommerce' ),
		);
		$st = Eru_SDD_Schedule::status();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Aynı Gün Teslimat', 'same-day-delivery-for-woocommerce' ); ?></h1>
			<p><?php echo esc_html( sprintf( /* translators: 1: site time, 2: message */ __( 'Site saati: %1$s — şu an verilen sipariş için mesaj: %2$s', 'same-day-delivery-for-woocommerce' ), Eru_SDD_Schedule::now()->format( 'd.m.Y H:i' ), $st ? $st['text'] : '—' ) ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_sdd' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Mesajlar', 'same-day-delivery-for-woocommerce' ); ?></th><td>
						<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[show_messages]" value="1" <?php checked( $s['show_messages'], 1 ); ?>> <?php esc_html_e( 'Ürün ve sepet sayfasında "Bugün/Yarın kargoda" mesajını göster', 'same-day-delivery-for-woocommerce' ); ?></label>
						<p class="description"><?php esc_html_e( 'Varsayılan kapalı. Kısa kod: [eru_sdd_message]. Sayfa önbelleği kullanıyorsanız mesaj önbellek süresi kadar eski kalabilir.', 'same-day-delivery-for-woocommerce' ); ?></p>
					</td></tr>
					<tr><th><label for="eru-sdd-cutoff"><?php esc_html_e( 'Kesim saati', 'same-day-delivery-for-woocommerce' ); ?></label></th><td>
						<input type="time" id="eru-sdd-cutoff" name="<?php echo esc_attr( $opt ); ?>[cutoff]" value="<?php echo esc_attr( $s['cutoff'] ); ?>">
						<p class="description"><?php esc_html_e( 'Site saat dilimine göre. Bu saatten önce ve çalışma gününde verilen sipariş aynı gün kargoya verilir.', 'same-day-delivery-for-woocommerce' ); ?></p>
					</td></tr>
					<tr><th><?php esc_html_e( 'Çalışma günleri', 'same-day-delivery-for-woocommerce' ); ?></th><td>
						<?php foreach ( $days as $n => $label ) : ?>
							<label style="margin-right:12px"><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[working_days][]" value="<?php echo esc_attr( (string) $n ); ?>" <?php checked( in_array( $n, array_map( 'intval', (array) $s['working_days'] ), true ) ); ?>> <?php echo esc_html( $label ); ?></label>
						<?php endforeach; ?>
					</td></tr>
					<tr><th><label for="eru-sdd-holidays"><?php esc_html_e( 'Tatil günleri', 'same-day-delivery-for-woocommerce' ); ?></label></th><td>
						<textarea id="eru-sdd-holidays" name="<?php echo esc_attr( $opt ); ?>[holidays]" rows="4" class="large-text code" placeholder="2026-12-31&#10;01-01"><?php echo esc_textarea( $s['holidays'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Her satıra bir tarih: YYYY-AA-GG (tek seferlik) veya AA-GG (her yıl).', 'same-day-delivery-for-woocommerce' ); ?></p>
					</td></tr>
					<tr><th><label for="eru-sdd-locations"><?php esc_html_e( 'Aynı gün teslim bölgeleri', 'same-day-delivery-for-woocommerce' ); ?></label></th><td>
						<textarea id="eru-sdd-locations" name="<?php echo esc_attr( $opt ); ?>[locations]" rows="5" class="large-text" placeholder="Örnek Şehir&#10;Örnek Şehir/Örnek İlçe"><?php echo esc_textarea( $s['locations'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Her satıra "Şehir" veya "Şehir/İlçe". Boşsa aynı gün yöntemi hiçbir yerde sunulmaz. İlçe, adres 2 veya şehir alanından eşleştirilir. Yöntemi ayrıca WooCommerce → Ayarlar → Gönderim bölgelerine eklemelisiniz.', 'same-day-delivery-for-woocommerce' ); ?></p>
					</td></tr>
					<tr><th><?php esc_html_e( 'Mesaj metinleri', 'same-day-delivery-for-woocommerce' ); ?></th><td>
						<p><input type="text" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[msg_today]" value="<?php echo esc_attr( $s['msg_today'] ); ?>" placeholder="<?php esc_attr_e( 'Bugün kargoda', 'same-day-delivery-for-woocommerce' ); ?>"></p>
						<p><input type="text" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[msg_tomorrow]" value="<?php echo esc_attr( $s['msg_tomorrow'] ); ?>" placeholder="<?php esc_attr_e( 'Yarın kargoda', 'same-day-delivery-for-woocommerce' ); ?>"></p>
						<p><input type="text" class="regular-text" name="<?php echo esc_attr( $opt ); ?>[msg_later]" value="<?php echo esc_attr( $s['msg_later'] ); ?>" placeholder="<?php esc_attr_e( '%s kargoda', 'same-day-delivery-for-woocommerce' ); ?>"></p>
						<p class="description"><?php esc_html_e( '{cutoff} yer tutucusu kesim saatiyle değişir. Üçüncü metinde %s gün adıdır (hafta sonu/tatil sonrası).', 'same-day-delivery-for-woocommerce' ); ?></p>
					</td></tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
