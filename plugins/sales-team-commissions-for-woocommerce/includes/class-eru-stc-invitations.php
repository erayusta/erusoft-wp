<?php
/**
 * Customer invitations. An agent never attaches an existing customer directly: "müşteri ekle" e-mails the
 * customer a signed, expiring confirmation link. The link (GET) shows a button and changes nothing; the
 * button (POST) links the customer to the agent (link type "invite", sticky like a manual link).
 *
 * - The agent always gets the same answer, whether the address has an account, is already linked, is the
 *   agent's own or is unknown, and the request does the same work in every case: it queues one Action
 *   Scheduler job (args: agent id and user id, 0 for an unknown address). The job decides and sends;
 *   only an unlinked customer account (not an agent) receives a mail. Unknown addresses get nothing (no
 *   unsolicited mail): agents share their referral link for new customers.
 * - Token: base64url( "customer.agent.expiry.invite-id" ) . "." . base64url( HMAC-SHA256 ), keyed from
 *   wp_salt(); default lifetime 7 days (filter eru_stc_invite_ttl). Single use: the random invite id is
 *   kept in the customer's user meta and must match; it is cleared on confirmation, when the customer is
 *   unlinked and by a newer invitation. A plain e-mail or a forged token is refused.
 * - Rate limits: per agent (default 5 an hour, 20 a day; filter eru_stc_invite_limits, counted on every
 *   attempt so the limit says nothing about the addresses) and per customer (one mail a day).
 * - A confirmation never moves a customer who is linked to another agent by then.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Invitations {

	const PARAM         = 'eru_stc_invite';
	const JOB           = 'eru_stc_send_invite';
	const AGENT_META    = '_eru_stc_invites';
	const CUSTOMER_META = '_eru_stc_invited_at';
	const ID_META       = '_eru_stc_invite_id';

	/** Front-end entry for the confirmation link (module on, invitations on), the mail job, unlink cleanup. */
	public static function init() {
		add_action( self::JOB, array( __CLASS__, 'send_job' ), 10, 2 );
		add_action( 'eru_stc_customer_unlinked', array( __CLASS__, 'forget' ), 10, 1 );
		if ( Eru_STC_Settings::enabled() && 'yes' === Eru_STC_Settings::get( 'customer_invites' ) ) {
			add_action( 'template_redirect', array( __CLASS__, 'page' ), 1 );
		}
	}

	/** @param int $customer_id Unlinked customer: any pending invitation link stops working. */
	public static function forget( $customer_id ) {
		delete_user_meta( (int) $customer_id, self::ID_META );
	}

	/** @return string Neutral answer for the agent. */
	public static function neutral() {
		return __( 'Davet isteğiniz alındı. Adres, henüz temsilcisi olmayan kayıtlı bir müşteriye aitse müşteriye bir onay bağlantısı gönderilir; müşteri onaylayınca listenizde görünür.', 'sales-team-commissions-for-woocommerce' );
	}

	/** @return array{hour:int,day:int} Per-agent limits. */
	public static function limits() {
		/**
		 * Invitations an agent may send.
		 *
		 * @param array $limits hour, day.
		 */
		$l = (array) apply_filters(
			'eru_stc_invite_limits',
			array(
				'hour' => 5,
				'day'  => 20,
			)
		);
		return array(
			'hour' => max( 1, (int) ( $l['hour'] ?? 5 ) ),
			'day'  => max( 1, (int) ( $l['day'] ?? 20 ) ),
		);
	}

	/**
	 * An agent "adds" a customer by e-mail.
	 *
	 * @param int    $agent_id Agent.
	 * @param string $email    Address typed by the agent.
	 * @return array{ok:bool,message:string}
	 */
	public static function request( $agent_id, $email ) {
		$agent = Eru_STC_Agents::get( $agent_id );
		if ( ! Eru_STC_Settings::enabled() || 'yes' !== Eru_STC_Settings::get( 'customer_invites' ) ) {
			return self::answer( false, __( 'Müşteri davetleri kapalı.', 'sales-team-commissions-for-woocommerce' ) );
		}
		if ( ! Eru_STC_Agents::is_active( $agent ) ) {
			return self::answer( false, __( 'Yalnızca etkin temsilciler davet gönderebilir.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$email = sanitize_email( (string) $email );
		if ( '' === $email || ! is_email( $email ) ) {
			return self::answer( false, __( 'Geçerli bir e-posta adresi girin.', 'sales-team-commissions-for-woocommerce' ) );
		}
		if ( ! self::count_attempt( (int) $agent->user_id ) ) {
			return self::answer( false, __( 'Çok fazla davet gönderdiniz. Lütfen daha sonra tekrar deneyin.', 'sales-team-commissions-for-woocommerce' ) );
		}
		// The same work for every address: one lookup and one queued job; eligibility is decided in the job.
		$user = get_user_by( 'email', $email );
		$args = array( (int) $agent->id, $user ? (int) $user->ID : 0 );
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::JOB, $args, Eru_STC_Eligibility::GROUP );
		} else {
			self::send_job( $args[0], $args[1] );
		}
		/**
		 * Fires after an agent asked to invite an address (whatever the outcome; no address passed).
		 *
		 * @param int $agent_id Agent.
		 */
		do_action( 'eru_stc_invitation_requested', (int) $agent->id );
		return self::answer( true, self::neutral() );
	}

	/** @return array{ok:bool,message:string} */
	private static function answer( $ok, $message ) {
		return array(
			'ok'      => (bool) $ok,
			'message' => (string) $message,
		);
	}

	/**
	 * Queued job: sends the confirmation mail when the account may be invited (at most one a day per
	 * customer). A new invitation replaces the customer's invite id, so older links stop working.
	 *
	 * @param int $agent_id    Agent.
	 * @param int $customer_id User id (0 = unknown address: nothing is sent).
	 */
	public static function send_job( $agent_id, $customer_id ) {
		if ( ! Eru_STC_Settings::enabled() || 'yes' !== Eru_STC_Settings::get( 'customer_invites' ) ) {
			return;
		}
		$agent = Eru_STC_Agents::get( (int) $agent_id );
		$user  = (int) $customer_id > 0 ? get_userdata( (int) $customer_id ) : false;
		if ( ! Eru_STC_Agents::is_active( $agent ) || ! $user || ! self::can_receive( $user, $agent ) ) {
			return;
		}
		if ( (int) get_user_meta( $user->ID, self::CUSTOMER_META, true ) >= time() - DAY_IN_SECONDS ) {
			return;
		}
		update_user_meta( $user->ID, self::CUSTOMER_META, time() );
		$id = bin2hex( random_bytes( 8 ) );
		update_user_meta( $user->ID, self::ID_META, $id );
		self::send( $user, $agent, $id );
	}

	/** Whether this account may be invited by this agent (an unlinked customer, not an agent). */
	private static function can_receive( $user, $agent ) {
		return (int) $user->ID !== (int) $agent->user_id && ! Eru_STC_Agents::get_by_user( $user->ID ) && ! Eru_STC_Links::get( $user->ID );
	}

	/**
	 * Counts one attempt against the agent's limits (user meta of the agent, at most "day" timestamps).
	 *
	 * @param int $user_id Agent's user.
	 * @return bool False when a limit is reached.
	 */
	private static function count_attempt( $user_id ) {
		$limits = self::limits();
		$now    = time();
		$log    = array_values( array_filter( array_map( 'intval', (array) get_user_meta( $user_id, self::AGENT_META, true ) ), fn( $t ) => $t > $now - DAY_IN_SECONDS ) );
		$hour   = count( array_filter( $log, fn( $t ) => $t > $now - HOUR_IN_SECONDS ) );
		if ( $hour >= $limits['hour'] || count( $log ) >= $limits['day'] ) {
			return false;
		}
		$log[] = $now;
		update_user_meta( $user_id, self::AGENT_META, array_slice( $log, -$limits['day'] ) );
		return true;
	}

	/** Sends the confirmation mail. */
	private static function send( $user, $agent, $id ) {
		/**
		 * Lifetime of an invitation link in seconds.
		 *
		 * @param int $ttl Default 7 days.
		 */
		$ttl   = max( HOUR_IN_SECONDS, (int) apply_filters( 'eru_stc_invite_ttl', 7 * DAY_IN_SECONDS ) );
		$url   = self::url( self::mint( (int) $user->ID, (int) $agent->id, time() + $ttl, $id ) );
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$title = __( 'Temsilci davetini onaylayın', 'sales-team-commissions-for-woocommerce' );
		$body  = '<p>' . esc_html(
			sprintf(
				/* translators: 1: agent name, 2: site name */
				__( '%1$s sizi %2$s müşteri temsilcisi listesine eklemek istiyor. Onaylarsanız bundan sonraki siparişleriniz bu temsilciyle ilişkilendirilir.', 'sales-team-commissions-for-woocommerce' ),
				Eru_STC_Agents::label( $agent ),
				$site
			)
		) . '</p><p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Daveti görüntüle ve onayla', 'sales-team-commissions-for-woocommerce' ) . '</a></p><p>'
			. esc_html(
				sprintf(
					/* translators: %d: days */
					_n( 'Bağlantı %d gün geçerlidir. Bu daveti siz istemediyseniz e-postayı yok sayın; hiçbir şey değişmez.', 'Bağlantı %d gün geçerlidir. Bu daveti siz istemediyseniz e-postayı yok sayın; hiçbir şey değişmez.', max( 1, (int) round( $ttl / DAY_IN_SECONDS ) ), 'sales-team-commissions-for-woocommerce' ),
					max( 1, (int) round( $ttl / DAY_IN_SECONDS ) )
				)
			) . '</p>';
		$message = function_exists( 'WC' ) && WC()->mailer() ? WC()->mailer()->wrap_message( $title, $body ) : $body;
		wp_mail( $user->user_email, $site . ': ' . $title, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}

	/** @param string $token Token. @return string Confirmation URL. */
	public static function url( $token ) {
		return add_query_arg( self::PARAM, rawurlencode( $token ), home_url( '/' ) );
	}

	/** @return string 32-byte HMAC key derived from the site's salt. */
	private static function key() {
		return hash_hmac( 'sha256', 'eru-stc-invite', wp_salt( 'auth' ), true );
	}

	/** @param string $bin Bytes. @return string base64url. */
	private static function b64( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/** @param string $txt base64url. @return string|false */
	private static function unb64( $txt ) {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_\-]+$/', (string) $txt ) ) {
			return false;
		}
		return base64_decode( strtr( $txt, '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
	}

	/**
	 * @param int $customer_id Customer.
	 * @param int $agent_id    Agent.
	 * @param int    $exp         Expiry (Unix).
	 * @param string $id          Invite id (letters and digits).
	 * @return string Token.
	 */
	public static function mint( $customer_id, $agent_id, $exp, $id ) {
		$payload = (int) $customer_id . '.' . (int) $agent_id . '.' . (int) $exp . '.' . preg_replace( '/[^A-Za-z0-9]/', '', (string) $id );
		return self::b64( $payload ) . '.' . self::b64( hash_hmac( 'sha256', 'invite|' . $payload, self::key(), true ) );
	}

	/**
	 * Checks a token: signature first, then expiry (only a genuine token is reported as expired).
	 *
	 * @param mixed $token Token.
	 * @return array{customer:int,agent:int,exp:int,id:string}|WP_Error
	 */
	public static function verify( $token ) {
		$invalid = new WP_Error( 'eru_stc_invite_invalid', __( 'Bağlantı geçersiz.', 'sales-team-commissions-for-woocommerce' ) );
		if ( ! is_string( $token ) || strlen( $token ) > 300 || 1 !== substr_count( $token, '.' ) ) {
			return $invalid;
		}
		list( $p64, $s64 ) = explode( '.', $token );
		$payload           = self::unb64( $p64 );
		$sig               = self::unb64( $s64 );
		if ( false === $payload || false === $sig || 1 !== preg_match( '/^(\d{1,20})\.(\d{1,20})\.(\d{1,12})\.([A-Za-z0-9]{1,32})$/', $payload, $m ) ) {
			return $invalid;
		}
		if ( ! hash_equals( hash_hmac( 'sha256', 'invite|' . $payload, self::key(), true ), $sig ) ) {
			return $invalid;
		}
		if ( (int) $m[3] < time() ) {
			return new WP_Error( 'eru_stc_invite_expired', __( 'Bağlantının süresi doldu. Temsilcinizden yeni bir davet isteyin.', 'sales-team-commissions-for-woocommerce' ) );
		}
		return array(
			'customer' => (int) $m[1],
			'agent'    => (int) $m[2],
			'exp'      => (int) $m[3],
			'id'       => (string) $m[4],
		);
	}

	/** Front-end page for the link (GET shows the button, POST confirms). */
	public static function page() {
		// phpcs:disable WordPress.Security.NonceVerification -- the signed token is the credential.
		if ( ! isset( $_REQUEST[ self::PARAM ] ) || ! is_string( $_REQUEST[ self::PARAM ] ) ) {
			return;
		}
		$req    = array( self::PARAM => sanitize_text_field( wp_unslash( $_REQUEST[ self::PARAM ] ) ) );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		// phpcs:enable
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		$r = self::handle( $method, $req );
		wp_die( self::kses( $r['html'] ), esc_html__( 'Temsilci daveti', 'sales-team-commissions-for-woocommerce' ), array( 'response' => $r['status'], 'back_link' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered by self::kses().
	}

	/**
	 * Page logic without output (testable).
	 *
	 * @param string $method HTTP method.
	 * @param array  $req    The token under PARAM.
	 * @return array{status:int,html:string}
	 */
	public static function handle( $method, array $req ) {
		$post = 'POST' === strtoupper( (string) $method );
		if ( $post && ! self::not_cross_origin() ) {
			return self::html( 403, '<p>' . esc_html__( 'İstek reddedildi. Bağlantıyı e-postadan yeniden açın.', 'sales-team-commissions-for-woocommerce' ) . '</p>' );
		}
		$data = self::verify( $req[ self::PARAM ] ?? '' );
		if ( is_wp_error( $data ) ) {
			return self::html( 'eru_stc_invite_expired' === $data->get_error_code() ? 410 : 400, '<p>' . esc_html( $data->get_error_message() ) . '</p>' );
		}
		$agent    = Eru_STC_Agents::get( $data['agent'] );
		$customer = get_userdata( $data['customer'] );
		if ( ! Eru_STC_Agents::is_active( $agent ) || ! $customer || (int) $agent->user_id === (int) $customer->ID ) {
			return self::html( 410, '<p>' . esc_html__( 'Bu davet artık geçerli değil.', 'sales-team-commissions-for-woocommerce' ) . '</p>' );
		}
		$name = esc_html( Eru_STC_Agents::label( $agent ) );
		$link = Eru_STC_Links::get( $customer->ID );
		if ( $link && (int) $link->agent_id === (int) $agent->id ) {
			/* translators: %s: agent name */
			return self::html( 200, '<p>' . sprintf( esc_html__( 'Hesabınız %s ile ilişkilendirildi.', 'sales-team-commissions-for-woocommerce' ), '<strong>' . $name . '</strong>' ) . '</p>' );
		}
		if ( $link ) {
			return self::html( 409, '<p>' . esc_html__( 'Hesabınız zaten başka bir temsilciyle ilişkili; bu davet bir değişiklik yapmaz. Temsilcinizi değiştirmek için mağazayla iletişime geçin.', 'sales-team-commissions-for-woocommerce' ) . '</p>' );
		}
		$current = (string) get_user_meta( $customer->ID, self::ID_META, true );
		if ( '' === $current || ! hash_equals( $current, $data['id'] ) ) {
			return self::html( 410, '<p>' . esc_html__( 'Bu davet artık geçerli değil. Temsilcinizden yeni bir davet isteyin.', 'sales-team-commissions-for-woocommerce' ) . '</p>' );
		}
		if ( ! $post ) {
			$body = '<p>' . sprintf(
				/* translators: %s: agent name */
				esc_html__( '%s sizi müşteri listesine eklemek istiyor. Onaylarsanız bundan sonraki siparişleriniz bu temsilciyle ilişkilendirilir.', 'sales-team-commissions-for-woocommerce' ),
				'<strong>' . $name . '</strong>'
			) . '</p><form method="post" action="' . esc_url( home_url( '/' ) ) . '" class="eru-stc-invite-form"><input type="hidden" name="' . esc_attr( self::PARAM ) . '" value="' . esc_attr( (string) $req[ self::PARAM ] ) . '"><button type="submit" class="button">' . esc_html__( 'Daveti onayla', 'sales-team-commissions-for-woocommerce' ) . '</button></form><p>' . esc_html__( 'İstemiyorsanız bu sayfayı kapatın; hiçbir şey değişmez.', 'sales-team-commissions-for-woocommerce' ) . '</p>';
			return self::html( 200, $body );
		}
		$ok = Eru_STC_Links::link( $customer->ID, (int) $agent->id, 'invite', array( 'created_by' => (int) $customer->ID ) );
		if ( true !== $ok ) {
			return self::html( 409, '<p>' . esc_html__( 'Davet onaylanamadı. Lütfen mağazayla iletişime geçin.', 'sales-team-commissions-for-woocommerce' ) . '</p>' );
		}
		delete_user_meta( $customer->ID, self::CUSTOMER_META );
		delete_user_meta( $customer->ID, self::ID_META );
		/**
		 * Fires after a customer confirmed an agent's invitation.
		 *
		 * @param int $customer_id Customer.
		 * @param int $agent_id    Agent.
		 */
		do_action( 'eru_stc_invitation_confirmed', (int) $customer->ID, (int) $agent->id );
		/* translators: %s: agent name */
		return self::html( 200, '<p>' . sprintf( esc_html__( 'Teşekkürler. Hesabınız %s ile ilişkilendirildi.', 'sales-team-commissions-for-woocommerce' ), '<strong>' . $name . '</strong>' ) . '</p>' );
	}

	/** @return array{status:int,html:string} */
	private static function html( $status, $body ) {
		return array(
			'status' => (int) $status,
			'html'   => '<div class="eru-stc-invite"><h1>' . esc_html__( 'Temsilci daveti', 'sales-team-commissions-for-woocommerce' ) . '</h1>' . $body . '</div>',
		);
	}

	/**
	 * Soft same-origin check for the POST: a foreign Origin or Referer host is refused; an absent header or
	 * "Origin: null" (privacy settings, mail clients) is allowed.
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

	/**
	 * Narrow allow-list for the page: wp_kses_post() would strip the form, the hidden input and the button.
	 *
	 * @param string $html HTML built from escaped parts.
	 * @return string
	 */
	public static function kses( $html ) {
		$allowed           = wp_kses_allowed_html( 'post' );
		$allowed['form']   = array(
			'method' => true,
			'action' => true,
			'class'  => true,
		);
		$allowed['input']  = array(
			'type'  => true,
			'name'  => true,
			'value' => true,
		);
		$allowed['button'] = array(
			'type'  => true,
			'class' => true,
		);
		return wp_kses( $html, $allowed );
	}
}
