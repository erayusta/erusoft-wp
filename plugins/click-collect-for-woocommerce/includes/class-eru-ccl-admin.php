<?php
/** WooCommerce > Mağazadan Teslim: locations + options. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CCL_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Mağazadan Teslim', 'click-collect-for-woocommerce' ), __( 'Mağazadan Teslim', 'click-collect-for-woocommerce' ), 'manage_woocommerce', 'eru-ccl', array( __CLASS__, 'render' ) );
	}

	public static function register(): void {
		// Saved through options.php: let the menu's capability (not manage_options) save it.
		add_filter( 'option_page_capability_eru_ccl', fn() => 'manage_woocommerce' );
		register_setting( 'eru_ccl', Eru_CCL_Locations::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize_locations' ), 'default' => array() ) );
		register_setting( 'eru_ccl', 'eru_ccl_settings', array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ), 'default' => array() ) );
	}

	public static function sanitize_locations( $in ): array {
		$out = array();
		foreach ( is_array( $in ) ? $in : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$name = sanitize_text_field( $row['name'] ?? '' );
			if ( '' === $name ) {
				continue;
			}
			$id = isset( $row['id'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $row['id'] ) ) : '';
			if ( '' === $id ) {
				$id = 'l' . substr( md5( wp_generate_uuid4() ), 0, 8 );
			}
			$out[] = array(
				'id'      => $id,
				'name'    => $name,
				'address' => sanitize_text_field( $row['address'] ?? '' ),
				'hours'   => sanitize_text_field( $row['hours'] ?? '' ),
			);
		}
		return $out;
	}

	public static function sanitize_settings( $in ): array {
		$in = is_array( $in ) ? $in : array();
		return array(
			'ready_status' => empty( $in['ready_status'] ) ? 0 : 1,
			'ready_email'  => empty( $in['ready_email'] ) ? 0 : 1,
		);
	}


	/** The classic [woocommerce_checkout] shortcode is required; warn when the checkout page uses the block. */
	private static function block_checkout_notice(): void {
		$id   = wc_get_page_id( 'checkout' );
		$page = $id > 0 ? get_post( $id ) : null;
		if ( $page && has_block( 'woocommerce/checkout', $page ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Ödeme sayfanız blok tabanlı. Bu eklenti klasik ödeme sayfasını ([woocommerce_checkout] kısa kodu) gerektirir; blok ödemede bu özellik çalışmaz.', 'click-collect-for-woocommerce' ) . '</p></div>';
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$rows = array_values( Eru_CCL_Locations::all() );
		for ( $i = 0; $i < 3; $i++ ) {
			$rows[] = array( 'id' => '', 'name' => '', 'address' => '', 'hours' => '' );
		}
		$s = Eru_CCL_Locations::settings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mağazadan Teslim', 'click-collect-for-woocommerce' ); ?></h1>
			<?php self::block_checkout_notice(); ?>
			<p><?php esc_html_e( 'Teslim noktası tanımlayın, ardından WooCommerce → Ayarlar → Gönderim bölgelerinde bölgenize "Mağazadan teslim" yöntemini ekleyin. Hiç nokta tanımlı değilse yöntem sunulmaz.', 'click-collect-for-woocommerce' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_ccl' ); ?>
				<table class="widefat striped" style="max-width:1000px">
					<thead><tr>
						<th><?php esc_html_e( 'Ad', 'click-collect-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Adres', 'click-collect-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Çalışma saatleri', 'click-collect-for-woocommerce' ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $rows as $i => $r ) : ?>
						<tr>
							<td><input type="hidden" name="<?php echo esc_attr( Eru_CCL_Locations::OPTION . '[' . $i . '][id]' ); ?>" value="<?php echo esc_attr( $r['id'] ); ?>"><input type="text" class="regular-text" name="<?php echo esc_attr( Eru_CCL_Locations::OPTION . '[' . $i . '][name]' ); ?>" value="<?php echo esc_attr( $r['name'] ); ?>" placeholder="Demo Mağaza"></td>
							<td><input type="text" class="large-text" name="<?php echo esc_attr( Eru_CCL_Locations::OPTION . '[' . $i . '][address]' ); ?>" value="<?php echo esc_attr( $r['address'] ); ?>" placeholder="Örnek Mah. 1, Ankara"></td>
							<td><input type="text" class="regular-text" name="<?php echo esc_attr( Eru_CCL_Locations::OPTION . '[' . $i . '][hours]' ); ?>" value="<?php echo esc_attr( $r['hours'] ); ?>" placeholder="Hafta içi 10:00-18:00"></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description"><?php esc_html_e( 'Satırı silmek için adı boş bırakıp kaydedin. Boş satırlar her kayıtta yeniden eklenir.', 'click-collect-for-woocommerce' ); ?></p>
				<h2><?php esc_html_e( 'Sipariş durumu', 'click-collect-for-woocommerce' ); ?></h2>
				<p>
					<label><input type="checkbox" name="eru_ccl_settings[ready_status]" value="1" <?php checked( $s['ready_status'], 1 ); ?>> <?php esc_html_e( '"Teslim almaya hazır" sipariş durumunu etkinleştir', 'click-collect-for-woocommerce' ); ?></label><br>
					<label><input type="checkbox" name="eru_ccl_settings[ready_email]" value="1" <?php checked( $s['ready_email'], 1 ); ?>> <?php esc_html_e( 'Bu duruma geçince müşteriye e-posta gönder', 'click-collect-for-woocommerce' ); ?></label>
				</p>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
