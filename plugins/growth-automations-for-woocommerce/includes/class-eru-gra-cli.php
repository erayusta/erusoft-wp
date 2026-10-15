<?php
/**
 * WP-CLI: wp eru-gra run <automation> [--dry-run]
 *
 * @package growth-automations-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run an automation by hand.
 */
class Eru_GRA_CLI {

	/**
	 * Runs an automation until nothing is due. Without --dry-run the automation must be switched on in the settings.
	 *
	 * ## OPTIONS
	 *
	 * <automation>
	 * : bacs, cart, winback or review.
	 *
	 * [--dry-run]
	 * : List what would be sent (masked addresses) and change nothing. Only the first batch is listed.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eru-gra run winback --dry-run
	 *
	 * @param array $args       Positional.
	 * @param array $assoc_args Flags.
	 */
	public function run( $args, $assoc_args ) {
		$key = $args[0] ?? '';
		if ( ! in_array( $key, Eru_GRA_Settings::RUNNABLE, true ) ) {
			WP_CLI::error( 'Unknown automation. Use: ' . implode( ', ', Eru_GRA_Settings::RUNNABLE ) );
		}
		if ( ! empty( $assoc_args['dry-run'] ) ) {
			$r = Eru_GRA_Runner::run( $key, true );
			foreach ( $r['items'] as $item ) {
				WP_CLI::line( $item['email'] . "\t" . $item['result'] );
			}
			WP_CLI::success( sprintf( '%d due in the first batch (dry run, nothing changed).', $r['processed'] ) );
			return;
		}
		if ( ! Eru_GRA_Settings::is_on( $key ) ) {
			WP_CLI::error( 'This automation is switched off in WooCommerce > Büyüme Otomasyonları.' );
		}
		$total = 0;
		$sent  = 0;
		for ( $i = 0; $i < 1000; $i++ ) {
			$r      = Eru_GRA_Runner::run( $key );
			$total += $r['processed'];
			$sent  += $r['sent'];
			if ( ! $r['more'] ) {
				break;
			}
		}
		WP_CLI::success( sprintf( '%d handled, %d sent.', $total, $sent ) );
	}
}
