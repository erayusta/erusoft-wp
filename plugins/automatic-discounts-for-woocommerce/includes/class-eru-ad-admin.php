<?php
/** WooCommerce > Otomatik İndirimler: rule table. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_AD_Admin {

	const CAP = 'manage_woocommerce';
	const TD  = 'automatic-discounts-for-woocommerce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_ad_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Otomatik İndirimler', 'automatic-discounts-for-woocommerce' ), __( 'Otomatik İndirimler', 'automatic-discounts-for-woocommerce' ), self::CAP, 'eru-ad', array( __CLASS__, 'render' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( 'woocommerce_page_eru-ad' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'eru-ad-admin', ERU_AD_URL . 'assets/admin.js', array( 'jquery' ), ERU_AD_VERSION, true );
		wp_add_inline_style( 'common', '.eru-ad-table td{vertical-align:top}.eru-ad-table input.small-text{width:80px}.eru-ad-table input[type=date]{width:140px}' );
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'automatic-discounts-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_ad_save' );
		$raw = isset( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in Eru_AD_Rules::sanitize().
		Eru_AD_Rules::save( Eru_AD_Rules::sanitize( $raw ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'eru-ad', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$rules = Eru_AD_Rules::get();
		$cats  = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$cats  = is_wp_error( $cats ) ? array() : $cats;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Otomatik İndirimler', 'automatic-discounts-for-woocommerce' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Kurallar kaydedildi.', 'automatic-discounts-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Kupon gerektirmeden sepete otomatik indirim uygulayın. Kurallar öncelik sırasına göre (küçük sayı önce) değerlendirilir; "Dur" işaretli bir kural eşleşirse sonraki kurallara bakılmaz. Hiç kural yoksa eklenti hiçbir şey yapmaz.', 'automatic-discounts-for-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_ad_save">
				<?php wp_nonce_field( 'eru_ad_save' ); ?>
				<table class="widefat striped eru-ad-table" id="eru-ad-rules">
					<thead><tr>
						<th><?php esc_html_e( 'Koşul', 'automatic-discounts-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'İndirim', 'automatic-discounts-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Etiket', 'automatic-discounts-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Tarih aralığı', 'automatic-discounts-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Öncelik', 'automatic-discounts-for-woocommerce' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $rules as $i => $rule ) { self::row( (string) $i, $rule, $cats ); } ?>
					</tbody>
				</table>
				<template id="eru-ad-template"><?php self::row( '__i__', array(), $cats ); ?></template>
				<p><button type="button" class="button" id="eru-ad-add"><?php esc_html_e( 'Kural ekle', 'automatic-discounts-for-woocommerce' ); ?></button></p>
				<?php submit_button( __( 'Kaydet', 'automatic-discounts-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function row( string $i, array $rule, array $cats ): void {
		$n    = 'rules[' . $i . ']';
		$rule = wp_parse_args( $rule, Eru_AD_Rules::defaults() );
		?>
		<tr>
			<td>
				<select name="<?php echo esc_attr( $n ); ?>[type]">
					<option value="subtotal" <?php selected( $rule['type'], 'subtotal' ); ?>><?php esc_html_e( 'Sepet ara toplamı en az', 'automatic-discounts-for-woocommerce' ); ?></option>
					<option value="category_qty" <?php selected( $rule['type'], 'category_qty' ); ?>><?php esc_html_e( 'Kategoriden en az N adet', 'automatic-discounts-for-woocommerce' ); ?></option>
				</select><br>
				<input type="text" class="small-text" name="<?php echo esc_attr( $n ); ?>[min_subtotal]" value="<?php echo esc_attr( $rule['min_subtotal'] ? (string) $rule['min_subtotal'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Tutar', 'automatic-discounts-for-woocommerce' ); ?>">
				<select name="<?php echo esc_attr( $n ); ?>[category]">
					<option value="0"><?php esc_html_e( '— kategori —', 'automatic-discounts-for-woocommerce' ); ?></option>
					<?php foreach ( $cats as $cat ) : ?>
						<option value="<?php echo (int) $cat->term_id; ?>" <?php selected( (int) $rule['category'], (int) $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="number" min="1" class="small-text" name="<?php echo esc_attr( $n ); ?>[min_qty]" value="<?php echo esc_attr( (string) $rule['min_qty'] ); ?>" title="<?php esc_attr_e( 'Adet', 'automatic-discounts-for-woocommerce' ); ?>">
			</td>
			<td>
				<select name="<?php echo esc_attr( $n ); ?>[mode]">
					<option value="percent" <?php selected( $rule['mode'], 'percent' ); ?>>%</option>
					<option value="fixed" <?php selected( $rule['mode'], 'fixed' ); ?>><?php esc_html_e( 'Sabit tutar', 'automatic-discounts-for-woocommerce' ); ?></option>
				</select>
				<input type="text" class="small-text" name="<?php echo esc_attr( $n ); ?>[value]" value="<?php echo esc_attr( (string) $rule['value'] ); ?>">
			</td>
			<td><input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $rule['label'] ); ?>" placeholder="<?php esc_attr_e( 'Sepette görünen ad', 'automatic-discounts-for-woocommerce' ); ?>"><br>
				<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[taxable]" value="1" <?php checked( ! empty( $rule['taxable'] ) ); ?>> <?php esc_html_e( 'Vergiye tabi', 'automatic-discounts-for-woocommerce' ); ?></label></td>
			<td>
				<input type="date" name="<?php echo esc_attr( $n ); ?>[start]" value="<?php echo esc_attr( $rule['start'] ); ?>"><br>
				<input type="date" name="<?php echo esc_attr( $n ); ?>[end]" value="<?php echo esc_attr( $rule['end'] ); ?>">
			</td>
			<td><input type="number" class="small-text" name="<?php echo esc_attr( $n ); ?>[priority]" value="<?php echo esc_attr( (string) $rule['priority'] ); ?>"><br>
				<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[stop]" value="1" <?php checked( ! empty( $rule['stop'] ) ); ?>> <?php esc_html_e( 'Dur', 'automatic-discounts-for-woocommerce' ); ?></label></td>
			<td><button type="button" class="button-link-delete eru-ad-remove"><?php esc_html_e( 'Sil', 'automatic-discounts-for-woocommerce' ); ?></button></td>
		</tr>
		<?php
	}
}
