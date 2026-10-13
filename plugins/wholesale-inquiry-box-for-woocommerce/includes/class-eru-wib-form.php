<?php
/** Product page box, form handler and e-mail. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_WIB_Form {

	const ACTION = 'eru_wib_submit';
	const NONCE  = 'eru_wib_submit';

	public static function init(): void {
		add_action( 'woocommerce_single_product_summary', array( __CLASS__, 'render' ), 35 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/** Whether the box shows on this product: enabled, and (no categories chosen = all) the category matches. */
	public static function applies_to( int $product_id ): bool {
		$o = Eru_WIB_Settings::get();
		if ( empty( $o['enabled'] ) ) {
			return false;
		}
		if ( empty( $o['category_ids'] ) ) {
			return true;
		}
		$targets = array_map( 'intval', $o['category_ids'] );
		foreach ( $targets as $cat ) {
			$children = get_term_children( $cat, 'product_cat' );
			if ( ! is_wp_error( $children ) ) {
				$targets = array_merge( $targets, array_map( 'intval', $children ) );
			}
		}
		return has_term( array_unique( $targets ), 'product_cat', $product_id );
	}

	public static function assets(): void {
		if ( function_exists( 'is_product' ) && is_product() && self::applies_to( get_queried_object_id() ) ) {
			wp_enqueue_style( 'eru-wib', plugins_url( 'assets/css/frontend.css', ERU_WIB_FILE ), array(), ERU_WIB_VERSION );
		}
	}

	private static function fill( string $tpl, WC_Product $product ): string {
		$sku = (string) $product->get_sku();
		$out = strtr( $tpl, array( '{urun}' => $product->get_name(), '{sku}' => $sku, '{link}' => get_permalink( $product->get_id() ) ) );
		if ( '' === $sku ) {
			$out = preg_replace( '/\s*\(\s*\)/', '', $out );
		}
		return trim( (string) $out );
	}

	public static function render(): void {
		global $product;
		if ( ! $product instanceof WC_Product || ! self::applies_to( $product->get_id() ) ) {
			return;
		}
		$o      = Eru_WIB_Settings::get();
		$status = isset( $_GET['eru_wib'] ) ? sanitize_key( wp_unslash( $_GET['eru_wib'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- display only.
		$errors = array(
			'invalid' => __( 'Lütfen adınızı ve geçerli bir e-posta adresini girin.', 'wholesale-inquiry-box-for-woocommerce' ),
			'qty'     => __( 'Adet, en az talep edilebilecek adetten düşük olamaz.', 'wholesale-inquiry-box-for-woocommerce' ),
			'rate'    => __( 'Çok sık istek gönderdiniz, lütfen biraz bekleyin.', 'wholesale-inquiry-box-for-woocommerce' ),
			'mail'    => __( 'Mesaj gönderilemedi, lütfen daha sonra tekrar deneyin.', 'wholesale-inquiry-box-for-woocommerce' ),
			'nonce'   => __( 'Oturum süresi doldu, sayfayı yenileyip tekrar deneyin.', 'wholesale-inquiry-box-for-woocommerce' ),
		);
		?>
		<div class="eru-wib" id="eru-wib">
			<details <?php echo '' !== $status ? 'open' : ''; ?>>
				<summary><?php echo esc_html( $o['title'] ); ?></summary>
				<?php if ( '' !== $o['text'] ) : ?>
					<p class="eru-wib-text"><?php echo esc_html( $o['text'] ); ?></p>
				<?php endif; ?>
				<?php if ( 'ok' === $status ) : ?>
					<p class="eru-wib-notice eru-wib-ok" role="status"><?php esc_html_e( 'Talebiniz alındı, en kısa sürede dönüş yapacağız.', 'wholesale-inquiry-box-for-woocommerce' ); ?></p>
				<?php elseif ( isset( $errors[ $status ] ) ) : ?>
					<p class="eru-wib-notice eru-wib-err" role="alert"><?php echo esc_html( $errors[ $status ] ); ?></p>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eru-wib-form">
					<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
					<input type="hidden" name="product_id" value="<?php echo esc_attr( $product->get_id() ); ?>">
					<?php wp_nonce_field( self::NONCE, '_eru_wib_nonce' ); ?>
					<div class="eru-wib-hp" aria-hidden="true"><label><?php esc_html_e( 'Web sitesi', 'wholesale-inquiry-box-for-woocommerce' ); ?> <input type="text" name="eru_wib_website" value="" tabindex="-1" autocomplete="off"></label></div>
					<p><label><?php esc_html_e( 'Ad soyad', 'wholesale-inquiry-box-for-woocommerce' ); ?> *<input type="text" name="name" required maxlength="100" autocomplete="name"></label></p>
					<p><label><?php esc_html_e( 'E-posta', 'wholesale-inquiry-box-for-woocommerce' ); ?> *<input type="email" name="email" required maxlength="100" autocomplete="email"></label></p>
					<p><label><?php esc_html_e( 'Telefon', 'wholesale-inquiry-box-for-woocommerce' ); ?><input type="tel" name="phone" maxlength="30" autocomplete="tel"></label></p>
					<p><label><?php esc_html_e( 'Adet', 'wholesale-inquiry-box-for-woocommerce' ); ?> *<input type="number" name="qty" required min="<?php echo esc_attr( (int) $o['min_qty'] ); ?>" value="<?php echo esc_attr( (int) $o['min_qty'] ); ?>"></label></p>
					<p><label><?php esc_html_e( 'Mesajınız', 'wholesale-inquiry-box-for-woocommerce' ); ?><textarea name="message" rows="3" maxlength="1000"></textarea></label></p>
					<p class="eru-wib-actions">
						<button type="submit" class="button eru-wib-submit"><?php echo esc_html( $o['button'] ); ?></button>
						<?php if ( '' !== $o['whatsapp'] ) : ?>
							<a class="button eru-wib-wa" rel="noopener nofollow" target="_blank" href="<?php echo esc_url( 'https://wa.me/' . rawurlencode( $o['whatsapp'] ) . '?text=' . rawurlencode( self::fill( $o['wa_template'], $product ) ) ); ?>">WhatsApp</a>
						<?php endif; ?>
					</p>
				</form>
			</details>
		</div>
		<?php
	}

	/**
	 * Process a submission.
	 *
	 * @param array $post Raw (slashed) request data.
	 * @return string ok | invalid | qty | rate | mail | nonce
	 */
	public static function process( array $post ): string {
		$post = wp_unslash( $post );
		$get  = static fn( $k ) => isset( $post[ $k ] ) && is_scalar( $post[ $k ] ) ? (string) $post[ $k ] : '';

		if ( ! wp_verify_nonce( $get( '_eru_wib_nonce' ), self::NONCE ) ) {
			return 'nonce';
		}
		// Honeypot: bots fill every field. Pretend success, send nothing.
		if ( '' !== trim( $get( 'eru_wib_website' ) ) ) {
			return 'ok';
		}
		$product = wc_get_product( (int) $get( 'product_id' ) );
		if ( ! $product || ! self::applies_to( $product->get_id() ) ) {
			return 'invalid';
		}
		$name  = sanitize_text_field( $get( 'name' ) );
		$email = sanitize_email( $get( 'email' ) );
		if ( '' === $name || ! is_email( $email ) ) {
			return 'invalid';
		}
		$o   = Eru_WIB_Settings::get();
		$qty = (int) $get( 'qty' );
		if ( $qty < (int) $o['min_qty'] ) {
			return 'qty';
		}
		$ip_key = 'eru_wib_' . md5( self::client_ip() );
		if ( get_transient( $ip_key ) ) {
			return 'rate';
		}

		$phone   = sanitize_text_field( $get( 'phone' ) );
		$message = sanitize_textarea_field( $get( 'message' ) );
		/* translators: %s: product name */
		$subject = sprintf( __( 'Toplu alım teklif talebi: %s', 'wholesale-inquiry-box-for-woocommerce' ), $product->get_name() );
		$lines   = array(
			__( 'Ürün', 'wholesale-inquiry-box-for-woocommerce' ) . ': ' . $product->get_name() . ( $product->get_sku() ? ' (' . $product->get_sku() . ')' : '' ),
			__( 'Bağlantı', 'wholesale-inquiry-box-for-woocommerce' ) . ': ' . get_permalink( $product->get_id() ),
			__( 'Adet', 'wholesale-inquiry-box-for-woocommerce' ) . ': ' . $qty,
			__( 'Ad soyad', 'wholesale-inquiry-box-for-woocommerce' ) . ': ' . $name,
			__( 'E-posta', 'wholesale-inquiry-box-for-woocommerce' ) . ': ' . $email,
			__( 'Telefon', 'wholesale-inquiry-box-for-woocommerce' ) . ': ' . $phone,
			'',
			$message,
		);
		$headers = array( 'Content-Type: text/plain; charset=UTF-8', 'Reply-To: ' . $name . ' <' . $email . '>' );
		/** Seconds a visitor must wait between two requests (0 disables). Every send attempt counts. */
		$wait = (int) apply_filters( 'eru_wib_rate_limit', 30 );
		if ( $wait > 0 ) {
			set_transient( $ip_key, 1, $wait );
		}
		$sent = wp_mail( Eru_WIB_Settings::recipient(), $subject, implode( "\n", $lines ), $headers );
		return $sent ? 'ok' : 'mail';
	}

	/** Visitor IP for the rate limit. Default REMOTE_ADDR; behind a proxy/CDN return the real address with `eru_wib_client_ip`. */
	public static function client_ip(): string {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filters the visitor IP used for rate limiting.
		 *
		 * @param string $ip REMOTE_ADDR.
		 */
		return (string) apply_filters( 'eru_wib_client_ip', $ip );
	}

	public static function handle(): void {
		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification -- verified in process().
		$result     = self::process( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
		$url        = $product_id ? get_permalink( $product_id ) : home_url( '/' );
		wp_safe_redirect( add_query_arg( 'eru_wib', $result, $url ) . '#eru-wib' );
		exit;
	}
}
