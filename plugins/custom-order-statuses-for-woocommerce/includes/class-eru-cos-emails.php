<?php
/**
 * Registers one customer and one admin WC_Email instance per status and sends them when an order enters it.
 *
 * @package custom-order-statuses-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_COS_Emails {

	public static function init(): void {
		add_filter( 'woocommerce_email_classes', array( __CLASS__, 'classes' ) );
		add_action( 'eru_cos_status_registered', array( __CLASS__, 'hook_status' ), 10, 1 );
	}

	private static function load(): void {
		if ( ! class_exists( 'Eru_COS_Email_Customer' ) && class_exists( 'WC_Email' ) ) {
			require_once ERU_COS_PATH . 'includes/emails/class-eru-cos-email-base.php';
			require_once ERU_COS_PATH . 'includes/emails/class-eru-cos-email-customer.php';
			require_once ERU_COS_PATH . 'includes/emails/class-eru-cos-email-admin.php';
		}
	}

	public static function key( string $type, string $slug ): string {
		return 'eru_cos_' . $type . '_' . str_replace( '-', '_', $slug );
	}

	/** Statuses with at least one e-mail switched on get their WC_Email entries. */
	public static function classes( $emails ) {
		self::load();
		foreach ( Eru_COS_Statuses::all() as $slug => $s ) {
			if ( $s['email_customer'] ) {
				$emails[ self::key( 'customer', $slug ) ] = new Eru_COS_Email_Customer( $slug );
			}
			if ( $s['email_admin'] ) {
				$emails[ self::key( 'admin', $slug ) ] = new Eru_COS_Email_Admin( $slug );
			}
		}
		return $emails;
	}

	private static array $hooked = array();

	public static function hook_status( $slug ): void {
		if ( ! in_array( $slug, self::$hooked, true ) ) {
			self::$hooked[] = $slug;
			add_action( 'woocommerce_order_status_' . $slug, array( __CLASS__, 'send' ), 20, 2 );
		}
	}

	/** Mailer instance for a status, created on demand when the status was added after the mailer loaded. */
	public static function email( string $type, string $slug ): ?WC_Email {
		$mailer = WC()->mailer();
		$key    = self::key( $type, $slug );
		$emails = $mailer->get_emails();
		if ( empty( $emails[ $key ] ) ) {
			self::load();
			$mailer->emails[ $key ] = 'admin' === $type ? new Eru_COS_Email_Admin( $slug ) : new Eru_COS_Email_Customer( $slug );
		}
		return $mailer->emails[ $key ];
	}

	public static function send( $order_id, $order = null ): void {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$s = Eru_COS_Statuses::get( $order->get_status() );
		if ( ! $s ) {
			return;
		}
		foreach ( array( 'customer', 'admin' ) as $type ) {
			if ( ! empty( $s[ 'email_' . $type ] ) ) {
				$email = self::email( $type, $s['slug'] );
				if ( $email ) {
					$email->trigger( $order->get_id(), $order );
				}
			}
		}
	}
}
