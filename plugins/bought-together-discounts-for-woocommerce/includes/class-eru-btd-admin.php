<?php
/** WooCommerce > Birlikte Al İndirimleri: set table. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_BTD_Admin {

	const CAP = 'manage_woocommerce';
	const TD  = 'bought-together-discounts-for-woocommerce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_btd_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Birlikte Al İndirimleri', 'bought-together-discounts-for-woocommerce' ), __( 'Birlikte Al İndirimleri', 'bought-together-discounts-for-woocommerce' ), self::CAP, 'eru-btd', array( __CLASS__, 'render' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( 'woocommerce_page_eru-btd' !== $hook ) {
			return;
		}
		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );
		wp_enqueue_script( 'eru-btd-admin', ERU_BTD_URL . 'assets/admin.js', array( 'jquery', 'wc-enhanced-select' ), ERU_BTD_VERSION, true );
		wp_add_inline_style( 'common', '.eru-btd-table td{vertical-align:top}.eru-btd-table input.small-text{width:80px}.eru-btd-table .wc-product-search{width:320px}' );
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'bought-together-discounts-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_btd_save' );
		$raw = isset( $_POST['sets'] ) ? wp_unslash( $_POST['sets'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in Eru_BTD_Sets::sanitize().
		update_option( Eru_BTD_Sets::OPTION, Eru_BTD_Sets::sanitize( $raw ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'eru-btd', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$sets = Eru_BTD_Sets::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Birlikte Al İndirimleri', 'bought-together-discounts-for-woocommerce' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Setler kaydedildi.', 'bought-together-discounts-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Birlikte satın alınan ürünleri bir set olarak tanımlayın. Set içindeki ürünlerin hepsi sepette olduğunda indirim otomatik uygulanır; ürün sayfasında "Hepsini sepete ekle" butonlu bir blok görünür. Varyasyonlu ürünlerde ilgili varyasyonu seçin. Hiç set yoksa eklenti hiçbir şey yapmaz.', 'bought-together-discounts-for-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_btd_save">
				<?php wp_nonce_field( 'eru_btd_save' ); ?>
				<table class="widefat striped eru-btd-table" id="eru-btd-sets">
					<thead><tr>
						<th><?php esc_html_e( 'Set adı', 'bought-together-discounts-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Ürünler (en az 2)', 'bought-together-discounts-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Set indirimi', 'bought-together-discounts-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Metinler', 'bought-together-discounts-for-woocommerce' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $sets as $i => $set ) { self::row( (string) $i, $set ); } ?>
					</tbody>
				</table>
				<template id="eru-btd-template"><?php self::row( '__i__', array() ); ?></template>
				<p><button type="button" class="button" id="eru-btd-add"><?php esc_html_e( 'Set ekle', 'bought-together-discounts-for-woocommerce' ); ?></button></p>
				<?php submit_button( __( 'Kaydet', 'bought-together-discounts-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function row( string $i, array $set ): void {
		$n   = 'sets[' . $i . ']';
		$set = wp_parse_args( $set, Eru_BTD_Sets::defaults() );
		?>
		<tr>
			<td><input type="hidden" name="<?php echo esc_attr( $n ); ?>[uid]" value="<?php echo esc_attr( (string) $set['uid'] ); ?>"><input type="text" name="<?php echo esc_attr( $n ); ?>[name]" value="<?php echo esc_attr( $set['name'] ); ?>" placeholder="<?php esc_attr_e( 'Yalnızca yönetim ekranında görünür', 'bought-together-discounts-for-woocommerce' ); ?>"><br>
				<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[active]" value="1" <?php checked( ! empty( $set['active'] ) ); ?>> <?php esc_html_e( 'Etkin', 'bought-together-discounts-for-woocommerce' ); ?></label></td>
			<td><select class="wc-product-search" multiple name="<?php echo esc_attr( $n ); ?>[products][]" data-placeholder="<?php esc_attr_e( 'Ürün ara…', 'bought-together-discounts-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products_and_variations">
				<?php
				foreach ( (array) $set['products'] as $pid ) {
					$p = wc_get_product( (int) $pid );
					if ( $p ) {
						echo '<option value="' . esc_attr( (string) $p->get_id() ) . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
					}
				}
				?>
			</select></td>
			<td>
				<select name="<?php echo esc_attr( $n ); ?>[mode]">
					<option value="percent" <?php selected( $set['mode'], 'percent' ); ?>>%</option>
					<option value="fixed" <?php selected( $set['mode'], 'fixed' ); ?>><?php esc_html_e( 'Sabit tutar (set başına)', 'bought-together-discounts-for-woocommerce' ); ?></option>
				</select>
				<input type="text" class="small-text" name="<?php echo esc_attr( $n ); ?>[value]" value="<?php echo esc_attr( (string) $set['value'] ); ?>">
			</td>
			<td>
				<input type="text" name="<?php echo esc_attr( $n ); ?>[heading]" value="<?php echo esc_attr( $set['heading'] ); ?>" placeholder="<?php esc_attr_e( 'Birlikte alın, {reward} kazanın', 'bought-together-discounts-for-woocommerce' ); ?>"><br>
				<input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $set['label'] ); ?>" placeholder="<?php esc_attr_e( 'Sepetteki indirim adı', 'bought-together-discounts-for-woocommerce' ); ?>">
			</td>
			<td><button type="button" class="button-link-delete eru-btd-remove"><?php esc_html_e( 'Sil', 'bought-together-discounts-for-woocommerce' ); ?></button></td>
		</tr>
		<?php
	}
}
