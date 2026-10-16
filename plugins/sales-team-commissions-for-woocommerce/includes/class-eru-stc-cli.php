<?php
/**
 * WP-CLI: wp eru-stc recalc | approve-due | report.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sales Team Commissions commands.
 */
class Eru_STC_CLI {

	/**
	 * Recalculates the commission rows of orders: fills line/level slots that have no live row (a slot that
	 * already holds a row, for any agent, is never paid twice), then re-applies the order's refunds.
	 *
	 * Use it for an order that had no agent (or no matching rule) when it reached the trigger status: link
	 * the customer to an agent first, or pass --agent to stamp the order. Rules are evaluated at the order's
	 * creation date with today's hierarchy.
	 *
	 * ## OPTIONS
	 *
	 * <order>...
	 * : Order ids.
	 *
	 * [--agent=<agent_id>]
	 * : Attribute the orders to this active agent first (writes the order's agent meta).
	 *
	 * [--dry-run]
	 * : Show the rows that would be written; change nothing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eru-stc recalc 1234
	 *     wp eru-stc recalc 1234 1235 --agent=7 --dry-run
	 *
	 * @param array $args  Order ids.
	 * @param array $assoc Options.
	 */
	public function recalc( $args, $assoc ) {
		$agent_id = isset( $assoc['agent'] ) ? absint( $assoc['agent'] ) : 0;
		$dry      = ! empty( $assoc['dry-run'] );
		if ( $agent_id && ! Eru_STC_Agents::is_active( Eru_STC_Agents::get( $agent_id ) ) ) {
			WP_CLI::error( sprintf( 'Agent %d not found or not active.', $agent_id ) );
		}
		$trigger = Eru_STC_Settings::get( 'trigger_status' );
		$failed  = 0;
		foreach ( $args as $raw ) {
			$order = wc_get_order( absint( $raw ) );
			if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
				WP_CLI::warning( sprintf( 'Order %s not found.', $raw ) );
				++$failed;
				continue;
			}
			$ok_status = 'completed' === $trigger ? $order->has_status( 'completed' ) : $order->has_status( array( 'processing', 'completed' ) );
			if ( ! $ok_status ) {
				WP_CLI::warning( sprintf( 'Order %d is "%s"; commissions are written at "%s".', $order->get_id(), $order->get_status(), $trigger ) );
				++$failed;
				continue;
			}
			if ( $agent_id && Eru_STC_Calculator::level1_conflict( $order->get_id(), $agent_id ) ) {
				WP_CLI::warning( sprintf( 'Order %d already has live commission rows of another level-1 agent: void those rows first, then recalculate.', $order->get_id() ) );
				++$failed;
				continue;
			}
			if ( $agent_id && ! $dry ) {
				$order->update_meta_data( '_eru_stc_agent_id', $agent_id );
				$order->update_meta_data( '_eru_stc_source', 'manual' );
				$order->save();
			}
			if ( $dry ) {
				$probe = $agent_id ? fn() => $agent_id : null;
				if ( $probe ) {
					add_filter( 'eru_stc_order_agent', $probe, 99 );
				}
				$plan = Eru_STC_Calculator::plan( $order );
				if ( $probe ) {
					remove_filter( 'eru_stc_order_agent', $probe, 99 );
				}
				if ( ! $plan ) {
					WP_CLI::log( sprintf( 'Order %d: no rows (no active agent or no matching rule).', $order->get_id() ) );
					continue;
				}
				WP_CLI\Utils\format_items( 'table', $plan, array( 'item_id', 'agent_id', 'level', 'rule_id', 'basis', 'amount' ) );
				continue;
			}
			if ( Eru_STC_Calculator::agent_for_order( $order ) <= 0 ) {
				WP_CLI::warning( sprintf( 'Order %d has no agent: link the customer to an agent or pass --agent=<id>.', $order->get_id() ) );
				++$failed;
				continue;
			}
			$ids = Eru_STC_Calculator::calculate( $order, true );
			Eru_STC_Refunds::reconcile( $order->get_id(), 'refund' );
			WP_CLI::log( sprintf( 'Order %d: %d new row(s).', $order->get_id(), count( $ids ) ) );
		}
		if ( $failed ) {
			WP_CLI::error( sprintf( '%d order(s) skipped.', $failed ) );
		}
		WP_CLI::success( 'Done.' );
	}

	/**
	 * Approves pending rows whose hold has passed, batch by batch, now (also while the module is off).
	 *
	 * ## OPTIONS
	 *
	 * [--max-batches=<n>]
	 * : Stop after this many batches.
	 * ---
	 * default: 50
	 * ---
	 *
	 * @subcommand approve-due
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Options.
	 */
	public function approve_due( $args, $assoc ) {
		$max   = max( 1, (int) ( $assoc['max-batches'] ?? 50 ) );
		$total = 0;
		for ( $i = 0; $i < $max; $i++ ) {
			$n      = Eru_STC_Eligibility::run();
			$total += $n;
			if ( 0 === $n ) {
				break;
			}
		}
		WP_CLI::success( sprintf( '%d row(s) approved.', $total ) );
	}

	/**
	 * Totals per agent for a month (site time zone).
	 *
	 * ## OPTIONS
	 *
	 * [--month=<YYYY-MM>]
	 * : Month (default: this month).
	 *
	 * [--agent=<agent_id>]
	 * : Only this agent.
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp eru-stc report --month=2026-09
	 *     wp eru-stc report --month=2026-09 --format=csv > rapor.csv
	 *
	 * @param array $args  Unused.
	 * @param array $assoc Options.
	 */
	public function report( $args, $assoc ) {
		$month = (string) ( $assoc['month'] ?? '' );
		if ( '' !== $month && 1 !== preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month ) ) {
			WP_CLI::error( 'Use --month=YYYY-MM.' );
		}
		$agent = absint( $assoc['agent'] ?? 0 );
		if ( 'csv' === ( $assoc['format'] ?? 'table' ) ) {
			echo Eru_STC_Reports::agents_csv( $month, $agent ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV to stdout, cells neutralised.
			return;
		}
		list( $from, $to, $month ) = Eru_STC_Reports::month_range( $month );
		$rows                      = Eru_STC_Reports::by_agent( $from, $to, $agent );
		if ( ! $rows ) {
			WP_CLI::log( sprintf( 'No commissions in %s.', $month ) );
			return;
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'agent_id', 'label', 'code', 'orders', 'total', 'pending', 'approved', 'paid' ) );
	}
}
