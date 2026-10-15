<?php
/**
 * Opt-in newsletter with a unique welcome coupon.
 *
 * @package conversion-boosters-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stores a subscriber only after explicit consent, then issues a coupon bound to the e-mail.
 *
 * The form can sit on a cached page, so it carries no nonce. Abuse is bounded by a hidden honeypot field,
 * a same-origin check (Origin/Referer) and a per-IP-hash limit of 5 attempts per hour.
 */
class Eru_CB_Newsletter {

	const CPT      = 'eru_cb_subscriber';
	const RATE_MAX = 5;

	/** Hooks. */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_shortcode( 'eru_cb_newsletter', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_ajax_nopriv_eru_cb_subscribe', array( __CLASS__, 'ajax' ) );
		add_action( 'wp_ajax_eru_cb_subscribe', array( __CLASS__, 'ajax' ) );
		add_action( 'admin_post_nopriv_eru_cb_subscribe', array( __CLASS__, 'post' ) );
		add_action( 'admin_post_eru_cb_subscribe', array( __CLASS__, 'post' ) );
		add_action( 'wp_footer', array( __CLASS__, 'footer' ) );
		add_action( 'template_redirect', array( __CLASS__, 'confirm_request' ) );
		add_filter( 'eru_gra_has_marketing_consent', array( __CLASS__, 'consent_filter' ), 10, 3 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	/** Subscribers are listed under WooCommerce, read-only. */
	public static function register_cpt() {
		$cap = 'manage_woocommerce';
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'Bülten aboneleri', 'conversion-boosters-for-woocommerce' ),
					'singular_name' => __( 'Abone', 'conversion-boosters-for-woocommerce' ),
					'search_items'  => __( 'Abone ara', 'conversion-boosters-for-woocommerce' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'woocommerce',
				'supports'            => array( 'title' ),
				'capabilities'        => array(
					'edit_post'           => $cap,
					'read_post'           => $cap,
					'delete_post'         => $cap,
					'edit_posts'          => $cap,
					'edit_others_posts'   => $cap,
					'delete_posts'        => $cap,
					'delete_others_posts' => $cap,
					'publish_posts'       => $cap,
					'read_private_posts'  => $cap,
					'create_posts'        => 'do_not_allow',
				),
				'map_meta_cap'        => false,
				'exclude_from_search' => true,
			)
		);
	}

	/** @param array $cols Columns. @return array */
	public static function columns( $cols ) {
		return array(
			'cb'             => $cols['cb'] ?? '',
			'title'          => __( 'E-posta', 'conversion-boosters-for-woocommerce' ),
			'eru_cb_consent' => __( 'Onay tarihi (UTC)', 'conversion-boosters-for-woocommerce' ),
			'eru_cb_source'  => __( 'Kaynak', 'conversion-boosters-for-woocommerce' ),
			'eru_cb_coupon'  => __( 'Kupon', 'conversion-boosters-for-woocommerce' ),
		);
	}

	/** @param string $col Column. @param int $id Post ID. */
	public static function column( $col, $id ) {
		$map = array( 'eru_cb_consent' => '_eru_cb_consent_at', 'eru_cb_source' => '_eru_cb_source', 'eru_cb_coupon' => '_eru_cb_coupon' );
		if ( isset( $map[ $col ] ) ) {
			echo esc_html( (string) get_post_meta( $id, $map[ $col ], true ) );
		}
	}

	/** @return string Transient key of the current visitor's attempt counter. */
	public static function rl_key() {
		return 'eru_cb_rl_' . substr( Eru_CB_Coupons::ip_hash(), 0, 32 );
	}

	/** Counts one attempt. @return bool False once the hourly limit is used. */
	private static function within_limit() {
		$key = self::rl_key();
		$n   = (int) get_transient( $key );
		if ( $n >= self::RATE_MAX ) {
			return false;
		}
		set_transient( $key, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	/** @return string Transient key of the global hourly signup counter. */
	public static function global_key() {
		return 'eru_cb_ngl';
	}

	/**
	 * Key used for uniqueness: lower-case, and the "+tag" of the local part removed, so plus-addressing
	 * cannot be used to collect several welcome coupons for one mailbox.
	 *
	 * @param string $email E-mail. @return string
	 */
	public static function email_key( $email ) {
		$email = strtolower( trim( (string) $email ) );
		$at    = strrpos( $email, '@' );
		if ( false === $at ) {
			return $email;
		}
		$local = substr( $email, 0, $at );
		$plus  = strpos( $local, '+' );
		if ( false !== $plus && $plus > 0 ) {
			$local = substr( $local, 0, $plus );
		}
		return $local . substr( $email, $at );
	}

	/** @param string $email E-mail. @return int Subscriber post ID with the same e-mail key, or 0. */
	private static function find( $email ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_eru_cb_email_key' WHERE p.post_type = %s AND m.meta_value = %s LIMIT 1", self::CPT, self::email_key( $email ) ) );
	}

	/** @param string $email E-mail. @return int Subscriber post ID with exactly this address, or 0. */
	public static function find_exact( $email ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title = %s LIMIT 1", self::CPT, strtolower( $email ) ) );
	}

	/** @return bool Whether any subscriber row exists. */
	public static function any_subscribers() {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s LIMIT 1", self::CPT ) );
	}

	/** Sends an HTML mail wrapped in the WooCommerce e-mail template. @param string $to Recipient. @param string $subject Subject. @param string $heading Heading. @param string $body Plain text body. */
	private static function mail( $to, $subject, $heading, $body ) {
		$mailer = WC()->mailer();
		$mailer->send( $to, $subject, $mailer->wrap_message( $heading, wpautop( make_clickable( esc_html( $body ) ) ) ) );
	}

	/**
	 * Start a double opt-in subscription. Nothing is confirmed, no consent is recorded and no coupon exists until
	 * the e-mail owner follows the link in the confirmation message.
	 *
	 * @param array $a email, consent, hp (honeypot), source.
	 * @return array{ok:bool,code:string,message:string}
	 */
	public static function subscribe( array $a ) {
		$res = fn( $ok, $code, $msg ) => array( 'ok' => $ok, 'code' => $code, 'message' => $msg );

		if ( ! Eru_CB_Settings::is_on( 'nl_enabled' ) ) {
			return $res( false, 'off', __( 'Abonelik şu anda kapalı.', 'conversion-boosters-for-woocommerce' ) );
		}
		if ( ! empty( $a['hp'] ) ) {
			return $res( false, 'spam', __( 'İstek reddedildi.', 'conversion-boosters-for-woocommerce' ) );
		}
		if ( ! self::within_limit() ) {
			return $res( false, 'rate', __( 'Çok fazla deneme yaptınız, lütfen daha sonra tekrar deneyin.', 'conversion-boosters-for-woocommerce' ) );
		}
		$email = strtolower( sanitize_email( (string) ( $a['email'] ?? '' ) ) );
		if ( ! is_email( $email ) ) {
			return $res( false, 'email', __( 'Geçerli bir e-posta adresi girin.', 'conversion-boosters-for-woocommerce' ) );
		}
		if ( empty( $a['consent'] ) ) {
			return $res( false, 'consent', __( 'Devam etmek için onay kutusunu işaretleyin.', 'conversion-boosters-for-woocommerce' ) );
		}

		// Short lock so two parallel requests for the same mailbox cannot both create a row.
		$lock  = 's' . md5( self::email_key( $email ) );
		$owner = Eru_CB_Lock::acquire( $lock, MINUTE_IN_SECONDS );
		if ( false === $owner ) {
			return $res( false, 'exists', __( 'Zaten abonesiniz.', 'conversion-boosters-for-woocommerce' ) );
		}
		try {
			$old = self::find( $email );
			if ( $old ) {
				$status  = get_post_meta( $old, '_eru_cb_status', true );
				$expires = (int) get_post_meta( $old, '_eru_cb_token_expires', true );
				if ( 'confirmed' === $status ) {
					return $res( false, 'exists', __( 'Zaten abonesiniz.', 'conversion-boosters-for-woocommerce' ) );
				}
				if ( $expires > time() ) {
					return $res( false, 'pending', __( 'Onay e-postası zaten gönderildi, lütfen gelen kutunuzu kontrol edin.', 'conversion-boosters-for-woocommerce' ) );
				}
				wp_delete_post( $old, true ); // Expired, unconfirmed: start over.
			}
			if ( ! Eru_CB_Coupons::global_allow( self::global_key() ) ) {
				return $res( false, 'busy', __( 'Şu anda çok fazla istek var, lütfen daha sonra tekrar deneyin.', 'conversion-boosters-for-woocommerce' ) );
			}
			$id = wp_insert_post(
				array(
					'post_type'   => self::CPT,
					'post_status' => 'publish',
					'post_title'  => $email,
				)
			);
			if ( ! $id || is_wp_error( $id ) ) {
				return $res( false, 'store', __( 'Kayıt oluşturulamadı.', 'conversion-boosters-for-woocommerce' ) );
			}
			$token = wp_generate_password( 32, false, false );
			$text  = (string) Eru_CB_Settings::get( 'nl_consent_text' );
			update_post_meta( $id, '_eru_cb_status', 'pending' );
			update_post_meta( $id, '_eru_cb_email_key', self::email_key( $email ) );
			update_post_meta( $id, '_eru_cb_consent_text', $text );
			update_post_meta( $id, '_eru_cb_consent_hash', hash( 'sha256', $text ) );
			update_post_meta( $id, '_eru_cb_source', sanitize_key( $a['source'] ?? 'form' ) );
			update_post_meta( $id, '_eru_cb_ip_hash', Eru_CB_Coupons::ip_hash() );
			update_post_meta( $id, '_eru_cb_token', hash( 'sha256', $token ) );
			update_post_meta( $id, '_eru_cb_token_expires', time() + (int) Eru_CB_Settings::get( 'nl_confirm_hours' ) * HOUR_IN_SECONDS );
			update_option( 'eru_cb_has_data', 1, false );
			Eru_CB_Coupons::maybe_schedule( true );
		} finally {
			Eru_CB_Lock::release( $lock, $owner );
		}

		$link = add_query_arg( 'eru_cb_confirm', $id . '.' . $token, home_url( '/' ) );
		$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		self::mail(
			$email,
			/* translators: %s: site name */
			sprintf( __( '%s aboneliğinizi onaylayın', 'conversion-boosters-for-woocommerce' ), $name ),
			__( 'Aboneliğinizi onaylayın', 'conversion-boosters-for-woocommerce' ),
			sprintf(
				/* translators: 1: confirmation link, 2: hours */
				__( "Bülten aboneliğinizi onaylamak için aşağıdaki bağlantıya tıklayın. Onayınızın ardından hoş geldin kuponunuz e-postayla gönderilir.\n\n%1\$s\n\nBağlantı %2\$d saat geçerlidir. Bu isteği siz yapmadıysanız e-postayı yok sayabilirsiniz.", 'conversion-boosters-for-woocommerce' ),
				$link,
				(int) Eru_CB_Settings::get( 'nl_confirm_hours' )
			)
		);

		return $res( true, 'pending', __( 'Teşekkürler! Onay için e-postanıza bir bağlantı gönderdik. Bağlantıya tıkladığınızda hoş geldin kuponunuz e-postayla gelecek.', 'conversion-boosters-for-woocommerce' ) );
	}

	/**
	 * Confirm a pending subscription with the emailed token (single use, expiring).
	 *
	 * @param int    $id    Subscriber post ID. @param string $token Token from the link.
	 * @return array{ok:bool,code:string,message:string,coupon:string}
	 */
	public static function confirm( $id, $token ) {
		$id  = (int) $id;
		$res = fn( $ok, $code, $msg, $coupon = '' ) => array( 'ok' => $ok, 'code' => $code, 'message' => $msg, 'coupon' => $coupon );
		$bad = $res( false, 'invalid', __( 'Onay bağlantısı geçersiz veya daha önce kullanılmış.', 'conversion-boosters-for-woocommerce' ) );

		if ( ! $id || self::CPT !== get_post_type( $id ) || 'pending' !== get_post_meta( $id, '_eru_cb_status', true ) ) {
			return $bad;
		}
		$hash = (string) get_post_meta( $id, '_eru_cb_token', true );
		if ( '' === $hash || ! hash_equals( $hash, hash( 'sha256', (string) $token ) ) ) {
			return $bad;
		}
		if ( (int) get_post_meta( $id, '_eru_cb_token_expires', true ) < time() ) {
			return $res( false, 'expired', __( 'Onay bağlantısının süresi dolmuş, lütfen yeniden abone olun.', 'conversion-boosters-for-woocommerce' ) );
		}
		$lock  = 'c' . $id;
		$owner = Eru_CB_Lock::acquire( $lock, MINUTE_IN_SECONDS );
		if ( false === $owner ) {
			return $bad;
		}
		try {
			if ( 'pending' !== get_post_meta( $id, '_eru_cb_status', true ) ) {
				return $bad;
			}
			$email  = get_the_title( $id );
			$coupon = self::coupon_for( $email );
			if ( null === $coupon ) {
				return $res( false, 'coupon', __( 'Kupon oluşturulamadı, lütfen bağlantıya tekrar tıklayın.', 'conversion-boosters-for-woocommerce' ) );
			}
			update_post_meta( $id, '_eru_cb_status', 'confirmed' );
			update_post_meta( $id, '_eru_cb_consent_at', gmdate( 'Y-m-d H:i:s' ) );
			update_post_meta( $id, '_eru_cb_coupon', $coupon );
			delete_post_meta( $id, '_eru_cb_token' );
			delete_post_meta( $id, '_eru_cb_token_expires' );
		} finally {
			Eru_CB_Lock::release( $lock, $owner );
		}

		self::mail(
			$email,
			/* translators: %s: site name */
			sprintf( __( '%s hoş geldin kuponunuz', 'conversion-boosters-for-woocommerce' ), wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) ),
			__( 'Hoş geldiniz', 'conversion-boosters-for-woocommerce' ),
			sprintf(
				/* translators: %s: coupon code */
				__( "Aboneliğiniz onaylandı, teşekkürler. Hoş geldin kuponunuz: %s\n\nKuponu sepet sayfasında kullanabilirsiniz.", 'conversion-boosters-for-woocommerce' ),
				$coupon
			)
		);
		/**
		 * Fires after a subscription was confirmed and the coupon issued.
		 *
		 * @param string $email  Subscriber e-mail.
		 * @param string $coupon Coupon code.
		 * @param int    $id     Subscriber post ID.
		 */
		do_action( 'eru_cb_subscribed', $email, $coupon, $id );

		return $res( true, 'ok', __( 'Aboneliğiniz onaylandı. Hoş geldin kuponunuz e-postayla gönderildi.', 'conversion-boosters-for-woocommerce' ), $coupon );
	}

	/**
	 * Link handling, kept free of output so it can be tested. GET only renders a button (mail scanners that pre-open
	 * links must not confirm anything); only a same-origin POST carrying the token confirms.
	 *
	 * @param string $method HTTP method. @param string $raw "<id>.<token>".
	 * @return array{status:int,html:string}
	 */
	public static function handle_confirm( $method, $raw ) {
		$title = esc_html__( 'Bülten aboneliği', 'conversion-boosters-for-woocommerce' );
		$page  = fn( $status, $body ) => array( 'status' => $status, 'html' => '<h1>' . $title . '</h1>' . $body );
		if ( 'POST' !== strtoupper( (string) $method ) ) {
			return $page(
				200,
				'<p>' . esc_html__( 'Aboneliğinizi tamamlamak için aşağıdaki düğmeye tıklayın.', 'conversion-boosters-for-woocommerce' ) . '</p>'
				. '<form method="post" action="' . esc_url( home_url( '/' ) ) . '"><input type="hidden" name="eru_cb_confirm" value="' . esc_attr( $raw ) . '">'
				. '<button type="submit" class="button">' . esc_html__( 'Aboneliğimi onayla', 'conversion-boosters-for-woocommerce' ) . '</button></form>'
			);
		}
		if ( ! Eru_CB_Coupons::not_cross_origin() ) {
			return $page( 403, '<p>' . esc_html__( 'İstek reddedildi.', 'conversion-boosters-for-woocommerce' ) . '</p>' );
		}
		$p = explode( '.', $raw, 2 );
		$r = self::confirm( (int) $p[0], $p[1] ?? '' );
		return $page( $r['ok'] ? 200 : 400, '<p>' . esc_html( $r['message'] ) . '</p>' );
	}

	/** Entry point for the link from the confirmation e-mail (GET shows the button, POST confirms). */
	public static function confirm_request() {
		// phpcs:disable WordPress.Security.NonceVerification -- the single-use token is the credential; POST also needs same-origin.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$src    = 'POST' === strtoupper( $method ) ? $_POST : $_GET;
		if ( empty( $src['eru_cb_confirm'] ) || ! is_string( $src['eru_cb_confirm'] ) ) {
			return;
		}
		$raw = sanitize_text_field( wp_unslash( $src['eru_cb_confirm'] ) );
		// phpcs:enable
		nocache_headers();
		$r = self::handle_confirm( $method, $raw );
		wp_die(
			self::kses( $r['html'] ),
			esc_html__( 'Bülten aboneliği', 'conversion-boosters-for-woocommerce' ),
			array( 'response' => $r['status'], 'back_link' => false )
		);
	}

	/** wp_kses_post() strips forms; the confirm page needs its POST button. @param string $html Page HTML. @return string */
	private static function kses( $html ) {
		$allowed           = wp_kses_allowed_html( 'post' );
		$allowed['form']   = array( 'method' => true, 'action' => true );
		$allowed['input']  = array( 'type' => true, 'name' => true, 'value' => true );
		$allowed['button'] = array( 'type' => true, 'class' => true );
		return wp_kses( $html, $allowed );
	}

	/** @param string $email Subscriber e-mail. @return string|null Coupon code. */
	private static function coupon_for( $email ) {
		if ( 'fixed' === Eru_CB_Settings::get( 'nl_mode' ) ) {
			$code = (string) Eru_CB_Settings::get( 'nl_fixed_code' );
			return ( '' !== $code && wc_get_coupon_id_by_code( $code ) ) ? $code : null;
		}
		$made = Eru_CB_Coupons::create(
			array(
				'prefix'   => Eru_CB_Settings::get( 'nl_prefix' ),
				'type'     => Eru_CB_Settings::get( 'nl_type' ),
				'amount'   => Eru_CB_Settings::get( 'nl_amount' ),
				'min_cart' => Eru_CB_Settings::get( 'nl_min_cart' ),
				'expires'  => time() + (int) Eru_CB_Settings::get( 'nl_days' ) * DAY_IN_SECONDS,
				'email'    => $email,
			)
		);
		return $made ? $made['code'] : null;
	}

	/** Collects the posted form. @return array */
	private static function posted() {
		// phpcs:disable WordPress.Security.NonceVerification -- cached-page form: honeypot + rate limit + same-origin instead, see class docblock.
		return array(
			'email'   => isset( $_POST['eru_cb_email'] ) ? sanitize_email( wp_unslash( $_POST['eru_cb_email'] ) ) : '',
			'consent' => ! empty( $_POST['eru_cb_consent'] ),
			'hp'      => isset( $_POST['eru_cb_website'] ) ? sanitize_text_field( wp_unslash( $_POST['eru_cb_website'] ) ) : '',
			'source'  => isset( $_POST['eru_cb_source'] ) ? sanitize_key( wp_unslash( $_POST['eru_cb_source'] ) ) : 'form',
		);
		// phpcs:enable
	}

	/** AJAX handler. */
	public static function ajax() {
		nocache_headers();
		if ( ! Eru_CB_Coupons::same_origin() ) {
			wp_send_json( array( 'ok' => false, 'code' => 'origin', 'message' => __( 'İstek reddedildi.', 'conversion-boosters-for-woocommerce' ) ), 403 );
		}
		wp_send_json( self::subscribe( self::posted() ) );
	}

	/** No-JS fallback: redirect back with the result in the query string. */
	public static function post() {
		nocache_headers();
		$back = wp_get_referer() ? wp_get_referer() : home_url( '/' );
		if ( ! Eru_CB_Coupons::same_origin() ) {
			wp_safe_redirect( $back );
			exit;
		}
		$r = self::subscribe( self::posted() );
		wp_safe_redirect( add_query_arg( 'eru_cb_nl', $r['code'], $back ) . '#eru-cb-newsletter' );
		exit;
	}

	/** @param array $atts Shortcode attributes (source). @return string Form HTML. */
	public static function shortcode( $atts = array() ) {
		if ( ! Eru_CB_Settings::is_on( 'nl_enabled' ) ) {
			return '';
		}
		$atts = shortcode_atts( array( 'source' => 'shortcode' ), $atts, 'eru_cb_newsletter' );
		return self::form( sanitize_key( $atts['source'] ) );
	}

	/** Optional footer form. */
	public static function footer() {
		if ( Eru_CB_Settings::is_on( 'nl_enabled' ) && Eru_CB_Settings::is_on( 'nl_footer' ) && ! is_admin() ) {
			echo '<div class="eru-cb-footer-form">' . self::form( 'footer' ) . '</div>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in form().
		}
	}

	/** @param string $source Source label. @return string */
	public static function form( $source ) {
		Eru_CB_Shipping::enqueue();
		// phpcs:disable WordPress.Security.NonceVerification -- display of a redirect result only.
		$flash = '';
		if ( isset( $_GET['eru_cb_nl'] ) ) {
			$code  = sanitize_key( wp_unslash( $_GET['eru_cb_nl'] ) );
			$texts = array(
				'pending' => __( 'Onay için e-postanıza bir bağlantı gönderdik.', 'conversion-boosters-for-woocommerce' ),
				'exists'  => __( 'Zaten abonesiniz.', 'conversion-boosters-for-woocommerce' ),
			);
			$flash = $texts[ $code ] ?? __( 'Kayıt tamamlanamadı, lütfen bilgileri kontrol edin.', 'conversion-boosters-for-woocommerce' );
		}
		// phpcs:enable
		ob_start();
		?>
		<form class="eru-cb-nl" id="eru-cb-newsletter" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" novalidate>
			<p class="eru-cb-nl__title"><?php echo esc_html( Eru_CB_Settings::get( 'nl_title' ) ); ?></p>
			<input type="hidden" name="action" value="eru_cb_subscribe">
			<input type="hidden" name="eru_cb_source" value="<?php echo esc_attr( $source ); ?>">
			<div class="eru-cb-nl__hp" aria-hidden="true"><label><?php esc_html_e( 'Web sitesi', 'conversion-boosters-for-woocommerce' ); ?><input type="text" name="eru_cb_website" tabindex="-1" autocomplete="off"></label></div>
			<div class="eru-cb-nl__row">
				<label class="screen-reader-text" for="eru-cb-email-<?php echo esc_attr( $source ); ?>"><?php esc_html_e( 'E-posta', 'conversion-boosters-for-woocommerce' ); ?></label>
				<input type="email" id="eru-cb-email-<?php echo esc_attr( $source ); ?>" name="eru_cb_email" placeholder="<?php esc_attr_e( 'E-posta adresiniz', 'conversion-boosters-for-woocommerce' ); ?>" required autocomplete="email">
				<button type="submit" class="button"><?php esc_html_e( 'Abone ol', 'conversion-boosters-for-woocommerce' ); ?></button>
			</div>
			<label class="eru-cb-nl__consent"><input type="checkbox" name="eru_cb_consent" value="1" required> <span><?php echo esc_html( Eru_CB_Settings::get( 'nl_consent_text' ) ); ?></span></label>
			<p class="eru-cb-nl__msg" role="status" aria-live="polite"><?php echo esc_html( $flash ); ?></p>
		</form>
		<?php
		return ob_get_clean();
	}

	/**
	 * Growth Automations consent source: true only for confirmed (double opt-in) subscribers.
	 *
	 * @param bool   $has     Current value.
	 * @param string $email   E-mail.
	 * @param string $context Where the check runs (`checkout`, `capture`, `send`…); not used, every context needs a confirmed subscription.
	 * @return bool
	 */
	public static function consent_filter( $has, $email = '', $context = 'send' ) {
		$email = strtolower( sanitize_email( (string) $email ) );
		if ( $has || '' === $email ) {
			return (bool) $has;
		}
		$id = self::find_exact( $email );
		return $id && 'confirmed' === get_post_meta( $id, '_eru_cb_status', true ) && '' !== (string) get_post_meta( $id, '_eru_cb_consent_at', true );
	}

	/** Purges unconfirmed rows after their token expired and rows past the retention period, in batches. */
	public static function purge() {
		$size = Eru_CB_Coupons::batch_size();
		$days = max( 1, (int) Eru_CB_Settings::get( 'retention_days' ) );
		$base = array(
			'post_type'      => self::CPT,
			'post_status'    => 'any',
			'fields'         => 'ids',
			'posts_per_page' => $size,
			'no_found_rows'  => true,
		);
		$stale = get_posts(
			$base + array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array( 'key' => '_eru_cb_status', 'value' => 'pending' ),
					array( 'key' => '_eru_cb_token_expires', 'value' => time(), 'compare' => '<', 'type' => 'NUMERIC' ),
				),
			)
		);
		$old   = get_posts( $base + array( 'date_query' => array( array( 'before' => gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ), 'column' => 'post_date_gmt' ) ) ) );
		foreach ( array_unique( array_merge( $stale, $old ) ) as $id ) {
			wp_delete_post( (int) $id, true );
		}
		if ( count( $stale ) >= $size || count( $old ) >= $size ) {
			Eru_CB_Coupons::rearm( 'eru_cb_purge_more' );
		}
	}
}
