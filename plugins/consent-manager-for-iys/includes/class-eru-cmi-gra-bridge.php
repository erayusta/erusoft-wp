<?php
/**
 * Growth Automations for WooCommerce: consent answers from this store.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loaded only when Growth Automations is active.
 *
 * - Filter `eru_gra_has_marketing_consent` at priority 20 (after other sources such as Conversion Boosters'
 *   double opt-in newsletter at 10): EPOSTA RET → false whatever they said, ONAY → true, no record → the
 *   incoming value unchanged.
 * - Growth Automations consults that filter only when its own sources (account box, checkout box) say nothing,
 *   so an EPOSTA RET recorded here is also passed to its suppression list through its public API; an ONAY
 *   proven by the address owner lifts that suppression again. A guest's ONAY never does.
 * - Opt-outs recorded while Growth Automations was inactive are copied once: on the first request with its
 *   API present (and again after it is re-activated) a background job walks the EPOSTA RET rows that still
 *   hold an address, STEP at a time, calling suppress(). The job arms its successor (pending-only) before
 *   each batch and stops when the marker option says "done". Nothing is scheduled when there is nothing to
 *   copy.
 */
class Eru_CMI_Gra_Bridge {

	/** One-off copy job (Action Scheduler hook). */
	const BACKFILL = 'eru_cmi_gra_backfill';

	/** Marker: missing = copy needed, a number = cursor of a copy in progress, "done" = copied. Autoloaded, tiny. */
	const MARKER = 'eru_cmi_gra_backfill';

	/** Rows per run. */
	const STEP = 200;

	/** Hooks. */
	public static function init() {
		add_action( self::BACKFILL, array( __CLASS__, 'run_backfill' ) );
		add_action( 'activated_plugin', array( __CLASS__, 'activated' ) );
		if ( ! defined( 'ERU_GRA_VERSION' ) ) {
			return;
		}
		add_filter( 'eru_gra_has_marketing_consent', array( __CLASS__, 'filter' ), 20, 3 );
		add_action( 'eru_cmi_consent_changed', array( __CLASS__, 'sync' ), 10, 3 );
		if ( 'done' !== get_option( self::MARKER ) ) {
			add_action( 'action_scheduler_init', array( __CLASS__, 'maybe_backfill' ) );
		}
	}

	/** @param string $plugin Plugin file activated. Growth Automations (re)activated: copy the opt-outs again. */
	public static function activated( $plugin ) {
		if ( 'growth-automations-for-woocommerce.php' === basename( (string) $plugin ) ) {
			delete_option( self::MARKER );
		}
	}

	/** @return bool Growth Automations' suppression API is loaded. */
	private static function api() {
		return class_exists( 'Eru_GRA_Consent' ) && method_exists( 'Eru_GRA_Consent', 'suppress' ) && method_exists( 'Eru_GRA_Consent', 'is_suppressed' );
	}

	/** @return int[] Pending copy jobs. */
	private static function pending() {
		return function_exists( 'as_get_scheduled_actions' ) ? as_get_scheduled_actions(
			array(
				'hook'     => self::BACKFILL,
				'group'    => Eru_CMI_Scheduler::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 5,
			),
			'ids'
		) : array();
	}

	/**
	 * @param int $after Cursor (consent id).
	 * @param int $limit Rows.
	 * @return object[] EPOSTA RET rows with an address after the cursor.
	 */
	private static function rows( $after, $limit ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT id, recipient FROM ' . Eru_CMI_Installer::table( 'consents' ) . " WHERE type = 'EPOSTA' AND status = 'RET' AND recipient NOT LIKE %s AND id > %d ORDER BY id ASC LIMIT %d", '#%', (int) $after, (int) $limit ) ) ?: array(); // phpcs:ignore
	}

	/** First run with the API present: starts the copy, or marks it done when there is nothing to copy. */
	public static function maybe_backfill() {
		if ( false !== get_option( self::MARKER ) || ! self::api() || ! function_exists( 'as_enqueue_async_action' ) ) {
			return; // Done, or already in progress (the job re-arms itself).
		}
		if ( ! self::rows( 0, 1 ) ) {
			update_option( self::MARKER, 'done', true );
			return;
		}
		update_option( self::MARKER, '0', true );
		if ( ! self::pending() ) {
			as_enqueue_async_action( self::BACKFILL, array(), Eru_CMI_Scheduler::GROUP );
		}
	}

	/**
	 * Copy job: one batch of opt-outs into Growth Automations' suppression list through suppress().
	 *
	 * @param int $limit Rows per run (default STEP).
	 */
	public static function run_backfill( $limit = self::STEP ) {
		$cursor = get_option( self::MARKER );
		if ( 'done' === $cursor ) {
			return;
		}
		if ( ! self::api() ) {
			delete_option( self::MARKER ); // Growth Automations went away meanwhile: start over when it is back.
			return;
		}
		$limit = max( 1, (int) $limit );
		if ( ! self::pending() ) {
			as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::BACKFILL, array(), Eru_CMI_Scheduler::GROUP ); // Successor first: a crash cannot break the chain.
		}
		$rows = self::rows( (int) $cursor, $limit );
		foreach ( $rows as $r ) {
			if ( is_email( $r->recipient ) && ! Eru_GRA_Consent::is_suppressed( $r->recipient ) ) {
				Eru_GRA_Consent::suppress( $r->recipient );
			}
			$cursor = (int) $r->id;
		}
		if ( count( $rows ) < $limit ) {
			update_option( self::MARKER, 'done', true );
			as_unschedule_all_actions( self::BACKFILL, array(), Eru_CMI_Scheduler::GROUP ); // Pending only.
			return;
		}
		update_option( self::MARKER, (string) (int) $cursor, true );
	}

	/**
	 * @param bool   $has     Value from earlier sources.
	 * @param string $email   Address.
	 * @param string $context capture | send.
	 * @return bool
	 */
	public static function filter( $has, $email = '', $context = 'send' ) {
		$state = eru_cmi_is_consented( (string) $email, 'EPOSTA' );
		return null === $state ? (bool) $has : $state;
	}

	/**
	 * Mirrors e-mail withdrawals (and owner-proven re-consent) to Growth Automations' suppression list.
	 *
	 * @param array       $result   Result of set().
	 * @param string|null $previous Previous status.
	 * @param array       $context  Context.
	 */
	public static function sync( $result, $previous, $context ) {
		if ( 'EPOSTA' !== ( $result['type'] ?? '' ) || ! class_exists( 'Eru_GRA_Consent' ) || ! is_email( (string) ( $result['recipient'] ?? '' ) ) ) {
			return;
		}
		if ( 'RET' === $result['status'] && method_exists( 'Eru_GRA_Consent', 'suppress' ) ) {
			Eru_GRA_Consent::suppress( $result['recipient'] );
		} elseif ( 'ONAY' === $result['status'] && ! empty( $context['verified'] ) && method_exists( 'Eru_GRA_Consent', 'remove_suppression' ) ) {
			Eru_GRA_Consent::remove_suppression( $result['recipient'] );
		}
	}
}
