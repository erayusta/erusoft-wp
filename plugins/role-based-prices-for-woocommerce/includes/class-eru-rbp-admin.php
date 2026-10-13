<?php
/** Role price fields (product + variation) and WooCommerce > Rol Fiyatları settings. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_RBP_Admin {

	const CAP = 'manage_woocommerce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_rbp_save', array( __CLASS__, 'save' ) );
		add_action( 'woocommerce_product_options_pricing', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product' ), 20 );
		add_action( 'woocommerce_variation_options_pricing', array( __CLASS__, 'variation_fields' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( __CLASS__, 'save_variation' ), 20, 2 );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Rol Fiyatları', 'role-based-prices-for-woocommerce' ), __( 'Rol Fiyatları', 'role-based-prices-for-woocommerce' ), self::CAP, 'eru-rbp', array( __CLASS__, 'render' ) );
	}

	private static function role_label( string $role ): string {
		$names = wp_roles()->get_names();
		return isset( $names[ $role ] ) ? translate_user_role( $names[ $role ] ) : $role;
	}

	public static function product_fields(): void {
		$roles = Eru_RBP_Prices::roles();
		if ( ! $roles ) {
			return;
		}
		echo '<div class="options_group">';
		foreach ( $roles as $role ) {
			woocommerce_wp_text_input(
				array(
					'id'        => Eru_RBP_Prices::META . $role,
					/* translators: %s: role name */
					'label'     => sprintf( __( '%s fiyatı', 'role-based-prices-for-woocommerce' ), self::role_label( $role ) ),
					'data_type' => 'price',
					'desc_tip'  => true,
					'description' => __( 'Boşsa bu role normal fiyat gösterilir.', 'role-based-prices-for-woocommerce' ),
				)
			);
		}
		echo '</div>';
	}

	/** WooCommerce verifies the product-save nonce and capability before this action. */
	public static function save_product( $post_id ): void {
		foreach ( Eru_RBP_Prices::roles() as $role ) {
			$key = Eru_RBP_Prices::META . $role;
			self::store( (int) $post_id, $key, isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		}
	}

	public static function variation_fields( $loop, $variation_data, $variation ): void {
		foreach ( Eru_RBP_Prices::roles() as $role ) {
			$key = Eru_RBP_Prices::META . $role;
			woocommerce_wp_text_input(
				array(
					'id'            => $key . '[' . $loop . ']',
					'name'          => $key . '[' . $loop . ']',
					'value'         => get_post_meta( $variation->ID, $key, true ),
					/* translators: %s: role name */
					'label'         => sprintf( __( '%s fiyatı', 'role-based-prices-for-woocommerce' ), self::role_label( $role ) ),
					'data_type'     => 'price',
					'wrapper_class' => 'form-row form-row-full',
				)
			);
		}
	}

	public static function save_variation( $variation_id, $loop ): void {
		foreach ( Eru_RBP_Prices::roles() as $role ) {
			$key = Eru_RBP_Prices::META . $role;
			self::store( (int) $variation_id, $key, isset( $_POST[ $key ][ $loop ] ) ? wp_unslash( $_POST[ $key ][ $loop ] ) : '' ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		}
	}

	private static function store( int $id, string $key, $raw ): void {
		$raw = is_scalar( $raw ) ? trim( (string) $raw ) : '';
		if ( '' === $raw ) {
			delete_post_meta( $id, $key );
			return;
		}
		$value = (float) wc_format_decimal( $raw );
		if ( $value >= 0 ) {
			update_post_meta( $id, $key, $value );
		}
		wc_delete_product_transients( $id );
	}

	public static function save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'role-based-prices-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_rbp_save' );
		$raw = isset( $_POST['roles'] ) ? (array) wp_unslash( $_POST['roles'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated against existing roles.
		update_option( Eru_RBP_Prices::OPTION, Eru_RBP_Prices::sanitize_roles( $raw ) );
		wp_safe_redirect( add_query_arg( array( 'page' => 'eru-rbp', 'saved' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$selected = Eru_RBP_Prices::roles();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Rol Bazlı Fiyatlar', 'role-based-prices-for-woocommerce' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ayarlar kaydedildi.', 'role-based-prices-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Özel fiyat tanımlayacağınız kullanıcı rollerini seçin. Seçilen her rol için ürün ve varyasyon fiyat alanlarının altında bir "Rol fiyatı" alanı çıkar. Hiçbir rol seçilmezse eklenti hiçbir şey yapmaz.', 'role-based-prices-for-woocommerce' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_rbp_save">
				<?php wp_nonce_field( 'eru_rbp_save' ); ?>
				<table class="form-table"><tr><th><?php esc_html_e( 'Roller', 'role-based-prices-for-woocommerce' ); ?></th><td>
					<?php foreach ( wp_roles()->get_names() as $slug => $name ) : ?>
						<label style="display:block"><input type="checkbox" name="roles[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $selected, true ) ); ?>> <?php echo esc_html( translate_user_role( $name ) ); ?> <code><?php echo esc_html( $slug ); ?></code></label>
					<?php endforeach; ?>
				</td></tr></table>
				<?php submit_button( __( 'Kaydet', 'role-based-prices-for-woocommerce' ) ); ?>
			</form>
		</div>
		<?php
	}
}
