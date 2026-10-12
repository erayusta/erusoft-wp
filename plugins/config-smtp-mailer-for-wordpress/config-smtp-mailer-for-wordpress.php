<?php
/**
 * Plugin Name:       Config SMTP Mailer for WordPress
 * Plugin URI:        https://wordpress.erusoft.com/config-smtp-mailer-for-wordpress/
 * Description:       wp_mail() gönderimini wp-config.php sabitlerinden okunan SMTP ayarlarıyla yapar ve HTML e-postalara düz metin alternatifi ekler. Sabit tanımlı değilse hiçbir şey yapmaz.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Erusoft
 * Author URI:        https://erusoft.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       config-smtp-mailer-for-wordpress
 * Domain Path:       /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'ERU_SMTP_VERSION', '1.0.0' );

/**
 * wp-config.php sabitlerinden SMTP yapılandırmasını okur.
 *
 * Zorunlu: ERU_SMTP_HOST. Kimlik doğrulama yalnızca ERU_SMTP_USER tanımlıysa açılır.
 * Sabit yoksa null döner ve eklenti hiçbir şeye dokunmaz.
 *
 * @return array|null host, port, secure, user, pass, from, from_name
 */
function eru_smtp_config(): ?array {
	if ( ! defined( 'ERU_SMTP_HOST' ) || ! is_string( ERU_SMTP_HOST ) || '' === trim( ERU_SMTP_HOST ) ) {
		return null;
	}

	$port   = defined( 'ERU_SMTP_PORT' ) ? absint( ERU_SMTP_PORT ) : 587;
	$port   = ( $port > 0 && $port < 65536 ) ? $port : 587;
	$secure = defined( 'ERU_SMTP_SECURE' ) ? strtolower( trim( (string) ERU_SMTP_SECURE ) ) : '';
	if ( ! in_array( $secure, array( 'ssl', 'tls', 'none' ), true ) ) {
		$secure = ( 465 === $port ) ? 'ssl' : 'tls';
	}

	$user = defined( 'ERU_SMTP_USER' ) ? (string) ERU_SMTP_USER : '';
	$from = defined( 'ERU_SMTP_FROM' ) ? sanitize_email( (string) ERU_SMTP_FROM ) : '';
	if ( '' === $from && is_email( $user ) ) {
		$from = $user;
	}

	$config = array(
		'host'      => trim( ERU_SMTP_HOST ),
		'port'      => $port,
		'secure'    => $secure,
		'user'      => $user,
		'pass'      => defined( 'ERU_SMTP_PASS' ) ? (string) ERU_SMTP_PASS : '',
		'from'      => $from,
		'from_name' => defined( 'ERU_SMTP_FROM_NAME' ) ? sanitize_text_field( (string) ERU_SMTP_FROM_NAME ) : '',
	);

	/**
	 * SMTP yapılandırmasını değiştirir veya (null ile) iptal eder.
	 *
	 * @param array|null $config Yapılandırma dizisi.
	 */
	return apply_filters( 'eru_smtp_config', $config );
}

add_action(
	'phpmailer_init',
	function ( $phpmailer ) {
		$config = eru_smtp_config();
		if ( ! is_array( $config ) ) {
			return;
		}

		$phpmailer->isSMTP();
		$phpmailer->Host       = $config['host'];
		$phpmailer->Port       = $config['port'];
		$phpmailer->SMTPSecure = ( 'none' === $config['secure'] ) ? '' : $config['secure'];
		$phpmailer->SMTPAutoTLS = ( 'none' !== $config['secure'] );
		$phpmailer->CharSet    = 'UTF-8';

		if ( '' !== $config['user'] ) {
			$phpmailer->SMTPAuth = true;
			$phpmailer->Username = $config['user'];
			$phpmailer->Password = $config['pass'];
		}

		if ( '' !== $config['from'] ) {
			$name = '' !== $config['from_name'] ? $config['from_name'] : (string) get_option( 'woocommerce_email_from_name', get_bloginfo( 'name' ) );
			$phpmailer->setFrom( $config['from'], wp_specialchars_decode( $name, ENT_QUOTES ), false );
			$phpmailer->Sender = $config['from']; // Return-Path: SPF/DKIM hizalaması için.
		}
	}
);

add_filter(
	'wp_mail_from',
	function ( $from ) {
		$config = eru_smtp_config();
		return ( is_array( $config ) && '' !== $config['from'] ) ? $config['from'] : $from;
	}
);

add_filter(
	'wp_mail_from_name',
	function ( $name ) {
		$config = eru_smtp_config();
		return ( is_array( $config ) && '' !== $config['from_name'] ) ? $config['from_name'] : $name;
	}
);

/**
 * HTML e-postalara düz metin alternatifi (multipart/alternative) ekler.
 *
 * Düz metin karşılığı olmayan HTML postalar spam filtrelerinden ceza alır. En geç çalışan
 * önceliktedir: daha erken ContentType/Body henüz hazır olmayabilir.
 */
add_action(
	'phpmailer_init',
	function ( $phpmailer ) {
		if ( ! is_array( eru_smtp_config() ) || ! apply_filters( 'eru_smtp_add_alt_body', true, $phpmailer ) ) {
			return;
		}
		if ( 'text/html' !== $phpmailer->ContentType || ! empty( $phpmailer->AltBody ) || empty( $phpmailer->Body ) ) {
			return;
		}

		$text = (string) $phpmailer->Body;
		$text = preg_replace( '#<(style|script|head)[^>]*>.*?</\1>#is', '', $text );
		// Bağlantıları "metin (adres)" biçimine çevir; düz metinde link kaybolmasın.
		$text = preg_replace_callback(
			'#<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)</a>#is',
			function ( $m ) {
				$label = trim( html_entity_decode( wp_strip_all_tags( $m[2] ), ENT_QUOTES, 'UTF-8' ) );
				$url   = trim( $m[1] );
				if ( '' === $label ) {
					return $url;
				}
				return ( str_starts_with( $url, 'mailto:' ) || $label === $url ) ? $label : "$label ($url)";
			},
			$text
		);
		$text = preg_replace( '#</(p|div|tr|h[1-6]|li)>#i', "\n", $text );
		$text = preg_replace( '#<br\s*/?>#i', "\n", $text );
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/", ' ', $text );
		$text = preg_replace( "/\n{3,}/", "\n\n", $text );

		$phpmailer->AltBody = trim( $text );
	},
	PHP_INT_MAX
);
