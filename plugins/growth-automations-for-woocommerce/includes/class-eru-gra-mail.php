<?php
/**
 * E-mail rendering: placeholders, WooCommerce wrapper, unsubscribe link and headers.
 *
 * @package growth-automations-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Builds and sends the messages. The wrapper is WooCommerce's own (`wrap_message`), so the colours, logo and footer
 * come from WooCommerce > Settings > E-mails.
 */
class Eru_GRA_Mail {

	/** @return string[] Placeholders usable in every text. */
	public static function placeholders() {
		return array( '{customer_name}', '{order_number}', '{cart_link}', '{coupon_code}', '{coupon_expiry}', '{site_title}', '{unsubscribe_link}' );
	}

	/**
	 * @param string $text Template. @param array $vars Placeholder => value (without braces).
	 * @return string Template with every known placeholder replaced; unknown ones are removed.
	 */
	public static function fill( $text, array $vars ) {
		$map = array();
		foreach ( $vars as $k => $v ) {
			$map[ '{' . $k . '}' ] = (string) $v;
		}
		$text = strtr( (string) $text, $map );
		return (string) preg_replace( '/\{[a-z_]+\}/', '', $text );
	}

	/** @param string $text Template. @return bool Mentions a coupon. */
	public static function mentions_coupon( $text ) {
		return false !== strpos( (string) $text, '{coupon_code}' );
	}

	/**
	 * Render the HTML of a message (also used by the admin preview).
	 *
	 * @param array $a heading, body (filled text), extra_html, marketing (bool), unsubscribe_url.
	 * @return string HTML wrapped by WooCommerce.
	 */
	public static function render( array $a ) {
		$body = wpautop( make_clickable( esc_html( (string) $a['body'] ) ) );
		$html = wc_get_template_html(
			'emails/eru-gra-message.php',
			array(
				'body_html'       => $body,
				'extra_html'      => (string) ( $a['extra_html'] ?? '' ),
				'marketing'       => ! empty( $a['marketing'] ),
				'unsubscribe_url' => (string) ( $a['unsubscribe_url'] ?? '' ),
			),
			'woocommerce/',
			ERU_GRA_PATH . 'templates/'
		);
		return WC()->mailer()->wrap_message( (string) $a['heading'], $html );
	}

	/** @return string Site name for texts. */
	public static function site_title() {
		return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Send one message through the WooCommerce mailer.
	 *
	 * @param string $to       Recipient.
	 * @param array  $cfg      subject, heading, body templates.
	 * @param array  $vars     Placeholder values.
	 * @param array  $opts     marketing (bool), extra_html (string).
	 * @return bool wp_mail result.
	 */
	public static function send( $to, array $cfg, array $vars, array $opts = array() ) {
		$marketing = ! empty( $opts['marketing'] );
		$unsub     = $marketing ? Eru_GRA_Consent::unsub_url( $to ) : '';
		$vars      = array_merge( array( 'site_title' => self::site_title(), 'unsubscribe_link' => $unsub ), $vars );
		$subject   = trim( preg_replace( '/[\r\n]+/', ' ', self::fill( $cfg['subject'], $vars ) ) );
		$heading   = trim( preg_replace( '/[\r\n]+/', ' ', self::fill( $cfg['heading'], $vars ) ) );
		$message   = self::render(
			array(
				'heading'         => $heading,
				'body'            => self::fill( $cfg['body'], $vars ),
				'extra_html'      => $opts['extra_html'] ?? '',
				'marketing'       => $marketing,
				'unsubscribe_url' => $unsub,
			)
		);
		$headers = "Content-Type: text/html; charset=UTF-8\r\n";
		if ( $marketing ) {
			$headers .= 'List-Unsubscribe: <' . esc_url_raw( $unsub ) . ">\r\n";
			$headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
		}
		return (bool) WC()->mailer()->send( $to, $subject, $message, $headers );
	}
}
