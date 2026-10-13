<?php
/** Settings page and drag-and-drop ordering of FAQ items. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_FAQ_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'wp_ajax_eru_faq_sort', array( __CLASS__, 'ajax_sort' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'admin_order' ) );
	}

	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Eru_FAQ_Post_Type::TYPE,
			__( 'SSS ayarları', 'faq-accordion-for-wordpress' ),
			__( 'Ayarlar', 'faq-accordion-for-wordpress' ),
			'manage_options',
			'eru-faq-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'eru_faq',
			Eru_FAQ_Settings::OPTION,
			array(
				'sanitize_callback' => array( 'Eru_FAQ_Settings', 'sanitize' ),
				'autoload'          => false,
			)
		);
	}

	/** Order the admin list by menu_order unless the user picked a column. */
	public static function admin_order( WP_Query $q ): void {
		if ( ! is_admin() || ! $q->is_main_query() || Eru_FAQ_Post_Type::TYPE !== $q->get( 'post_type' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['orderby'] ) ) {
			$q->set( 'orderby', array( 'menu_order' => 'ASC', 'date' => 'ASC' ) );
		}
	}

	public static function assets( string $hook ): void {
		$screen = get_current_screen();
		if ( 'edit.php' === $hook && $screen && Eru_FAQ_Post_Type::TYPE === $screen->post_type ) {
			wp_enqueue_script( 'eru-faq-sort', ERU_FAQ_URL . 'assets/admin-sort.js', array( 'jquery', 'jquery-ui-sortable' ), ERU_FAQ_VERSION, true );
			wp_localize_script(
				'eru-faq-sort',
				'eruFaqSort',
				array(
					'ajax'  => admin_url( 'admin-ajax.php' ),
					'nonce' => wp_create_nonce( 'eru_faq_sort' ),
					'hint'  => __( 'Sıralamak için satırları sürükleyin.', 'faq-accordion-for-wordpress' ),
				)
			);
		}
	}

	public static function ajax_sort(): void {
		check_ajax_referer( 'eru_faq_sort', 'nonce' );
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_send_json_error( null, 403 );
		}
		$ids = isset( $_POST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['ids'] ) ) : array();
		$pos = 0;
		foreach ( $ids as $id ) {
			if ( $id && Eru_FAQ_Post_Type::TYPE === get_post_type( $id ) && current_user_can( 'edit_post', $id ) ) {
				wp_update_post( array( 'ID' => $id, 'menu_order' => ++$pos ) );
			}
		}
		wp_send_json_success( array( 'count' => $pos ) );
	}

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Eru_FAQ_Settings::get();
		$o = Eru_FAQ_Settings::OPTION;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'SSS Akordeon ayarları', 'faq-accordion-for-wordpress' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_faq' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'FAQPage şeması', 'faq-accordion-for-wordpress' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[schema]" value="1" <?php checked( $s['schema'] ); ?>> <?php echo esc_html__( 'Sayfaya FAQPage JSON-LD ekle (sayfa başına bir kez)', 'faq-accordion-for-wordpress' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Açılış durumu', 'faq-accordion-for-wordpress' ); ?></th>
						<td>
							<select name="<?php echo esc_attr( $o ); ?>[open]">
								<option value="none" <?php selected( $s['open'], 'none' ); ?>><?php echo esc_html__( 'Hepsi kapalı', 'faq-accordion-for-wordpress' ); ?></option>
								<option value="first" <?php selected( $s['open'], 'first' ); ?>><?php echo esc_html__( 'İlk soru açık', 'faq-accordion-for-wordpress' ); ?></option>
								<option value="all" <?php selected( $s['open'], 'all' ); ?>><?php echo esc_html__( 'Hepsi açık', 'faq-accordion-for-wordpress' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Tek seferde bir soru', 'faq-accordion-for-wordpress' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[exclusive]" value="1" <?php checked( $s['exclusive'] ); ?>> <?php echo esc_html__( 'Bir soru açılınca diğerleri kapansın (destekleyen tarayıcılarda)', 'faq-accordion-for-wordpress' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-faq-accent"><?php echo esc_html__( 'Vurgu rengi', 'faq-accordion-for-wordpress' ); ?></label></th>
						<td><input type="text" id="eru-faq-accent" name="<?php echo esc_attr( $o ); ?>[accent]" value="<?php echo esc_attr( $s['accent'] ); ?>" class="small-text"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
			<h2><?php echo esc_html__( 'Kullanım', 'faq-accordion-for-wordpress' ); ?></h2>
			<p><code>[eru_faq]</code> &nbsp; <code>[eru_faq group="genel" open="first" limit="10"]</code></p>
			<p><?php echo esc_html__( 'Soruları SSS → Yeni soru ile ekleyin; grupları "Gruplar" ekranından yönetin. Sıralama için liste ekranında satırları sürükleyin.', 'faq-accordion-for-wordpress' ); ?></p>
		</div>
		<?php
	}
}
