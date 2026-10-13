<?php
/** Meta box for editing a price list. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_PLST_Admin {

	const TD = 'price-lists-for-woocommerce';

	public static function init(): void {
		add_action( 'add_meta_boxes_' . Eru_PLST_Lists::CPT, array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_' . Eru_PLST_Lists::CPT, array( __CLASS__, 'save' ), 5, 2 );
		add_filter( 'manage_' . Eru_PLST_Lists::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Eru_PLST_Lists::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	public static function columns( $cols ): array {
		return array(
			'cb'     => $cols['cb'] ?? '',
			'title'  => __( 'Liste adı', 'price-lists-for-woocommerce' ),
			'assign' => __( 'Atanan', 'price-lists-for-woocommerce' ),
			'rules'  => __( 'Kurallar', 'price-lists-for-woocommerce' ),
		);
	}

	public static function column( $col, $post_id ): void {
		if ( 'assign' === $col ) {
			$roles = array_filter( (array) get_post_meta( $post_id, '_eru_plst_roles', true ) );
			$users = array_filter( (array) get_post_meta( $post_id, '_eru_plst_users', true ) );
			$names = wp_roles()->get_names();
			$parts = array();
			foreach ( $roles as $r ) {
				$parts[] = isset( $names[ $r ] ) ? translate_user_role( $names[ $r ] ) : $r;
			}
			if ( $users ) {
				/* translators: %d: number of users */
				$parts[] = sprintf( _n( '%d kullanıcı', '%d kullanıcı', count( $users ), 'price-lists-for-woocommerce' ), count( $users ) );
			}
			echo esc_html( $parts ? implode( ', ', $parts ) : '—' );
		} elseif ( 'rules' === $col ) {
			$pc     = (float) get_post_meta( $post_id, '_eru_plst_percent', true );
			$cats   = array_filter( (array) get_post_meta( $post_id, '_eru_plst_cats', true ) );
			$prices = array_filter( (array) get_post_meta( $post_id, '_eru_plst_prices', true ), 'is_numeric' );
			$parts  = array();
			if ( $pc > 0 ) {
				$parts[] = '-%' . wc_format_localized_decimal( $pc );
			}
			if ( $cats ) {
				/* translators: %d: count */
				$parts[] = sprintf( __( '%d kategori kuralı', 'price-lists-for-woocommerce' ), count( $cats ) );
			}
			if ( $prices ) {
				/* translators: %d: count */
				$parts[] = sprintf( __( '%d sabit fiyat', 'price-lists-for-woocommerce' ), count( $prices ) );
			}
			echo esc_html( $parts ? implode( ' · ', $parts ) : '—' );
		}
	}

	public static function add_box(): void {
		add_meta_box( 'eru_plst_box', __( 'Liste ayarları', 'price-lists-for-woocommerce' ), array( __CLASS__, 'render_box' ), Eru_PLST_Lists::CPT, 'normal', 'high' );
	}

	public static function render_box( $post ): void {
		$id       = $post->ID;
		$roles    = array_map( 'sanitize_key', (array) get_post_meta( $id, '_eru_plst_roles', true ) );
		$users    = array_filter( array_map( 'intval', (array) get_post_meta( $id, '_eru_plst_users', true ) ) );
		$emails   = array();
		foreach ( $users as $uid ) {
			$u = get_userdata( $uid );
			if ( $u ) {
				$emails[] = $u->user_email;
			}
		}
		$percent  = (float) get_post_meta( $id, '_eru_plst_percent', true );
		$cats     = (array) get_post_meta( $id, '_eru_plst_cats', true );
		$lines    = (string) get_post_meta( $id, '_eru_plst_lines', true );
		$priority = (int) get_post_meta( $id, '_eru_plst_priority', true );
		$terms    = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		wp_nonce_field( 'eru_plst_save', 'eru_plst_nonce' );
		?>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Roller', 'price-lists-for-woocommerce' ); ?></th><td>
				<?php foreach ( wp_roles()->get_names() as $slug => $name ) : ?>
					<label style="display:inline-block;margin-right:14px"><input type="checkbox" name="eru_plst_roles[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $roles, true ) ); ?>> <?php echo esc_html( translate_user_role( $name ) ); ?></label>
				<?php endforeach; ?>
				<p class="description"><?php esc_html_e( 'Bu roldeki kullanıcılar listenin fiyatlarını görür.', 'price-lists-for-woocommerce' ); ?></p>
			</td></tr>
			<tr><th><label for="eru_plst_users"><?php esc_html_e( 'Kullanıcılar (e-posta)', 'price-lists-for-woocommerce' ); ?></label></th><td>
				<textarea id="eru_plst_users" name="eru_plst_users" rows="2" class="large-text" placeholder="ornek@example.com, ornek2@example.com"><?php echo esc_textarea( implode( ', ', $emails ) ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Virgülle ayırın. Kişiye atanmış liste, role atanmış listeden önce uygulanır.', 'price-lists-for-woocommerce' ); ?></p>
			</td></tr>
			<tr><th><label for="eru_plst_percent"><?php esc_html_e( 'Tüm ürünlerde indirim %', 'price-lists-for-woocommerce' ); ?></label></th><td>
				<input type="number" id="eru_plst_percent" name="eru_plst_percent" min="0" max="100" step="0.01" value="<?php echo esc_attr( $percent > 0 ? $percent : '' ); ?>" style="width:100px">
				<p class="description"><?php esc_html_e( 'Normal fiyat üzerinden hesaplanır. Boş = indirim yok.', 'price-lists-for-woocommerce' ); ?></p>
			</td></tr>
			<tr><th><?php esc_html_e( 'Kategori indirimleri %', 'price-lists-for-woocommerce' ); ?></th><td>
				<div style="max-height:180px;overflow:auto;border:1px solid #c3c4c7;padding:6px 10px;background:#fff;max-width:420px">
				<?php if ( ! is_wp_error( $terms ) ) : foreach ( $terms as $t ) : ?>
					<label style="display:block;margin:2px 0"><input type="number" name="eru_plst_cats[<?php echo esc_attr( $t->term_id ); ?>]" min="0" max="100" step="0.01" style="width:80px" value="<?php echo esc_attr( isset( $cats[ $t->term_id ] ) ? $cats[ $t->term_id ] : '' ); ?>"> <?php echo esc_html( $t->name ); ?></label>
				<?php endforeach; endif; ?>
				</div>
				<p class="description"><?php esc_html_e( 'Kategori kuralı, tüm ürünler kuralından önce uygulanır.', 'price-lists-for-woocommerce' ); ?></p>
			</td></tr>
			<tr><th><label for="eru_plst_lines"><?php esc_html_e( 'Sabit fiyatlar', 'price-lists-for-woocommerce' ); ?></label></th><td>
				<textarea id="eru_plst_lines" name="eru_plst_lines" rows="6" class="large-text code" placeholder="SKU-001 = 79,90&#10;1234 = 149"><?php echo esc_textarea( $lines ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Her satıra bir ürün: SKU (veya ürün/varyasyon ID) = fiyat. Sabit fiyat, yüzde kurallarından önce uygulanır. Bulunamayan satırlar kaydedilmez.', 'price-lists-for-woocommerce' ); ?></p>
			</td></tr>
			<tr><th><label for="eru_plst_priority"><?php esc_html_e( 'Öncelik', 'price-lists-for-woocommerce' ); ?></label></th><td>
				<input type="number" id="eru_plst_priority" name="eru_plst_priority" step="1" value="<?php echo esc_attr( $priority ); ?>" style="width:100px">
				<p class="description"><?php esc_html_e( 'Birden çok liste eşleşirse küçük sayı önce gelir. Bir liste ürün için fiyat vermezse sıradaki listeye bakılır.', 'price-lists-for-woocommerce' ); ?></p>
			</td></tr>
		</table>
		<?php
	}

	public static function save( $post_id, $post ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['eru_plst_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eru_plst_nonce'] ) ), 'eru_plst_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$valid = array_keys( wp_roles()->get_names() );
		$roles = array();
		foreach ( (array) ( isset( $_POST['eru_plst_roles'] ) ? wp_unslash( $_POST['eru_plst_roles'] ) : array() ) as $r ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated against existing roles below.
			$r = sanitize_key( (string) $r );
			if ( in_array( $r, $valid, true ) && ! in_array( $r, $roles, true ) ) {
				$roles[] = $r;
			}
		}
		$users = array();
		$raw   = isset( $_POST['eru_plst_users'] ) ? sanitize_textarea_field( wp_unslash( $_POST['eru_plst_users'] ) ) : '';
		foreach ( preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) as $email ) {
			$u = get_user_by( 'email', sanitize_email( $email ) );
			if ( $u ) {
				$users[] = (int) $u->ID;
			}
		}
		$percent = isset( $_POST['eru_plst_percent'] ) ? (float) wc_format_decimal( sanitize_text_field( wp_unslash( $_POST['eru_plst_percent'] ) ) ) : 0;
		$cats    = array();
		foreach ( (array) ( isset( $_POST['eru_plst_cats'] ) ? wp_unslash( $_POST['eru_plst_cats'] ) : array() ) as $term_id => $pc ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- each value is cast below.
			if ( is_scalar( $pc ) && '' !== trim( (string) $pc ) && term_exists( (int) $term_id, 'product_cat' ) ) {
				$cats[ (int) $term_id ] = min( 100, max( 0, (float) wc_format_decimal( (string) $pc ) ) );
			}
		}
		$lines = isset( $_POST['eru_plst_lines'] ) ? sanitize_textarea_field( wp_unslash( $_POST['eru_plst_lines'] ) ) : '';
		list( $prices ) = Eru_PLST_Lists::parse_lines( $lines );

		update_post_meta( $post_id, '_eru_plst_roles', $roles );
		update_post_meta( $post_id, '_eru_plst_users', array_values( array_unique( $users ) ) );
		update_post_meta( $post_id, '_eru_plst_percent', min( 100, max( 0, $percent ) ) );
		update_post_meta( $post_id, '_eru_plst_cats', $cats );
		update_post_meta( $post_id, '_eru_plst_lines', $lines );
		update_post_meta( $post_id, '_eru_plst_prices', $prices );
		update_post_meta( $post_id, '_eru_plst_priority', isset( $_POST['eru_plst_priority'] ) ? (int) $_POST['eru_plst_priority'] : 0 );
	}
}
