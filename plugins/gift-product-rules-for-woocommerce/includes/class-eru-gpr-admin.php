<?php
/** WooCommerce > Hediye Kuralları: rule table. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_GPR_Admin {

	const CAP = 'manage_woocommerce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_gpr_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Hediye Kuralları', 'gift-product-rules-for-woocommerce' ), __( 'Hediye Kuralları', 'gift-product-rules-for-woocommerce' ), self::CAP, 'eru-gpr', array( __CLASS__, 'render' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( 'woocommerce_page_eru-gpr' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'eru-gpr-admin', ERU_GPR_URL . 'assets/admin.js', array( 'jquery' ), ERU_GPR_VERSION, true );
		wp_add_inline_style( 'common', '.eru-gpr-table td{vertical-align:top}.eru-gpr-table select[multiple]{min-height:90px;min-width:180px}.eru-gpr-table input.small-text{width:80px}.eru-gpr-table .description{margin:4px 0 0}' );
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'gift-product-rules-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_gpr_save' );
		$raw = isset( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in Eru_GPR_Rules::sanitize().
		Eru_GPR_Rules::save( Eru_GPR_Rules::sanitize( $raw ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'eru-gpr', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$rules = Eru_GPR_Rules::get();
		$cats  = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$cats  = is_wp_error( $cats ) ? array() : $cats;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Hediye Ürün Kuralları', 'gift-product-rules-for-woocommerce' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Kurallar kaydedildi.', 'gift-product-rules-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Kural eşleştiğinde hediye ürün sepete 0 TL ile otomatik eklenir; kural artık eşleşmediğinde sepetten çıkarılır. Hiç kural yoksa eklenti hiçbir şey yapmaz. Hediye olarak basit ürün veya varyasyon (varyasyon kimliği) seçin.', 'gift-product-rules-for-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_gpr_save">
				<?php wp_nonce_field( 'eru_gpr_save' ); ?>
				<table class="widefat striped eru-gpr-table" id="eru-gpr-rules">
					<thead><tr>
						<th><?php esc_html_e( 'Açık', 'gift-product-rules-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Koşul', 'gift-product-rules-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Hediye ürün', 'gift-product-rules-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Etiket', 'gift-product-rules-for-woocommerce' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $rules as $id => $rule ) { self::row( (string) $id, $rule, $cats ); } ?>
					</tbody>
				</table>
				<template id="eru-gpr-template"><?php self::row( '__i__', array(), $cats ); ?></template>
				<p><button type="button" class="button" id="eru-gpr-add"><?php esc_html_e( 'Kural ekle', 'gift-product-rules-for-woocommerce' ); ?></button></p>
				<?php submit_button( __( 'Kaydet', 'gift-product-rules-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function row( string $i, array $rule, array $cats ): void {
		$n    = 'rules[' . $i . ']';
		$rule = wp_parse_args(
			$rule,
			array( 'enabled' => 1, 'label' => '', 'trigger' => 'cart_total', 'min_total' => '', 'product_ids' => array(), 'category_ids' => array(), 'gift_id' => '', 'qty' => 1 )
		);
		$gift = $rule['gift_id'] ? wc_get_product( (int) $rule['gift_id'] ) : false;
		$id   = '__i__' === $i ? '' : $i;
		?>
		<tr>
			<td>
				<input type="hidden" name="<?php echo esc_attr( $n ); ?>[id]" value="<?php echo esc_attr( $id ); ?>">
				<input type="checkbox" name="<?php echo esc_attr( $n ); ?>[enabled]" value="1" <?php checked( ! empty( $rule['enabled'] ) ); ?>>
			</td>
			<td>
				<select name="<?php echo esc_attr( $n ); ?>[trigger]">
					<option value="cart_total" <?php selected( $rule['trigger'], 'cart_total' ); ?>><?php esc_html_e( 'Sepet tutarı en az', 'gift-product-rules-for-woocommerce' ); ?></option>
					<option value="product" <?php selected( $rule['trigger'], 'product' ); ?>><?php esc_html_e( 'Sepette belirli ürün var', 'gift-product-rules-for-woocommerce' ); ?></option>
					<option value="category" <?php selected( $rule['trigger'], 'category' ); ?>><?php esc_html_e( 'Sepette belirli kategori var', 'gift-product-rules-for-woocommerce' ); ?></option>
				</select>
				<p><label><?php esc_html_e( 'Asgari sepet tutarı', 'gift-product-rules-for-woocommerce' ); ?>
					<input type="text" class="small-text" name="<?php echo esc_attr( $n ); ?>[min_total]" value="<?php echo esc_attr( $rule['min_total'] ? wc_format_localized_price( (string) $rule['min_total'] ) : '' ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Ürün kimlikleri (virgülle)', 'gift-product-rules-for-woocommerce' ); ?><br>
					<input type="text" class="regular-text" name="<?php echo esc_attr( $n ); ?>[product_ids]" value="<?php echo esc_attr( implode( ', ', (array) $rule['product_ids'] ) ); ?>"></label></p>
				<p><label><?php esc_html_e( 'Kategoriler', 'gift-product-rules-for-woocommerce' ); ?><br>
					<select multiple name="<?php echo esc_attr( $n ); ?>[category_ids][]">
						<?php foreach ( $cats as $cat ) : ?>
							<option value="<?php echo esc_attr( (string) $cat->term_id ); ?>" <?php selected( in_array( (int) $cat->term_id, array_map( 'intval', (array) $rule['category_ids'] ), true ) ); ?>><?php echo esc_html( $cat->name ); ?></option>
						<?php endforeach; ?>
					</select></label></p>
			</td>
			<td>
				<input type="number" min="1" class="small-text" name="<?php echo esc_attr( $n ); ?>[gift_id]" value="<?php echo esc_attr( (string) $rule['gift_id'] ); ?>" placeholder="ID">
				<?php if ( $gift ) : ?>
					<p class="description"><?php echo esc_html( wp_strip_all_tags( $gift->get_formatted_name() ) ); ?></p>
				<?php endif; ?>
				<p><label><?php esc_html_e( 'Adet', 'gift-product-rules-for-woocommerce' ); ?>
					<input type="number" min="1" max="99" class="small-text" name="<?php echo esc_attr( $n ); ?>[qty]" value="<?php echo esc_attr( (string) $rule['qty'] ); ?>"></label></p>
			</td>
			<td><input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $rule['label'] ); ?>" placeholder="<?php esc_attr_e( 'Hediye', 'gift-product-rules-for-woocommerce' ); ?>"></td>
			<td><button type="button" class="button-link-delete eru-gpr-remove"><?php esc_html_e( 'Sil', 'gift-product-rules-for-woocommerce' ); ?></button></td>
		</tr>
		<?php
	}
}
