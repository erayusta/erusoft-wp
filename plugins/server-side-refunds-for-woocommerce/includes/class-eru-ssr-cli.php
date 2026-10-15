<?php
/**
 * wp eru-ssr queue status|run|retry|purge
 *
 * @package server-side-refunds-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage the refund queue.
 */
class Eru_SSR_CLI {

	/**
	 * Queue maintenance.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : status, run, retry or purge.
	 *
	 * [--id=<id>]
	 * : Row id for retry (default: every failed row).
	 *
	 * ## EXAMPLES
	 *
	 *     wp eru-ssr queue status
	 *     wp eru-ssr queue run
	 *     wp eru-ssr queue retry --id=12
	 *     wp eru-ssr queue purge
	 *
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc ) {
		switch ( $args[0] ?? '' ) {
			case 'status':
				$c = Eru_SSR_Queue::counts();
				WP_CLI\Utils\format_items( 'table', array( $c ), array( 'pending', 'sent', 'failed' ) );
				break;
			case 'run':
				$s = Eru_SSR_Queue::run();
				WP_CLI::success( sprintf( 'sent %d, retry %d, failed %d', $s['sent'], $s['retry'], $s['failed'] ) );
				break;
			case 'retry':
				$n = Eru_SSR_Queue::retry( isset( $assoc['id'] ) ? (int) $assoc['id'] : null );
				WP_CLI::success( sprintf( '%d row(s) re-queued.', $n ) );
				break;
			case 'purge':
				WP_CLI::success( sprintf( '%d row(s) deleted.', Eru_SSR_Queue::purge() ) );
				break;
			default:
				WP_CLI::error( 'Use: status | run | retry [--id=] | purge' );
		}
	}
}
