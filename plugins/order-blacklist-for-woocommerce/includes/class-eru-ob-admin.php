<?php
/** WooCommerce > Kara Liste: lists, message, log. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OB_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_eru_ob_clear_log', array( __CLASS__, 'clear_log' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Sipariş Kara Liste', 'order-blacklist-for-woocommerce' ), __( 'Kara Liste', 'order-blacklist-for-woocommerce' ), 'manage_woocommerce', 'eru-ob', array( __CLASS__, 'render' ) );
	}

	public static function register(): void {
		// Saved through options.php: let the menu's capability (not manage_options) save it.
		add_filter( 'option_page_capability_eru_ob', fn() => 'manage_woocommerce' );
		register_setting(
			'eru_ob',
			Eru_OB_Guard::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Eru_OB_Guard::defaults(),
			)
		);
	}

	public static function sanitize( $in ): array {
		$in  = is_array( $in ) ? $in : array();
		$out = array();
		foreach ( array( 'emails', 'phones', 'ips', 'addresses' ) as $k ) {
			$rows = array();
			foreach ( Eru_OB_Guard::lines( isset( $in[ $k ] ) ? $in[ $k ] : '' ) as $row ) {
				$row = sanitize_text_field( $row );
				if ( 'ips' === $k && ! filter_var( $row, FILTER_VALIDATE_IP ) ) {
					continue;
				}
				if ( '' !== $row ) {
					$rows[] = $row;
				}
			}
			$out[ $k ] = implode( "\n", array_unique( $rows ) );
		}
		$out['message']  = isset( $in['message'] ) ? sanitize_text_field( $in['message'] ) : '';
		$out['auto_ip']  = empty( $in['auto_ip'] ) ? 0 : 1;
		$out['backstop'] = empty( $in['backstop'] ) ? 0 : 1;
		return $out;
	}

	public static function clear_log(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'order-blacklist-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_ob_clear_log' );
		update_option( Eru_OB_Guard::LOG_OPTION, array(), false );
		wp_safe_redirect( admin_url( 'admin.php?page=eru-ob' ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$s   = Eru_OB_Guard::settings();
		$opt = Eru_OB_Guard::OPTION;
		$log = get_option( Eru_OB_Guard::LOG_OPTION, array() );
		$log = is_array( $log ) ? $log : array();
		$types = array(
			'email'   => __( 'E-posta', 'order-blacklist-for-woocommerce' ),
			'phone'   => __( 'Telefon', 'order-blacklist-for-woocommerce' ),
			'ip'      => __( 'IP', 'order-blacklist-for-woocommerce' ),
			'address' => __( 'Adres', 'order-blacklist-for-woocommerce' ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sipariş Kara Liste', 'order-blacklist-for-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Listelerden biriyle eşleşen müşteri ödeme adımında engellenir. Listeler varsayılan olarak boştur; boşken eklenti hiçbir şey yapmaz.', 'order-blacklist-for-woocommerce' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_ob' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><label for="eru-ob-emails"><?php esc_html_e( 'E-posta', 'order-blacklist-for-woocommerce' ); ?></label></th><td>
						<textarea id="eru-ob-emails" name="<?php echo esc_attr( $opt ); ?>[emails]" rows="5" class="large-text code" placeholder="kotu@example.com&#10;@example.net"><?php echo esc_textarea( $s['emails'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Her satıra bir adres; tam eşleşme, büyük/küçük harf duyarsız. @alanadi.com yazarsanız alan adının tamamı engellenir.', 'order-blacklist-for-woocommerce' ); ?></p>
					</td></tr>
					<tr><th><label for="eru-ob-phones"><?php esc_html_e( 'Telefon', 'order-blacklist-for-woocommerce' ); ?></label></th><td>
						<textarea id="eru-ob-phones" name="<?php echo esc_attr( $opt ); ?>[phones]" rows="4" class="large-text code" placeholder="05XX XXX XX XX"><?php echo esc_textarea( $s['phones'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Her satıra bir numara. Yalnızca rakamlar karşılaştırılır (son 10 hane; +90 ve 0 öneki fark etmez).', 'order-blacklist-for-woocommerce' ); ?></p>
					</td></tr>
					<tr><th><label for="eru-ob-ips"><?php esc_html_e( 'IP adresi', 'order-blacklist-for-woocommerce' ); ?></label></th><td>
						<textarea id="eru-ob-ips" name="<?php echo esc_attr( $opt ); ?>[ips]" rows="4" class="large-text code" placeholder="2001:db8::7"><?php echo esc_textarea( $s['ips'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Her satıra bir IPv4/IPv6 adresi. Ters proxy/CDN arkasındaysanız eru_ob_client_ip filtresini kullanın.', 'order-blacklist-for-woocommerce' ); ?></p>
					</td></tr>
					<tr><th><label for="eru-ob-addr"><?php esc_html_e( 'Adres parçası', 'order-blacklist-for-woocommerce' ); ?></label></th><td>
						<textarea id="eru-ob-addr" name="<?php echo esc_attr( $opt ); ?>[addresses]" rows="4" class="large-text" placeholder="Örnek Sk. No: 1"><?php echo esc_textarea( $s['addresses'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Her satıra bir adres parçası; fatura ve teslimat adresinde aranır (büyük/küçük harf duyarsız).', 'order-blacklist-for-woocommerce' ); ?></p>
					</td></tr>
					<tr><th><label for="eru-ob-msg"><?php esc_html_e( 'Müşteriye gösterilen mesaj', 'order-blacklist-for-woocommerce' ); ?></label></th><td>
						<input type="text" id="eru-ob-msg" class="large-text" name="<?php echo esc_attr( $opt ); ?>[message]" value="<?php echo esc_attr( $s['message'] ); ?>" placeholder="<?php echo esc_attr( Eru_OB_Guard::message() ); ?>">
						<p class="description"><?php esc_html_e( 'Hangi alanın eşleştiğini belli etmeyen genel bir mesaj kullanın.', 'order-blacklist-for-woocommerce' ); ?></p>
					</td></tr>
					<tr><th><?php esc_html_e( 'Seçenekler', 'order-blacklist-for-woocommerce' ); ?></th><td>
						<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[backstop]" value="1" <?php checked( $s['backstop'], 1 ); ?>> <?php esc_html_e( 'Diğer yollarla oluşan eşleşen siparişleri iptal et (REST API, kodla ya da başka eklentilerle oluşturulanlar). Klasik ve blok ödeme sayfası bu ayardan bağımsız her zaman denetlenir; mağaza personelinin yönetim panelinden oluşturduğu siparişlere dokunulmaz.', 'order-blacklist-for-woocommerce' ); ?></label><br>
						<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[auto_ip]" value="1" <?php checked( $s['auto_ip'], 1 ); ?>> <?php esc_html_e( 'E-posta/telefon/adres eşleşmesinde ziyaretçi IP\'sini listeye ekle (paylaşılan IP\'lerde yanlış engelleme riski)', 'order-blacklist-for-woocommerce' ); ?></label>
					</td></tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Engellenen denemeler (son 100)', 'order-blacklist-for-woocommerce' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Kayıt yalnızca eşleşen kuralı tutar (kuralın kendisi kişisel veri olabilir); ziyaretçinin diğer bilgileri saklanmaz.', 'order-blacklist-for-woocommerce' ); ?></p>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr><th><?php esc_html_e( 'Zaman', 'order-blacklist-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Tür', 'order-blacklist-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Eşleşen kural', 'order-blacklist-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Kaynak', 'order-blacklist-for-woocommerce' ); ?></th></tr></thead>
				<tbody>
				<?php if ( ! $log ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Henüz engellenen deneme yok.', 'order-blacklist-for-woocommerce' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $log as $row ) : ?>
					<tr>
						<td><?php echo esc_html( wp_date( 'd.m.Y H:i', (int) $row['t'] ) ); ?></td>
						<td><?php echo esc_html( $types[ $row['type'] ] ?? $row['type'] ); ?></td>
						<td><code><?php echo esc_html( $row['rule'] ); ?></code></td>
						<td><?php echo esc_html( 'backstop' === $row['source'] ? __( 'Sipariş (iptal)', 'order-blacklist-for-woocommerce' ) : __( 'Ödeme adımı', 'order-blacklist-for-woocommerce' ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $log ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:10px">
					<input type="hidden" name="action" value="eru_ob_clear_log">
					<?php wp_nonce_field( 'eru_ob_clear_log' ); ?>
					<?php submit_button( __( 'Kaydı temizle', 'order-blacklist-for-woocommerce' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</div>
		<?php
	}
}
