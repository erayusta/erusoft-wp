<?php
/**
 * Signed link pages: opt-out (?eru_cmi_optout=<token>) and re-consent confirmation (?eru_cmi_confirm=<token>),
 * plus the e-mails that carry them.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A GET never changes anything: mail scanners open links. It shows the masked recipient and a button; the
 * button POSTs the same token. The POST carries no nonce (the page may be cached and the token is the
 * credential); it is rate limited and refused only when the browser names another site in Origin/Referer
 * (absent headers and "Origin: null" pass).
 *
 * - Opt-out POST: writes RET for the token's recipient and channel (actor user, source link).
 * - Expired opt-out link: offers to e-mail a fresh link to the address on record (at most once a day).
 * - Confirm POST: writes ONAY for the address the link was sent to (owner proven: actor user, verified). The
 *   link carries that address encrypted, so a row known only by hash is restored with it. A confirmation
 *   link is void once the status changed after the link was sent, so an old link cannot undo a later opt-out.
 *
 * Confirmation mails are the only way an e-mail RET is lifted on the site: checkout, registration, the
 * Hesabım tab and REST all send one instead of writing ONAY. At most MAIL_LIMIT per client per 10 minutes
 * (checked first) and one per address per day (atomic marker option, released when the mail fails, purged by
 * the daily job). A privacy erasure voids every confirmation link sent before it.
 */
class Eru_CMI_Links {

	/** Hooks. */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'request' ), 1 );
	}

	/** @return array{ip:string,ua:string} Client data for the history (stored hashed). */
	public static function client() {
		return array(
			'ip' => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'ua' => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '',
		);
	}

	/**
	 * Opt-out link for e-mail footers and SMS texts.
	 *
	 * @param string $recipient E-mail or phone.
	 * @param string $channel   EPOSTA | MESAJ | ARAMA.
	 * @return string URL, or '' for an invalid recipient.
	 */
	public static function optout_url( $recipient, $channel = 'EPOSTA' ) {
		$t = Eru_CMI_Tokens::mint( $recipient, $channel );
		return '' === $t ? '' : add_query_arg( 'eru_cmi_optout', $t, home_url( '/' ) );
	}

	/** @param string $type Channel. @return string Turkish label. */
	public static function channel_label( $type ) {
		$labels = array(
			'EPOSTA' => __( 'E-posta', 'consent-manager-for-iys' ),
			'MESAJ'  => __( 'SMS', 'consent-manager-for-iys' ),
			'ARAMA'  => __( 'Telefon araması', 'consent-manager-for-iys' ),
		);
		return $labels[ $type ] ?? $type;
	}

	/** Front-end entry point. */
	public static function request() {
		// phpcs:disable WordPress.Security.NonceVerification -- the signed token is the credential.
		$req = array();
		foreach ( array( 'eru_cmi_optout', 'eru_cmi_confirm', 'eru_cmi_resend' ) as $k ) {
			if ( isset( $_REQUEST[ $k ] ) && is_string( $_REQUEST[ $k ] ) ) {
				$req[ $k ] = sanitize_text_field( wp_unslash( $_REQUEST[ $k ] ) );
			}
		}
		if ( empty( $req['eru_cmi_optout'] ) && empty( $req['eru_cmi_confirm'] ) ) {
			return;
		}
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		// phpcs:enable
		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'Referrer-Policy: no-referrer' );
		$r = self::handle( $method, $req );
		wp_die( self::kses( $r['html'] ), esc_html__( 'İletişim izinleri', 'consent-manager-for-iys' ), array( 'response' => $r['status'], 'back_link' => false ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered by self::kses().
	}

	/**
	 * Page logic without output (testable).
	 *
	 * @param string $method HTTP method.
	 * @param array  $req    eru_cmi_optout | eru_cmi_confirm, optional eru_cmi_resend.
	 * @return array{status:int,html:string}
	 */
	public static function handle( $method, array $req ) {
		$post = 'POST' === strtoupper( (string) $method );
		if ( $post && ! Eru_CMI_Rate_Limit::hit() ) {
			return self::page( 429, '<p>' . esc_html__( 'Çok fazla istek gönderildi. Lütfen birkaç dakika sonra tekrar deneyin.', 'consent-manager-for-iys' ) . '</p>' );
		}
		if ( ! empty( $req['eru_cmi_confirm'] ) ) {
			return self::confirm( $post, (string) $req['eru_cmi_confirm'] );
		}
		return self::optout( $post, (string) ( $req['eru_cmi_optout'] ?? '' ), ! empty( $req['eru_cmi_resend'] ) );
	}

	/** @param int $status HTTP status. @param string $body Escaped HTML. @return array */
	private static function page( $status, $body ) {
		ob_start();
		$eru_cmi_body = $body;
		include ERU_CMI_PATH . 'templates/token-page.php';
		return array(
			'status' => $status,
			'html'   => (string) ob_get_clean(),
		);
	}

	/**
	 * @param string $name   Hidden field name.
	 * @param string $token  Token.
	 * @param string $label  Button label.
	 * @param array  $extra  More hidden fields.
	 * @return string Form HTML (escaped).
	 */
	private static function form( $name, $token, $label, array $extra = array() ) {
		$html = '<form method="post" action="' . esc_url( home_url( '/' ) ) . '" class="eru-cmi-link-form">'
			. '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $token ) . '">';
		foreach ( $extra as $k => $v ) {
			$html .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		return $html . '<button type="submit" class="button">' . esc_html( $label ) . '</button></form>';
	}

	/** @param object|null $row Consent row. @return string Masked recipient or a neutral phrase. */
	private static function who( $row ) {
		if ( $row && ! Eru_CMI_Repository::is_placeholder( $row ) ) {
			return Eru_CMI_Recipient::mask( $row->recipient );
		}
		return __( 'bu adres', 'consent-manager-for-iys' );
	}

	/** Opt-out page. */
	private static function optout( $post, $token, $resend ) {
		$v = Eru_CMI_Tokens::verify( $token, 'optout' );
		if ( is_wp_error( $v ) && 'eru_cmi_token_expired' === $v->get_error_code() ) {
			return self::expired( $post, $token, (array) $v->get_error_data(), $resend );
		}
		if ( is_wp_error( $v ) ) {
			return self::page( 400, '<p>' . esc_html__( 'Bağlantı geçersiz. Lütfen iletideki bağlantıyı eksiksiz açın.', 'consent-manager-for-iys' ) . '</p>' );
		}
		$row     = Eru_CMI_Repository::by_hash( $v['hash'], $v['channel'] );
		$channel = self::channel_label( $v['channel'] );
		if ( ! $post ) {
			if ( $row && 'RET' === $row->status ) {
				/* translators: 1: masked recipient, 2: channel */
				return self::page( 200, '<p>' . esc_html( sprintf( __( '%1$s için %2$s izni zaten geri çekilmiş. Ticari ileti gönderilmez.', 'consent-manager-for-iys' ), self::who( $row ), $channel ) ) . '</p>' );
			}
			/* translators: 1: masked recipient, 2: channel */
			$text = sprintf( __( '%1$s adresine %2$s ile ticari ileti gönderilmesine verdiğiniz izni geri çekmek için aşağıdaki düğmeye tıklayın.', 'consent-manager-for-iys' ), self::who( $row ), $channel );
			return self::page( 200, '<p>' . esc_html( $text ) . '</p>' . self::form( 'eru_cmi_optout', $token, __( 'İzni geri çek', 'consent-manager-for-iys' ) ) );
		}
		if ( ! self::not_cross_origin() ) {
			return self::page( 403, '<p>' . esc_html__( 'İstek reddedildi.', 'consent-manager-for-iys' ) . '</p>' );
		}
		$r = Eru_CMI_Repository::withdraw_hash( $v['hash'], $v['channel'], array( 'actor' => 'user', 'source' => 'link', 'actor_id' => get_current_user_id() ) + self::client() );
		if ( is_wp_error( $r ) ) {
			return self::page( 503, '<p>' . esc_html__( 'İşlem şu anda tamamlanamadı. Lütfen biraz sonra tekrar deneyin.', 'consent-manager-for-iys' ) . '</p>' );
		}
		/* translators: %s: channel */
		return self::page( 200, '<p>' . esc_html( sprintf( __( '%s izniniz geri çekildi. Bundan sonra bu kanaldan size ticari ileti gönderilmez.', 'consent-manager-for-iys' ), $channel ) ) . '</p>' );
	}

	/** Expired opt-out link: explain, and offer a fresh link by e-mail (POST). */
	private static function expired( $post, $token, array $data, $resend ) {
		$row   = isset( $data['hash'], $data['channel'] ) ? Eru_CMI_Repository::by_hash( (string) $data['hash'], (string) $data['channel'] ) : null;
		$can   = $row && 'EPOSTA' === $row->type && ! Eru_CMI_Repository::is_placeholder( $row );
		$intro = '<p>' . esc_html__( 'Bu bağlantının süresi doldu.', 'consent-manager-for-iys' ) . '</p>';
		if ( $post && $resend && $can ) {
			if ( ! self::not_cross_origin() ) {
				return self::page( 403, '<p>' . esc_html__( 'İstek reddedildi.', 'consent-manager-for-iys' ) . '</p>' );
			}
			self::send_optout( $row->recipient );
			// Same answer whether or not a mail went out (once a day per address).
			return self::page( 200, '<p>' . esc_html__( 'Kayıtlı adrese yeni bir bağlantı gönderildi. Gelen kutunuzu kontrol edin.', 'consent-manager-for-iys' ) . '</p>' );
		}
		if ( $can ) {
			return self::page( 410, $intro . '<p>' . esc_html__( 'Kayıtlı e-posta adresinize yeni bir bağlantı gönderebiliriz.', 'consent-manager-for-iys' ) . '</p>' . self::form( 'eru_cmi_optout', $token, __( 'Yeni bağlantı gönder', 'consent-manager-for-iys' ), array( 'eru_cmi_resend' => '1' ) ) );
		}
		return self::page( 410, $intro . '<p>' . esc_html__( 'İzninizi hesabınızdaki iletişim tercihlerinden ya da size gönderilen yeni bir iletideki bağlantıdan geri çekebilirsiniz.', 'consent-manager-for-iys' ) . '</p>' );
	}

	/** Re-consent confirmation page. */
	private static function confirm( $post, $token ) {
		$bad = self::page( 400, '<p>' . esc_html__( 'Onay bağlantısı geçersiz, süresi dolmuş ya da artık kullanılamıyor.', 'consent-manager-for-iys' ) . '</p>' );
		$v   = Eru_CMI_Tokens::verify( $token, 'confirm' );
		if ( is_wp_error( $v ) ) {
			return $bad;
		}
		$row     = Eru_CMI_Repository::by_hash( $v['hash'], $v['channel'] );
		$address = '' !== $v['recipient'] ? $v['recipient'] : ( $row && ! Eru_CMI_Repository::is_placeholder( $row ) ? $row->recipient : '' );
		if ( ! $row || '' === $address || ! self::still_valid( $row, $v ) ) {
			return $bad;
		}
		$channel = self::channel_label( $v['channel'] );
		if ( ! $post ) {
			if ( 'ONAY' === $row->status ) {
				return self::page( 200, '<p>' . esc_html__( 'İzniniz zaten kayıtlı.', 'consent-manager-for-iys' ) . '</p>' );
			}
			/* translators: 1: masked recipient, 2: channel */
			$text = sprintf( __( '%1$s adresine %2$s ile kampanya ve duyuru gönderilmesine izin vermek için aşağıdaki düğmeye tıklayın.', 'consent-manager-for-iys' ), Eru_CMI_Recipient::mask( $address ), $channel );
			return self::page( 200, '<p>' . esc_html( $text ) . '</p>' . self::form( 'eru_cmi_confirm', $token, __( 'İzni onayla', 'consent-manager-for-iys' ) ) );
		}
		if ( ! self::not_cross_origin() ) {
			return self::page( 403, '<p>' . esc_html__( 'İstek reddedildi.', 'consent-manager-for-iys' ) . '</p>' );
		}
		$r = Eru_CMI_Repository::set(
			$address,
			$v['channel'],
			'ONAY',
			array(
				'actor'    => 'user',
				'source'   => 'link',
				'actor_id' => get_current_user_id(),
				'verified' => true,
			) + self::client()
		);
		if ( is_wp_error( $r ) ) {
			return self::page( 503, '<p>' . esc_html__( 'İşlem şu anda tamamlanamadı. Lütfen biraz sonra tekrar deneyin.', 'consent-manager-for-iys' ) . '</p>' );
		}
		return self::page( 200, '<p>' . esc_html__( 'Teşekkürler, izniniz kaydedildi. İstediğiniz zaman iletilerdeki bağlantıdan geri çekebilirsiniz.', 'consent-manager-for-iys' ) . '</p>' );
	}

	/**
	 * A confirmation link counts only while the status has not changed since it was sent. A privacy erasure
	 * writes a history row too (source "privacy"), so every link mailed before it is void, even one sent in
	 * the same second: the erased address is never restored by an old link.
	 *
	 * @param object $row Consent row.
	 * @param array  $v   Verified token.
	 * @return bool
	 */
	private static function still_valid( $row, array $v ) {
		global $wpdb;
		$issued = (int) $v['exp'] - Eru_CMI_Tokens::CONFIRM_TTL;
		$table  = Eru_CMI_Installer::table( 'history' );
		$last   = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(created_at) FROM ' . $table . ' WHERE consent_id = %d', (int) $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		if ( $last && (int) strtotime( $last . ' UTC' ) > $issued ) {
			return false;
		}
		$erased = $wpdb->get_var( $wpdb->prepare( 'SELECT MAX(created_at) FROM ' . $table . ' WHERE consent_id = %d AND source = %s', (int) $row->id, Eru_CMI_Privacy::SOURCE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL
		return ! $erased || (int) strtotime( $erased . ' UTC' ) < $issued;
	}

	/**
	 * Same-site check for token POSTs: refuse only when the browser names a different site. Absent headers
	 * and "Origin: null" (no-referrer policies, some mail clients) pass; the token is the credential.
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
	 * Narrow allow-list for the link pages: wp_kses_post() would strip the form, hidden input and button.
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

	/**
	 * Re-consent request for an address that withdrew: one e-mail with a 48-hour confirmation link, at most
	 * once a day per address and MAIL_LIMIT per client. Used whenever an e-mail RET would be lifted on the
	 * site (checkout, registration, Hesabım, REST, e-mail change).
	 *
	 * The client limit is checked first, so a client over its limit never uses up the address's daily slot;
	 * a mail that fails to go out releases the slot again.
	 *
	 * @param string $email E-mail.
	 * @return string sent | pending (one already went out today) | limited (this client asked too often) |
	 *                failed (invalid address, no cipher for the link, or the mail was not accepted).
	 */
	public static function request_confirm( $email ) {
		$norm = Eru_CMI_Recipient::normalize( $email, 'EPOSTA' );
		if ( is_wp_error( $norm ) || ! Eru_CMI_Tokens::can_carry() ) {
			return 'failed';
		}
		if ( ! Eru_CMI_Rate_Limit::hit( 'mail' ) ) {
			return 'limited';
		}
		$slot = self::once_a_day( 'rc', $norm );
		if ( false === $slot ) {
			return 'pending';
		}
		if ( ! self::send_confirm_mail( $norm ) ) {
			Eru_CMI_Lock::release( $slot['name'], $slot['token'] );
			return 'failed';
		}
		return 'sent';
	}

	/**
	 * @param string $email E-mail.
	 * @return bool A confirmation mail was sent now (see request_confirm()).
	 */
	public static function send_confirm( $email ) {
		return 'sent' === self::request_confirm( $email );
	}

	/** @param string $norm Normalised address. @return bool Mail accepted by wp_mail(). */
	private static function send_confirm_mail( $norm ) {
		$url = add_query_arg( 'eru_cmi_confirm', Eru_CMI_Tokens::mint( $norm, 'EPOSTA', 'confirm' ), home_url( '/' ) );
		return self::mail(
			$norm,
			/* translators: %s: site name */
			sprintf( __( 'İletişim izninizi onaylayın — %s', 'consent-manager-for-iys' ), self::site() ),
			__( 'E-posta iznini onaylıyor musunuz?', 'consent-manager-for-iys' ),
			array(
				__( 'Bu adres daha önce kampanya ve duyuru e-postalarını almak istemediğini bildirmişti. Yakın zamanda bu adres için yeniden izin işaretlendi.', 'consent-manager-for-iys' ),
				__( 'Bu siz idiyseniz ve e-postaları yeniden almak istiyorsanız aşağıdaki bağlantıyı açıp onaylayın. Bağlantı 48 saat geçerlidir.', 'consent-manager-for-iys' ),
				__( 'Siz değilseniz bu e-postayı dikkate almayın; hiçbir şey değişmez.', 'consent-manager-for-iys' ),
			),
			$url,
			__( 'İzni onayla', 'consent-manager-for-iys' )
		);
	}

	/**
	 * A fresh opt-out link to the address on record (expired link page), at most once a day.
	 *
	 * @param string $email E-mail.
	 * @return bool Mail sent.
	 */
	public static function send_optout( $email ) {
		$norm = Eru_CMI_Recipient::normalize( $email, 'EPOSTA' );
		if ( is_wp_error( $norm ) || ! Eru_CMI_Rate_Limit::hit( 'mail' ) ) {
			return false;
		}
		$slot = self::once_a_day( 'rs', $norm );
		if ( false === $slot ) {
			return false;
		}
		$sent = self::mail(
			$norm,
			/* translators: %s: site name */
			sprintf( __( 'İzninizi geri çekme bağlantınız — %s', 'consent-manager-for-iys' ), self::site() ),
			__( 'Yeni bağlantınız', 'consent-manager-for-iys' ),
			array( __( 'İstediğiniz yeni bağlantı aşağıdadır. Kampanya ve duyuru e-postalarına verdiğiniz izni geri çekmek için bağlantıyı açıp düğmeye tıklayın.', 'consent-manager-for-iys' ) ),
			self::optout_url( $norm, 'EPOSTA' ),
			__( 'İzni geri çek', 'consent-manager-for-iys' )
		);
		if ( ! $sent ) {
			Eru_CMI_Lock::release( $slot['name'], $slot['token'] );
		}
		return $sent;
	}

	/** @return string Site name for subjects. */
	private static function site() {
		return wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Per-address daily limit: an atomic marker row (Eru_CMI_Lock, INSERT IGNORE; taken over only after a day),
	 * keyed by the salted hash. Markers are only created for addresses on record or on an order, and the daily
	 * job deletes expired ones.
	 *
	 * @param string $kind rc | rs.
	 * @param string $norm Normalised address.
	 * @return array{name:string,token:string}|false The marker taken (to release it when the mail fails), or
	 *                                               false when one was already taken today.
	 */
	private static function once_a_day( $kind, $norm ) {
		$name  = $kind . '_' . substr( Eru_CMI_Recipient::hash( $norm ), 0, 32 );
		$token = Eru_CMI_Lock::acquire( $name, DAY_IN_SECONDS );
		if ( false === $token ) {
			return false;
		}
		Eru_CMI_Scheduler::arm_purge();
		return array(
			'name'  => $name,
			'token' => $token,
		);
	}

	/** @return string LIKE pattern of the daily mail markers. */
	private static function marker_like() {
		return 'eru\\_cmi\\_lock\\_r_\\_%';
	}

	/**
	 * Deletes expired daily mail markers in batches.
	 *
	 * @param int $batches Batches of 1000.
	 * @return int Deleted.
	 */
	public static function purge_markers( $batches = 20 ) {
		global $wpdb;
		$cut = time() - DAY_IN_SECONDS;
		$n   = 0;
		for ( $i = 0; $i < $batches; $i++ ) {
			$d  = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s AND CAST(SUBSTRING_INDEX(option_value, '|', 1) AS UNSIGNED) < %d LIMIT 1000", self::marker_like(), $cut ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$n += $d;
			if ( $d < 1000 ) {
				break;
			}
		}
		return $n;
	}

	/** @return bool Daily mail markers exist. */
	public static function has_markers() {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT 1 FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 1", self::marker_like() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Sends a short HTML mail (WooCommerce's template when available).
	 *
	 * @param string   $to      Address.
	 * @param string   $subject Subject.
	 * @param string   $heading Heading.
	 * @param string[] $paras   Paragraphs (plain text).
	 * @param string   $url     Link.
	 * @param string   $label   Link label.
	 * @return bool
	 */
	private static function mail( $to, $subject, $heading, array $paras, $url, $label ) {
		$body = '';
		foreach ( $paras as $p ) {
			$body .= '<p>' . esc_html( $p ) . '</p>';
		}
		$body .= '<p><a href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a></p>';
		$body .= '<p style="font-size:12px;word-break:break-all">' . esc_html( $url ) . '</p>';
		if ( function_exists( 'WC' ) && WC()->mailer() ) {
			$body = WC()->mailer()->wrap_message( $heading, $body );
		} else {
			$body = '<h1>' . esc_html( $heading ) . '</h1>' . $body;
		}
		return (bool) wp_mail( $to, $subject, $body, array( 'Content-Type: text/html; charset=UTF-8' ) );
	}
}

/**
 * Signed opt-out link for a recipient: for e-mail footers and SMS texts of other plugins.
 * Opening it shows a confirmation button; nothing changes until the button is pressed.
 *
 * @param string $recipient E-mail or phone.
 * @param string $channel   EPOSTA | MESAJ | ARAMA.
 * @return string URL ('' for an invalid recipient or channel).
 */
function eru_cmi_optout_url( $recipient, $channel = 'EPOSTA' ) {
	return Eru_CMI_Links::optout_url( $recipient, $channel );
}
