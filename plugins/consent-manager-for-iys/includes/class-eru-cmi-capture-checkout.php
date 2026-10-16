<?php
/**
 * Checkout consent box: classic checkout and the Store API (Checkout block).
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The box is unticked by default. Ticked → ONAY for the billing e-mail (EPOSTA) and the billing phone (MESAJ,
 * ARAMA) for the channels offered, source "checkout", order meta _eru_cmi_optin = yes. Unticked → nothing is
 * written (an unticked box is not a refusal).
 *
 * A ticked box never lifts an earlier RET: for an e-mail with RET it only triggers a confirmation e-mail to
 * that address (Eru_CMI_Links::send_confirm()); being logged in with that account e-mail is no proof, since
 * WooCommerce does not verify it. A phone RET is never lifted by the site (only through İYS, or by an admin
 * with a note).
 *
 * A first consent (no RET on record) is written directly, as is usual for a web form, but recorded as not
 * verified with its evidence: time, the hash of the consent text shown (the text itself is kept once in
 * option eru_cmi_texts), IP and user agent hashes and the source. It never touches other plugins'
 * suppression lists.
 *
 * The order is marked captured only after every write succeeded; otherwise one retry is scheduled (Action
 * Scheduler, hook eru_cmi_capture_order) and the order stays open for it.
 *
 * Hooks are always registered and read only the autoloaded capture switches, so a switched-off box costs
 * no query. The Checkout block field is registered only while the box is on.
 */
class Eru_CMI_Capture_Checkout {

	const FIELD = 'eru-cmi/optin';
	const META  = '_eru_cmi_optin';
	const DONE  = '_eru_cmi_captured';
	const TEXTS = 'eru_cmi_texts';
	const RETRY = 'eru_cmi_capture_order';

	/** Hooks. */
	public static function init() {
		add_action( 'woocommerce_review_order_before_submit', array( __CLASS__, 'render' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'classic_meta' ), 10, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'classic_processed' ), 20, 3 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'blocks_meta' ), 10, 2 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'capture' ), 20, 1 );
		add_action( self::RETRY, array( __CLASS__, 'retry' ), 10, 1 );
		if ( Eru_CMI_Settings::front()['checkout'] ) {
			add_action( 'woocommerce_init', array( __CLASS__, 'register_block_field' ) );
		}
	}

	/** @return bool The box is switched on (autoloaded flag). */
	private static function on() {
		return (bool) Eru_CMI_Settings::front()['checkout'];
	}

	/** Checkout block: an optional checkbox in the order section (WooCommerce 8.9+). */
	public static function register_block_field() {
		if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		try {
			woocommerce_register_additional_checkout_field(
				array(
					'id'       => self::FIELD,
					'label'    => self::label_text(),
					'location' => 'order',
					'type'     => 'checkbox',
					'required' => false,
				)
			);
		} catch ( Exception $e ) {
			return; // Another plugin registered the id, or the API changed: the classic box still works.
		}
	}

	/**
	 * The consent text shown with the box, and its hash. Each distinct text is stored once (with the time it
	 * was first used) so the hash in the history can be matched to the words the person saw.
	 *
	 * @return array{text:string,hash:string}
	 */
	public static function consent_text() {
		$text = Eru_CMI_Settings::box_label();
		$page = (int) Eru_CMI_Settings::get( 'policy_page' );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			$text .= ' | ' . get_the_title( $page ) . ' <' . get_permalink( $page ) . '> (' . get_post_modified_time( 'Y-m-d H:i', true, $page ) . ' UTC)';
		}
		$hash  = hash( 'sha256', $text );
		$texts = get_option( self::TEXTS, array() );
		$texts = is_array( $texts ) ? $texts : array();
		if ( ! isset( $texts[ $hash ] ) ) {
			$texts[ $hash ] = array(
				't' => $text,
				'd' => gmdate( 'Y-m-d H:i:s' ),
			);
			$texts          = array_slice( $texts, -200, null, true ); // Grows only when an admin edits the text.
			if ( false === get_option( self::TEXTS, false ) ) {
				add_option( self::TEXTS, $texts, '', false );
			} else {
				update_option( self::TEXTS, $texts, false );
			}
		}
		return array(
			'text' => $text,
			'hash' => $hash,
		);
	}

	/** @param int $order_id Order. Scheduled retry of a capture whose write failed. */
	public static function retry( $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( $order ) {
			self::capture( $order, false );
		}
	}

	/** @return string Label with the policy page title appended (plain text, for the block field). */
	private static function label_text() {
		$label = Eru_CMI_Settings::box_label();
		$page  = (int) Eru_CMI_Settings::get( 'policy_page' );
		if ( $page && 'publish' === get_post_status( $page ) ) {
			/* translators: 1: box label, 2: policy page title */
			$label = sprintf( __( '%1$s (%2$s)', 'consent-manager-for-iys' ), $label, get_the_title( $page ) );
		}
		return $label;
	}

	/**
	 * The box (classic checkout and the registration form).
	 *
	 * @param string $context checkout | register.
	 * @return string HTML.
	 */
	public static function box_html( $context = 'checkout' ) {
		$page = (int) Eru_CMI_Settings::get( 'policy_page' );
		$link = '';
		if ( $page && 'publish' === get_post_status( $page ) ) {
			$link = ' <a href="' . esc_url( get_permalink( $page ) ) . '" target="_blank" rel="noopener">' . esc_html( get_the_title( $page ) ) . '</a>';
		}
		$class = 'register' === $context ? 'woocommerce-form-row form-row' : 'form-row';
		return '<p class="' . esc_attr( $class ) . ' eru-cmi-optin"><label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox">'
			. '<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="eru_cmi_optin" value="1"> '
			. '<span>' . esc_html( Eru_CMI_Settings::box_label() ) . '</span></label>' . $link . '</p>';
	}

	/** Classic checkout: the box above the place-order button. */
	public static function render() {
		if ( self::on() ) {
			echo self::box_html( 'checkout' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in box_html().
		}
	}

	/**
	 * Classic checkout: remember the tick on the order (WooCommerce verified the checkout nonce).
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted data.
	 */
	public static function classic_meta( $order, $data = array() ) {
		if ( self::on() && $order instanceof WC_Order ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
			self::mark( $order, ! empty( $_POST['eru_cmi_optin'] ) );
		}
	}

	/**
	 * @param int           $order_id Order id.
	 * @param array         $posted   Posted data.
	 * @param WC_Order|null $order    Order.
	 */
	public static function classic_processed( $order_id, $posted = array(), $order = null ) {
		$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
		if ( $order ) {
			self::capture( $order );
		}
	}

	/**
	 * Store API: read the block field from the request.
	 *
	 * @param WC_Order        $order   Order.
	 * @param WP_REST_Request $request Request.
	 */
	public static function blocks_meta( $order, $request ) {
		if ( ! self::on() || ! $order instanceof WC_Order || ! $request instanceof WP_REST_Request ) {
			return;
		}
		$fields = $request->get_param( 'additional_fields' );
		self::mark( $order, is_array( $fields ) && ! empty( $fields[ self::FIELD ] ) );
	}

	/**
	 * Records this submission's answer. A resumed order (classic "awaiting payment", Store API draft) keeps its
	 * meta between attempts, so an unticked submission clears a tick left by an earlier failed attempt.
	 *
	 * @param WC_Order $order  Order (saved by WooCommerce afterwards).
	 * @param bool     $ticked Box ticked in this submission.
	 */
	private static function mark( WC_Order $order, $ticked ) {
		if ( $order->get_meta( self::DONE ) ) {
			return; // Already captured; the record stays as it was.
		}
		if ( $ticked ) {
			$order->update_meta_data( self::META, 'yes' );
		} else {
			$order->delete_meta_data( self::META );
		}
	}

	/**
	 * Writes the consents of a ticked order, once.
	 *
	 * @param WC_Order $order       Order.
	 * @param bool     $allow_retry Schedule one retry when a write fails.
	 */
	public static function capture( $order, $allow_retry = true ) {
		if ( ! $order instanceof WC_Order || 'yes' !== $order->get_meta( self::META ) || $order->get_meta( self::DONE ) ) {
			return;
		}
		$uid   = (int) $order->get_customer_id();
		$email = (string) $order->get_billing_email();
		$text  = self::consent_text();
		$base  = array(
			'actor'     => 'user',
			'source'    => 'checkout',
			'actor_id'  => get_current_user_id(),
			'user_id'   => $uid,
			'company'   => (string) $order->get_billing_company(),
			'order_id'  => (int) $order->get_id(),
			'text_hash' => $text['hash'],
			'verified'  => false,
			// The order's own client data, so a scheduled retry records the buyer, not the server.
			'ip'        => (string) $order->get_customer_ip_address(),
			'ua'        => substr( (string) $order->get_customer_user_agent(), 0, 255 ),
		);
		if ( '' === $base['ip'] ) {
			$base = array_merge( $base, Eru_CMI_Links::client() );
		}

		$failed = false;
		foreach ( Eru_CMI_Settings::channels() as $type ) {
			$recipient = 'EPOSTA' === $type ? $email : (string) $order->get_billing_phone();
			if ( is_wp_error( Eru_CMI_Recipient::normalize( $recipient, $type ) ) ) {
				continue;
			}
			$row = Eru_CMI_Repository::get( $recipient, $type );
			if ( $row && 'RET' === $row->status ) {
				// The first run already offered the confirmation for a RET that existed then; a RET found only
				// by the retry was written after the order (an opt-out in between) and gets no mail.
				if ( 'EPOSTA' === $type && $allow_retry ) {
					Eru_CMI_Links::send_confirm( $recipient );
				}
				continue; // A RET is lifted only by its owner's confirmation link, İYS or an admin.
			}
			$r = Eru_CMI_Repository::set( $recipient, $type, 'ONAY', $base );
			if ( is_wp_error( $r ) ) {
				$failed = true;
			}
		}
		if ( $failed ) {
			if ( $allow_retry && function_exists( 'as_schedule_single_action' ) ) {
				as_schedule_single_action( time() + MINUTE_IN_SECONDS, self::RETRY, array( (int) $order->get_id() ), Eru_CMI_Scheduler::GROUP );
			}
			return;
		}
		$order->update_meta_data( self::DONE, (string) time() );
		$order->save();
	}
}
