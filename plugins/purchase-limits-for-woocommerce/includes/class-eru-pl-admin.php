<?php
/** Product fields (Inventory tab) and WooCommerce > Satın Alma Limitleri settings. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PL_Admin {

	const CAP = 'manage_woocommerce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_pl_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_product_options_inventory_product_data', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Satın Alma Limitleri', 'purchase-limits-for-woocommerce' ), __( 'Satın Alma Limitleri', 'purchase-limits-for-woocommerce' ), self::CAP, 'eru-pl', array( __CLASS__, 'render' ) );
	}

	public static function enqueue( string $hook ): void {
		if ( 'woocommerce_page_eru-pl' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'eru-pl-admin', ERU_PL_URL . 'assets/admin.js', array( 'jquery' ), ERU_PL_VERSION, true );
		wp_add_inline_style( 'common', '.eru-pl-table input.small-text{width:80px}' );
	}

	/* ---- product ------------------------------------------------------------ */

	public static function product_fields(): void {
		echo '<div class="options_group">';
		woocommerce_wp_text_input( array( 'id' => '_eru_pl_min', 'label' => __( 'Siparişte asgari adet', 'purchase-limits-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0, 'step' => 1 ), 'desc_tip' => true, 'description' => __( 'Boş veya 0: sınır yok.', 'purchase-limits-for-woocommerce' ) ) );
		woocommerce_wp_text_input( array( 'id' => '_eru_pl_max', 'label' => __( 'Siparişte azami adet', 'purchase-limits-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0, 'step' => 1 ), 'desc_tip' => true, 'description' => __( 'Boş veya 0: sınır yok.', 'purchase-limits-for-woocommerce' ) ) );
		woocommerce_wp_text_input( array( 'id' => '_eru_pl_customer_max', 'label' => __( 'Müşteri başına azami adet', 'purchase-limits-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0, 'step' => 1 ), 'desc_tip' => true, 'description' => __( 'Bir müşterinin bu üründen dönem içinde toplam alabileceği adet.', 'purchase-limits-for-woocommerce' ) ) );
		woocommerce_wp_text_input( array( 'id' => '_eru_pl_period_days', 'label' => __( 'Dönem (gün)', 'purchase-limits-for-woocommerce' ), 'type' => 'number', 'custom_attributes' => array( 'min' => 0, 'step' => 1 ), 'desc_tip' => true, 'description' => __( 'Müşteri limiti için geriye dönük gün sayısı. 0: tüm zamanlar.', 'purchase-limits-for-woocommerce' ) ) );
		echo '</div>';
	}

	/** WooCommerce verifies the product-save nonce and capability before this action. */
	public static function save_product( $post_id ): void {
		foreach ( array( '_eru_pl_min', '_eru_pl_max', '_eru_pl_customer_max', '_eru_pl_period_days' ) as $key ) {
			$value = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
			if ( $value > 0 ) {
				update_post_meta( $post_id, $key, $value );
			} else {
				delete_post_meta( $post_id, $key );
			}
		}
	}

	/* ---- settings ------------------------------------------------------------ */

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'purchase-limits-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_pl_save' );
		$raw = isset( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in Eru_PL_Settings::sanitize().
		update_option( Eru_PL_Settings::OPTION, Eru_PL_Settings::sanitize( $raw ) );
		Eru_PL_Limits::flush_cache();
		wp_safe_redirect( add_query_arg( array( 'page' => 'eru-pl', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$s    = Eru_PL_Settings::get();
		$cats = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$cats = is_wp_error( $cats ) ? array() : $cats;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Satın Alma Limitleri', 'purchase-limits-for-woocommerce' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ayarlar kaydedildi.', 'purchase-limits-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Ürün bazlı limitleri ürün düzenleme ekranında Envanter sekmesinden girersiniz. Burada kategori ve sipariş geneli limitler ile mesajlar yönetilir. 0 veya boş: sınır yok.', 'purchase-limits-for-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_pl_save">
				<?php wp_nonce_field( 'eru_pl_save' ); ?>
				<h2><?php esc_html_e( 'Sipariş geneli', 'purchase-limits-for-woocommerce' ); ?></h2>
				<table class="form-table">
					<tr><th><label for="eru-pl-omin"><?php esc_html_e( 'Siparişte asgari toplam adet', 'purchase-limits-for-woocommerce' ); ?></label></th><td><input id="eru-pl-omin" type="number" min="0" class="small-text" name="s[order_min]" value="<?php echo esc_attr( (string) $s['order_min'] ); ?>"></td></tr>
					<tr><th><label for="eru-pl-omax"><?php esc_html_e( 'Siparişte azami toplam adet', 'purchase-limits-for-woocommerce' ); ?></label></th><td><input id="eru-pl-omax" type="number" min="0" class="small-text" name="s[order_max]" value="<?php echo esc_attr( (string) $s['order_max'] ); ?>"></td></tr>
				</table>
				<h2><?php esc_html_e( 'Kategori limitleri', 'purchase-limits-for-woocommerce' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Sepetteki kategorideki (alt kategoriler dahil) tüm ürünlerin adetleri birlikte sayılır.', 'purchase-limits-for-woocommerce' ); ?></p>
				<table class="widefat striped eru-pl-table" id="eru-pl-rules">
					<thead><tr>
						<th><?php esc_html_e( 'Kategori', 'purchase-limits-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Asgari', 'purchase-limits-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Azami', 'purchase-limits-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Müşteri başına azami', 'purchase-limits-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Dönem (gün)', 'purchase-limits-for-woocommerce' ); ?></th>
						<th></th>
					</tr></thead>
					<tbody>
					<?php foreach ( (array) $s['category_rules'] as $i => $rule ) { self::row( (string) $i, $rule, $cats ); } ?>
					</tbody>
				</table>
				<template id="eru-pl-template"><?php self::row( '__i__', array(), $cats ); ?></template>
				<p><button type="button" class="button" id="eru-pl-add"><?php esc_html_e( 'Kategori kuralı ekle', 'purchase-limits-for-woocommerce' ); ?></button></p>
				<h2><?php esc_html_e( 'Mesajlar', 'purchase-limits-for-woocommerce' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Kullanılabilir yer tutucular: {name}, {limit}, {bought}, {period}. Boş bırakırsanız varsayılan metin kullanılır.', 'purchase-limits-for-woocommerce' ); ?></p>
				<table class="form-table">
					<?php foreach ( array( 'msg_max' => __( 'Azami adet aşıldı', 'purchase-limits-for-woocommerce' ), 'msg_min' => __( 'Asgari adet altında', 'purchase-limits-for-woocommerce' ), 'msg_customer' => __( 'Müşteri limiti aşıldı', 'purchase-limits-for-woocommerce' ) ) as $key => $label ) : ?>
						<tr><th><label for="eru-pl-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td><input id="eru-pl-<?php echo esc_attr( $key ); ?>" type="text" class="large-text" name="s[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( (string) $s[ $key ] ); ?>" placeholder="<?php echo esc_attr( Eru_PL_Settings::default_message( $key ) ); ?>"></td></tr>
					<?php endforeach; ?>
				</table>
				<?php submit_button( __( 'Kaydet', 'purchase-limits-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}

	private static function row( string $i, array $rule, array $cats ): void {
		$n    = 's[category_rules][' . $i . ']';
		$rule = wp_parse_args( $rule, array( 'category' => 0, 'min' => '', 'max' => '', 'customer_max' => '', 'period_days' => '' ) );
		?>
		<tr>
			<td><select name="<?php echo esc_attr( $n ); ?>[category]">
				<option value="0"><?php esc_html_e( '— seçin —', 'purchase-limits-for-woocommerce' ); ?></option>
				<?php foreach ( $cats as $cat ) : ?>
					<option value="<?php echo (int) $cat->term_id; ?>" <?php selected( (int) $rule['category'], (int) $cat->term_id ); ?>><?php echo esc_html( $cat->name ); ?></option>
				<?php endforeach; ?>
			</select></td>
			<?php foreach ( array( 'min', 'max', 'customer_max', 'period_days' ) as $f ) : ?>
				<td><input type="number" min="0" class="small-text" name="<?php echo esc_attr( $n ); ?>[<?php echo esc_attr( $f ); ?>]" value="<?php echo esc_attr( $rule[ $f ] ? (string) $rule[ $f ] : '' ); ?>"></td>
			<?php endforeach; ?>
			<td><button type="button" class="button-link-delete eru-pl-remove"><?php esc_html_e( 'Sil', 'purchase-limits-for-woocommerce' ); ?></button></td>
		</tr>
		<?php
	}
}
