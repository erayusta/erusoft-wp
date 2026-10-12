<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Ayarlar → Arşiv H1 ve terim düzenleme alanı. */
class Eru_Cah_Settings {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_init', array( __CLASS__, 'term_fields' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_CAH_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=eru-cah' ) ) . '">' . esc_html__( 'Ayarlar', 'category-archive-h1-for-wordpress' ) . '</a>' );
				return $links;
			}
		);
	}

	/** Seçilebilir sınıflandırmalar (arayüzü olan, herkese açık). */
	public static function available_taxonomies(): array {
		return get_taxonomies( array( 'public' => true, 'show_ui' => true ), 'objects' );
	}

	public static function menu(): void {
		add_options_page(
			__( 'Arşiv H1', 'category-archive-h1-for-wordpress' ),
			__( 'Arşiv H1', 'category-archive-h1-for-wordpress' ),
			'manage_options',
			'eru-cah',
			array( __CLASS__, 'render' )
		);
	}

	public static function register(): void {
		register_setting(
			'eru_cah',
			Eru_Cah::OPTION_TAXES,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_taxonomies' ),
				'default'           => array( 'category' ),
			)
		);
		register_setting(
			'eru_cah',
			Eru_Cah::OPTION_MODE,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_mode' ),
				'default'           => Eru_Cah::MODE_AUTO,
			)
		);
		register_setting(
			'eru_cah',
			Eru_Cah::OPTION_SEO,
			array(
				'type'              => 'string',
				'sanitize_callback' => fn( $v ) => $v ? 'yes' : 'no',
				'default'           => 'yes',
			)
		);
	}

	public static function sanitize_taxonomies( $input ): array {
		$available = self::available_taxonomies();
		$out       = array();
		foreach ( (array) $input as $tax ) {
			$tax = sanitize_key( (string) $tax );
			if ( isset( $available[ $tax ] ) ) {
				$out[] = $tax;
			}
		}
		return array_values( array_unique( $out ) );
	}

	public static function sanitize_mode( $input ): string {
		$input = sanitize_key( (string) $input );
		return in_array( $input, array( Eru_Cah::MODE_FILTER, Eru_Cah::MODE_AUTO, Eru_Cah::MODE_REPLACE ), true ) ? $input : Eru_Cah::MODE_AUTO;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$enabled = Eru_Cah::taxonomies();
		$mode    = Eru_Cah::mode();
		$modes   = array(
			Eru_Cah::MODE_FILTER  => array( __( 'Yalnızca başlığı değiştir', 'category-archive-h1-for-wordpress' ), __( 'Temanın arşiv başlığı olarak kullandığı metni değiştirir; sayfaya hiçbir şey eklemez.', 'category-archive-h1-for-wordpress' ) ),
			Eru_Cah::MODE_AUTO    => array( __( 'Otomatik (önerilen)', 'category-archive-h1-for-wordpress' ), __( 'Başlığı değiştirir; sayfada hiç H1 yoksa bir H1 ekler. Temanın H1\'i varsa çoğaltmaz.', 'category-archive-h1-for-wordpress' ) ),
			Eru_Cah::MODE_REPLACE => array( __( 'Tema H1\'ini değiştir', 'category-archive-h1-for-wordpress' ), __( 'Otomatik kipine ek olarak, tema başlığı terim adını sabit yazıyorsa H1 metnini özel başlıkla değiştirir.', 'category-archive-h1-for-wordpress' ) ),
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Arşiv H1', 'category-archive-h1-for-wordpress' ); ?></h1>
			<p><?php esc_html_e( 'Etkin sınıflandırmaların terim düzenleme ekranına "Arşiv H1 başlığı" alanı eklenir. Doluysa arşiv sayfasının H1\'inde kullanılır; terim adı menülerde ve yazılarda olduğu gibi kalır.', 'category-archive-h1-for-wordpress' ); ?></p>
			<form action="options.php" method="post">
				<?php settings_fields( 'eru_cah' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Sınıflandırmalar', 'category-archive-h1-for-wordpress' ); ?></th>
						<td>
							<input type="hidden" name="<?php echo esc_attr( Eru_Cah::OPTION_TAXES ); ?>[]" value="">
							<?php foreach ( self::available_taxonomies() as $slug => $tax ) : ?>
								<label style="display:block;margin-bottom:4px">
									<input type="checkbox" name="<?php echo esc_attr( Eru_Cah::OPTION_TAXES ); ?>[]" value="<?php echo esc_attr( $slug ); ?>" <?php checked( in_array( $slug, $enabled, true ) ); ?>>
									<?php echo esc_html( $tax->labels->name ); ?> <code><?php echo esc_html( $slug ); ?></code>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Tema zaten H1 yazdırıyorsa', 'category-archive-h1-for-wordpress' ); ?></th>
						<td>
							<?php foreach ( $modes as $key => $info ) : ?>
								<label style="display:block;margin-bottom:6px">
									<input type="radio" name="<?php echo esc_attr( Eru_Cah::OPTION_MODE ); ?>" value="<?php echo esc_attr( $key ); ?>" <?php checked( $mode, $key ); ?>>
									<strong><?php echo esc_html( $info[0] ); ?></strong><br>
									<span class="description" style="margin-left:24px"><?php echo esc_html( $info[1] ); ?></span>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'SEO başlığı', 'category-archive-h1-for-wordpress' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( Eru_Cah::OPTION_SEO ); ?>" value="1" <?php checked( 'yes', get_option( Eru_Cah::OPTION_SEO, 'yes' ) ); ?>>
								<?php esc_html_e( 'Rank Math ve Yoast SEO başlığında terim adı yerine özel başlığı kullan', 'category-archive-h1-for-wordpress' ); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/** Etkin sınıflandırmaların ekle/düzenle formlarına alan ekler. */
	public static function term_fields(): void {
		foreach ( Eru_Cah::taxonomies() as $tax ) {
			if ( ! taxonomy_exists( $tax ) ) {
				continue;
			}
			add_action( "{$tax}_add_form_fields", array( __CLASS__, 'render_add_field' ) );
			add_action( "{$tax}_edit_form_fields", array( __CLASS__, 'render_edit_field' ) );
			add_action( "created_{$tax}", array( __CLASS__, 'save_field' ) );
			add_action( "edited_{$tax}", array( __CLASS__, 'save_field' ) );
		}
	}

	private static function description(): string {
		return __( 'Doluysa yalnızca arşiv sayfasının H1 başlığında (ve SEO başlığında) kullanılır; terim adı başka yerlerde değişmez.', 'category-archive-h1-for-wordpress' );
	}

	public static function render_add_field(): void {
		wp_nonce_field( 'eru_cah_save', 'eru_cah_nonce' );
		?>
		<div class="form-field">
			<label for="eru_cah_heading"><?php esc_html_e( 'Arşiv H1 başlığı', 'category-archive-h1-for-wordpress' ); ?></label>
			<input type="text" name="eru_cah_heading" id="eru_cah_heading" value="">
			<p class="description"><?php echo esc_html( self::description() ); ?></p>
		</div>
		<?php
	}

	public static function render_edit_field( $term ): void {
		wp_nonce_field( 'eru_cah_save', 'eru_cah_nonce' );
		$value = get_term_meta( $term->term_id, Eru_Cah::META_KEY, true );
		?>
		<tr class="form-field">
			<th scope="row"><label for="eru_cah_heading"><?php esc_html_e( 'Arşiv H1 başlığı', 'category-archive-h1-for-wordpress' ); ?></label></th>
			<td>
				<input type="text" name="eru_cah_heading" id="eru_cah_heading" value="<?php echo esc_attr( (string) $value ); ?>">
				<p class="description"><?php echo esc_html( self::description() ); ?></p>
			</td>
		</tr>
		<?php
	}

	public static function save_field( $term_id ): void {
		if (
			! isset( $_POST['eru_cah_nonce'], $_POST['eru_cah_heading'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eru_cah_nonce'] ) ), 'eru_cah_save' )
			|| ! current_user_can( 'edit_term', $term_id )
		) {
			return;
		}
		$value = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( wp_unslash( $_POST['eru_cah_heading'] ) ) ) ); // sanitize_text_field "%15" gibi ifadeleri siler.
		if ( '' === $value ) {
			delete_term_meta( $term_id, Eru_Cah::META_KEY );
		} else {
			update_term_meta( $term_id, Eru_Cah::META_KEY, $value );
		}
	}
}
