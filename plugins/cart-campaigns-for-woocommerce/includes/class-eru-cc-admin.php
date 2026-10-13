<?php
/** WooCommerce > Sepet Kampanyaları: tiers and message settings. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CC_Admin {

	const CAP = 'manage_woocommerce';
	const TD  = 'cart-campaigns-for-woocommerce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_cc_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Sepet Kampanyaları', 'cart-campaigns-for-woocommerce' ), __( 'Sepet Kampanyaları', 'cart-campaigns-for-woocommerce' ), self::CAP, 'eru-cc', array( __CLASS__, 'render' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( 'woocommerce_page_eru-cc' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'eru-cc-admin', ERU_CC_URL . 'assets/admin.js', array( 'jquery' ), ERU_CC_VERSION, true );
		wp_add_inline_style( 'common', '.eru-cc-table td{vertical-align:top}.eru-cc-table input.small-text{width:90px}' );
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'cart-campaigns-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_cc_save' );
		$raw = isset( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in Eru_CC_Campaigns::sanitize().
		update_option( Eru_CC_Campaigns::OPTION, Eru_CC_Campaigns::sanitize( $raw ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'eru-cc', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s = Eru_CC_Campaigns::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Sepet Kampanyaları', 'cart-campaigns-for-woocommerce' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ayarlar kaydedildi.', 'cart-campaigns-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Sepet ara toplamı bir eşiğe ulaştığında otomatik indirim uygulayın. Birden fazla kademe tanımlarsanız yalnızca ulaşılan en yüksek kademe uygulanır. Hiç kademe yoksa eklenti hiçbir şey yapmaz.', 'cart-campaigns-for-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_cc_save">
				<?php wp_nonce_field( 'eru_cc_save' ); ?>
				<table class="widefat striped eru-cc-table" id="eru-cc-tiers">
					<thead><tr>
						<th><?php esc_html_e( 'Sepet tutarı eşiği', 'cart-campaigns-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'İndirim', 'cart-campaigns-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Sepette görünen ad', 'cart-campaigns-for-woocommerce' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $s['tiers'] as $i => $tier ) { self::row( (string) $i, $tier ); } ?>
					</tbody>
				</table>
				<template id="eru-cc-template"><?php self::row( '__i__', array() ); ?></template>
				<p><button type="button" class="button" id="eru-cc-add"><?php esc_html_e( 'Kademe ekle', 'cart-campaigns-for-woocommerce' ); ?></button></p>

				<h2><?php esc_html_e( 'Mesajlar', 'cart-campaigns-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr><th><label for="eru-cc-progress"><?php esc_html_e( 'İlerleme mesajı', 'cart-campaigns-for-woocommerce' ); ?></label></th>
						<td><input type="text" class="large-text" id="eru-cc-progress" name="s[progress_text]" value="<?php echo esc_attr( $s['progress_text'] ); ?>">
						<p class="description"><?php esc_html_e( '{amount}: eksik tutar, {reward}: kazanılacak indirim.', 'cart-campaigns-for-woocommerce' ); ?></p></td></tr>
					<tr><th><label for="eru-cc-success"><?php esc_html_e( 'Kazanım mesajı', 'cart-campaigns-for-woocommerce' ); ?></label></th>
						<td><input type="text" class="large-text" id="eru-cc-success" name="s[success_text]" value="<?php echo esc_attr( $s['success_text'] ); ?>">
						<p class="description"><?php esc_html_e( 'En yüksek kademeye ulaşıldığında gösterilir. {reward}: kazanılan indirim.', 'cart-campaigns-for-woocommerce' ); ?></p></td></tr>
					<tr><th><?php esc_html_e( 'Gösterim yeri', 'cart-campaigns-for-woocommerce' ); ?></th>
						<td><label><input type="checkbox" name="s[show_cart]" value="1" <?php checked( ! empty( $s['show_cart'] ) ); ?>> <?php esc_html_e( 'Sepet sayfası', 'cart-campaigns-for-woocommerce' ); ?></label><br>
						<label><input type="checkbox" name="s[show_mini]" value="1" <?php checked( ! empty( $s['show_mini'] ) ); ?>> <?php esc_html_e( 'Mini sepet', 'cart-campaigns-for-woocommerce' ); ?></label></td></tr>
				</table>
				<?php submit_button( __( 'Kaydet', 'cart-campaigns-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function row( string $i, array $tier ): void {
		$n    = 's[tiers][' . $i . ']';
		$tier = wp_parse_args( $tier, array( 'threshold' => '', 'mode' => 'percent', 'value' => '', 'label' => '' ) );
		?>
		<tr>
			<td><input type="text" class="small-text" name="<?php echo esc_attr( $n ); ?>[threshold]" value="<?php echo esc_attr( (string) $tier['threshold'] ); ?>"></td>
			<td>
				<select name="<?php echo esc_attr( $n ); ?>[mode]">
					<option value="percent" <?php selected( $tier['mode'], 'percent' ); ?>>%</option>
					<option value="fixed" <?php selected( $tier['mode'], 'fixed' ); ?>><?php esc_html_e( 'Sabit tutar', 'cart-campaigns-for-woocommerce' ); ?></option>
				</select>
				<input type="text" class="small-text" name="<?php echo esc_attr( $n ); ?>[value]" value="<?php echo esc_attr( (string) $tier['value'] ); ?>">
			</td>
			<td><input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $tier['label'] ); ?>" placeholder="<?php esc_attr_e( 'Sepet kampanyası', 'cart-campaigns-for-woocommerce' ); ?>"></td>
			<td><button type="button" class="button-link-delete eru-cc-remove"><?php esc_html_e( 'Sil', 'cart-campaigns-for-woocommerce' ); ?></button></td>
		</tr>
		<?php
	}
}
