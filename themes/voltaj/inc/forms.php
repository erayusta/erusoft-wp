<?php
/**
 * Contact and quote-request forms: nonce, honeypot, per-IP rate limit (salted hash, the IP is never stored),
 * wp_mail to the Customizer recipient. Messages are never stored in the database. Works with and without
 * JavaScript (admin-ajax with JS, admin-post + redirect without).
 *
 * @package Voltaj
 */

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Form types and their fields.
 *
 * @return array<string,array>
 */
function voltaj_form_types() {
	return array(
		'contact' => array(
			/* translators: %s: site title */
			'subject' => __('[%s] Yeni iletişim formu mesajı', 'voltaj'),
			'fields'  => array('name', 'email', 'phone', 'message'),
			'require' => array('name', 'email', 'message'),
		),
		'quote'   => array(
			/* translators: %s: site title */
			'subject' => __('[%s] Yeni teklif talebi', 'voltaj'),
			'fields'  => array('name', 'email', 'phone', 'company', 'product', 'message'),
			'require' => array('name', 'email', 'message'),
		),
	);
}

/**
 * Field labels (also used in the e-mail body).
 *
 * @param string $type Form type.
 * @return array<string,string>
 */
function voltaj_form_labels($type) {
	return array(
		'name'    => __('Ad soyad', 'voltaj'),
		'email'   => __('E-posta', 'voltaj'),
		'phone'   => __('Telefon', 'voltaj'),
		'company' => __('Firma adı', 'voltaj'),
		'product' => __('İlgili ürün', 'voltaj'),
		'message' => $type === 'quote' ? __('İhtiyaç listesi', 'voltaj') : __('Mesaj', 'voltaj'),
	);
}

/**
 * Recipient: the Customizer e-mail, else the site admin e-mail.
 *
 * @return string
 */
function voltaj_form_recipient() {
	$to = voltaj_setting('form_recipient');
	return $to && is_email($to) ? $to : (string) get_option('admin_email');
}

/**
 * Status messages, keyed by result code.
 *
 * @param string $type Form type.
 * @return array<string,string>
 */
function voltaj_form_messages($type = 'contact') {
	return array(
		'sent'    => $type === 'quote' ? __('Teklif talebiniz alındı. En kısa sürede e-postayla dönüş yapılacak.', 'voltaj') : __('Mesajınız gönderildi, teşekkürler.', 'voltaj'),
		'invalid' => $type === 'quote' ? __('Lütfen adınızı, geçerli bir e-posta adresini ve ihtiyaç listenizi yazın.', 'voltaj') : __('Lütfen adınızı, geçerli bir e-posta adresini ve mesajınızı yazın.', 'voltaj'),
		'limit'   => __('Kısa sürede çok fazla form gönderildi. Lütfen biraz sonra tekrar deneyin.', 'voltaj'),
		'expired' => __('Oturum süresi doldu. Lütfen sayfayı yenileyip tekrar deneyin.', 'voltaj'),
		'failed'  => __('Form gönderilemedi. Lütfen daha sonra tekrar deneyin.', 'voltaj'),
	);
}

/**
 * Rate-limit key: a salted hash of the client IP.
 *
 * @return string
 */
function voltaj_form_rate_key() {
	$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
	$ip = (string) apply_filters('voltaj_form_client_ip', $ip);
	return 'voltaj_form_' . substr(hash_hmac('sha256', $ip, wp_salt('nonce')), 0, 32);
}

/**
 * Handle a submission (admin-ajax for JS, admin-post without JS).
 */
function voltaj_form_handle() {
	// phpcs:ignore WordPress.Security.NonceVerification.Missing -- the type only selects which nonce is verified.
	$type  = isset($_POST['voltaj_form_type']) ? sanitize_key(wp_unslash($_POST['voltaj_form_type'])) : '';
	$types = voltaj_form_types();
	if (!isset($types[$type])) {
		$type = 'contact';
	}
	$code = voltaj_form_process($type);
	$ok   = $code === 'sent';

	if (wp_doing_ajax()) {
		$messages = voltaj_form_messages($type);
		$payload  = array('message' => $messages[$code], 'code' => $code);
		if ($ok) {
			wp_send_json_success($payload);
		}
		wp_send_json_error($payload, $code === 'limit' ? 429 : 400);
	}

	$back = wp_get_referer();
	$back = $back ? wp_validate_redirect($back, home_url('/')) : home_url('/');
	$back = remove_query_arg('voltaj_form', $back);
	// The result travels as a one-time random token (flash transient), so the banner cannot be spoofed by a URL.
	$token = strtolower(wp_generate_password(20, false, false));
	set_transient('voltaj_form_flash_' . $token, array('code' => $code, 'type' => $type), 10 * MINUTE_IN_SECONDS);
	wp_safe_redirect(add_query_arg('voltaj_form', $token, $back) . '#vj-form');
	exit;
}
add_action('wp_ajax_voltaj_form', 'voltaj_form_handle');
add_action('wp_ajax_nopriv_voltaj_form', 'voltaj_form_handle');
add_action('admin_post_voltaj_form', 'voltaj_form_handle');
add_action('admin_post_nopriv_voltaj_form', 'voltaj_form_handle');

/**
 * Validate and send. Returns a result code from voltaj_form_messages().
 *
 * @param string $type Form type.
 * @return string
 */
function voltaj_form_process($type) {
	$types = voltaj_form_types();
	$def   = $types[$type];

	// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified right below.
	$nonce = isset($_POST['voltaj_form_nonce']) ? sanitize_text_field(wp_unslash($_POST['voltaj_form_nonce'])) : '';
	if (!wp_verify_nonce($nonce, 'voltaj_form_' . $type)) {
		return 'expired';
	}

	// Honeypot: bots fill every field. Pretend success and send nothing.
	if (!empty($_POST['voltaj_website'])) {
		return 'sent';
	}

	$max    = array('name' => 100, 'email' => 190, 'phone' => 40, 'company' => 150, 'product' => 200, 'message' => 5000);
	$values = array();
	foreach ($def['fields'] as $field) {
		$raw = isset($_POST['voltaj_' . $field]) ? wp_unslash($_POST['voltaj_' . $field]) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised per field below.
		if (!is_string($raw)) {
			$raw = '';
		}
		if ($field === 'email') {
			$value = sanitize_email($raw);
		} elseif ($field === 'message') {
			$value = sanitize_textarea_field($raw);
		} else {
			$value = sanitize_text_field($raw);
		}
		$values[$field] = mb_substr($value, 0, $max[$field]);
	}
	// phpcs:enable

	foreach ($def['require'] as $field) {
		if (trim($values[$field]) === '') {
			return 'invalid';
		}
	}
	if (!is_email($values['email'])) {
		return 'invalid';
	}

	$limit  = max(1, (int) apply_filters('voltaj_form_rate_limit', 5));
	$window = max(60, (int) apply_filters('voltaj_form_rate_window', 10 * MINUTE_IN_SECONDS));
	$key    = voltaj_form_rate_key();
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

	$labels = voltaj_form_labels($type);
	$lines  = array();
	foreach ($def['fields'] as $field) {
		if ($field === 'message' || $values[$field] === '') {
			continue;
		}
		$lines[] = $labels[$field] . ': ' . $values[$field];
	}
	$site = wp_specialchars_decode(get_bloginfo('name'), ENT_QUOTES);
	$body = implode("\n", $lines) . "\n\n" . $labels['message'] . ":\n" . $values['message'] . "\n";

	$mail = apply_filters('voltaj_form_mail', array(
		'to'      => voltaj_form_recipient(),
		'subject' => sprintf($def['subject'], $site),
		'body'    => $body,
		'headers' => array('Reply-To: ' . $values['email']),
	), $type, $values);

	return wp_mail($mail['to'], $mail['subject'], $mail['body'], $mail['headers']) ? 'sent' : 'failed';
}

/**
 * Product reference for the quote form when the page is opened from a product (?urun=ID).
 *
 * @return string "Name (SKU)" or ''.
 */
function voltaj_quote_product_label() {
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only prefill.
	$id = isset($_GET['urun']) ? absint($_GET['urun']) : 0;
	if (!$id || !voltaj_wc_active()) {
		return '';
	}
	$product = wc_get_product($id);
	if (!$product || $product->get_status() !== 'publish') {
		return '';
	}
	$sku = $product->get_sku();
	return $sku !== '' ? sprintf('%1$s (%2$s)', $product->get_name(), $sku) : $product->get_name();
}

/**
 * Result of a no-JS submission, read once from the flash transient named by the ?voltaj_form token.
 *
 * @param string $type Form type being rendered.
 * @return string Result code, or '' when there is none for this form.
 */
function voltaj_form_flash($type) {
	static $flash = null;
	if ($flash === null) {
		$flash = array();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the random token is the credential.
		$token = isset($_GET['voltaj_form']) ? preg_replace('/[^a-z0-9]/', '', strtolower(sanitize_text_field(wp_unslash($_GET['voltaj_form'])))) : '';
		if (strlen($token) === 20) {
			$data = get_transient('voltaj_form_flash_' . $token);
			if (is_array($data) && isset($data['code'], $data['type'])) {
				delete_transient('voltaj_form_flash_' . $token);
				$flash = $data;
			}
		}
	}
	return (isset($flash['type']) && $flash['type'] === $type) ? (string) $flash['code'] : '';
}

/**
 * Render a form.
 *
 * @param string $type contact|quote.
 */
function voltaj_form($type = 'contact') {
	$types = voltaj_form_types();
	if (!isset($types[$type])) {
		return;
	}
	if ($type === 'contact') {
		$shortcode = trim((string) voltaj_setting('contact_shortcode'));
		if ($shortcode !== '') {
			echo '<div class="vj-form vj-form--shortcode" id="vj-form">' . do_shortcode($shortcode) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			return;
		}
		if (!voltaj_setting('contact_form')) {
			return;
		}
	}

	$status = voltaj_form_flash($type);
	$messages = voltaj_form_messages($type);
	$labels   = voltaj_form_labels($type);
	$uid      = wp_unique_id('vj-f-');
	$product  = $type === 'quote' ? voltaj_quote_product_label() : '';
	$note     = isset($messages[$status]) ? $messages[$status] : '';
	?>
	<form class="vj-form" id="vj-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" data-vj-form novalidate>
		<input type="hidden" name="action" value="voltaj_form">
		<input type="hidden" name="voltaj_form_type" value="<?php echo esc_attr($type); ?>">
		<?php wp_nonce_field('voltaj_form_' . $type, 'voltaj_form_nonce', false); ?>
		<div class="vj-form__grid">
			<p class="vj-field">
				<label for="<?php echo esc_attr($uid); ?>-name"><?php echo esc_html($labels['name']); ?> <span class="vj-req" aria-hidden="true">*</span></label>
				<input type="text" id="<?php echo esc_attr($uid); ?>-name" name="voltaj_name" required maxlength="100" autocomplete="name">
			</p>
			<p class="vj-field">
				<label for="<?php echo esc_attr($uid); ?>-email"><?php echo esc_html($labels['email']); ?> <span class="vj-req" aria-hidden="true">*</span></label>
				<input type="email" id="<?php echo esc_attr($uid); ?>-email" name="voltaj_email" required maxlength="190" autocomplete="email">
			</p>
			<p class="vj-field">
				<label for="<?php echo esc_attr($uid); ?>-phone"><?php echo esc_html($labels['phone']); ?> <span class="vj-optional"><?php esc_html_e('(isteğe bağlı)', 'voltaj'); ?></span></label>
				<input type="tel" id="<?php echo esc_attr($uid); ?>-phone" name="voltaj_phone" maxlength="40" autocomplete="tel">
			</p>
			<?php if ($type === 'quote') : ?>
				<p class="vj-field">
					<label for="<?php echo esc_attr($uid); ?>-company"><?php echo esc_html($labels['company']); ?> <span class="vj-optional"><?php esc_html_e('(isteğe bağlı)', 'voltaj'); ?></span></label>
					<input type="text" id="<?php echo esc_attr($uid); ?>-company" name="voltaj_company" maxlength="150" autocomplete="organization">
				</p>
				<p class="vj-field vj-field--wide">
					<label for="<?php echo esc_attr($uid); ?>-product"><?php echo esc_html($labels['product']); ?> <span class="vj-optional"><?php esc_html_e('(isteğe bağlı)', 'voltaj'); ?></span></label>
					<input type="text" id="<?php echo esc_attr($uid); ?>-product" name="voltaj_product" maxlength="200" value="<?php echo esc_attr($product); ?>">
				</p>
			<?php endif; ?>
			<p class="vj-field vj-field--wide">
				<label for="<?php echo esc_attr($uid); ?>-message"><?php echo esc_html($labels['message']); ?> <span class="vj-req" aria-hidden="true">*</span></label>
				<?php if ($type === 'quote') : ?>
					<span class="vj-field__hint" id="<?php echo esc_attr($uid); ?>-hint"><?php esc_html_e('Her satıra bir kalem yazın: ürün adı veya kodu ve adet.', 'voltaj'); ?></span>
				<?php endif; ?>
				<textarea id="<?php echo esc_attr($uid); ?>-message" name="voltaj_message" rows="<?php echo $type === 'quote' ? 7 : 5; ?>" required maxlength="5000"<?php echo $type === 'quote' ? ' aria-describedby="' . esc_attr($uid) . '-hint"' : ''; ?>></textarea>
			</p>
		</div>
		<div class="vj-hp" aria-hidden="true">
			<label for="<?php echo esc_attr($uid); ?>-website"><?php esc_html_e('Bu alanı boş bırakın', 'voltaj'); ?></label>
			<input type="text" id="<?php echo esc_attr($uid); ?>-website" name="voltaj_website" tabindex="-1" autocomplete="off">
		</div>
		<div class="vj-form__foot">
			<button type="submit" class="vj-btn vj-btn--primary">
				<?php echo esc_html($type === 'quote' ? __('Teklif iste', 'voltaj') : __('Mesajı gönder', 'voltaj')); ?>
				<?php voltaj_icon('arrow-right'); ?>
			</button>
			<p class="vj-form__privacy"><?php esc_html_e('Bilgileriniz yalnızca size yanıt vermek için e-postayla iletilir, sitede saklanmaz.', 'voltaj'); ?></p>
		</div>
		<p class="vj-form__status<?php echo $status === 'sent' ? ' is-success' : ($note !== '' ? ' is-error' : ''); ?>" role="status" aria-live="polite"><?php echo esc_html($note); ?></p>
	</form>
	<?php
}
