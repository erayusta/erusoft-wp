<?php
/** Generator screen (edit_posts) and settings screen (manage_options). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_APG_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_action( 'admin_post_eru_apg_generate', array( __CLASS__, 'handle_generate' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'edit.php', __( 'Yapay zekâ ile yazı', 'ai-post-generator-for-wordpress' ), __( 'Yapay zekâ ile yazı', 'ai-post-generator-for-wordpress' ), Eru_APG_Generator::capability(), 'eru-apg-generate', array( __CLASS__, 'render_generate' ) );
		add_options_page( __( 'Yapay Zekâ Yazı Üretici', 'ai-post-generator-for-wordpress' ), __( 'Yapay Zekâ Yazı', 'ai-post-generator-for-wordpress' ), 'manage_options', 'eru-apg', array( __CLASS__, 'render_settings' ) );
	}

	public static function register_settings(): void {
		register_setting(
			'eru_apg',
			Eru_APG_Settings::OPTION,
			array(
				'sanitize_callback' => array( 'Eru_APG_Settings', 'sanitize' ),
				'autoload'          => false,
			)
		);
	}

	public static function handle_generate(): void {
		check_admin_referer( 'eru_apg_generate' );
		if ( ! current_user_can( Eru_APG_Generator::capability() ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'ai-post-generator-for-wordpress' ), 403 );
		}
		$res = Eru_APG_Generator::generate(
			array(
				'topic'    => isset( $_POST['topic'] ) ? sanitize_text_field( wp_unslash( $_POST['topic'] ) ) : '',
				'keywords' => isset( $_POST['keywords'] ) ? sanitize_text_field( wp_unslash( $_POST['keywords'] ) ) : '',
				'length'   => isset( $_POST['length'] ) ? sanitize_key( wp_unslash( $_POST['length'] ) ) : 'medium',
				'category' => isset( $_POST['category'] ) ? absint( $_POST['category'] ) : 0,
			)
		);
		if ( is_wp_error( $res ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => 'eru-apg-generate', 'eru_apg_err' => rawurlencode( $res->get_error_message() ) ), admin_url( 'edit.php' ) ) );
			exit;
		}
		wp_safe_redirect( get_edit_post_link( $res, 'url' ) );
		exit;
	}

	private static function responsibility_note(): string {
		return esc_html__( 'Yapay zekâ çıktısından ve sağlayıcının kullanım koşullarından site sahibi sorumludur. Yayımlamadan önce metni mutlaka okuyup doğrulayın.', 'ai-post-generator-for-wordpress' );
	}

	public static function render_generate(): void {
		if ( ! current_user_can( Eru_APG_Generator::capability() ) ) {
			return;
		}
		$s    = Eru_APG_Settings::get();
		$prov = Eru_APG_Client::providers()[ $s['provider'] ];
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$err = isset( $_GET['eru_apg_err'] ) ? sanitize_text_field( wp_unslash( $_GET['eru_apg_err'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Yapay zekâ ile yazı', 'ai-post-generator-for-wordpress' ); ?></h1>
			<?php if ( '' !== $err ) : ?>
				<div class="notice notice-error"><p><?php echo esc_html( $err ); ?></p></div>
			<?php endif; ?>
			<?php if ( '' === Eru_APG_Settings::key( $s['provider'] ) ) : ?>
				<div class="notice notice-warning inline"><p>
					<?php
					/* translators: %s: provider name */
					echo esc_html( sprintf( __( '%s için API anahtarı girilmemiş.', 'ai-post-generator-for-wordpress' ), $prov['label'] ) );
					if ( current_user_can( 'manage_options' ) ) {
						echo ' <a href="' . esc_url( admin_url( 'options-general.php?page=eru-apg' ) ) . '">' . esc_html__( 'Ayarlar', 'ai-post-generator-for-wordpress' ) . '</a>';
					}
					?>
				</p></div>
			<?php endif; ?>
			<p>
				<?php
				/* translators: 1: provider, 2: model, 3: used today, 4: daily cap */
				echo esc_html( sprintf( __( 'Sağlayıcı: %1$s · Model: %2$s · Bugünkü istek: %3$d / %4$d', 'ai-post-generator-for-wordpress' ), $prov['label'], Eru_APG_Settings::model( $s['provider'] ), Eru_APG_Settings::usage_today(), (int) $s['daily_cap'] ) );
				?>
			</p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="eru_apg_generate">
				<?php wp_nonce_field( 'eru_apg_generate' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="eru-apg-topic"><?php echo esc_html__( 'Konu', 'ai-post-generator-for-wordpress' ); ?></label></th>
						<td><input type="text" id="eru-apg-topic" name="topic" class="large-text" required maxlength="300" placeholder="<?php echo esc_attr__( 'Örn. WordPress güvenliği için 5 ipucu', 'ai-post-generator-for-wordpress' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-apg-kw"><?php echo esc_html__( 'Anahtar kelimeler', 'ai-post-generator-for-wordpress' ); ?></label></th>
						<td><input type="text" id="eru-apg-kw" name="keywords" class="large-text" maxlength="300"></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-apg-len"><?php echo esc_html__( 'Uzunluk', 'ai-post-generator-for-wordpress' ); ?></label></th>
						<td>
							<select id="eru-apg-len" name="length">
								<option value="short"><?php echo esc_html__( 'Kısa (~400 kelime)', 'ai-post-generator-for-wordpress' ); ?></option>
								<option value="medium" selected><?php echo esc_html__( 'Orta (~800 kelime)', 'ai-post-generator-for-wordpress' ); ?></option>
								<option value="long"><?php echo esc_html__( 'Uzun (~1500 kelime)', 'ai-post-generator-for-wordpress' ); ?></option>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cat"><?php echo esc_html__( 'Kategori', 'ai-post-generator-for-wordpress' ); ?></label></th>
						<td><?php wp_dropdown_categories( array( 'name' => 'category', 'id' => 'cat', 'show_option_none' => esc_html__( '— Yok —', 'ai-post-generator-for-wordpress' ), 'option_none_value' => 0, 'hide_empty' => 0 ) ); ?></td>
					</tr>
				</table>
				<?php submit_button( __( 'Taslak oluştur', 'ai-post-generator-for-wordpress' ) ); ?>
			</form>
			<p class="description"><?php echo self::responsibility_note(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></p>
		</div>
		<?php
	}

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s = Eru_APG_Settings::get();
		$o = Eru_APG_Settings::OPTION;
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Yapay Zekâ Yazı Üretici', 'ai-post-generator-for-wordpress' ); ?></h1>
			<p><?php echo self::responsibility_note(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></p>
			<form method="post" action="options.php" autocomplete="off">
				<?php settings_fields( 'eru_apg' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="eru-apg-provider"><?php echo esc_html__( 'Sağlayıcı', 'ai-post-generator-for-wordpress' ); ?></label></th>
						<td>
							<select id="eru-apg-provider" name="<?php echo esc_attr( $o ); ?>[provider]">
								<?php foreach ( Eru_APG_Client::providers() as $slug => $p ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $s['provider'], $slug ); ?>><?php echo esc_html( $p['label'] ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<?php foreach ( Eru_APG_Client::providers() as $slug => $p ) : $mask = Eru_APG_Settings::masked( $slug ); ?>
						<tr>
							<th scope="row"><?php echo esc_html( $p['label'] ); ?></th>
							<td>
								<p><input type="password" name="<?php echo esc_attr( $o ); ?>[new_key][<?php echo esc_attr( $slug ); ?>]" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr( '' !== $mask ? $mask . ' ' . __( '(kayıtlı; değiştirmek için yeni anahtar yazın)', 'ai-post-generator-for-wordpress' ) : __( 'API anahtarı', 'ai-post-generator-for-wordpress' ) ); ?>" aria-label="<?php echo esc_attr( $p['label'] . ' API' ); ?>">
								<?php if ( '' !== $mask ) : ?>
									<label style="margin-left:.6em"><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[clear_key][<?php echo esc_attr( $slug ); ?>]" value="1"> <?php echo esc_html__( 'Anahtarı sil', 'ai-post-generator-for-wordpress' ); ?></label>
								<?php endif; ?></p>
								<p><label><?php echo esc_html__( 'Model:', 'ai-post-generator-for-wordpress' ); ?> <input type="text" name="<?php echo esc_attr( $o ); ?>[models][<?php echo esc_attr( $slug ); ?>]" value="<?php echo esc_attr( $s['models'][ $slug ] ); ?>" class="regular-text code"></label></p>
								<p class="description"><?php echo esc_html( sprintf( /* translators: %s: host name */ __( 'Bu anahtar yalnızca %s adresine gönderilir.', 'ai-post-generator-for-wordpress' ), $p['host'] ) ); ?></p>
							</td>
						</tr>
					<?php endforeach; ?>
					<tr>
						<th scope="row"><label for="eru-apg-lang"><?php echo esc_html__( 'Yazı dili', 'ai-post-generator-for-wordpress' ); ?></label></th>
						<td><input type="text" id="eru-apg-lang" name="<?php echo esc_attr( $o ); ?>[language]" value="<?php echo esc_attr( $s['language'] ); ?>" class="regular-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-apg-max"><?php echo esc_html__( 'İstek başına en fazla token', 'ai-post-generator-for-wordpress' ); ?></label></th>
						<td><input type="number" id="eru-apg-max" min="200" max="8000" name="<?php echo esc_attr( $o ); ?>[max_tokens]" value="<?php echo esc_attr( (string) $s['max_tokens'] ); ?>" class="small-text"></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-apg-cap"><?php echo esc_html__( 'Günlük istek sınırı', 'ai-post-generator-for-wordpress' ); ?></label></th>
						<td><input type="number" id="eru-apg-cap" min="1" max="500" name="<?php echo esc_attr( $o ); ?>[daily_cap]" value="<?php echo esc_attr( (string) $s['daily_cap'] ); ?>" class="small-text"> <span class="description"><?php echo esc_html( sprintf( /* translators: %d: number of requests */ __( 'Bugün: %d', 'ai-post-generator-for-wordpress' ), Eru_APG_Settings::usage_today() ) ); ?></span></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-apg-status"><?php echo esc_html__( 'Kaydedilecek durum', 'ai-post-generator-for-wordpress' ); ?></label></th>
						<td>
							<select id="eru-apg-status" name="<?php echo esc_attr( $o ); ?>[status]">
								<option value="draft" <?php selected( $s['status'], 'draft' ); ?>><?php echo esc_html__( 'Taslak', 'ai-post-generator-for-wordpress' ); ?></option>
								<option value="pending" <?php selected( $s['status'], 'pending' ); ?>><?php echo esc_html__( 'İncelemede', 'ai-post-generator-for-wordpress' ); ?></option>
							</select>
							<p class="description"><?php echo esc_html__( 'Eklenti yazıları hiçbir zaman kendiliğinden yayımlamaz.', 'ai-post-generator-for-wordpress' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Öne çıkan görsel', 'ai-post-generator-for-wordpress' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $o ); ?>[image]" value="1" <?php checked( $s['image'] ); ?>> <?php echo esc_html__( 'OpenAI seçiliyse görsel de üret (ek istek sayılır)', 'ai-post-generator-for-wordpress' ); ?></label>
							<p><label><?php echo esc_html__( 'Görsel modeli:', 'ai-post-generator-for-wordpress' ); ?> <input type="text" name="<?php echo esc_attr( $o ); ?>[image_model]" value="<?php echo esc_attr( $s['image_model'] ); ?>" class="regular-text code"></label></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
