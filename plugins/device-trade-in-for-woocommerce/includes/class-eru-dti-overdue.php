<?php
/**
 * Daily job: overdue reminders and retention purge.
 *
 * @package device-trade-in-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Action Scheduler daily action in group eru-dti.
 */
class Eru_DTI_Overdue {

	const HOOK  = 'eru_dti_daily';
	const GROUP = 'eru-dti';

	/** Hooks. */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'daily' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 30 );
	}

	/**
	 * Schedules the daily action while a target is enabled or trade-in/lead rows exist (retention must keep purging
	 * personal data after every product was disabled); removes it otherwise.
	 */
	public static function maybe_schedule() {
		if ( ! function_exists( 'as_has_scheduled_action' ) || ! did_action( 'action_scheduler_init' ) ) {
			return;
		}
		$has = as_has_scheduled_action( self::HOOK, array(), self::GROUP );
		if ( Eru_DTI_Settings::has_targets() || get_option( Eru_DTI_DB::DATA_FLAG ) ) {
			if ( ! $has ) {
				as_schedule_recurring_action( time() + HOUR_IN_SECONDS, DAY_IN_SECONDS, self::HOOK, array(), self::GROUP );
			}
		} elseif ( $has ) {
			as_unschedule_all_actions( self::HOOK, array(), self::GROUP );
		}
	}

	/** Entry point of the scheduled action. */
	public static function daily() {
		if ( get_transient( 'eru_dti_lock' ) ) {
			return;
		}
		set_transient( 'eru_dti_lock', 1, 10 * MINUTE_IN_SECONDS );
		try {
			self::run();
			self::purge();
			if ( ! Eru_DTI_DB::has_rows() ) {
				delete_option( Eru_DTI_DB::DATA_FLAG );
			}
		} finally {
			delete_transient( 'eru_dti_lock' );
		}
	}

	/** Reminds about pending trade-ins older than the configured days; each row only once. */
	public static function run() {
		$days = (int) Eru_DTI_Settings::get( 'overdue_days' );
		if ( $days <= 0 ) {
			return;
		}
		global $wpdb;
		$t    = Eru_DTI_DB::table( 'tradeins' );
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $t WHERE status = 'beklemede' AND reminded_at IS NULL AND created_at < %s ORDER BY id ASC LIMIT 100", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL
		foreach ( $rows as $row ) {
			$claimed = $wpdb->query( $wpdb->prepare( "UPDATE $t SET reminded_at = %s WHERE id = %d AND reminded_at IS NULL", gmdate( 'Y-m-d H:i:s' ), (int) $row['id'] ) ); // phpcs:ignore WordPress.DB.PreparedSQL
			if ( ! $claimed ) {
				continue;
			}
			self::remind( $row );
		}
	}

	/** @param array $row Trade-in row. */
	private static function remind( array $row ) {
		$order    = $row['order_id'] ? wc_get_order( (int) $row['order_id'] ) : null;
		$number   = $order ? $order->get_order_number() : (string) $row['id'];
		$mailer   = WC()->mailer();
		$team     = Eru_DTI_Settings::get( 'notify_email' );
		$team     = is_email( $team ) ? $team : get_option( 'admin_email' );
		$days     = (int) Eru_DTI_Settings::get( 'overdue_days' );
		$subject  = sprintf( /* translators: %s: order number. */ __( '#%s numaralı siparişteki takas cihazı bekleniyor', 'device-trade-in-for-woocommerce' ), $number );
		$ship     = self::instructions( $number );
		$cust     = '<p>' . esc_html( sprintf( /* translators: %s: order number. */ __( '#%s numaralı siparişinizdeki takas cihazı henüz bize ulaşmadı.', 'device-trade-in-for-woocommerce' ), $number ) ) . '</p>';
		if ( '' !== $ship ) {
			$cust .= '<p>' . nl2br( esc_html( $ship ) ) . '</p>';
		}
		if ( is_email( $row['customer_email'] ) ) {
			$mailer->send( $row['customer_email'], $subject, $mailer->wrap_message( $subject, $cust ) );
		}
		$adm = '<p>' . esc_html( sprintf( /* translators: 1: order number, 2: days. */ __( '#%1$s numaralı siparişin takas cihazı %2$d günden uzun süredir beklemede.', 'device-trade-in-for-woocommerce' ), $number, $days ) ) . '</p>';
		$mailer->send( $team, $subject, $mailer->wrap_message( $subject, $adm ) );
	}

	/** @param string $number Order number. @return string Filled shipping instructions. */
	public static function instructions( $number ) {
		$text = trim( (string) Eru_DTI_Settings::get( 'ship_text' ) );
		if ( '' === $text ) {
			return '';
		}
		return Eru_DTI_Settings::fill(
			$text,
			array(
				'{days}'      => (string) Eru_DTI_Settings::get( 'overdue_days' ),
				'{order}'     => (string) $number,
				'{reference}' => (string) Eru_DTI_Settings::get( 'ship_reference' ),
			)
		);
	}

	/** Deletes old leads and decided trade-ins past the retention period. */
	public static function purge() {
		global $wpdb;
		$cut = gmdate( 'Y-m-d H:i:s', time() - max( 1, (int) Eru_DTI_Settings::get( 'retention_days' ) ) * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Eru_DTI_DB::table( 'leads' ) . ' WHERE created_at < %s', $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . Eru_DTI_DB::table( 'tradeins' ) . " WHERE status IN ('onaylandi','reddedildi') AND created_at < %s", $cut ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
