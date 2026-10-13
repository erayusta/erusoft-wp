<?php
/** Settings page. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_HP_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	public static function menu(): void {
		add_options_page(
			__( 'Başlık Sayfalama', 'heading-pagination-for-wordpress' ),
			__( 'Başlık Sayfalama', 'heading-pagination-for-wordpress' ),
			'manage_options',
			'eru-hp',
			array( __CLASS__, 'render' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'eru_hp',
			Eru_HP_Settings::OPTION,
			array(
				'sanitize_callback' => array( 'Eru_HP_Settings', 'sanitize' ),
				'autoload'          => false,
			)
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s     = Eru_HP_Settings::get();
		$o     = Eru_HP_Settings::OPTION;
		$saved = get_option( $o, array() );
		$title = is_array( $saved ) && isset( $saved['toc_title'] ) ? (string) $saved['toc_title'] : '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Başlık Sayfalama', 'heading-pagination-for-wordpress' ); ?></h1>
			<p><?php echo esc_html__( 'Uzun içerikleri seçtiğiniz başlık düzeyinde sayfalara böler. Yeterli başlığı veya kelimesi olmayan içeriklere dokunulmaz. Elle <!--nextpage--> kullanan içerikler de olduğu gibi kalır.', 'heading-pagination-for-wordpress' ); ?></p>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_hp' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'İçerik türleri', 'heading-pagination-for-wordpress' ); ?></th>
						<td>
							<?php foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $type_obj ) : ?>
								<?php if ( 'attachment' === $type_obj->name ) { continue; } ?>
								<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[post_types][]" value="<?php echo esc_attr( $type_obj->name ); ?>" <?php checked( in_array( $type_obj->name, (array) $s['post_types'], true ) ); ?>> <?php echo esc_html( $type_obj->labels->name ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-hp-level"><?php echo esc_html__( 'Bölme başlığı', 'heading-pagination-for-wordpress' ); ?></label></th>
						<td>
							<select id="eru-hp-level" name="<?php echo esc_attr( $o ); ?>[level]">
								<?php foreach ( array( 2, 3, 4 ) as $l ) : ?>
									<option value="<?php echo esc_attr( (string) $l ); ?>" <?php selected( (int) $s['level'], $l ); ?>>H<?php echo esc_html( (string) $l ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-hp-sec"><?php echo esc_html__( 'En az bölüm sayısı', 'heading-pagination-for-wordpress' ); ?></label></th>
						<td><input type="number" id="eru-hp-sec" min="2" max="50" name="<?php echo esc_attr( $o ); ?>[min_sections]" value="<?php echo esc_attr( (string) $s['min_sections'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-hp-words"><?php echo esc_html__( 'En az kelime sayısı', 'heading-pagination-for-wordpress' ); ?></label></th>
						<td><input type="number" id="eru-hp-words" min="0" name="<?php echo esc_attr( $o ); ?>[min_words]" value="<?php echo esc_attr( (string) $s['min_words'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Gösterim', 'heading-pagination-for-wordpress' ); ?></th>
						<td>
							<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[show_toc]" value="1" <?php checked( $s['show_toc'] ); ?>> <?php echo esc_html__( 'İçindekiler kutusu', 'heading-pagination-for-wordpress' ); ?></label>
							<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[show_nav]" value="1" <?php checked( $s['show_nav'] ); ?>> <?php echo esc_html__( 'Önceki / sonraki bağlantıları', 'heading-pagination-for-wordpress' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-hp-title"><?php echo esc_html__( 'İçindekiler başlığı', 'heading-pagination-for-wordpress' ); ?></label></th>
						<td><input type="text" id="eru-hp-title" name="<?php echo esc_attr( $o ); ?>[toc_title]" value="<?php echo esc_attr( $title ); ?>" placeholder="<?php echo esc_attr( $s['toc_title'] ); ?>" class="regular-text"></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
