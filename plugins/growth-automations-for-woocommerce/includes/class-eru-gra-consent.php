<?php
/**
 * Marketing consent, the suppression list and the unsubscribe flow.
 *
 * @package growth-automations-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Who may receive marketing e-mail. Suppression always wins over any consent source.
 */
class Eru_GRA_Consent {

	const OPTION    = 'eru_gra_suppressed';
	const USER_META = 'eru_gra_marketing_optin';

	/** Pending re-consent requests: suppression hash => { t: sha256(token), e: expiry }. */
	const RECONSENT_OPTION = 'eru_gra_reconsent';

	/** Re-consent link lifetime. */
	const RECONSENT_TTL = 2 * DAY_IN_SECONDS;

	/** Hooks. */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'unsub_request' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'reconsent_request' ), 1 );
		add_action( 'woocommerce_edit_account_form', array( __CLASS__, 'account_field' ) );
		add_action( 'woocommerce_save_account_details', array( __CLASS__, 'account_save' ) );
	}

	/** @param string $email E-mail. @return string Lower-case, sanitised. */
	public static function norm( $email ) {
		return strtolower( sanitize_email( (string) $email ) );
	}

	/** @param string $email E-mail. @return string Hash used in the suppression list. */
	private static function hash( $email ) {
		return hash( 'sha256', 'eru-gra|' . self::norm( $email ) );
	}

	/** @param string $email E-mail. @return bool On the suppression list. */
	public static function is_suppressed( $email ) {
		$list = get_option( self::OPTION, array() );
		return is_array( $list ) && isset( $list[ self::hash( $email ) ] );
	}

	/** @param string $email E-mail. Adds the address (as a hash) to the list. */
	public static function suppress( $email ) {
		if ( '' === self::norm( $email ) ) {
			return;
		}
		$list = get_option( self::OPTION, array() );
		$list = is_array( $list ) ? $list : array();
		$list[ self::hash( $email ) ] = time();
		update_option( self::OPTION, $list, false );
		// The account checkbox must show the opt-out too, so a later account save cannot silently lift it.
		$user = get_user_by( 'email', self::norm( $email ) );
		if ( $user ) {
			update_user_meta( $user->ID, self::USER_META, 'no' );
		}
		global $wpdb;
		// Open carts of this address stop being mailed.
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . Eru_GRA_DB::carts() . " SET status = 'expired' WHERE email = %s AND status = 'open'", self::norm( $email ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @param string $email E-mail. Fresh explicit consent lifts an earlier opt-out. */
	public static function remove_suppression( $email ) {
		$list = get_option( self::OPTION, array() );
		if ( is_array( $list ) && isset( $list[ self::hash( $email ) ] ) ) {
			unset( $list[ self::hash( $email ) ] );
			update_option( self::OPTION, $list, false );
		}
	}

	/**
	 * A checkout tick for an opted-out address by someone who is not its signed-in owner: e-mail one confirmation link
	 * to the address. Only the owner confirming it (POST) lifts the opt-out. At most one such e-mail per address per 24 h.
	 *
	 * @param string $email E-mail.
	 * @return bool A confirmation e-mail was sent.
	 */
	public static function request_reconsent( $email ) {
		$email = self::norm( $email );
		if ( '' === $email || ! self::is_suppressed( $email ) ) {
			return false;
		}
		$key  = self::hash( $email );
		$rate = 'eru_gra_rc_' . substr( $key, 0, 32 );
		if ( get_transient( $rate ) ) {
			return false;
		}
		set_transient( $rate, 1, DAY_IN_SECONDS );
		$token = wp_generate_password( 32, false, false );
		$list  = get_option( self::RECONSENT_OPTION, array() );
		$list  = is_array( $list ) ? array_filter( $list, fn( $r ) => is_array( $r ) && (int) ( $r['e'] ?? 0 ) > time() ) : array();
		$list[ $key ] = array( 't' => hash( 'sha256', $token ), 'e' => time() + self::RECONSENT_TTL );
		update_option( self::RECONSENT_OPTION, $list, false );
		$raw = rtrim( strtr( base64_encode( $email ), '+/', '-_' ), '=' ) . '.' . $token;
		return Eru_GRA_Mail::send(
			$email,
			array(
				/* translators: {site_title} stays as a placeholder. */
				'subject' => __( 'E-posta tercihinizi onaylayın - {site_title}', 'growth-automations-for-woocommerce' ),
				'heading' => __( 'E-postaları yeniden almak ister misiniz?', 'growth-automations-for-woocommerce' ),
				'body'    => __( "Bu adres daha önce pazarlama e-postalarından çıkmıştı. Bir siparişte bu adres için e-posta izni işaretlendi. Bu siz idiyseniz ve e-postaları yeniden almak istiyorsanız aşağıdaki bağlantıyı açıp onaylayın:\n\n{confirm_link}\n\nBağlantı 48 saat geçerlidir. Siz değilseniz bu e-postayı yok sayın; hiçbir şey değişmez.", 'growth-automations-for-woocommerce' ),
			),
			array( 'confirm_link' => add_query_arg( 'eru_gra_reconsent', $raw, home_url( '/' ) ) )
		);
	}

	/**
	 * Re-consent link handling, free of output. GET only renders a button; a same-site POST with a valid, unexpired,
	 * unused token lifts the opt-out.
	 *
	 * @param string $method HTTP method. @param string $raw "<base64 e-mail>.<token>".
	 * @return array{status:int,html:string}
	 */
	public static function handle_reconsent( $method, $raw ) {
		$title = esc_html__( 'E-posta aboneliği', 'growth-automations-for-woocommerce' );
		$page  = fn( $status, $body ) => array( 'status' => $status, 'html' => '<h1>' . $title . '</h1>' . $body );
		$bad   = $page( 400, '<p>' . esc_html__( 'Onay bağlantısı geçersiz, süresi dolmuş veya daha önce kullanılmış.', 'growth-automations-for-woocommerce' ) . '</p>' );
		$p     = explode( '.', (string) $raw, 2 );
		$email = 2 === count( $p ) ? self::norm( (string) base64_decode( strtr( $p[0], '-_', '+/' ), true ) ) : '';
		if ( '' === $email ) {
			return $bad;
		}
		if ( 'POST' !== strtoupper( (string) $method ) ) {
			return $page(
				200,
				'<p>' . esc_html__( 'Pazarlama e-postalarını yeniden almak için aşağıdaki düğmeye tıklayın.', 'growth-automations-for-woocommerce' ) . '</p>'
				. '<form method="post" action="' . esc_url( home_url( '/' ) ) . '"><input type="hidden" name="eru_gra_reconsent" value="' . esc_attr( $raw ) . '">'
				. '<button type="submit" class="button">' . esc_html__( 'E-postaları yeniden almak istiyorum', 'growth-automations-for-woocommerce' ) . '</button></form>'
			);
		}
		if ( ! self::not_cross_origin() ) {
			return $page( 403, '<p>' . esc_html__( 'İstek reddedildi.', 'growth-automations-for-woocommerce' ) . '</p>' );
		}
		$key  = self::hash( $email );
		$list = get_option( self::RECONSENT_OPTION, array() );
		$row  = is_array( $list ) ? ( $list[ $key ] ?? null ) : null;
		if ( ! is_array( $row ) || (int) ( $row['e'] ?? 0 ) < time() || ! hash_equals( (string) ( $row['t'] ?? '' ), hash( 'sha256', (string) $p[1] ) ) ) {
			return $bad;
		}
		unset( $list[ $key ] );
		update_option( self::RECONSENT_OPTION, $list, false );
		self::remove_suppression( $email );
		return $page( 200, '<p>' . esc_html__( 'Teşekkürler! Bu adrese yeniden pazarlama e-postası gönderebiliriz. İstediğiniz zaman e-postadaki bağlantıdan vazgeçebilirsiniz.', 'growth-automations-for-woocommerce' ) . '</p>' );
	}

	/** Front-end entry point for the re-consent link. */
	public static function reconsent_request() {
		// phpcs:disable WordPress.Security.NonceVerification -- the single-use token is the credential.
		if ( empty( $_REQUEST['eru_gra_reconsent'] ) || ! is_string( $_REQUEST['eru_gra_reconsent'] ) ) {
			return;
		}
		$raw    = sanitize_text_field( wp_unslash( $_REQUEST['eru_gra_reconsent'] ) );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		// phpcs:enable
		nocache_headers();
		$r = self::handle_reconsent( $method, $raw );
		wp_die( self::kses( $r['html'] ), esc_html__( 'E-posta aboneliği', 'growth-automations-for-woocommerce' ), array( 'response' => $r['status'], 'back_link' => false ) );
	}

	/** @param string $email E-mail. @return bool The hash is on the list (used by the eraser message). */
	public static function has_record( $email ) {
		return self::is_suppressed( $email );
	}

	/**
	 * Which consent covers this address, or an empty string.
	 *
	 * @param string $email   E-mail.
	 * @param int    $user_id User ID, 0 to look it up by e-mail.
	 * @param string $context Where the check runs: `capture` (storing a cart) or `send` (before a marketing e-mail).
	 * @return string account|checkout|filter|''
	 */
	public static function source( $email, $user_id = 0, $context = 'send' ) {
		$email = self::norm( $email );
		if ( '' === $email || self::is_suppressed( $email ) ) {
			return '';
		}
		$user = $user_id ? get_userdata( (int) $user_id ) : get_user_by( 'email', $email );
		if ( $user && 'yes' === get_user_meta( $user->ID, self::USER_META, true ) ) {
			return 'account';
		}
		$orders = wc_get_orders(
			array(
				'billing_email' => $email,
				'limit'         => 1,
				'return'        => 'ids',
				'meta_query'    => array( array( 'key' => '_eru_gra_marketing_consent', 'value' => 'yes' ) ), // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $orders ) {
			return 'checkout';
		}
		/**
		 * Lets a consent source (for example a double opt-in newsletter or a consent platform) grant marketing consent.
		 * Return true only when the address gave explicit, provable consent. No Turkish İYS registration is done here.
		 *
		 * @param bool   $has     Current value (false).
		 * @param string $email   Lower-case address.
		 * @param string $context `capture` (storing a cart) or `send` (re-check right before a marketing e-mail).
		 */
		return apply_filters( 'eru_gra_has_marketing_consent', false, $email, (string) $context ) ? 'filter' : '';
	}

	/** @param string $email E-mail. @param int $user_id User ID. @param string $context capture|send. @return bool */
	public static function has( $email, $user_id = 0, $context = 'send' ) {
		return '' !== self::source( $email, $user_id, $context );
	}

	/**
	 * Consent still holds for a stored cart row, checked again at send time.
	 *
	 * @param array $row Cart row. @return bool
	 */
	public static function row_consent( array $row ) {
		if ( self::is_suppressed( $row['email'] ) ) {
			return false;
		}
		switch ( $row['consent_source'] ) {
			case 'checkout':
				return true; // Recorded when the box was ticked; withdrawing removes the row or suppresses the address.
			case 'account':
				$u = get_userdata( (int) $row['user_id'] );
				return $u && 'yes' === get_user_meta( $u->ID, self::USER_META, true );
			case 'filter':
				return (bool) apply_filters( 'eru_gra_has_marketing_consent', false, $row['email'], 'send' );
		}
		return false;
	}

	/** @param string $html Page HTML built from escaped parts. @return string Filtered HTML that keeps the confirm form (wp_kses_post would strip it). */
	public static function kses( $html ) {
		$allowed          = wp_kses_allowed_html( 'post' );
		$allowed['form']   = array( 'method' => true, 'action' => true );
		$allowed['input']  = array( 'type' => true, 'name' => true, 'value' => true );
		$allowed['button'] = array( 'type' => true, 'class' => true );
		return wp_kses( $html, $allowed );
	}

	/** @param string $email E-mail. @return string Signed token for the unsubscribe link. */
	private static function token( $email ) {
		$email = self::norm( $email );
		return rtrim( strtr( base64_encode( $email ), '+/', '-_' ), '=' ) . '.' . substr( hash_hmac( 'sha256', 'unsub|' . $email, wp_salt( 'auth' ) ), 0, 32 );
	}

	/** @param string $email E-mail. @return string Unsubscribe URL (GET shows a confirm button only). */
	public static function unsub_url( $email ) {
		return add_query_arg( 'eru_gra_unsub', self::token( $email ), home_url( '/' ) );
	}

	/** @param string $raw Token. @return string E-mail or ''. */
	private static function email_from_token( $raw ) {
		$p = explode( '.', (string) $raw, 2 );
		if ( 2 !== count( $p ) || '' === $p[0] ) {
			return '';
		}
		$email = self::norm( (string) base64_decode( strtr( $p[0], '-_', '+/' ), true ) );
		return ( '' !== $email && hash_equals( self::token( $email ), (string) $raw ) ) ? $email : '';
	}

	/**
	 * Unsubscribe handling, free of output so it can be tested. A GET only renders a button (mail scanners that
	 * pre-open links must not change anything); a POST carrying a valid signed token suppresses the address.
	 * Mail clients that support one-click unsubscribe send the POST themselves.
	 *
	 * @param string $method HTTP method. @param string $raw Signed token.
	 * @return array{status:int,html:string}
	 */
	public static function handle_unsub( $method, $raw ) {
		$title = esc_html__( 'E-posta aboneliği', 'growth-automations-for-woocommerce' );
		$page  = fn( $status, $body ) => array( 'status' => $status, 'html' => '<h1>' . $title . '</h1>' . $body );
		$email = self::email_from_token( $raw );
		if ( '' === $email ) {
			return $page( 400, '<p>' . esc_html__( 'Bağlantı geçersiz.', 'growth-automations-for-woocommerce' ) . '</p>' );
		}
		if ( 'POST' !== strtoupper( (string) $method ) ) {
			return $page(
				200,
				'<p>' . esc_html__( 'Pazarlama e-postalarını durdurmak için aşağıdaki düğmeye tıklayın.', 'growth-automations-for-woocommerce' ) . '</p>'
				. '<form method="post" action="' . esc_url( home_url( '/' ) ) . '"><input type="hidden" name="eru_gra_unsub" value="' . esc_attr( $raw ) . '">'
				. '<button type="submit" class="button">' . esc_html__( 'E-posta almak istemiyorum', 'growth-automations-for-woocommerce' ) . '</button></form>'
			);
		}
		self::suppress( $email );
		return $page( 200, '<p>' . esc_html__( 'Bu adrese artık pazarlama e-postası göndermeyeceğiz.', 'growth-automations-for-woocommerce' ) . '</p>' );
	}

	/** Front-end entry point for the unsubscribe link. */
	public static function unsub_request() {
		// phpcs:disable WordPress.Security.NonceVerification -- the signed token is the credential.
		if ( empty( $_REQUEST['eru_gra_unsub'] ) || ! is_string( $_REQUEST['eru_gra_unsub'] ) ) {
			return;
		}
		$raw    = sanitize_text_field( wp_unslash( $_REQUEST['eru_gra_unsub'] ) );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		// phpcs:enable
		nocache_headers();
		$r = self::handle_unsub( $method, $raw );
		wp_die( Eru_GRA_Consent::kses( $r['html'] ), esc_html__( 'E-posta aboneliği', 'growth-automations-for-woocommerce' ), array( 'response' => $r['status'], 'back_link' => false ) );
	}

	/** My Account: opt-in checkbox for marketing e-mail. */
	public static function account_field() {
		if ( ! Eru_GRA_Settings::marketing_on() ) {
			return;
		}
		$yes = 'yes' === get_user_meta( get_current_user_id(), self::USER_META, true );
		echo '<p class="woocommerce-form-row form-row eru-gra-optin"><label><input type="checkbox" name="eru_gra_optin" value="1"' . checked( $yes, true, false ) . '> ' . esc_html( Eru_GRA_Settings::get( 'general', 'optin_text' ) ) . '</label></p>';
	}

	/** @param int $user_id User. */
	public static function account_save( $user_id ) {
		if ( ! Eru_GRA_Settings::marketing_on() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce verifies the account form nonce before this action.
		$on  = ! empty( $_POST['eru_gra_optin'] );
		$was = 'yes' === get_user_meta( $user_id, self::USER_META, true );
		update_user_meta( $user_id, self::USER_META, $on ? 'yes' : 'no' );
		$u = get_userdata( $user_id );
		// Only a genuine no-to-yes change by the account owner lifts an opt-out.
		if ( $on && ! $was && $u ) {
			self::remove_suppression( $u->user_email );
		}
	}

	/**
	 * Same-site check for token-carrying POSTs: refuse only when the browser names a different site. Absent headers or
	 * `Origin: null` (no-referrer policies) pass, because the secret token is the credential.
	 *
	 * @return bool
	 */
	public static function not_cross_origin() {
		$own = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		foreach ( array( 'HTTP_ORIGIN', 'HTTP_REFERER' ) as $key ) {
			$value = isset( $_SERVER[ $key ] ) ? trim( sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) ) ) : '';
			$host  = ( '' === $value || 'null' === strtolower( $value ) ) ? '' : (string) wp_parse_url( $value, PHP_URL_HOST );
			if ( '' !== $host && 0 !== strcasecmp( $host, $own ) ) {
				return false;
			}
		}
		return true;
	}
}
