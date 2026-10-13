<?php
/** Settings page. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_WN_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function menu(): void {
		add_options_page(
			__( 'Hoş geldin notu', 'welcome-note-for-wordpress' ),
			__( 'Hoş geldin notu', 'welcome-note-for-wordpress' ),
			'manage_options',
			'eru-wn',
			array( __CLASS__, 'render' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'eru_wn',
			Eru_WN_Settings::OPTION,
			array(
				'sanitize_callback' => array( 'Eru_WN_Settings', 'sanitize' ),
				'autoload'          => false,
			)
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Eru_WN_Settings::get();
		$o = Eru_WN_Settings::OPTION;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Hoş geldin notu', 'welcome-note-for-wordpress' ); ?></h1>
			<p><?php echo esc_html__( 'Metin boş bırakılırsa eklenti hiçbir şey göstermez. Metinde {name} (kullanıcının adı) ve {site} (site adı) kullanabilirsiniz. Metni veya bağlantıyı değiştirdiğinizde not, kapatmış olanlara yeniden gösterilir.', 'welcome-note-for-wordpress' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_wn' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="eru-wn-text"><?php echo esc_html__( 'Not metni', 'welcome-note-for-wordpress' ); ?></label></th>
						<td><textarea id="eru-wn-text" name="<?php echo esc_attr( $o ); ?>[text]" rows="3" class="large-text"><?php echo esc_textarea( $s['text'] ); ?></textarea></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-wn-url"><?php echo esc_html__( 'Bağlantı adresi', 'welcome-note-for-wordpress' ); ?></label></th>
						<td><input type="url" id="eru-wn-url" name="<?php echo esc_attr( $o ); ?>[link_url]" value="<?php echo esc_attr( $s['link_url'] ); ?>" class="regular-text" placeholder="https://"></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-wn-label"><?php echo esc_html__( 'Bağlantı yazısı', 'welcome-note-for-wordpress' ); ?></label></th>
						<td><input type="text" id="eru-wn-label" name="<?php echo esc_attr( $o ); ?>[link_label]" value="<?php echo esc_attr( $s['link_label'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-wn-style"><?php echo esc_html__( 'Görünüm', 'welcome-note-for-wordpress' ); ?></label></th>
						<td>
							<select id="eru-wn-style" name="<?php echo esc_attr( $o ); ?>[style]">
								<option value="info" <?php selected( $s['style'], 'info' ); ?>><?php echo esc_html__( 'Bilgi', 'welcome-note-for-wordpress' ); ?></option>
								<option value="success" <?php selected( $s['style'], 'success' ); ?>><?php echo esc_html__( 'Başarı', 'welcome-note-for-wordpress' ); ?></option>
								<option value="warning" <?php selected( $s['style'], 'warning' ); ?>><?php echo esc_html__( 'Uyarı', 'welcome-note-for-wordpress' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Yönetim paneli', 'welcome-note-for-wordpress' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[admin_enabled]" value="1" <?php checked( $s['admin_enabled'] ); ?>> <?php echo esc_html__( 'Seçili rollere yönetim panelinde göster', 'welcome-note-for-wordpress' ); ?></label>
							<p>
								<?php foreach ( wp_roles()->roles as $slug => $role ) : ?>
									<label style="display:inline-block;margin-right:1em"><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[roles][]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, (array) $s['roles'], true ) ); ?>> <?php echo esc_html( translate_user_role( $role['name'] ) ); ?></label>
								<?php endforeach; ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Site ziyaretçileri', 'welcome-note-for-wordpress' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[front_enabled]" value="1" <?php checked( $s['front_enabled'] ); ?>> <?php echo esc_html__( 'Sitede duyuru çubuğu olarak da göster', 'welcome-note-for-wordpress' ); ?></label>
							<p>
								<select name="<?php echo esc_attr( $o ); ?>[front_pos]">
									<option value="top" <?php selected( $s['front_pos'], 'top' ); ?>><?php echo esc_html__( 'Üstte', 'welcome-note-for-wordpress' ); ?></option>
									<option value="bottom" <?php selected( $s['front_pos'], 'bottom' ); ?>><?php echo esc_html__( 'Altta', 'welcome-note-for-wordpress' ); ?></option>
								</select>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
