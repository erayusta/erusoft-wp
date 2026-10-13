<?php
/** One settings screen: pipeline settings, topic queue and manual run (manage_options). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CA_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_eru_ca_add', array( __CLASS__, 'handle_add' ) );
		add_action( 'admin_post_eru_ca_run', array( __CLASS__, 'handle_run' ) );
		add_action( 'admin_post_eru_ca_clear', array( __CLASS__, 'handle_clear' ) );
	}

	public static function menu(): void {
		add_options_page( __( 'İçerik Otomasyonu', 'content-automation-for-wordpress' ), __( 'İçerik Otomasyonu', 'content-automation-for-wordpress' ), 'manage_options', 'eru-ca', array( __CLASS__, 'render' ) );
	}

	public static function register_settings(): void {
		register_setting(
			'eru_ca',
			Eru_CA_Settings::OPTION,
			array(
				'sanitize_callback' => array( 'Eru_CA_Settings', 'sanitize' ),
				'autoload'          => false,
			)
		);
	}

	private static function guard(): void {
		check_admin_referer( 'eru_ca_action' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'content-automation-for-wordpress' ), 403 );
		}
	}

	private static function back( string $msg ): void {
		wp_safe_redirect( add_query_arg( array( 'page' => 'eru-ca', 'eru_ca_msg' => rawurlencode( $msg ) ), admin_url( 'options-general.php' ) ) );
		exit;
	}

	public static function handle_add(): void {
		self::guard();
		$n = Eru_CA_Queue::add( isset( $_POST['topics'] ) ? sanitize_textarea_field( wp_unslash( $_POST['topics'] ) ) : '' );
		/* translators: %d: number of topics */
		self::back( sprintf( __( '%d konu kuyruğa eklendi.', 'content-automation-for-wordpress' ), $n ) );
	}

	public static function handle_run(): void {
		self::guard();
		$made = Eru_CA_Runner::tick();
		/* translators: %d: number of posts */
		self::back( sprintf( __( 'Çalıştırıldı: %d yazı oluşturuldu.', 'content-automation-for-wordpress' ), $made ) );
	}

	public static function handle_clear(): void {
		self::guard();
		Eru_CA_Queue::clear_finished( ! empty( $_POST['failed_too'] ) );
		self::back( __( 'Tamamlananlar temizlendi.', 'content-automation-for-wordpress' ) );
	}

	private static function action_form( string $action, string $label, string $extra = '' ): void {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( 'eru_ca_action' );
		echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup from this class.
		submit_button( $label, 'secondary', 'submit', false );
		echo '</form> ';
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Eru_CA_Settings::get();
		$o = Eru_CA_Settings::OPTION;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$msg = isset( $_GET['eru_ca_msg'] ) ? sanitize_text_field( wp_unslash( $_GET['eru_ca_msg'] ) ) : '';
		$next = wp_next_scheduled( Eru_CA_Runner::HOOK );
		$statuses = array(
			'pending' => __( 'Bekliyor', 'content-automation-for-wordpress' ),
			'done'    => __( 'Tamam', 'content-automation-for-wordpress' ),
			'failed'  => __( 'Başarısız', 'content-automation-for-wordpress' ),
		);
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'İçerik Otomasyonu', 'content-automation-for-wordpress' ); ?></h1>
			<?php if ( '' !== $msg ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $msg ); ?></p></div>
			<?php endif; ?>
			<p><?php echo esc_html__( 'Yapay zekâ çıktısından ve sağlayıcının kullanım koşullarından site sahibi sorumludur. Varsayılan kural taslak oluşturmaktır; yayımlamadan önce metinleri okuyun.', 'content-automation-for-wordpress' ); ?></p>

			<form method="post" action="options.php" autocomplete="off">
				<?php settings_fields( 'eru_ca' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php echo esc_html__( 'Otomasyon', 'content-automation-for-wordpress' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[enabled]" value="1" <?php checked( $s['enabled'] ); ?>> <?php echo esc_html__( 'Zamanlanmış çalışmayı etkinleştir', 'content-automation-for-wordpress' ); ?></label>
							<p class="description">
								<?php
								echo esc_html(
									$next
									/* translators: %s: date and time */
									? sprintf( __( 'Sonraki çalışma: %s', 'content-automation-for-wordpress' ), wp_date( 'Y-m-d H:i', $next ) )
									: __( 'Zamanlanmış çalışma yok.', 'content-automation-for-wordpress' )
								);
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-ca-int"><?php echo esc_html__( 'Sıklık', 'content-automation-for-wordpress' ); ?></label></th>
						<td>
							<select id="eru-ca-int" name="<?php echo esc_attr( $o ); ?>[interval]">
								<option value="hourly" <?php selected( $s['interval'], 'hourly' ); ?>><?php echo esc_html__( 'Saatlik', 'content-automation-for-wordpress' ); ?></option>
								<option value="twicedaily" <?php selected( $s['interval'], 'twicedaily' ); ?>><?php echo esc_html__( 'Günde iki kez', 'content-automation-for-wordpress' ); ?></option>
								<option value="daily" <?php selected( $s['interval'], 'daily' ); ?>><?php echo esc_html__( 'Günlük', 'content-automation-for-wordpress' ); ?></option>
							</select>
							<label style="margin-left:1em"><?php echo esc_html__( 'Çalışma başına konu:', 'content-automation-for-wordpress' ); ?> <input type="number" min="1" max="3" name="<?php echo esc_attr( $o ); ?>[per_tick]" value="<?php echo esc_attr( (string) $s['per_tick'] ); ?>" class="small-text"></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-ca-provider"><?php echo esc_html__( 'Sağlayıcı', 'content-automation-for-wordpress' ); ?></label></th>
						<td>
							<select id="eru-ca-provider" name="<?php echo esc_attr( $o ); ?>[provider]">
								<?php foreach ( Eru_CA_Client::providers() as $slug => $p ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['provider'], $slug ); ?>><?php echo esc_html( $p['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<?php foreach ( Eru_CA_Client::providers() as $slug => $p ) : $mask = Eru_CA_Settings::masked( $slug ); ?>
						<tr>
							<th scope="row"><?php echo esc_html( $p['label'] ); ?></th>
							<td>
								<p><input type="password" name="<?php echo esc_attr( $o ); ?>[new_key][<?php echo esc_attr( $slug ); ?>]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== $mask ? $mask . ' ' . __( '(kayıtlı; değiştirmek için yeni anahtar yazın)', 'content-automation-for-wordpress' ) : __( 'API anahtarı', 'content-automation-for-wordpress' ) ); ?>" aria-label="<?php echo esc_attr( $p['label'] . ' API' ); ?>">
								<?php if ( '' !== $mask ) : ?>
									<label style="margin-left:.6em"><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[clear_key][<?php echo esc_attr( $slug ); ?>]" value="1"> <?php echo esc_html__( 'Anahtarı sil', 'content-automation-for-wordpress' ); ?></label>
								<?php endif; ?></p>
								<p><label><?php echo esc_html__( 'Model:', 'content-automation-for-wordpress' ); ?> <input type="text" name="<?php echo esc_attr( $o ); ?>[models][<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( $s['models'][ $slug ] ); ?>" class="regular-text code"></label></p>
								<p class="description"><?php echo esc_html( sprintf( /* translators: %s: host name */ __( 'Bu anahtar yalnızca %s adresine gönderilir.', 'content-automation-for-wordpress' ), $p['host'] ) ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="eru-ca-lang"><?php echo esc_html__( 'Yazı dili', 'content-automation-for-wordpress' ); ?></label></th>
						<td><input type="text" id="eru-ca-lang" name="<?php echo esc_attr( $o ); ?>[language]" value="<?php echo esc_attr( $s['language'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-ca-rule"><?php echo esc_html__( 'Yayın kuralı', 'content-automation-for-wordpress' ); ?></label></th>
						<td>
							<select id="eru-ca-rule" name="<?php echo esc_attr( $o ); ?>[publish_rule]">
								<option value="draft" <?php selected( $s['publish_rule'], 'draft' ); ?>><?php echo esc_html__( 'Taslak (önerilen)', 'content-automation-for-wordpress' ); ?></option>
								<option value="pending" <?php selected( $s['publish_rule'], 'pending' ); ?>><?php echo esc_html__( 'İncelemede', 'content-automation-for-wordpress' ); ?></option>
								<option value="schedule" <?php selected( $s['publish_rule'], 'schedule' ); ?>><?php echo esc_html__( 'Zamanla (gecikmeli yayın)', 'content-automation-for-wordpress' ); ?></option>
								<option value="publish" <?php selected( $s['publish_rule'], 'publish' ); ?>><?php echo esc_html__( 'Hemen yayımla (dikkat)', 'content-automation-for-wordpress' ); ?></option>
							</select>
							<label style="margin-left:1em"><?php echo esc_html__( 'Zamanlama gecikmesi (saat):', 'content-automation-for-wordpress' ); ?> <input type="number" min="1" max="720" name="<?php echo esc_attr( $o ); ?>[schedule_delay_hours]" value="<?php echo esc_attr( (string) $s['schedule_delay_hours'] ); ?>" class="small-text"></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-ca-cat"><?php echo esc_html__( 'Kategori', 'content-automation-for-wordpress' ); ?></label></th>
						<td><?php wp_dropdown_categories( array( 'name' => $o . '[category]', 'id' => 'eru-ca-cat', 'selected' => (int) $s['category'], 'show_option_none' => esc_html__( '— Yok —', 'content-automation-for-wordpress' ), 'option_none_value' => 0, 'hide_empty' => 0 ) ); ?></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-ca-max"><?php echo esc_html__( 'Sınırlar', 'content-automation-for-wordpress' ); ?></label></th>
						<td>
							<label><?php echo esc_html__( 'İstek başına token:', 'content-automation-for-wordpress' ); ?> <input type="number" id="eru-ca-max" min="200" max="8000" name="<?php echo esc_attr( $o ); ?>[max_tokens]" value="<?php echo esc_attr( (string) $s['max_tokens'] ); ?>" class="small-text"></label>
							<label style="margin-left:1em"><?php echo esc_html__( 'Günlük istek:', 'content-automation-for-wordpress' ); ?> <input type="number" min="1" max="200" name="<?php echo esc_attr( $o ); ?>[daily_cap]" value="<?php echo esc_attr( (string) $s['daily_cap'] ); ?>" class="small-text"></label>
							<span class="description"><?php echo esc_html( sprintf( /* translators: %d: number of requests */ __( 'Bugün: %d', 'content-automation-for-wordpress' ), Eru_CA_Settings::usage_today() ) ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Öne çıkan görsel', 'content-automation-for-wordpress' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[image]" value="1" <?php checked( $s['image'] ); ?>> <?php echo esc_html__( 'OpenAI seçiliyse görsel de üret (ek istek sayılır)', 'content-automation-for-wordpress' ); ?></label>
							<p><label><?php echo esc_html__( 'Görsel modeli:', 'content-automation-for-wordpress' ); ?> <input type="text" name="<?php echo esc_attr( $o ); ?>[image_model]" value="<?php echo esc_attr( $s['image_model'] ); ?>" class="regular-text code"></label></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php echo esc_html__( 'Konu kuyruğu', 'content-automation-for-wordpress' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_ca_add">
				<?php wp_nonce_field( 'eru_ca_action' ); ?>
				<p><label for="eru-ca-topics"><?php echo esc_html__( 'Her satıra bir konu; isteğe bağlı olarak "konu | anahtar kelime, anahtar kelime".', 'content-automation-for-wordpress' ); ?></label></p>
				<textarea id="eru-ca-topics" name="topics" rows="4" class="large-text" placeholder="<?php echo esc_attr__( 'WordPress güvenliği için 5 ipucu | güvenlik, parola', 'content-automation-for-wordpress' ); ?>"></textarea>
				<?php submit_button( __( 'Kuyruğa ekle', 'content-automation-for-wordpress' ), 'secondary' ); ?>
			</form>

			<table class="widefat striped" style="margin-top:1em">
				<thead><tr><th>#</th><th><?php echo esc_html__( 'Konu', 'content-automation-for-wordpress' ); ?></th><th><?php echo esc_html__( 'Durum', 'content-automation-for-wordpress' ); ?></th><th><?php echo esc_html__( 'Yazı', 'content-automation-for-wordpress' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( Eru_CA_Queue::all() as $item ) : ?>
					<tr>
						<td><?php echo esc_html( (string) $item['id'] ); ?></td>
						<td><?php echo esc_html( $item['topic'] ); ?><?php echo '' !== $item['keywords'] ? ' <span class="description">(' . esc_html( $item['keywords'] ) . ')</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo esc_html( $statuses[ $item['status'] ] ?? $item['status'] ); ?><?php echo '' !== $item['error'] ? ' <span class="description">— ' . esc_html( $item['error'] ) . '</span>' : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo $item['post_id'] ? '<a href="' . esc_url( (string) get_edit_post_link( (int) $item['post_id'], 'url' ) ) . '">#' . esc_html( (string) $item['post_id'] ) . '</a>' : '—'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! Eru_CA_Queue::all() ) : ?>
					<tr><td colspan="4"><?php echo esc_html__( 'Kuyruk boş.', 'content-automation-for-wordpress' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
			<p style="margin-top:1em">
				<?php
				self::action_form( 'eru_ca_run', __( 'Şimdi çalıştır', 'content-automation-for-wordpress' ) );
				self::action_form( 'eru_ca_clear', __( 'Tamamlananları temizle', 'content-automation-for-wordpress' ) );
				?>
			</p>
		</div>
		<?php
	}
}
