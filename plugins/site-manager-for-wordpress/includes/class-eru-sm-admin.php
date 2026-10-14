<?php
/** Settings → Site Yönetimi, one tab per module. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SM_Admin {

	const SLUG = 'eru-sm';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu(): void {
		add_options_page(
			__( 'Site Yönetimi', 'site-manager-for-wordpress' ),
			__( 'Site Yönetimi', 'site-manager-for-wordpress' ),
			'manage_options',
			self::SLUG,
			array( __CLASS__, 'render' )
		);
	}

	public static function register(): void {
		register_setting(
			'eru_sm',
			Eru_SM_Settings::OPTION,
			array(
				'sanitize_callback' => array( 'Eru_SM_Settings', 'sanitize' ),
				'autoload'          => false,
			)
		);
	}

	private static function tabs(): array {
		return array(
			'llms'   => __( 'llms.txt', 'site-manager-for-wordpress' ),
			'schema' => __( 'Yerel işletme şeması', 'site-manager-for-wordpress' ),
			'robots' => __( 'robots.txt yapay zekâ kuralları', 'site-manager-for-wordpress' ),
		);
	}

	private static function notice( string $type, string $msg ): void {
		echo '<div class="notice notice-' . esc_attr( $type ) . ' eru-keep inline"><p>' . esc_html( $msg ) . '</p></div>';
	}

	private static function tab_notices( string $tab, array $s ): void {
		if ( 'llms' === $tab && file_exists( ABSPATH . 'llms.txt' ) ) {
			self::notice( 'warning', __( 'Sitenin kök dizininde fiziksel bir llms.txt dosyası var; bu dosya sanal olanın önüne geçer. Sanal sürümün görünmesi için dosyayı silin veya yeniden adlandırın.', 'site-manager-for-wordpress' ) );
		}
		if ( 'robots' === $tab ) {
			if ( file_exists( ABSPATH . 'robots.txt' ) ) {
				self::notice( 'warning', __( 'Sitenin kök dizininde fiziksel bir robots.txt dosyası var; bu dosya sanal olanın önüne geçer ve buradaki kurallar yayımlanmaz.', 'site-manager-for-wordpress' ) );
			}
			if ( '0' === (string) get_option( 'blog_public' ) ) {
				self::notice( 'warning', __( '"Arama motorlarının siteyi dizine eklemesini engelle" seçeneği açık. WordPress bu durumda tüm tarayıcıları engeller; buradaki izinler bu ayarı aşamaz.', 'site-manager-for-wordpress' ) );
			}
		}
		if ( 'schema' === $tab && ! empty( $s['schema']['enabled'] ) ) {
			$c = Eru_SM_Schema::conflict();
			if ( '' !== $c ) {
				/* translators: %s: SEO plugin name */
				self::notice( 'warning', sprintf( __( '%s zaten yerel işletme şeması basıyor; çift çıktıyı önlemek için bu modül şema yazmıyor.', 'site-manager-for-wordpress' ), $c ) );
			}
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$tabs = self::tabs();
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'llms'; // phpcs:ignore WordPress.Security.NonceVerification -- read-only tab switch.
		$tab  = isset( $tabs[ $tab ] ) ? $tab : 'llms';
		$s    = Eru_SM_Settings::get();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Site Yönetimi', 'site-manager-for-wordpress' ); ?></h1>
			<?php settings_errors(); ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $slug ), admin_url( 'options-general.php' ) ) ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<?php self::tab_notices( $tab, $s ); ?>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_sm' ); ?>
				<input type="hidden" name="<?php echo esc_attr( Eru_SM_Settings::OPTION ); ?>[_tab]" value="<?php echo esc_attr( $tab ); ?>">
				<table class="form-table" role="presentation">
					<?php
					if ( 'llms' === $tab ) {
						self::tab_llms( $s['llms'] );
					} elseif ( 'schema' === $tab ) {
						self::tab_schema( $s['schema'] );
					} else {
						self::tab_robots( $s['robots'] );
					}
					?>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	private static function name( string $key ): string {
		return Eru_SM_Settings::OPTION . '[' . $key . ']';
	}

	private static function row( string $label, string $id, callable $field, string $help = '' ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td>';
		$field();
		if ( '' !== $help ) {
			echo '<p class="description">' . esc_html( $help ) . '</p>';
		}
		echo '</td></tr>';
	}

	private static function text_row( string $label, string $key, array $s, string $type = 'text', string $help = '', string $ph = '' ): void {
		self::row(
			$label,
			'eru-sm-' . $key,
			function () use ( $key, $s, $type, $ph ) {
				echo '<input type="' . esc_attr( $type ) . '" id="eru-sm-' . esc_attr( $key ) . '" name="' . esc_attr( self::name( $key ) ) . '" value="' . esc_attr( (string) $s[ $key ] ) . '" class="regular-text" placeholder="' . esc_attr( $ph ) . '">';
			},
			$help
		);
	}

	private static function switch_row( string $label, string $key, array $s, string $text ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( self::name( $key ) ) . '" value="1" ' . checked( ! empty( $s[ $key ] ), true, false ) . '> ' . esc_html( $text ) . '</label></td></tr>';
	}

	private static function tab_llms( array $s ): void {
		self::switch_row( __( 'Modül', 'site-manager-for-wordpress' ), 'enabled', $s, __( '/llms.txt dosyasını yayımla', 'site-manager-for-wordpress' ) );
		self::text_row( __( 'Özet cümlesi', 'site-manager-for-wordpress' ), 'summary', $s, 'text', __( 'Boş bırakılırsa sitenin sloganı kullanılır.', 'site-manager-for-wordpress' ) );
		self::row(
			__( 'Serbest metin', 'site-manager-for-wordpress' ),
			'eru-sm-intro',
			function () use ( $s ) {
				echo '<textarea id="eru-sm-intro" name="' . esc_attr( self::name( 'intro' ) ) . '" rows="3" class="large-text">' . esc_textarea( $s['intro'] ) . '</textarea>';
			},
			__( 'İsteğe bağlı; özetin altına olduğu gibi eklenir.', 'site-manager-for-wordpress' )
		);
		self::row(
			__( 'Listelenecek içerik türleri', 'site-manager-for-wordpress' ),
			'eru-sm-post_types',
			function () use ( $s ) {
				foreach ( Eru_SM_Llms::available_post_types() as $slug => $label ) {
					echo '<label style="display:inline-block;margin-right:1em"><input type="checkbox" name="' . esc_attr( self::name( 'post_types' ) ) . '[]" value="' . esc_attr( $slug ) . '" ' . checked( in_array( $slug, (array) $s['post_types'], true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
				}
			}
		);
		self::row(
			__( 'Tür başına en çok', 'site-manager-for-wordpress' ),
			'eru-sm-max',
			function () use ( $s ) {
				echo '<input type="number" min="1" max="500" id="eru-sm-max" name="' . esc_attr( self::name( 'max' ) ) . '" value="' . esc_attr( (string) $s['max'] ) . '" class="small-text">';
			}
		);
		self::text_row( __( 'Hariç tutulacak ID\'ler', 'site-manager-for-wordpress' ), 'exclude', $s, 'text', __( 'Virgülle ayırın. Şifreli, özel ve noindex içerikler zaten listelenmez.', 'site-manager-for-wordpress' ) );
		self::row(
			__( 'Elle eklenen bağlantılar', 'site-manager-for-wordpress' ),
			'eru-sm-links',
			function () use ( $s ) {
				echo '<textarea id="eru-sm-links" name="' . esc_attr( self::name( 'links' ) ) . '" rows="4" class="large-text code">' . esc_textarea( $s['links'] ) . '</textarea>';
			},
			__( 'Her satıra bir bağlantı: Başlık | https://adres | kısa açıklama (açıklama isteğe bağlı). "Bağlantılar" başlığı altında yer alır.', 'site-manager-for-wordpress' )
		);
		self::switch_row( __( 'Tam metin', 'site-manager-for-wordpress' ), 'full', $s, __( '/llms-full.txt dosyasını da yayımla (düz metin, en çok 1 MB)', 'site-manager-for-wordpress' ) );
		if ( ! empty( $s['enabled'] ) ) {
			echo '<tr><th scope="row">' . esc_html__( 'Adres', 'site-manager-for-wordpress' ) . '</th><td><a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank" rel="noopener">' . esc_html( home_url( '/llms.txt' ) ) . '</a></td></tr>';
		}
	}

	private static function tab_schema( array $s ): void {
		self::switch_row( __( 'Modül', 'site-manager-for-wordpress' ), 'enabled', $s, __( 'LocalBusiness JSON-LD bloğunu yaz', 'site-manager-for-wordpress' ) );
		self::row(
			__( 'İşletme türü', 'site-manager-for-wordpress' ),
			'eru-sm-type',
			function () use ( $s ) {
				echo '<select id="eru-sm-type" name="' . esc_attr( self::name( 'type' ) ) . '">';
				foreach ( Eru_SM_Settings::TYPES as $t ) {
					echo '<option value="' . esc_attr( $t ) . '" ' . selected( $s['type'], $t, false ) . '>' . esc_html( $t ) . '</option>';
				}
				echo '</select>';
			}
		);
		self::row(
			__( 'Nerede yazılsın', 'site-manager-for-wordpress' ),
			'eru-sm-where',
			function () use ( $s ) {
				echo '<select id="eru-sm-where" name="' . esc_attr( self::name( 'where' ) ) . '"><option value="front" ' . selected( $s['where'], 'front', false ) . '>' . esc_html__( 'Yalnızca ana sayfa', 'site-manager-for-wordpress' ) . '</option><option value="all" ' . selected( $s['where'], 'all', false ) . '>' . esc_html__( 'Tüm sayfalar', 'site-manager-for-wordpress' ) . '</option></select>';
			}
		);
		self::text_row( __( 'İşletme adı', 'site-manager-for-wordpress' ), 'name', $s, 'text', __( 'Boş bırakılırsa site başlığı kullanılır.', 'site-manager-for-wordpress' ) );
		self::text_row( __( 'Ticari unvan', 'site-manager-for-wordpress' ), 'legal_name', $s );
		self::text_row( __( 'Sokak / cadde', 'site-manager-for-wordpress' ), 'street', $s );
		self::text_row( __( 'İlçe', 'site-manager-for-wordpress' ), 'district', $s );
		self::text_row( __( 'İl', 'site-manager-for-wordpress' ), 'city', $s );
		self::text_row( __( 'Posta kodu', 'site-manager-for-wordpress' ), 'postal', $s );
		self::text_row( __( 'Ülke kodu', 'site-manager-for-wordpress' ), 'country', $s, 'text', __( 'İki harfli, örn. TR.', 'site-manager-for-wordpress' ) );
		self::text_row( __( 'Telefon', 'site-manager-for-wordpress' ), 'phone', $s, 'tel' );
		self::text_row( __( 'E-posta', 'site-manager-for-wordpress' ), 'email', $s, 'email' );
		self::text_row( __( 'Fiyat aralığı', 'site-manager-for-wordpress' ), 'price', $s, 'text', '', '₺₺' );
		self::text_row( __( 'Enlem', 'site-manager-for-wordpress' ), 'lat', $s );
		self::text_row( __( 'Boylam', 'site-manager-for-wordpress' ), 'lng', $s );
		self::text_row( __( 'Logo adresi', 'site-manager-for-wordpress' ), 'logo', $s, 'url', __( 'Boş bırakılırsa tema logosu, o da yoksa site simgesi kullanılır.', 'site-manager-for-wordpress' ) );
		self::row(
			__( 'Sosyal profiller (sameAs)', 'site-manager-for-wordpress' ),
			'eru-sm-same_as',
			function () use ( $s ) {
				echo '<textarea id="eru-sm-same_as" name="' . esc_attr( self::name( 'same_as' ) ) . '" rows="3" class="large-text code">' . esc_textarea( $s['same_as'] ) . '</textarea>';
			},
			__( 'Her satıra bir adres.', 'site-manager-for-wordpress' )
		);
		$days = array(
			'mon' => __( 'Pazartesi', 'site-manager-for-wordpress' ),
			'tue' => __( 'Salı', 'site-manager-for-wordpress' ),
			'wed' => __( 'Çarşamba', 'site-manager-for-wordpress' ),
			'thu' => __( 'Perşembe', 'site-manager-for-wordpress' ),
			'fri' => __( 'Cuma', 'site-manager-for-wordpress' ),
			'sat' => __( 'Cumartesi', 'site-manager-for-wordpress' ),
			'sun' => __( 'Pazar', 'site-manager-for-wordpress' ),
		);
		echo '<tr><th scope="row">' . esc_html__( 'Çalışma saatleri', 'site-manager-for-wordpress' ) . '</th><td>';
		foreach ( $days as $k => $label ) {
			$n = self::name( 'hours' ) . '[' . $k . ']';
			echo '<p style="margin:0 0 4px"><span style="display:inline-block;width:6em">' . esc_html( $label ) . '</span> <input type="time" name="' . esc_attr( $n ) . '[open]" value="' . esc_attr( $s['hours'][ $k ]['open'] ?? '' ) . '"> – <input type="time" name="' . esc_attr( $n ) . '[close]" value="' . esc_attr( $s['hours'][ $k ]['close'] ?? '' ) . '"></p>';
		}
		echo '<p class="description">' . esc_html__( 'Açılış ve kapanış saatinin ikisi de doluysa o gün yazılır; boş günler kapalı sayılır.', 'site-manager-for-wordpress' ) . '</p></td></tr>';
	}

	private static function tab_robots( array $s ): void {
		self::switch_row( __( 'Modül', 'site-manager-for-wordpress' ), 'enabled', $s, __( 'robots.txt çıktısına kuralları ekle', 'site-manager-for-wordpress' ) );
		self::switch_row( __( 'Site haritası', 'site-manager-for-wordpress' ), 'sitemap', $s, __( 'WordPress site haritası açıksa ve başka bir eklenti eklemediyse "Sitemap:" satırı ekle', 'site-manager-for-wordpress' ) );
		echo '<tr><th scope="row">' . esc_html__( 'Tarayıcılar', 'site-manager-for-wordpress' ) . '</th><td><table class="widefat striped" style="max-width:640px"><thead><tr><th>' . esc_html__( 'Tarayıcı', 'site-manager-for-wordpress' ) . '</th><th>' . esc_html__( 'Sahibi', 'site-manager-for-wordpress' ) . '</th><th>' . esc_html__( 'Kural', 'site-manager-for-wordpress' ) . '</th></tr></thead><tbody>';
		$opts = array(
			'default' => __( 'Varsayılan', 'site-manager-for-wordpress' ),
			'allow'   => __( 'İzin ver', 'site-manager-for-wordpress' ),
			'block'   => __( 'Engelle', 'site-manager-for-wordpress' ),
		);
		foreach ( Eru_SM_Settings::BOTS as $bot => $owner ) {
			$cur = $s['bots'][ $bot ] ?? 'default';
			echo '<tr><td><code>' . esc_html( $bot ) . '</code></td><td>' . esc_html( $owner ) . '</td><td><select name="' . esc_attr( self::name( 'bots' ) . '[' . $bot . ']' ) . '" aria-label="' . esc_attr( $bot ) . '">';
			foreach ( $opts as $v => $l ) {
				echo '<option value="' . esc_attr( $v ) . '" ' . selected( $cur, $v, false ) . '>' . esc_html( $l ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table><p class="description">' . esc_html__( 'Varsayılan: bu tarayıcı için hiçbir satır eklenmez. robots.txt kuralları tavsiye niteliğindedir; uyup uymamak tarayıcıya bağlıdır.', 'site-manager-for-wordpress' ) . '</p></td></tr>';
	}
}
