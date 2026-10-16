<?php
/**
 * Keeps the campaign editor and the plugin's pages free of other plugins' admin scripts and styles,
 * which often break the wizard's selects or slow the screen down. On these screens only core assets,
 * WooCommerce's admin assets and this plugin's own stay; other handles are dequeued.
 *
 * Filter `eru_ce_admin_asset_allowlist` (array of handle prefixes) keeps more.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Admin;

final class ScreenCleanup {

	/** Handle prefixes kept by default. */
	private const DEFAULT_ALLOW = array( 'eru-ce-', 'woocommerce', 'wc-', 'select2', 'selectWoo', 'jquery', 'wp-', 'admin-', 'common', 'utils', 'media', 'thickbox', 'dashicons', 'buttons', 'forms', 'edit', 'colors', 'ie', 'heartbeat', 'autosave', 'post', 'postbox', 'tags-box', 'word-count', 'svg-painter', 'underscore', 'backbone', 'hoverIntent' );

	public static function hooks(): void {
		add_action( 'admin_enqueue_scripts', array( self::class, 'clean' ), PHP_INT_MAX );
	}

	public static function clean(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! $screen || ! Menu::isPluginScreen( $screen ) ) {
			return;
		}

		/**
		 * Filters the handle prefixes kept on the campaign screens.
		 *
		 * @param string[] $prefixes Default: core, WooCommerce and this plugin.
		 */
		$allow = array_values( array_filter( array_map( 'strval', (array) apply_filters( 'eru_ce_admin_asset_allowlist', self::DEFAULT_ALLOW ) ) ) );

		foreach ( array( wp_scripts(), wp_styles() ) as $deps ) {
			foreach ( (array) $deps->queue as $handle ) {
				$src = isset( $deps->registered[ $handle ] ) ? (string) $deps->registered[ $handle ]->src : '';

				if ( ! self::allowed( (string) $handle, $src, $allow ) ) {
					$deps->dequeue( $handle );
				}
			}
		}
	}

	/** Core files, handles with an allowed prefix and source-less handles are kept. */
	public static function allowed( string $handle, string $src, array $allow ): bool {
		if ( '' === $src || 0 === strpos( $src, '/wp-includes/' ) || 0 === strpos( $src, '/wp-admin/' ) || 0 === strpos( $src, includes_url() ) || 0 === strpos( $src, admin_url() ) ) {
			return true;
		}

		foreach ( $allow as $prefix ) {
			if ( 0 === strpos( $handle, $prefix ) ) {
				return true;
			}
		}

		return false;
	}
}
