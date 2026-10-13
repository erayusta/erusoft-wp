<?php
/**
 * Contact form: nonce, honeypot, per-IP rate limit, wp_mail to the configured recipient.
 * Messages are never stored in the database. Works with and without JavaScript.
 *
 * @package Ufuk
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Recipient: the Customizer e-mail, else the site admin e-mail.
 *
 * @return string
 */
function ufuk_contact_recipient() {
	$to = ufuk_setting('form_recipient');
	return $to && is_email($to) ? $to : get_option('admin_email');
}

/**
 * Status messages, keyed by result code.
 *
 * @return array<string,string>
 */
function ufuk_contact_messages() {
	return array(
		'sent'    => __('Mesajınız gönderildi, teşekkürler.', 'ufuk'),
		'invalid' => __('Lütfen adınızı, geçerli bir e-posta adresini ve mesajınızı yazın.', 'ufuk'),
		'limit'   => __('Kısa sürede çok fazla mesaj gönderildi. Lütfen biraz sonra tekrar deneyin.', 'ufuk'),
		'expired' => __('Oturum süresi doldu. Lütfen sayfayı yenileyip tekrar deneyin.', 'ufuk'),
		'failed'  => __('Mesaj gönderilemedi. Lütfen daha sonra tekrar deneyin.', 'ufuk'),
	);
}

/**
 * Rate-limit key: a salted hash of the client IP (the IP itself is never stored).
 *
 * @return string
 */
function ufuk_contact_rate_key() {
	$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
	$ip = (string) apply_filters('ufuk_contact_client_ip', $ip);
	return 'ufuk_contact_' . substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 32);
}

/**
 * Handle a submission (admin-ajax for JS, admin-post without JS).
 */
function ufuk_contact_handle() {
	$code = ufuk_contact_process();
	$ok   = $code === 'sent';

	if (wp_doing_ajax()) {
		$messages = ufuk_contact_messages();
		$payload  = array('message' => $messages[$code], 'code' => $code);
		if ($ok) {
			wp_send_json_success($payload);
		}
		wp_send_json_error($payload, $code === 'limit' ? 429 : 400);
	}

	$back = wp_get_referer();
	$back = $back ? wp_validate_redirect($back, home_url('/')) : home_url('/');
	$back = remove_query_arg('ufuk_contact', $back);
	// The result travels as a one-time random token (flash transient), so the message cannot be spoofed by a URL.
	$token = strtolower(wp_generate_password(20, false, false));
	set_transient('ufuk_contact_flash_' . $token, $code, 10 * MINUTE_IN_SECONDS);
	wp_safe_redirect(add_query_arg('ufuk_contact', $token, $back) . '#iletisim');
	exit;
}
add_action('wp_ajax_ufuk_contact', 'ufuk_contact_handle');
add_action('wp_ajax_nopriv_ufuk_contact', 'ufuk_contact_handle');
add_action('admin_post_ufuk_contact', 'ufuk_contact_handle');
add_action('admin_post_nopriv_ufuk_contact', 'ufuk_contact_handle');

/**
 * Validate and send. Returns a result code from ufuk_contact_messages().
 *
 * @return string
 */
function ufuk_contact_process() {
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified right below.
	$nonce = isset($_POST['ufuk_contact_nonce']) ? sanitize_text_field(wp_unslash($_POST['ufuk_contact_nonce'])) : '';
	if (!wp_verify_nonce($nonce, 'ufuk_contact')) {
		return 'expired';
	}

	// Honeypot: bots fill every field. Pretend success and send nothing.
	if (!empty($_POST['ufuk_website'])) {
		return 'sent';
	}

	$name    = isset($_POST['ufuk_name']) ? sanitize_text_field(wp_unslash($_POST['ufuk_name'])) : '';
	$email   = isset($_POST['ufuk_email']) ? sanitize_email(wp_unslash($_POST['ufuk_email'])) : '';
	$phone   = isset($_POST['ufuk_phone']) ? sanitize_text_field(wp_unslash($_POST['ufuk_phone'])) : '';
	$message = isset($_POST['ufuk_message']) ? sanitize_textarea_field(wp_unslash($_POST['ufuk_message'])) : '';
	// phpcs:enable

	$name    = mb_substr($name, 0, 100);
	$phone   = mb_substr($phone, 0, 40);
	$message = mb_substr($message, 0, 5000);

	if ($name === '' || !is_email($email) || trim($message) === '') {
		return 'invalid';
	}

	$limit  = max(1, (int) apply_filters('ufuk_contact_rate_limit', 5));
	$window = max(60, (int) apply_filters('ufuk_contact_rate_window', 10 * MINUTE_IN_SECONDS));
	$key    = ufuk_contact_rate_key();
	$state  = get_transient($key);
	$now    = time();
	if (!is_array($state) || !isset($state['n'], $state['t']) || $now - (int) $state['t'] >= $window) {
		$state = array('n' => 0, 't' => $now);
	}
	if ($state['n'] >= $limit) {
		return 'limit';
	}
	$state['n']++;
	set_transient($key, $state, max(1, $window - ($now - (int) $state['t'])));

	$site    = ufuk_site_name();
	/* translators: %s: site title */
	$subject = sprintf(__('[%s] Yeni iletişim formu mesajı', 'ufuk'), $site);
	$lines   = array(
		/* translators: %s: sender name */
		sprintf(__('Ad soyad: %s', 'ufuk'), $name),
		/* translators: %s: sender e-mail */
		sprintf(__('E-posta: %s', 'ufuk'), $email),
	);
	if ($phone !== '') {
		/* translators: %s: sender phone */
		$lines[] = sprintf(__('Telefon: %s', 'ufuk'), $phone);
	}
	$body = implode("\n", $lines) . "\n\n" . __('Mesaj:', 'ufuk') . "\n" . $message . "\n";

	$mail = apply_filters('ufuk_contact_mail', array(
		'to'      => ufuk_contact_recipient(),
		'subject' => $subject,
		'body'    => $body,
		'headers' => array('Reply-To: ' . $email),
	), compact('name', 'email', 'phone', 'message'));

	return wp_mail($mail['to'], $mail['subject'], $mail['body'], $mail['headers']) ? 'sent' : 'failed';
}

/**
 * Result of a no-JS submission, read once from the flash transient named by the ?ufuk_contact token.
 *
 * @return string Result code, or '' when there is none.
 */
function ufuk_contact_flash() {
	static $code = null;
	if ($code === null) {
		$code = '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the random token is the credential.
		$token = isset($_GET['ufuk_contact']) ? preg_replace('/[^a-z0-9]/', '', strtolower(sanitize_text_field(wp_unslash($_GET['ufuk_contact'])))) : '';
		if (strlen($token) === 20) {
			$data = get_transient('ufuk_contact_flash_' . $token);
			if (is_string($data) && isset(ufuk_contact_messages()[$data])) {
				delete_transient('ufuk_contact_flash_' . $token);
				$code = $data;
			}
		}
	}
	return $code;
}

/**
 * Render the form (or the configured shortcode). Nothing when the form is disabled and no shortcode is set.
 */
function ufuk_contact_form() {
	$shortcode = trim((string) ufuk_setting('contact_shortcode'));
	if ($shortcode !== '') {
		echo '<div class="contact-form contact-form--shortcode">' . do_shortcode($shortcode) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return;
	}
	if (!ufuk_setting('contact_form')) {
		return;
	}

	$status   = ufuk_contact_flash();
	$messages = ufuk_contact_messages();
	$uid      = wp_unique_id('ufuk-cf-');
	?>
	<form class="contact-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-ufuk-contact novalidate>
		<input type="hidden" name="action" value="ufuk_contact">
		<?php wp_nonce_field('ufuk_contact', 'ufuk_contact_nonce', false); ?>
		<div class="form-row cols-2">
			<div class="form-field">
				<label for="<?php echo esc_attr($uid); ?>-name"><?php esc_html_e('Ad soyad', 'ufuk'); ?> <span aria-hidden="true">*</span></label>
				<input type="text" id="<?php echo esc_attr($uid); ?>-name" name="ufuk_name" required maxlength="100" autocomplete="name">
			</div>
			<div class="form-field">
				<label for="<?php echo esc_attr($uid); ?>-email"><?php esc_html_e('E-posta', 'ufuk'); ?> <span aria-hidden="true">*</span></label>
				<input type="email" id="<?php echo esc_attr($uid); ?>-email" name="ufuk_email" required maxlength="190" autocomplete="email">
			</div>
		</div>
		<div class="form-row">
			<div class="form-field">
				<label for="<?php echo esc_attr($uid); ?>-phone"><?php esc_html_e('Telefon (isteğe bağlı)', 'ufuk'); ?></label>
				<input type="tel" id="<?php echo esc_attr($uid); ?>-phone" name="ufuk_phone" maxlength="40" autocomplete="tel">
			</div>
		</div>
		<div class="form-row">
			<div class="form-field">
				<label for="<?php echo esc_attr($uid); ?>-message"><?php esc_html_e('Mesajınız', 'ufuk'); ?> <span aria-hidden="true">*</span></label>
				<textarea id="<?php echo esc_attr($uid); ?>-message" name="ufuk_message" rows="5" required maxlength="5000"></textarea>
			</div>
		</div>
		<div class="ufuk-hp" aria-hidden="true">
			<label for="<?php echo esc_attr($uid); ?>-website"><?php esc_html_e('Bu alanı boş bırakın', 'ufuk'); ?></label>
			<input type="text" id="<?php echo esc_attr($uid); ?>-website" name="ufuk_website" tabindex="-1" autocomplete="off">
		</div>
		<button type="submit" class="btn btn-primary btn-block">
			<?php esc_html_e('Mesajı gönder', 'ufuk'); ?>
			<?php ufuk_icon('arrow-right'); ?>
		</button>
		<p class="form-note <?php echo $status === 'sent' ? 'is-success' : ($status ? 'is-error' : ''); ?>" role="status" aria-live="polite"><?php echo isset($messages[$status]) ? esc_html($messages[$status]) : ''; ?></p>
		<p class="form-privacy"><?php esc_html_e('Gönderdiğiniz bilgiler yalnızca size yanıt vermek için e-postayla iletilir, sitede saklanmaz.', 'ufuk'); ?></p>
	</form>
	<?php
}
