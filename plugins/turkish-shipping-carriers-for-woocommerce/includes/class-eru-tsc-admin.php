<?php
/**
 * WooCommerce → Kargo Firmaları: carriers, rates, tracking URL templates.
 *
 * @package turkish-shipping-carriers-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_TSC_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Kargo Firmaları', 'turkish-shipping-carriers-for-woocommerce' ), __( 'Kargo Firmaları', 'turkish-shipping-carriers-for-woocommerce' ), 'manage_woocommerce', 'eru-tsc', array( __CLASS__, 'render' ) );
	}

	public static function register(): void {
		// Saved through options.php: let the menu's capability (not manage_options) save it.
		add_filter( 'option_page_capability_eru_tsc', fn() => 'manage_woocommerce' );
		register_setting( 'eru_tsc', Eru_TSC_Carriers::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( 'Eru_TSC_Carriers', 'sanitize_carriers' ), 'default' => array() ) );
		register_setting( 'eru_tsc', Eru_TSC_Carriers::SETTINGS_OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ), 'default' => array() ) );
	}

	public static function sanitize_settings( $in ): array {
		$in = is_array( $in ) ? $in : array();
		return array(
			'notify_customer' => empty( $in['notify_customer'] ) ? 0 : 1,
			'show_in_emails'  => empty( $in['show_in_emails'] ) ? 0 : 1,
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$opt = Eru_TSC_Carriers::OPTION;
		$s   = Eru_TSC_Carriers::settings();
		$cur = get_woocommerce_currency_symbol();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kargo Firmaları', 'turkish-shipping-carriers-for-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Firmaları etkinleştirip ücretlerini girin, ardından WooCommerce → Ayarlar → Gönderim bölgelerinde bölgenize "Kargo firmaları" yöntemini ekleyin. Etkin her firma ödemede ayrı bir seçenek olarak görünür.', 'turkish-shipping-carriers-for-woocommerce' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_tsc' ); ?>
				<table class="widefat striped eru-tsc-table">
					<thead><tr>
						<th style="width:52px"><?php esc_html_e( 'Etkin', 'turkish-shipping-carriers-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Firma adı', 'turkish-shipping-carriers-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Ücret tipi', 'turkish-shipping-carriers-for-woocommerce' ); ?></th>
						<?php /* translators: %s: currency symbol */ ?>
						<th><?php echo esc_html( sprintf( __( 'Ücret / taban (%s)', 'turkish-shipping-carriers-for-woocommerce' ), $cur ) ); ?></th>
						<?php /* translators: %s: currency symbol */ ?>
						<th><?php echo esc_html( sprintf( __( 'Desi başına (%s)', 'turkish-shipping-carriers-for-woocommerce' ), $cur ) ); ?></th>
						<?php /* translators: %s: currency symbol */ ?>
						<th><?php echo esc_html( sprintf( __( 'Bu tutar ve üzeri ücretsiz (%s)', 'turkish-shipping-carriers-for-woocommerce' ), $cur ) ); ?></th>
						<th><?php esc_html_e( 'Takip URL şablonu', 'turkish-shipping-carriers-for-woocommerce' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( Eru_TSC_Carriers::all() as $id => $c ) : $n = $opt . '[' . $id . ']'; ?>
						<tr>
							<td><input type="checkbox" name="<?php echo esc_attr( $n . '[enabled]' ); ?>" value="1" <?php checked( $c['enabled'], 1 ); ?> aria-label="<?php esc_attr_e( 'Etkin', 'turkish-shipping-carriers-for-woocommerce' ); ?>"></td>
							<td><input type="text" class="regular-text" style="width:100%" name="<?php echo esc_attr( $n . '[name]' ); ?>" value="<?php echo esc_attr( $c['name'] ); ?>" placeholder="<?php esc_attr_e( 'Diğer kargo firması', 'turkish-shipping-carriers-for-woocommerce' ); ?>"></td>
							<td><select name="<?php echo esc_attr( $n . '[mode]' ); ?>">
								<option value="flat" <?php selected( $c['mode'], 'flat' ); ?>><?php esc_html_e( 'Sabit', 'turkish-shipping-carriers-for-woocommerce' ); ?></option>
								<option value="desi" <?php selected( $c['mode'], 'desi' ); ?>><?php esc_html_e( 'Taban + desi', 'turkish-shipping-carriers-for-woocommerce' ); ?></option>
							</select></td>
							<td><input type="text" inputmode="decimal" size="7" name="<?php echo esc_attr( $n . '[cost]' ); ?>" value="<?php echo esc_attr( $c['cost'] ); ?>"></td>
							<td><input type="text" inputmode="decimal" size="7" name="<?php echo esc_attr( $n . '[per_desi]' ); ?>" value="<?php echo esc_attr( $c['per_desi'] ); ?>"></td>
							<td><input type="text" inputmode="decimal" size="9" name="<?php echo esc_attr( $n . '[free_over]' ); ?>" value="<?php echo esc_attr( $c['free_over'] ); ?>"></td>
							<td><input type="text" class="large-text code" name="<?php echo esc_attr( $n . '[tracking_url]' ); ?>" value="<?php echo esc_attr( $c['tracking_url'] ); ?>" placeholder="https://…?no={takip_no}"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">
					<?php esc_html_e( 'Ücretsiz eşiği 0 ise kapalıdır; eşik, indirimler sonrası vergi hariç ürün tutarıyla karşılaştırılır. Desi = her ürün için ağırlık (kg) ile en×boy×yükseklik (cm) / 3000 değerinden büyüğü × adet; toplam yukarı yuvarlanır. Takip URL şablonunda {takip_no} takip numarasıyla değiştirilir. Firmalar takip sayfalarını zaman zaman değiştirir; şablonları kendi hesabınızla doğrulayın.', 'turkish-shipping-carriers-for-woocommerce' ); ?>
				</p>
				<h2><?php esc_html_e( 'Bildirim', 'turkish-shipping-carriers-for-woocommerce' ); ?></h2>
				<p>
					<label><input type="checkbox" name="<?php echo esc_attr( Eru_TSC_Carriers::SETTINGS_OPTION ); ?>[show_in_emails]" value="1" <?php checked( $s['show_in_emails'], 1 ); ?>> <?php esc_html_e( 'Takip numarası ve bağlantısını sipariş e-postalarında göster', 'turkish-shipping-carriers-for-woocommerce' ); ?></label><br>
					<label><input type="checkbox" name="<?php echo esc_attr( Eru_TSC_Carriers::SETTINGS_OPTION ); ?>[notify_customer]" value="1" <?php checked( $s['notify_customer'], 1 ); ?>> <?php esc_html_e( 'Yeni takip numarası kaydedilince müşteriye sipariş notu e-postası gönder', 'turkish-shipping-carriers-for-woocommerce' ); ?></label>
				</p>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
