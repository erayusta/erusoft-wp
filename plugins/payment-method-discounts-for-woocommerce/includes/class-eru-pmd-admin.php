<?php
/** WooCommerce > Ödeme İndirimleri: rule table. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PMD_Admin {

	const CAP = 'manage_woocommerce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_pmd_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Ödeme İndirimleri', 'payment-method-discounts-for-woocommerce' ), __( 'Ödeme İndirimleri', 'payment-method-discounts-for-woocommerce' ), self::CAP, 'eru-pmd', array( __CLASS__, 'render' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( 'woocommerce_page_eru-pmd' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'eru-pmd-admin', ERU_PMD_URL . 'assets/admin.js', array( 'jquery' ), ERU_PMD_VERSION, true );
		wp_add_inline_style( 'common', '.eru-pmd-table td{vertical-align:top}.eru-pmd-table select[multiple]{min-height:90px;min-width:180px}.eru-pmd-table input.small-text{width:80px}' );
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'payment-method-discounts-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_pmd_save' );
		$raw = isset( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in Eru_PMD_Rules::sanitize().
		Eru_PMD_Rules::save( Eru_PMD_Rules::sanitize( $raw ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'eru-pmd', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$rules    = Eru_PMD_Rules::get();
		$gateways = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			$gateways[ $id ] = wp_strip_all_tags( $gateway->get_method_title() ?: $id );
		}
		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$cats = is_wp_error( $cats ) ? array() : $cats;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Ödeme Yöntemi İndirimleri', 'payment-method-discounts-for-woocommerce' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Kurallar kaydedildi.', 'payment-method-discounts-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Müşterinin ödeme sayfasında seçtiği ödeme yöntemine göre indirim veya ek ücret uygulayın. Bir ödeme yöntemi için eşleşen ilk kural uygulanır. Hiç kural yoksa eklenti hiçbir şey yapmaz.', 'payment-method-discounts-for-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_pmd_save">
				<?php wp_nonce_field( 'eru_pmd_save' ); ?>
				<table class="widefat striped eru-pmd-table" id="eru-pmd-rules">
					<thead><tr>
						<th><?php esc_html_e( 'Ödeme yöntemi', 'payment-method-discounts-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Tür', 'payment-method-discounts-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Değer', 'payment-method-discounts-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Etiket', 'payment-method-discounts-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Asgari sepet', 'payment-method-discounts-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Hariç kategoriler', 'payment-method-discounts-for-woocommerce' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $rules as $i => $rule ) { self::row( (string) $i, $rule, $gateways, $cats ); } ?>
					</tbody>
				</table>
				<template id="eru-pmd-template"><?php self::row( '__i__', array(), $gateways, $cats ); ?></template>
				<p><button type="button" class="button" id="eru-pmd-add"><?php esc_html_e( 'Kural ekle', 'payment-method-discounts-for-woocommerce' ); ?></button></p>
				<?php submit_button( __( 'Kaydet', 'payment-method-discounts-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function row( string $i, array $rule, array $gateways, array $cats ): void {
		$n    = 'rules[' . $i . ']';
		$rule = wp_parse_args( $rule, array( 'gateway' => '', 'type' => 'discount', 'mode' => 'percent', 'value' => '', 'label' => '', 'min_cart' => '', 'exclude_categories' => array(), 'taxable' => 1 ) );
		?>
		<tr>
			<td><select name="<?php echo esc_attr( $n ); ?>[gateway]">
				<option value=""><?php esc_html_e( '— seçin —', 'payment-method-discounts-for-woocommerce' ); ?></option>
				<?php foreach ( $gateways as $id => $title ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $rule['gateway'], $id ); ?>><?php echo esc_html( $title ); ?></option>
				<?php endforeach; ?>
			</select></td>
			<td>
				<select name="<?php echo esc_attr( $n ); ?>[type]">
					<option value="discount" <?php selected( $rule['type'], 'discount' ); ?>><?php esc_html_e( 'İndirim', 'payment-method-discounts-for-woocommerce' ); ?></option>
					<option value="fee" <?php selected( $rule['type'], 'fee' ); ?>><?php esc_html_e( 'Ek ücret', 'payment-method-discounts-for-woocommerce' ); ?></option>
				</select><br>
				<select name="<?php echo esc_attr( $n ); ?>[mode]">
					<option value="percent" <?php selected( $rule['mode'], 'percent' ); ?>>%</option>
					<option value="fixed" <?php selected( $rule['mode'], 'fixed' ); ?>><?php esc_html_e( 'Sabit tutar', 'payment-method-discounts-for-woocommerce' ); ?></option>
				</select>
			</td>
			<td><input type="text" class="small-text" name="<?php echo esc_attr( $n ); ?>[value]" value="<?php echo esc_attr( (string) $rule['value'] ); ?>"></td>
			<td><input type="text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $rule['label'] ); ?>" placeholder="<?php esc_attr_e( 'Sepette görünen ad', 'payment-method-discounts-for-woocommerce' ); ?>"><br>
				<label><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[taxable]" value="1" <?php checked( ! empty( $rule['taxable'] ) ); ?>> <?php esc_html_e( 'Vergiye tabi', 'payment-method-discounts-for-woocommerce' ); ?></label></td>
			<td><input type="text" class="small-text" name="<?php echo esc_attr( $n ); ?>[min_cart]" value="<?php echo esc_attr( $rule['min_cart'] ? (string) $rule['min_cart'] : '' ); ?>"></td>
			<td><select multiple name="<?php echo esc_attr( $n ); ?>[exclude_categories][]">
				<?php foreach ( $cats as $cat ) : ?>
					<option value="<?php echo (int) $cat->term_id; ?>" <?php selected( in_array( (int) $cat->term_id, array_map( 'intval', (array) $rule['exclude_categories'] ), true ) ); ?>><?php echo esc_html( $cat->name ); ?></option>
				<?php endforeach; ?>
			</select></td>
			<td><button type="button" class="button-link-delete eru-pmd-remove"><?php esc_html_e( 'Sil', 'payment-method-discounts-for-woocommerce' ); ?></button></td>
		</tr>
		<?php
	}
}
