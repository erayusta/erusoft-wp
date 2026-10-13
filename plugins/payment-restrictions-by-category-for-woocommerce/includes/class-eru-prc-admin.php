<?php
/** WooCommerce > Ödeme Kısıtları: rule table. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PRC_Admin {

	const CAP = 'manage_woocommerce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_prc_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Ödeme Kısıtları', 'payment-restrictions-by-category-for-woocommerce' ), __( 'Ödeme Kısıtları', 'payment-restrictions-by-category-for-woocommerce' ), self::CAP, 'eru-prc', array( __CLASS__, 'render' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( 'woocommerce_page_eru-prc' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'eru-prc-admin', ERU_PRC_URL . 'assets/admin.js', array( 'jquery' ), ERU_PRC_VERSION, true );
		wp_add_inline_style( 'common', '.eru-prc-table td{vertical-align:top}.eru-prc-table select[multiple]{min-height:120px;min-width:200px}.eru-prc-table textarea{width:100%;min-width:200px}' );
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'payment-restrictions-by-category-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_prc_save' );
		$raw = isset( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in Eru_PRC_Rules::sanitize().
		Eru_PRC_Rules::save( Eru_PRC_Rules::sanitize( $raw ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'eru-prc', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$gateways = array();
		foreach ( WC()->payment_gateways()->payment_gateways() as $id => $gateway ) {
			$gateways[ $id ] = wp_strip_all_tags( $gateway->get_method_title() ?: $id );
		}
		$groups = array();
		foreach ( Eru_PRC_Rules::taxonomies() as $tax => $label ) {
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
			if ( ! is_wp_error( $terms ) && $terms ) {
				$groups[ $label ] = array( $tax, $terms );
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kategoriye Göre Ödeme Kısıtları', 'payment-restrictions-by-category-for-woocommerce' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Kurallar kaydedildi.', 'payment-restrictions-by-category-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Sepette seçtiğiniz kategorilerden (veya etiket/özellik gibi diğer ürün sınıflandırmalarından) bir ürün varsa, işaretlediğiniz ödeme yöntemleri ödeme sayfasında gizlenir. Hiç kural yoksa eklenti hiçbir şey yapmaz.', 'payment-restrictions-by-category-for-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_prc_save">
				<?php wp_nonce_field( 'eru_prc_save' ); ?>
				<table class="widefat striped eru-prc-table" id="eru-prc-rules">
					<thead><tr>
						<th><?php esc_html_e( 'Ürün sınıfları (Ctrl/Cmd ile çoklu seçim)', 'payment-restrictions-by-category-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Gizlenecek ödeme yöntemleri', 'payment-restrictions-by-category-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Sepet/ödeme notu', 'payment-restrictions-by-category-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Ürün sayfası notu', 'payment-restrictions-by-category-for-woocommerce' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( Eru_PRC_Rules::get() as $i => $rule ) { self::row( (string) $i, $rule, $gateways, $groups ); } ?>
					</tbody>
				</table>
				<template id="eru-prc-template"><?php self::row( '__i__', array(), $gateways, $groups ); ?></template>
				<p><button type="button" class="button" id="eru-prc-add"><?php esc_html_e( 'Kural ekle', 'payment-restrictions-by-category-for-woocommerce' ); ?></button></p>
				<?php submit_button( __( 'Kaydet', 'payment-restrictions-by-category-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function row( string $i, array $rule, array $gateways, array $groups ): void {
		$n        = 'rules[' . $i . ']';
		$rule     = wp_parse_args( $rule, array( 'terms' => array(), 'gateways' => array(), 'notice' => '', 'product_notice' => '' ) );
		?>
		<tr>
			<td><select multiple name="<?php echo esc_attr( $n ); ?>[terms][]">
				<?php foreach ( $groups as $label => $group ) : ?>
					<optgroup label="<?php echo esc_attr( $label ); ?>">
					<?php foreach ( $group[1] as $term ) : $val = $group[0] . ':' . $term->term_id; ?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( in_array( $val, (array) $rule['terms'], true ) ); ?>><?php echo esc_html( $term->name ); ?></option>
					<?php endforeach; ?>
					</optgroup>
				<?php endforeach; ?>
			</select></td>
			<td>
				<?php foreach ( $gateways as $id => $title ) : ?>
					<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[gateways][]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, (array) $rule['gateways'], true ) ); ?>> <?php echo esc_html( $title ); ?></label>
				<?php endforeach; ?>
			</td>
			<td><textarea rows="4" name="<?php echo esc_attr( $n ); ?>[notice]"><?php echo esc_textarea( $rule['notice'] ); ?></textarea></td>
			<td><textarea rows="4" name="<?php echo esc_attr( $n ); ?>[product_notice]"><?php echo esc_textarea( $rule['product_notice'] ); ?></textarea></td>
			<td><button type="button" class="button-link-delete eru-prc-remove"><?php esc_html_e( 'Sil', 'payment-restrictions-by-category-for-woocommerce' ); ?></button></td>
		</tr>
		<?php
	}
}
