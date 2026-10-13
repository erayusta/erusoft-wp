<?php
/** Meta box, list columns and the settings screen. Capability: edit_post for the meta box, manage_options for settings. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CP_Admin {

	const NONCE = 'eru_cp_meta';

	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_box' ) );
		add_action( 'save_post_' . Eru_CP_Campaign::TYPE, array( __CLASS__, 'save_meta' ), 10, 1 );
		add_filter( 'manage_' . Eru_CP_Campaign::TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Eru_CP_Campaign::TYPE . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_cp_save', array( __CLASS__, 'save_settings' ) );
	}

	/* ---------- meta box ---------- */

	public static function add_box(): void {
		add_meta_box( 'eru_cp_box', __( 'Kampanya ayarları', 'campaign-pages-for-wordpress' ), array( __CLASS__, 'render_box' ), Eru_CP_Campaign::TYPE, 'side', 'high' );
	}

	public static function render_box( WP_Post $post ): void {
		wp_nonce_field( self::NONCE, self::NONCE );
		$g    = fn( $k ) => (string) get_post_meta( $post->ID, $k, true );
		$type = $g( Eru_CP_Campaign::LINK ) ?: 'none';
		$ref  = $g( Eru_CP_Campaign::REF );
		$cats = taxonomy_exists( 'product_cat' ) ? get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) ) : array();
		$cats = is_wp_error( $cats ) ? array() : $cats;
		?>
		<p><label for="eru_cp_start"><strong><?php esc_html_e( 'Başlangıç tarihi', 'campaign-pages-for-wordpress' ); ?></strong></label><br>
			<input type="date" id="eru_cp_start" name="eru_cp_start" value="<?php echo esc_attr( $g( Eru_CP_Campaign::START ) ); ?>" style="width:100%"></p>
		<p><label for="eru_cp_end"><strong><?php esc_html_e( 'Bitiş tarihi', 'campaign-pages-for-wordpress' ); ?></strong></label><br>
			<input type="date" id="eru_cp_end" name="eru_cp_end" value="<?php echo esc_attr( $g( Eru_CP_Campaign::END ) ); ?>" style="width:100%"></p>
		<p class="description"><?php esc_html_e( 'Bitiş tarihinden sonraki gün kampanya listelerden kendiliğinden çıkar. Tarihleri boş bırakırsanız süresiz yayında kalır.', 'campaign-pages-for-wordpress' ); ?></p>
		<p><label><input type="checkbox" name="eru_cp_off" value="1" <?php checked( $g( Eru_CP_Campaign::OFF ), '1' ); ?>> <?php esc_html_e( 'Elle gizle (tarihi içinde olsa bile)', 'campaign-pages-for-wordpress' ); ?></label></p>
		<hr>
		<p><label for="eru_cp_link_type"><strong><?php esc_html_e( 'Buton bağlantısı', 'campaign-pages-for-wordpress' ); ?></strong></label><br>
			<select id="eru_cp_link_type" name="eru_cp_link_type" style="width:100%">
				<option value="none" <?php selected( $type, 'none' ); ?>><?php esc_html_e( 'Buton yok', 'campaign-pages-for-wordpress' ); ?></option>
				<?php if ( $cats ) : ?>
					<option value="product" <?php selected( $type, 'product' ); ?>><?php esc_html_e( 'Ürün (kimlik)', 'campaign-pages-for-wordpress' ); ?></option>
					<option value="category" <?php selected( $type, 'category' ); ?>><?php esc_html_e( 'Ürün kategorisi', 'campaign-pages-for-wordpress' ); ?></option>
				<?php endif; ?>
				<option value="url" <?php selected( $type, 'url' ); ?>><?php esc_html_e( 'Özel adres (URL)', 'campaign-pages-for-wordpress' ); ?></option>
			</select></p>
		<p><label for="eru_cp_link_ref"><?php esc_html_e( 'Ürün kimliği, kategori kimliği veya adres', 'campaign-pages-for-wordpress' ); ?></label><br>
			<input type="text" id="eru_cp_link_ref" name="eru_cp_link_ref" value="<?php echo esc_attr( $ref ); ?>" style="width:100%"></p>
		<?php if ( $cats ) : ?>
			<p class="description"><?php esc_html_e( 'Kategori kimlikleri:', 'campaign-pages-for-wordpress' ); ?>
				<?php echo esc_html( implode( ', ', array_map( fn( $c ) => $c->name . ' = ' . $c->term_id, array_slice( $cats, 0, 12 ) ) ) ); ?></p>
		<?php endif; ?>
		<p><label for="eru_cp_cta"><?php esc_html_e( 'Buton yazısı', 'campaign-pages-for-wordpress' ); ?></label><br>
			<input type="text" id="eru_cp_cta" name="eru_cp_cta" value="<?php echo esc_attr( $g( Eru_CP_Campaign::CTA ) ); ?>" placeholder="<?php echo esc_attr( Eru_CP_Settings::get()['cta_default'] ); ?>" style="width:100%"></p>
		<p class="description"><?php esc_html_e( 'Banner için “Öne çıkan görsel” alanını kullanın (önerilen oran 12:5).', 'campaign-pages-for-wordpress' ); ?></p>
		<?php
	}

	public static function save_meta( int $post_id ): void {
		if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::NONCE ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$p = fn( $k ) => isset( $_POST[ $k ] ) ? sanitize_text_field( wp_unslash( $_POST[ $k ] ) ) : '';

		update_post_meta( $post_id, Eru_CP_Campaign::START, Eru_CP_Campaign::clean_date( $p( 'eru_cp_start' ) ) );
		update_post_meta( $post_id, Eru_CP_Campaign::END, Eru_CP_Campaign::clean_date( $p( 'eru_cp_end' ) ) );
		if ( '' !== $p( 'eru_cp_off' ) ) {
			update_post_meta( $post_id, Eru_CP_Campaign::OFF, '1' );
		} else {
			delete_post_meta( $post_id, Eru_CP_Campaign::OFF );
		}
		$type = in_array( $p( 'eru_cp_link_type' ), array( 'product', 'category', 'url' ), true ) ? $p( 'eru_cp_link_type' ) : 'none';
		$ref  = $p( 'eru_cp_link_ref' );
		if ( 'url' === $type ) {
			$ref = esc_url_raw( $ref, array( 'http', 'https' ) );
		} else {
			$ref = (string) absint( $ref );
		}
		update_post_meta( $post_id, Eru_CP_Campaign::LINK, $type );
		update_post_meta( $post_id, Eru_CP_Campaign::REF, $ref );
		update_post_meta( $post_id, Eru_CP_Campaign::CTA, $p( 'eru_cp_cta' ) );
	}

	/* ---------- list columns ---------- */

	public static function columns( array $cols ): array {
		$out = array();
		foreach ( $cols as $k => $v ) {
			$out[ $k ] = $v;
			if ( 'title' === $k ) {
				$out['eru_cp_status'] = __( 'Durum', 'campaign-pages-for-wordpress' );
				$out['eru_cp_dates']  = __( 'Tarihler', 'campaign-pages-for-wordpress' );
			}
		}
		return $out;
	}

	public static function column( string $col, int $post_id ): void {
		if ( 'eru_cp_status' === $col ) {
			$labels = array(
				'active'   => __( 'Aktif', 'campaign-pages-for-wordpress' ),
				'upcoming' => __( 'Yaklaşan', 'campaign-pages-for-wordpress' ),
				'ended'    => __( 'Bitti', 'campaign-pages-for-wordpress' ),
				'off'      => __( 'Gizli', 'campaign-pages-for-wordpress' ),
			);
			echo esc_html( $labels[ Eru_CP_Campaign::status( $post_id ) ] );
		} elseif ( 'eru_cp_dates' === $col ) {
			echo esc_html( Eru_CP_Campaign::date_label( $post_id ) ?: '—' );
		}
	}

	/* ---------- settings ---------- */

	public static function menu(): void {
		add_submenu_page( 'edit.php?post_type=' . Eru_CP_Campaign::TYPE, __( 'Kampanya ayarları', 'campaign-pages-for-wordpress' ), __( 'Ayarlar', 'campaign-pages-for-wordpress' ), 'manage_options', 'eru-cp-settings', array( __CLASS__, 'render_settings' ) );
	}

	public static function save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'campaign-pages-for-wordpress' ), 403 );
		}
		check_admin_referer( 'eru_cp_save' );
		$raw = isset( $_POST['s'] ) ? wp_unslash( $_POST['s'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in Eru_CP_Settings::sanitize().
		Eru_CP_Settings::save( Eru_CP_Settings::sanitize( $raw ) );
		wp_safe_redirect( add_query_arg( array( 'post_type' => Eru_CP_Campaign::TYPE, 'page' => 'eru-cp-settings', 'saved' => 1 ), admin_url( 'edit.php' ) ) );
		exit;
	}

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Eru_CP_Settings::get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kampanya sayfaları', 'campaign-pages-for-wordpress' ); ?></h1>
			<?php if ( isset( $_GET['saved'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Ayarlar kaydedildi.', 'campaign-pages-for-wordpress' ); ?></p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_cp_save">
				<?php wp_nonce_field( 'eru_cp_save' ); ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="eru_cp_slug"><?php esc_html_e( 'Arşiv adresi', 'campaign-pages-for-wordpress' ); ?></label></th>
						<td><code><?php echo esc_html( trailingslashit( home_url() ) ); ?></code><input type="text" id="eru_cp_slug" name="s[archive_slug]" value="<?php echo esc_attr( $s['archive_slug'] ); ?>" class="regular-text">
						<p class="description"><?php esc_html_e( 'Kampanya listesi ve tek kampanya adresleri bu yolu kullanır. Değiştirdiğinizde adres kuralları bir kez yenilenir.', 'campaign-pages-for-wordpress' ); ?></p></td></tr>
					<tr><th scope="row"><label for="eru_cp_title"><?php esc_html_e( 'Arşiv başlığı', 'campaign-pages-for-wordpress' ); ?></label></th>
						<td><input type="text" id="eru_cp_title" name="s[archive_title]" value="<?php echo esc_attr( $s['archive_title'] ); ?>" class="regular-text"></td></tr>
					<tr><th scope="row"><label for="eru_cp_intro"><?php esc_html_e( 'Giriş metni', 'campaign-pages-for-wordpress' ); ?></label></th>
						<td><textarea id="eru_cp_intro" name="s[archive_intro]" rows="3" class="large-text"><?php echo esc_textarea( $s['archive_intro'] ); ?></textarea></td></tr>
					<tr><th scope="row"><?php esc_html_e( 'Listede göster', 'campaign-pages-for-wordpress' ); ?></th>
						<td><label><input type="checkbox" name="s[show_upcoming]" value="1" <?php checked( ! empty( $s['show_upcoming'] ) ); ?>> <?php esc_html_e( 'Yaklaşan kampanyaları da göster', 'campaign-pages-for-wordpress' ); ?></label><br>
						<label><input type="checkbox" name="s[show_ended]" value="1" <?php checked( ! empty( $s['show_ended'] ) ); ?>> <?php esc_html_e( 'Sona eren kampanyaları da göster', 'campaign-pages-for-wordpress' ); ?></label></td></tr>
					<tr><th scope="row"><label for="eru_cp_cols"><?php esc_html_e( 'Sütun sayısı', 'campaign-pages-for-wordpress' ); ?></label></th>
						<td><input type="number" id="eru_cp_cols" name="s[columns]" min="1" max="4" value="<?php echo esc_attr( (string) $s['columns'] ); ?>" class="small-text"></td></tr>
					<tr><th scope="row"><label for="eru_cp_accent"><?php esc_html_e( 'Vurgu rengi', 'campaign-pages-for-wordpress' ); ?></label></th>
						<td><input type="text" id="eru_cp_accent" name="s[accent]" value="<?php echo esc_attr( $s['accent'] ); ?>" class="small-text" style="width:110px"></td></tr>
					<tr><th scope="row"><label for="eru_cp_cta_default"><?php esc_html_e( 'Varsayılan buton yazısı', 'campaign-pages-for-wordpress' ); ?></label></th>
						<td><input type="text" id="eru_cp_cta_default" name="s[cta_default]" value="<?php echo esc_attr( $s['cta_default'] ); ?>" class="regular-text"></td></tr>
				</table>
				<?php submit_button( __( 'Kaydet', 'campaign-pages-for-wordpress' ) ); ?>
			</form>
		</div>
		<?php
	}
}
