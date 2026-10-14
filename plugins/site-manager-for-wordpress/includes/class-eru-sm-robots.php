<?php
/** AI crawler rules appended to the virtual robots.txt. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SM_Robots {

	public static function init(): void {
		add_filter( 'robots_txt', array( __CLASS__, 'filter' ), 100000, 2 );
	}

	public static function filter( $output, $public = '1' ) {
		$s = Eru_SM_Settings::get( 'robots' );
		if ( empty( $s['enabled'] ) ) {
			return $output;
		}
		$output = (string) $output;
		$add    = '';
		foreach ( Eru_SM_Settings::BOTS as $bot => $owner ) {
			$v = $s['bots'][ $bot ] ?? 'default';
			if ( 'block' === $v ) {
				$add .= "\nUser-agent: $bot\nDisallow: /\n";
			} elseif ( 'allow' === $v ) {
				$add .= "\nUser-agent: $bot\nAllow: /\n";
			}
		}
		if ( ! empty( $s['sitemap'] ) && '0' !== (string) $public && function_exists( 'wp_sitemaps_get_server' ) && wp_sitemaps_get_server()->sitemaps_enabled() && false === stripos( $output, 'Sitemap:' ) ) {
			$add .= "\nSitemap: " . esc_url_raw( home_url( '/wp-sitemap.xml' ) ) . "\n";
		}
		return '' === $add ? $output : rtrim( $output ) . "\n" . $add;
	}
}
