<?php
/**
 * Plugin Name: HTF — Plugin Diet
 * Plugin URI: https://wordpress.erusoft.com/high-traffic-fixes-for-woocommerce/
 * Description: Does not load admin/cron/CLI-only plugins on front-end page requests. You supply the list; it is empty by default.
 * Version: 1.0.0
 * Author: Erusoft
 * Author URI: https://erusoft.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 6.5
 * Text Domain: high-traffic-fixes-for-woocommerce
 *
 * WHY: some plugins only do work in the admin, in cron or in WP-CLI (importers/exporters,
 * translation editors, license managers, report builders, feed generators that write
 * static files). WordPress still includes their code on every front-end request; a heavy
 * one can add tens of milliseconds per request. This file removes the plugins you list
 * from the `active_plugins` option ONLY for front-end requests. Plugin files are not
 * touched and the change is not persisted.
 *
 * RULES ARE YOURS: the list is empty by default, so installing this file changes nothing.
 * Provide it from a mu-plugin or wp-config-level code:
 *
 *     add_filter( 'eru_htf_plugin_diet_rules', static function ( $rules ) {
 *         return array_merge( $rules, array(
 *             'example-importer/example-importer.php',
 *             'example-translation-editor/example-translation-editor.php',
 *         ) );
 *     } );
 *
 * Entries are plugin basenames exactly as they appear in the `active_plugins` option
 * (`folder/main-file.php`). BEFORE adding a plugin, scan it for hooks that matter on the
 * front end (`wp_head`, `wp_footer`, `wp_enqueue_scripts`, `template_redirect`, thank-you
 * page hooks, shortcodes, REST routes, ordering/query filters). A plugin with any
 * front-end hook must NOT be listed. Plugins that change their storage model between
 * versions (for example moving sort order out of `menu_order`) can silently become
 * front-end dependent after an update - re-check after major updates.
 *
 * ALWAYS EXEMPT: wp-cron, WP-CLI, the admin (admin-ajax included), REST requests,
 * xmlrpc.php, wp-login.php, and requests carrying import/export trigger parameters
 * (import_key, export_key, import_id, export_id).
 *
 * OPTIONAL EXEMPTION REMOVAL (high-traffic tracking endpoints): browser-triggered,
 * output-less tracking endpoints can arrive at hundreds of requests per minute and each
 * one bootstraps the full plugin list. For the endpoints below the REST/admin-ajax
 * exemption is lifted so the diet applies there too:
 *   - REST routes ending in /pys-facebook/v1/event, /pys-tiktok/v1/event,
 *     /pys-pinterest/v1/event (PixelYourSite) and /gtm4wp/v2/visitor-data (GTM4WP)
 *   - admin-ajax action pys_get_pbid (PixelYourSite)
 * Both lists are filterable: `eru_htf_plugin_diet_tracking_endpoints` (path suffixes) and
 * `eru_htf_plugin_diet_tracking_ajax_actions` (action names). Only add an endpoint when no
 * listed plugin hooks into it.
 *
 * CRITICAL SAFETY - THE WRITE GUARD: `option_active_plugins` is a READ filter, but any code
 * that writes the option first reads it, changes it and calls update_option(). On the front
 * end the list it reads is our SHORTENED one, so a license manager, a self-deactivating
 * plugin, etc. would persist the shortened list and silently deactivate every listed plugin
 * in the database. The write guard remembers what was removed in this request and puts the
 * missing entries back before the option is saved; the writer's own change is preserved. A
 * real "Deactivate" click in the admin is unaffected because the read filter never runs
 * there. When the guard has to restore entries it logs one line to the PHP error log so the
 * writing code can be found and exempted.
 *
 * Disable: add_filter( 'eru_htf_plugin_diet_enabled', '__return_false' );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugins that must not be loaded on front-end requests (default: none).
 *
 * @return string[] Plugin basenames.
 */
function eru_htf_plugin_diet_rules() {
	$rules = apply_filters( 'eru_htf_plugin_diet_rules', array() );

	return is_array( $rules ) ? array_values( array_filter( $rules, 'is_string' ) ) : array();
}

/**
 * Is this a browser-triggered, output-less tracking REST endpoint?
 *
 * The path must END with a listed suffix (query string ignored), so no unrelated REST
 * route is caught by accident.
 *
 * @param string $uri REQUEST_URI.
 * @return bool
 */
function eru_htf_plugin_diet_is_tracking_endpoint( $uri ) {
	$path = rtrim( (string) strtok( (string) $uri, '?' ), '/' );

	$targets = apply_filters(
		'eru_htf_plugin_diet_tracking_endpoints',
		array(
			'/pys-facebook/v1/event',
			'/pys-tiktok/v1/event',
			'/pys-pinterest/v1/event',
			'/gtm4wp/v2/visitor-data',
		)
	);

	foreach ( (array) $targets as $target ) {
		$target = (string) $target;
		if ( '' !== $target && strlen( $path ) >= strlen( $target ) && substr( $path, -strlen( $target ) ) === $target ) {
			return true;
		}
	}

	return false;
}

/**
 * Is this a browser-triggered tracking admin-ajax action?
 *
 * admin-ajax is NORMALLY fully exempt (`is_admin()` is true there) and must stay so: the
 * admin's own AJAX calls may need the listed plugins. Only whitelisted, visitor-triggered
 * actions unrelated to admin work are lifted.
 *
 * @return bool
 */
function eru_htf_plugin_diet_is_tracking_ajax() {
	if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
		return false;
	}

	$action = '';
	if ( isset( $_REQUEST['action'] ) && is_string( $_REQUEST['action'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		$action = sanitize_key( wp_unslash( $_REQUEST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	$actions = apply_filters( 'eru_htf_plugin_diet_tracking_ajax_actions', array( 'pys_get_pbid' ) );

	return in_array( $action, (array) $actions, true );
}

/** Plugins actually removed in this request; used by the write guard. */
$GLOBALS['eru_htf_plugin_diet_hidden'] = array();

add_filter(
	'option_active_plugins',
	static function ( $plugins ) {
		if ( ! is_array( $plugins ) ) {
			return $plugins;
		}

		if ( ! apply_filters( 'eru_htf_plugin_diet_enabled', true ) ) {
			return $plugins;
		}

		$rules = eru_htf_plugin_diet_rules();
		if ( empty( $rules ) ) {
			return $plugins;
		}

		// Cron and CLI are always exempt.
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return $plugins;
		}

		// Admin is exempt - ONE EXCEPTION: whitelisted tracking actions (is_admin() is
		// true in admin-ajax).
		if ( is_admin() && ! eru_htf_plugin_diet_is_tracking_ajax() ) {
			return $plugins;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		// REST is exempt by default - ONE EXCEPTION: tracking endpoints.
		if ( 0 === strpos( $uri, '/wp-json' ) && ! eru_htf_plugin_diet_is_tracking_endpoint( $uri ) ) {
			return $plugins;
		}
		if ( false !== strpos( $uri, 'xmlrpc.php' ) || false !== strpos( $uri, 'wp-login.php' ) ) {
			return $plugins;
		}

		// Defensive: external import/export trigger URLs arrive in a front-end context.
		// If one of these signatures is present, leave the list alone.
		if ( isset( $_GET['import_key'] ) || isset( $_GET['export_key'] ) || isset( $_GET['import_id'] ) || isset( $_GET['export_id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return $plugins;
		}

		$hidden = array_intersect( $plugins, $rules );
		if ( empty( $hidden ) ) {
			return $plugins;
		}

		$GLOBALS['eru_htf_plugin_diet_hidden'] = array_values(
			array_unique( array_merge( $GLOBALS['eru_htf_plugin_diet_hidden'], $hidden ) )
		);

		return array_values( array_diff( $plugins, $hidden ) );
	}
);

/**
 * WRITE GUARD - keeps the shortened list from being persisted.
 *
 * Code that rewrites the list on the front end never saw the hidden plugins, so it tries
 * to save an array without them. Hidden entries are added back here; the writer's own
 * change (adding/removing some other plugin) is kept as is.
 */
add_filter(
	'pre_update_option_active_plugins',
	static function ( $new_value, $old_value = null ) {
		if ( ! is_array( $new_value ) || empty( $GLOBALS['eru_htf_plugin_diet_hidden'] ) ) {
			return $new_value;
		}

		$restore = array_diff( $GLOBALS['eru_htf_plugin_diet_hidden'], $new_value );
		if ( empty( $restore ) ) {
			return $new_value;
		}

		// Not silent: seeing this line means some front-end code writes the list. Find it
		// and, if needed, add an exemption.
		error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			'[eru-htf-plugin-diet] active_plugins was rewritten on the front end; restored: '
			. implode( ', ', $restore )
			. ' | path: ' . strtok( isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '-', '?' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);

		return array_values( array_unique( array_merge( $new_value, $restore ) ) );
	},
	10,
	2
);
