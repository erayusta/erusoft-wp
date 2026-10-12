<?php
/**
 * Plugin Name: HTF — Category Rewrite Flush
 * Plugin URI: https://wordpress.erusoft.com/high-traffic-fixes-for-woocommerce/
 * Description: Runs Rank Math's product category rewrite flush only when a category edit changes its slug or parent, avoiding a full object cache flush.
 * Version: 1.0.0
 * Author: Erusoft
 * Author URI: https://erusoft.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Requires PHP: 8.0
 * Requires at least: 6.5
 * Text Domain: high-traffic-fixes-for-woocommerce
 *
 * PROBLEM: saving a product category makes Rank Math's WooCommerce Permalink_Watcher
 * schedule flush_rewrite_rules() on shutdown. While the rules are rebuilt, the
 * 'rewrite_rules_array' filter reaches Permalink_Watcher::add_rewrite_rules(), which calls
 * wp_cache_flush(). With Redis and no selective flush configured that is a raw FLUSHDB: the
 * whole object cache is emptied (hundreds of thousands of keys on a large catalogue), Redis
 * blocks for a while, and a cold-cache storm follows.
 *
 * WHY RANK MATH'S wp_cache_flush() IS NOT REMOVED: that flush is there on purpose, so the
 * get_terms() call right after it sees a fresh category list. Removing it blindly would
 * leave a newly added or renamed category out of the rules and its URL would 404.
 *
 * FIX - CUT THE CHAIN HIGHER UP: a category's rewrite rule depends on exactly two things,
 * its slug and its parent. Editing the name, description, image, display type or SEO title
 * changes no rule. For those edits the flush is never started, so wp_cache_flush() never
 * runs, and the object cache layer is not touched at all.
 *
 * WHEN IN DOUBT, FLUSH: if the previous state could not be captured, the term cannot be
 * read, or the slug / parent changed, Rank Math's own flush callback is called unchanged.
 * Category creation and deletion hooks are not touched; they always flush.
 *
 * Does nothing without Rank Math (no matching callback is found on the hook).
 *
 * Disable: add_filter( 'eru_htf_category_rewrite_flush_enabled', '__return_false' );
 *
 * When the Redis Flush Tracer file is also installed, skipped flushes are written to its
 * log (a verification aid, not a permanent log).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Slug/parent snapshots taken before an edit, keyed by term ID. */
$GLOBALS['eru_htf_category_previous_state'] = array();

/**
 * Store the current slug and parent right BEFORE the term is updated.
 *
 * `edit_terms` fires in core before $wpdb->update, so what is read here is the old,
 * unsaved state.
 */
add_action(
	'edit_terms',
	static function ( $term_id, $taxonomy ) {
		if ( 'product_cat' !== $taxonomy ) {
			return;
		}

		if ( ! apply_filters( 'eru_htf_category_rewrite_flush_enabled', true ) ) {
			return;
		}

		$term = get_term( $term_id, $taxonomy );

		if ( $term && ! is_wp_error( $term ) ) {
			$GLOBALS['eru_htf_category_previous_state'][ (int) $term_id ] = array(
				'slug'   => $term->slug,
				'parent' => (int) $term->parent,
			);
		}

		// Rank Math is certainly loaded at this point; taking over the hook now avoids
		// depending on module load order.
		eru_htf_category_take_over_hook();
	},
	1,
	2
);

/**
 * Replace Rank Math's `edited_product_cat` -> flush_rules hook with a conditional wrapper.
 *
 * Matching is by class, so a renamed method in a later release cannot make us remove the
 * wrong callback. Runs once.
 */
function eru_htf_category_take_over_hook() {
	static $done = false;

	if ( $done ) {
		return;
	}

	global $wp_filter;

	if ( empty( $wp_filter['edited_product_cat'] ) || ! is_object( $wp_filter['edited_product_cat'] ) ) {
		return;
	}

	foreach ( $wp_filter['edited_product_cat']->callbacks as $priority => $callbacks ) {
		foreach ( $callbacks as $callback ) {
			$fn = $callback['function'];

			if ( ! is_array( $fn ) || ! isset( $fn[0], $fn[1] ) || ! is_object( $fn[0] ) ) {
				continue;
			}

			if ( 'RankMath\\WooCommerce\\Permalink_Watcher' !== get_class( $fn[0] ) ) {
				continue;
			}

			$watcher = $fn[0];
			$method  = $fn[1];

			remove_action( 'edited_product_cat', $fn, $priority );

			add_action(
				'edited_product_cat',
				static function ( $term_id ) use ( $watcher, $method ) {
					if ( ! eru_htf_category_url_structure_changed( $term_id ) ) {
						eru_htf_category_log_skipped_flush( $term_id );
						return;
					}

					call_user_func( array( $watcher, $method ), $term_id );
				},
				$priority,
				1
			);

			$done = true;
		}
	}
}

/**
 * Did this edit affect the category's URL?
 *
 * Only slug and parent enter the rewrite rules. Every uncertain case returns true (flush):
 * the cost of a wrong "true" is one needless flush, the cost of a wrong "false" is a 404.
 *
 * @param int $term_id Term ID.
 * @return bool
 */
function eru_htf_category_url_structure_changed( $term_id ) {
	$term_id = (int) $term_id;

	if ( empty( $GLOBALS['eru_htf_category_previous_state'][ $term_id ] ) ) {
		return true; // Previous state not captured.
	}

	$previous = $GLOBALS['eru_htf_category_previous_state'][ $term_id ];
	$term     = get_term( $term_id, 'product_cat' );

	if ( ! $term || is_wp_error( $term ) ) {
		return true;
	}

	return $term->slug !== $previous['slug'] || (int) $term->parent !== (int) $previous['parent'];
}

/**
 * Write skipped flushes to the Redis Flush Tracer log as a single line.
 *
 * Only active while the tracer file is loaded (it defines the log constant); without it
 * this is silent.
 *
 * @param int $term_id Term ID.
 */
function eru_htf_category_log_skipped_flush( $term_id ) {
	if ( ! defined( 'ERU_HTF_REDIS_FLUSH_TRACER_LOG' ) ) {
		return;
	}

	$max = defined( 'ERU_HTF_REDIS_FLUSH_TRACER_MAX' ) ? (int) ERU_HTF_REDIS_FLUSH_TRACER_MAX : 5242880;

	if ( file_exists( ERU_HTF_REDIS_FLUSH_TRACER_LOG ) && filesize( ERU_HTF_REDIS_FLUSH_TRACER_LOG ) > $max ) {
		return;
	}

	if ( ! is_writable( file_exists( ERU_HTF_REDIS_FLUSH_TRACER_LOG ) ? ERU_HTF_REDIS_FLUSH_TRACER_LOG : dirname( ERU_HTF_REDIS_FLUSH_TRACER_LOG ) ) ) {
		return;
	}

	$term = get_term( (int) $term_id, 'product_cat' );

	error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		sprintf(
			"----- %s | flush SKIPPED | category #%d (%s) | slug and parent unchanged\n",
			function_exists( 'wp_date' ) ? wp_date( 'Y-m-d H:i:s' ) : gmdate( 'Y-m-d H:i:s' ),
			(int) $term_id,
			( $term && ! is_wp_error( $term ) ) ? $term->slug : '?'
		),
		3,
		ERU_HTF_REDIS_FLUSH_TRACER_LOG
	);
}
