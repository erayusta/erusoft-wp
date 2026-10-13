<?php
/** Settings page. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_NB_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu(): void {
		add_options_page(
			__( 'Bilgi Kutuları', 'notice-boxes-for-wordpress' ),
			__( 'Bilgi Kutuları', 'notice-boxes-for-wordpress' ),
			'manage_options',
			'eru-nb',
			array( __CLASS__, 'render' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'eru_nb',
			Eru_NB_Settings::OPTION,
			array(
				'sanitize_callback' => array( 'Eru_NB_Settings', 'sanitize' ),
				'autoload'          => false,
			)
		);
	}

	public static function assets( string $hook ): void {
		if ( 'settings_page_eru-nb' === $hook ) {
			wp_enqueue_style( 'eru-notice-box-style' );
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = Eru_NB_Settings::get();
		$o     = Eru_NB_Settings::OPTION;
		$types = array(
			'info'    => __( 'Bilgi', 'notice-boxes-for-wordpress' ),
			'warning' => __( 'Uyarı', 'notice-boxes-for-wordpress' ),
			'success' => __( 'Başarı', 'notice-boxes-for-wordpress' ),
			'error'   => __( 'Hata', 'notice-boxes-for-wordpress' ),
		);
		$styles = array(
			'soft'    => __( 'Yumuşak', 'notice-boxes-for-wordpress' ),
			'solid'   => __( 'Dolgulu', 'notice-boxes-for-wordpress' ),
			'outline' => __( 'Çerçeveli', 'notice-boxes-for-wordpress' ),
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Bilgi Kutuları', 'notice-boxes-for-wordpress' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_nb' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="eru-nb-type"><?php echo esc_html__( 'Varsayılan tür', 'notice-boxes-for-wordpress' ); ?></label></th>
						<td><select id="eru-nb-type" name="<?php echo esc_attr( $o ); ?>[type]"><?php foreach ( $types as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['type'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-nb-style"><?php echo esc_html__( 'Varsayılan görünüm', 'notice-boxes-for-wordpress' ); ?></label></th>
						<td><select id="eru-nb-style" name="<?php echo esc_attr( $o ); ?>[style]"><?php foreach ( $styles as $k => $l ) : ?><option value="<?php echo esc_attr( $k ); ?>" <?php selected( $s['style'], $k ); ?>><?php echo esc_html( $l ); ?></option><?php endforeach; ?></select></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Simgeler', 'notice-boxes-for-wordpress' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[icons]" value="1" <?php checked( $s['icons'] ); ?>> <?php echo esc_html__( 'Kutularda simge göster', 'notice-boxes-for-wordpress' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-nb-dark"><?php echo esc_html__( 'Koyu mod', 'notice-boxes-for-wordpress' ); ?></label></th>
						<td>
							<select id="eru-nb-dark" name="<?php echo esc_attr( $o ); ?>[dark]">
								<option value="auto" <?php selected( $s['dark'], 'auto' ); ?>><?php echo esc_html__( 'Cihaz ayarını izle', 'notice-boxes-for-wordpress' ); ?></option>
								<option value="off" <?php selected( $s['dark'], 'off' ); ?>><?php echo esc_html__( 'Her zaman açık renkler', 'notice-boxes-for-wordpress' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php echo esc_html__( 'Örnekler', 'notice-boxes-for-wordpress' ); ?></h2>
			<?php
			foreach ( $styles as $sk => $sl ) {
				echo '<h3>' . esc_html( $sl ) . '</h3>';
				foreach ( $types as $tk => $tl ) {
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside box().
					echo Eru_NB_Render::box( $tk, $tl, $sk, '<p>' . esc_html__( 'Örnek metin: bu kutu bir bilgi, uyarı, başarı veya hata iletisi içindir.', 'notice-boxes-for-wordpress' ) . '</p>' );
				}
			}
			?>
			<h2><?php echo esc_html__( 'Kullanım', 'notice-boxes-for-wordpress' ); ?></h2>
			<p><code>[eru_notice type="warning" title="Dikkat" style="solid"]Metin[/eru_notice]</code></p>
			<p><?php echo esc_html__( 'Blok düzenleyicide "Bilgi Kutusu" bloğunu da ekleyebilirsiniz.', 'notice-boxes-for-wordpress' ); ?></p>
		</div>
		<?php
	}
}
