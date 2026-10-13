<?php
/** WooCommerce > B2B Fiyatlar admin screen and the customer-group profile field. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_B2B_Admin {

	const CAP = 'manage_woocommerce';
	const TD  = 'b2b-customer-pricing-for-woocommerce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_b2b_op', array( __CLASS__, 'handle' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'profile_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'profile_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_profile' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_profile' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'B2B Fiyatlar', 'b2b-customer-pricing-for-woocommerce' ), __( 'B2B Fiyatlar', 'b2b-customer-pricing-for-woocommerce' ), self::CAP, 'eru-b2b', array( __CLASS__, 'render' ) );
	}

	private static function back( string $notice ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => 'eru-b2b', 'notice' => $notice ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'b2b-customer-pricing-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_b2b_op' );
		$op = isset( $_POST['op'] ) ? sanitize_key( wp_unslash( $_POST['op'] ) ) : ( isset( $_GET['op'] ) ? sanitize_key( wp_unslash( $_GET['op'] ) ) : '' );

		switch ( $op ) {
			case 'label':
				Eru_B2B_Store::save_label( isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '' );
				self::back( 'saved' );
				break;
			case 'add_group':
				$slug = Eru_B2B_Store::add_group( isset( $_POST['group_name'] ) ? wp_unslash( $_POST['group_name'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in add_group().
				self::back( '' === $slug ? 'invalid' : 'saved' );
				break;
			case 'delete_group':
				Eru_B2B_Store::delete_group( isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '' );
				self::back( 'deleted' );
				break;
			case 'add_rule':
				$in = array(
					'scope'  => isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : '',
					'type'   => isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '',
					'mode'   => isset( $_POST['mode'] ) ? sanitize_key( wp_unslash( $_POST['mode'] ) ) : '',
					'value'  => isset( $_POST['value'] ) ? sanitize_text_field( wp_unslash( $_POST['value'] ) ) : '',
					'object' => isset( $_POST['category'] ) ? absint( wp_unslash( $_POST['category'] ) ) : 0,
				);
				if ( 'user' === $in['scope'] ) {
					$email        = isset( $_POST['user_email'] ) ? sanitize_email( wp_unslash( $_POST['user_email'] ) ) : '';
					$user         = $email ? get_user_by( 'email', $email ) : false;
					$in['target'] = $user ? $user->ID : 0;
				} else {
					$in['target'] = isset( $_POST['group'] ) ? sanitize_key( wp_unslash( $_POST['group'] ) ) : '';
				}
				if ( 'product' === $in['type'] ) {
					$ref          = isset( $_POST['product_ref'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['product_ref'] ) ) ) : '';
					$in['object'] = (int) wc_get_product_id_by_sku( $ref );
					if ( ! $in['object'] && ctype_digit( $ref ) ) {
						$in['object'] = (int) $ref;
					}
				}
				self::back( '' === Eru_B2B_Store::add_rule( $in ) ? 'invalid' : 'saved' );
				break;
			case 'delete_rule':
				Eru_B2B_Store::delete_rule( isset( $_GET['id'] ) ? sanitize_text_field( wp_unslash( $_GET['id'] ) ) : '' );
				self::back( 'deleted' );
				break;
		}
		self::back( 'invalid' );
	}

	public static function profile_field( $user ): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$groups = Eru_B2B_Store::groups();
		if ( ! $groups ) {
			return;
		}
		$current = Eru_B2B_Store::user_group( $user->ID );
		wp_nonce_field( 'eru_b2b_profile', 'eru_b2b_profile_nonce' );
		?>
		<h2><?php esc_html_e( 'B2B müşteri grubu', 'b2b-customer-pricing-for-woocommerce' ); ?></h2>
		<table class="form-table" role="presentation"><tr>
			<th><label for="eru_b2b_group"><?php esc_html_e( 'Grup', 'b2b-customer-pricing-for-woocommerce' ); ?></label></th>
			<td><select name="eru_b2b_group" id="eru_b2b_group">
				<option value=""><?php esc_html_e( '— Grup yok —', 'b2b-customer-pricing-for-woocommerce' ); ?></option>
				<?php foreach ( $groups as $slug => $name ) : ?>
					<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $current, $slug ); ?>><?php echo esc_html( $name ); ?></option>
				<?php endforeach; ?>
			</select></td>
		</tr></table>
		<?php
	}

	public static function save_profile( $user_id ): void {
		if ( ! current_user_can( self::CAP ) || ! isset( $_POST['eru_b2b_profile_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eru_b2b_profile_nonce'] ) ), 'eru_b2b_profile' ) ) {
			return;
		}
		$slug = isset( $_POST['eru_b2b_group'] ) ? sanitize_key( wp_unslash( $_POST['eru_b2b_group'] ) ) : '';
		if ( '' !== $slug && isset( Eru_B2B_Store::groups()[ $slug ] ) ) {
			update_user_meta( $user_id, Eru_B2B_Store::USERMETA, $slug );
		} else {
			delete_user_meta( $user_id, Eru_B2B_Store::USERMETA );
		}
		Eru_B2B_Store::save_groups( Eru_B2B_Store::groups() ); // Bump the version so cached price ranges refresh.
	}

	private static function url( string $op, string $id ): string {
		return wp_nonce_url( add_query_arg( array( 'action' => 'eru_b2b_op', 'op' => $op, 'id' => $id ), admin_url( 'admin-post.php' ) ), 'eru_b2b_op' );
	}

	private static function target_label( array $r ): string {
		if ( 'user' === $r['scope'] ) {
			$u = get_userdata( (int) $r['target'] );
			return $u ? $u->user_email : '#' . (int) $r['target'];
		}
		$g = Eru_B2B_Store::groups();
		return $g[ $r['target'] ] ?? (string) $r['target'];
	}

	private static function object_label( array $r ): string {
		if ( 'all' === $r['type'] ) {
			return __( 'Tüm ürünler', 'b2b-customer-pricing-for-woocommerce' );
		}
		if ( 'category' === $r['type'] ) {
			$t = get_term( (int) $r['object'], 'product_cat' );
			return $t && ! is_wp_error( $t ) ? $t->name : '#' . (int) $r['object'];
		}
		$p = wc_get_product( (int) $r['object'] );
		return $p ? $p->get_name() : '#' . (int) $r['object'];
	}

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$groups = Eru_B2B_Store::groups();
		$rules  = Eru_B2B_Store::rules();
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( $_GET['notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$cats   = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$form   = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'B2B Müşteri Fiyatları', 'b2b-customer-pricing-for-woocommerce' ); ?></h1>
			<?php if ( 'saved' === $notice || 'deleted' === $notice ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Kaydedildi.', 'b2b-customer-pricing-for-woocommerce' ); ?></p></div>
			<?php elseif ( 'invalid' === $notice ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Girdiğiniz bilgiler geçersiz; kural kaydedilmedi.', 'b2b-customer-pricing-for-woocommerce' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Müşteriye veya müşteri grubuna özel sabit fiyat ya da yüzde indirim kuralları tanımlayın. Kural yoksa eklenti hiçbir şey yapmaz. Öncelik: müşteriye özel kural > grup kuralı; aynı düzeyde ürün > kategori > tüm ürünler.', 'b2b-customer-pricing-for-woocommerce' ); ?></p>

			<h2><?php esc_html_e( 'Etiket', 'b2b-customer-pricing-for-woocommerce' ); ?></h2>
			<form method="post" action="<?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput ?>">
				<input type="hidden" name="action" value="eru_b2b_op"><input type="hidden" name="op" value="label">
				<?php wp_nonce_field( 'eru_b2b_op' ); ?>
				<input type="text" name="label" class="regular-text" value="<?php echo esc_attr( Eru_B2B_Store::label() ); ?>">
				<?php submit_button( __( 'Kaydet', 'b2b-customer-pricing-for-woocommerce' ), 'secondary', 'submit', false ); ?>
				<p class="description"><?php esc_html_e( 'Özel fiyatı olan müşteriye fiyatın yanında gösterilir. Boş bırakırsanız gösterilmez.', 'b2b-customer-pricing-for-woocommerce' ); ?></p>
			</form>

			<h2><?php esc_html_e( 'Müşteri grupları', 'b2b-customer-pricing-for-woocommerce' ); ?></h2>
			<form method="post" action="<?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput ?>">
				<input type="hidden" name="action" value="eru_b2b_op"><input type="hidden" name="op" value="add_group">
				<?php wp_nonce_field( 'eru_b2b_op' ); ?>
				<input type="text" name="group_name" class="regular-text" placeholder="<?php esc_attr_e( 'Grup adı', 'b2b-customer-pricing-for-woocommerce' ); ?>" required>
				<?php submit_button( __( 'Grup ekle', 'b2b-customer-pricing-for-woocommerce' ), 'secondary', 'submit', false ); ?>
			</form>
			<?php if ( $groups ) : ?>
				<table class="widefat striped" style="max-width:600px;margin-top:10px"><tbody>
				<?php foreach ( $groups as $slug => $name ) : ?>
					<tr><td><?php echo esc_html( $name ); ?> <code><?php echo esc_html( $slug ); ?></code></td>
					<td><a href="<?php echo esc_url( self::url( 'delete_group', $slug ) ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Grup ve kuralları silinsin mi?', 'b2b-customer-pricing-for-woocommerce' ) ); ?>')"><?php esc_html_e( 'Sil', 'b2b-customer-pricing-for-woocommerce' ); ?></a></td></tr>
				<?php endforeach; ?>
				</tbody></table>
				<p class="description"><?php esc_html_e( 'Kullanıcıyı gruba almak için kullanıcı profilindeki "B2B müşteri grubu" alanını kullanın.', 'b2b-customer-pricing-for-woocommerce' ); ?></p>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Fiyat kuralları', 'b2b-customer-pricing-for-woocommerce' ); ?></h2>
			<form method="post" action="<?php echo $form; // phpcs:ignore WordPress.Security.EscapeOutput ?>">
				<input type="hidden" name="action" value="eru_b2b_op"><input type="hidden" name="op" value="add_rule">
				<?php wp_nonce_field( 'eru_b2b_op' ); ?>
				<table class="form-table" role="presentation">
					<tr><th><?php esc_html_e( 'Kime', 'b2b-customer-pricing-for-woocommerce' ); ?></th><td>
						<select name="scope">
							<option value="user"><?php esc_html_e( 'Müşteri (e-posta)', 'b2b-customer-pricing-for-woocommerce' ); ?></option>
							<option value="group"><?php esc_html_e( 'Grup', 'b2b-customer-pricing-for-woocommerce' ); ?></option>
						</select>
						<input type="email" name="user_email" placeholder="ornek@example.com">
						<select name="group"><option value=""><?php esc_html_e( '— Grup —', 'b2b-customer-pricing-for-woocommerce' ); ?></option>
							<?php foreach ( $groups as $slug => $name ) : ?><option value="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $name ); ?></option><?php endforeach; ?>
						</select>
					</td></tr>
					<tr><th><?php esc_html_e( 'Neye', 'b2b-customer-pricing-for-woocommerce' ); ?></th><td>
						<select name="type">
							<option value="product"><?php esc_html_e( 'Ürün', 'b2b-customer-pricing-for-woocommerce' ); ?></option>
							<option value="category"><?php esc_html_e( 'Kategori', 'b2b-customer-pricing-for-woocommerce' ); ?></option>
							<option value="all"><?php esc_html_e( 'Tüm ürünler', 'b2b-customer-pricing-for-woocommerce' ); ?></option>
						</select>
						<input type="text" name="product_ref" placeholder="<?php esc_attr_e( 'Ürün SKU veya ID', 'b2b-customer-pricing-for-woocommerce' ); ?>">
						<select name="category"><option value="0"><?php esc_html_e( '— Kategori —', 'b2b-customer-pricing-for-woocommerce' ); ?></option>
							<?php if ( ! is_wp_error( $cats ) ) : foreach ( $cats as $t ) : ?><option value="<?php echo esc_attr( $t->term_id ); ?>"><?php echo esc_html( $t->name ); ?></option><?php endforeach; endif; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Ürün için SKU veya ID yazın, kategori için listeden seçin.', 'b2b-customer-pricing-for-woocommerce' ); ?></p>
					</td></tr>
					<tr><th><?php esc_html_e( 'Kural', 'b2b-customer-pricing-for-woocommerce' ); ?></th><td>
						<select name="mode">
							<option value="price"><?php esc_html_e( 'Sabit fiyat', 'b2b-customer-pricing-for-woocommerce' ); ?></option>
							<option value="percent"><?php esc_html_e( 'Yüzde indirim', 'b2b-customer-pricing-for-woocommerce' ); ?></option>
						</select>
						<input type="text" name="value" required style="width:100px" placeholder="70">
					</td></tr>
				</table>
				<?php submit_button( __( 'Kural ekle', 'b2b-customer-pricing-for-woocommerce' ) ); ?>
			</form>

			<table class="widefat striped">
				<thead><tr><th><?php esc_html_e( 'Kime', 'b2b-customer-pricing-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Neye', 'b2b-customer-pricing-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Kural', 'b2b-customer-pricing-for-woocommerce' ); ?></th><th></th></tr></thead>
				<tbody>
				<?php if ( ! $rules ) : ?>
					<tr><td colspan="4"><?php esc_html_e( 'Henüz kural yok.', 'b2b-customer-pricing-for-woocommerce' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rules as $r ) : ?>
					<tr>
						<td><?php echo esc_html( ( 'user' === $r['scope'] ? __( 'Müşteri', 'b2b-customer-pricing-for-woocommerce' ) : __( 'Grup', 'b2b-customer-pricing-for-woocommerce' ) ) . ': ' . self::target_label( $r ) ); ?></td>
						<td><?php echo esc_html( self::object_label( $r ) ); ?></td>
						<td><?php echo 'price' === $r['mode'] ? wp_kses_post( wc_price( $r['value'] ) ) : esc_html( '-%' . wc_format_localized_decimal( $r['value'] ) ); ?></td>
						<td><a href="<?php echo esc_url( self::url( 'delete_rule', $r['id'] ) ); ?>"><?php esc_html_e( 'Sil', 'b2b-customer-pricing-for-woocommerce' ); ?></a></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
