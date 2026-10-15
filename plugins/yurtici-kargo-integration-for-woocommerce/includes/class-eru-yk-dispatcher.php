<?php
/**
 * Dispatch (createShipment) and cancel, idempotent on the order's cargo key.
 *
 * @package yurtici-kargo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * One shipment per order and cargo key:
 * - The key and account are stored (state "pending") before the HTTP request, so every retry reuses them.
 * - A per-order lock stops two workers from sending at once.
 * - When the last attempt ended without an answer (timeout, 5xx), the next attempt first asks
 *   queryShipment for that key; if the lookup itself fails the order goes to "needs_review" and nothing
 *   is created automatically.
 * - "Already registered" is recognised only by error code (Eru_YK_Client::duplicate_codes()).
 * Status-change requests only enqueue an Action Scheduler action.
 */
class Eru_YK_Dispatcher {

	const HOOK  = 'eru_yk_dispatch';
	const GROUP = 'eru-yk';
	const STALE = 120;

	const M_STATE     = '_eru_yk_state';
	const M_ACCOUNT   = '_eru_yk_account';
	const M_LABEL     = '_eru_yk_account_label';
	const M_KEY       = '_eru_yk_cargo_key';
	const M_JOB       = '_eru_yk_job_id';
	const M_TRACKING  = '_eru_yk_tracking_number';
	const M_URL       = '_eru_yk_tracking_url';
	const M_SEQ       = '_eru_yk_seq';
	const M_ERROR     = '_eru_yk_error';
	const M_PENDING   = '_eru_yk_pending_at';
	const M_TRIES     = '_eru_yk_attempts';
	const M_SENT      = '_eru_yk_dispatched_at';
	const M_POLLED    = '_eru_yk_last_polled_at';
	const M_NEXT      = '_eru_yk_next_poll_at';
	const M_NODETAIL  = '_eru_yk_no_detail';
	const M_LASTEVENT = '_eru_yk_last_event';

	/** Retry delays after a transient error, in seconds (3 retries). */
	const RETRY_DELAYS = array( 60, 300, 900 );

	/** States in which a shipment exists. */
	const LIVE = array( 'dispatched', 'in_transit', 'delivered', 'gave_up' );

	/** Hooks. */
	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run_job' ), 10, 2 );
		add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status' ), 20, 4 );
	}

	/** @return array<string,string> State labels. */
	public static function states() {
		return array(
			''             => __( 'Gönderilmedi', 'yurtici-kargo-integration-for-woocommerce' ),
			'pending'      => __( 'Gönderiliyor', 'yurtici-kargo-integration-for-woocommerce' ),
			'retry'        => __( 'Yeniden denenecek', 'yurtici-kargo-integration-for-woocommerce' ),
			'unknown'      => __( 'Belirsiz (sonraki denemede sorgulanacak)', 'yurtici-kargo-integration-for-woocommerce' ),
			'needs_review' => __( 'İnceleme gerekli', 'yurtici-kargo-integration-for-woocommerce' ),
			'failed'       => __( 'Hata', 'yurtici-kargo-integration-for-woocommerce' ),
			'dispatched'   => __( 'Gönderi oluşturuldu', 'yurtici-kargo-integration-for-woocommerce' ),
			'in_transit'   => __( 'Yolda', 'yurtici-kargo-integration-for-woocommerce' ),
			'delivered'    => __( 'Teslim edildi', 'yurtici-kargo-integration-for-woocommerce' ),
			'cancelled'    => __( 'Yurtiçi tarafında iptal', 'yurtici-kargo-integration-for-woocommerce' ),
			'gave_up'      => __( 'Takip bırakıldı', 'yurtici-kargo-integration-for-woocommerce' ),
		);
	}

	/** @return bool Turkish Shipping Carriers is active (filterable). */
	public static function tsc_active() {
		return (bool) apply_filters( 'eru_yk_tsc_active', defined( 'ERU_TSC_VERSION' ) );
	}

	/**
	 * @param string $hook Hook.
	 * @param array  $args Args.
	 * @return bool A pending (not running) action exists.
	 */
	public static function has_pending( $hook, array $args ) {
		if ( ! function_exists( 'as_get_scheduled_actions' ) ) {
			return false;
		}
		return (bool) as_get_scheduled_actions(
			array(
				'hook'     => $hook,
				'args'     => $args,
				'group'    => self::GROUP,
				'status'   => ActionScheduler_Store::STATUS_PENDING,
				'per_page' => 1,
			),
			'ids'
		);
	}

	/**
	 * Queues a dispatch.
	 *
	 * @param int  $id     Order id.
	 * @param bool $manual Manual (button, bulk, CLI): also runs for "needs_review" orders.
	 * @param int  $delay  Seconds.
	 * @return bool Queued.
	 */
	public static function enqueue( $id, $manual = false, $delay = 0 ) {
		$args = $manual ? array( (int) $id, 1 ) : array( (int) $id );
		if ( ! function_exists( 'as_schedule_single_action' ) || self::has_pending( self::HOOK, $args ) ) {
			return false;
		}
		return (bool) as_schedule_single_action( time() + max( 0, (int) $delay ), self::HOOK, $args, self::GROUP );
	}

	/**
	 * @param WC_Order $order Order.
	 * @return string Excluded shipping method id, or ''.
	 */
	public static function excluded_method( WC_Order $order ) {
		$excluded = (array) Eru_YK_Settings::get( 'excluded_methods' );
		foreach ( $order->get_shipping_methods() as $item ) {
			if ( in_array( (string) $item->get_method_id(), $excluded, true ) ) {
				return (string) $item->get_method_id();
			}
		}
		return '';
	}

	/**
	 * Auto-dispatch trigger: only enqueues.
	 *
	 * @param int           $id    Order id.
	 * @param string        $from  Old status.
	 * @param string        $to    New status.
	 * @param WC_Order|null $order Order.
	 */
	public static function on_status( $id, $from, $to, $order = null ) {
		$trigger = (string) Eru_YK_Settings::get( 'auto_status' );
		if ( '' === $trigger || $trigger !== $to || ! Eru_YK_Settings::is_enabled() ) {
			return;
		}
		$order = $order instanceof WC_Order ? $order : wc_get_order( $id );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
			return;
		}
		if ( ! in_array( (string) $order->get_meta( self::M_STATE ), array( '', 'failed' ), true ) ) {
			return;
		}
		$method = self::excluded_method( $order );
		if ( '' !== $method ) {
			/* translators: %s: shipping method id */
			Eru_YK_Logger::event( $order->get_id(), 'auto_skipped', sprintf( __( 'Otomatik gönderim atlandı: kargo yöntemi hariç tutuluyor (%s).', 'yurtici-kargo-integration-for-woocommerce' ), $method ) );
			return;
		}
		if ( self::enqueue( $order->get_id() ) ) {
			Eru_YK_Logger::event( $order->get_id(), 'queued', __( 'Gönderim kuyruğa alındı (otomatik).', 'yurtici-kargo-integration-for-woocommerce' ) );
		}
	}

	/**
	 * Action Scheduler callback.
	 *
	 * @param int $id     Order id.
	 * @param int $manual Manual flag.
	 */
	public static function run_job( $id, $manual = 0 ) {
		self::dispatch( (int) $id, (bool) $manual );
	}

	/**
	 * cargoKey: prefix + order id, plus K<seq> after a cancel.
	 *
	 * @param WC_Order $order   Order.
	 * @param array    $account Account.
	 * @return string
	 */
	public static function cargo_key( WC_Order $order, array $account ) {
		$seq = (int) $order->get_meta( self::M_SEQ );
		return (string) $account['prefix'] . $order->get_id() . ( $seq > 0 ? 'K' . $seq : '' );
	}

	/**
	 * Province name from the WooCommerce state code (TR35 → İzmir).
	 *
	 * @param string $country Country.
	 * @param string $state   State code.
	 * @return string
	 */
	public static function province( $country, $state ) {
		$country = '' !== (string) $country ? (string) $country : 'TR';
		$state   = trim( (string) $state );
		if ( '' === $state ) {
			return '';
		}
		$states = WC()->countries ? WC()->countries->get_states( $country ) : array();
		if ( is_array( $states ) ) {
			if ( isset( $states[ $state ] ) ) {
				return (string) $states[ $state ];
			}
			if ( ctype_digit( $state ) ) {
				$k = $country . str_pad( $state, 2, '0', STR_PAD_LEFT );
				if ( isset( $states[ $k ] ) ) {
					return (string) $states[ $k ];
				}
			}
		}
		return $state;
	}

	/**
	 * Receiver: the shipping address when its first line is filled, otherwise the billing address (never mixed).
	 *
	 * @param WC_Order $order Order.
	 * @return array{name:string,address:string,city:string,town:string,phone:string,email:string,postcode:string}
	 */
	public static function receiver( WC_Order $order ) {
		$type    = '' !== trim( (string) $order->get_shipping_address_1() ) ? 'shipping' : 'billing';
		$g       = fn( $f ) => trim( (string) call_user_func( array( $order, 'get_' . $type . '_' . $f ) ) );
		$person  = trim( $g( 'first_name' ) . ' ' . $g( 'last_name' ) );
		$company = $g( 'company' );
		$name    = '' !== $company && '' !== $person ? $company . ' - ' . $person : ( '' !== $person ? $person : $company );
		$phone   = 'shipping' === $type ? trim( (string) $order->get_shipping_phone() ) : '';
		if ( '' === $phone ) {
			$phone = trim( (string) $order->get_billing_phone() );
		}
		return array(
			'name'     => $name,
			'address'  => trim( $g( 'address_1' ) . ' ' . $g( 'address_2' ) ),
			'city'     => self::province( $g( 'country' ), $g( 'state' ) ),
			'town'     => $g( 'city' ),
			'phone'    => self::phone( $phone ),
			'email'    => (string) $order->get_billing_email(),
			'postcode' => $g( 'postcode' ),
		);
	}

	/** @param string $raw Phone. @return string Digits, national format (0XXXXXXXXXX) when it looks Turkish. */
	public static function phone( $raw ) {
		$d = (string) preg_replace( '/\D+/', '', (string) $raw );
		if ( 12 === strlen( $d ) && 0 === strpos( $d, '90' ) ) {
			$d = '0' . substr( $d, 2 );
		} elseif ( 10 === strlen( $d ) && '0' !== $d[0] ) {
			$d = '0' . $d;
		}
		return $d;
	}

	/**
	 * ShippingOrderVO fields (values are escaped by the XML builder).
	 *
	 * @param WC_Order $order     Order.
	 * @param string   $cargo_key Cargo key.
	 * @param array    $account   Account.
	 * @return array
	 */
	public static function payload( WC_Order $order, $cargo_key, array $account ) {
		$r       = self::receiver( $order );
		$payload = array(
			'cargoKey'         => $cargo_key,
			'invoiceKey'       => (string) $order->get_order_number(),
			'receiverCustName' => $r['name'],
			'receiverAddress'  => $r['address'],
			'receiverPhone1'   => $r['phone'],
			'cityName'         => $r['city'],
			'townName'         => $r['town'],
			'cargoCount'       => '1',
			'emailAddress'     => $r['email'],
		);
		/**
		 * Filters the createShipment ShippingOrderVO (e.g. to add desi or kg). Keys outside the documented
		 * field list are dropped; cargoKey cannot be changed.
		 *
		 * @param array    $payload Fields.
		 * @param WC_Order $order   Order.
		 * @param array    $account Account (without the password).
		 */
		$filtered = (array) apply_filters( 'eru_yk_dispatch_payload', $payload, $order, array_diff_key( $account, array( 'password' => 1 ) ) );
		// Field order of ShippingOrderVO in the service WSDL (document/literal sequence).
		$allowed  = array( 'cargoKey', 'invoiceKey', 'receiverCustName', 'receiverAddress', 'cityName', 'townName', 'receiverPhone1', 'receiverPhone2', 'receiverPhone3', 'emailAddress', 'taxOfficeId', 'taxNumber', 'taxOfficeName', 'desi', 'kg', 'cargoCount', 'waybillNo', 'specialField1', 'specialField2', 'specialField3', 'ttInvoiceAmount', 'ttDocumentId', 'ttCollectionType', 'ttDocumentSaveType', 'dcSelectedCredit', 'dcCreditRule', 'description', 'orgGeoCode', 'privilegeOrder', 'custProdId', 'orgReceiverCustId' );
		$out      = array();
		foreach ( $allowed as $k ) {
			if ( isset( $filtered[ $k ] ) && is_scalar( $filtered[ $k ] ) && '' !== (string) $filtered[ $k ] ) {
				$out[ $k ] = (string) $filtered[ $k ];
			}
		}
		$out['cargoKey'] = $cargo_key;
		return $out;
	}

	/**
	 * Creates the shipment.
	 *
	 * @param int  $id     Order id.
	 * @param bool $manual Manual run (allowed for needs_review orders).
	 * @return string created | recovered | exists | cancelled | locked | pending | needs_review | retry | failed | disabled | missing | no_account
	 */
	public static function dispatch( $id, $manual = false ) {
		if ( ! Eru_YK_Settings::is_enabled() ) {
			return 'disabled';
		}
		$order = wc_get_order( (int) $id );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
			return 'missing';
		}
		if ( in_array( (string) $order->get_meta( self::M_STATE ), self::LIVE, true ) ) {
			return 'exists';
		}
		if ( ! $manual && 'needs_review' === $order->get_meta( self::M_STATE ) ) {
			return 'needs_review';
		}
		if ( 'cancelled' === $order->get_meta( self::M_STATE ) ) {
			// Cancelled at Yurtiçi: the old key must not be sent again; a local reset gives a new one.
			return 'cancelled';
		}
		$lock = 'order_' . (int) $id;
		if ( ! Eru_YK_Lock::acquire( $lock, self::STALE ) ) {
			return 'locked';
		}
		try {
			$order = wc_get_order( (int) $id );
			$order->read_meta_data( true );
			$state = (string) $order->get_meta( self::M_STATE );
			if ( in_array( $state, self::LIVE, true ) ) {
				return 'exists';
			}
			if ( 'cancelled' === $state ) {
				return 'cancelled';
			}
			if ( 'pending' === $state && (int) $order->get_meta( self::M_PENDING ) > time() - self::STALE ) {
				// Another worker is sending (or crashed right after the pending write): look again later.
				self::enqueue( $order->get_id(), $manual, self::STALE + 30 );
				return 'pending';
			}

			// Account and key: an earlier attempt's pair is reused, so a retry can never create a second shipment.
			$key     = (string) $order->get_meta( self::M_KEY );
			$acc_id  = (string) $order->get_meta( self::M_ACCOUNT );
			$account = '' !== $acc_id ? ( Eru_YK_Accounts::usable()[ $acc_id ] ?? null ) : null;
			$doubt   = in_array( $state, array( 'unknown', 'pending', 'needs_review' ), true );
			if ( '' !== $key && ! $account ) {
				if ( $doubt ) {
					return self::review( $order, __( 'Önceki gönderimin hesabı artık kullanılamıyor; kayıt sorgulanamadı.', 'yurtici-kargo-integration-for-woocommerce' ) );
				}
				$key = '';
			}
			if ( '' === $key ) {
				$acc_id  = Eru_YK_Accounts::route( $order );
				$account = Eru_YK_Accounts::usable()[ $acc_id ] ?? null;
				if ( ! $account ) {
					self::fail( $order, __( 'Kullanılabilir Yurtiçi hesabı yok (kullanıcı adı ve şifre girin).', 'yurtici-kargo-integration-for-woocommerce' ) );
					return 'no_account';
				}
				$key = self::cargo_key( $order, $account );
			}
			$client = new Eru_YK_Client( $account );

			// The last attempt may have reached Yurtiçi: look the key up before sending again.
			if ( $doubt ) {
				$q       = $client->query( $key, $order->get_id() );
				$outcome = Eru_YK_Client::outcome( $q );
				if ( 'found' === $outcome ) {
					self::success( $order, $account, $key, '', Eru_YK_Client::delivery( $q['data'] ), true );
					return 'recovered';
				}
				if ( 'absent' !== $outcome ) {
					// Only an explicit "not found" answer allows a new createShipment.
					return self::review( $order, __( 'Önceki gönderimin sonucu belirsiz ve kargo anahtarı için kesin bir "kayıt yok" yanıtı alınamadı. Yurtiçi tarafını kontrol edin: kayıt varsa "Yeniden dene" ile bağlayın; gönderi oluşmadıysa "Yerel sıfırla" ile yeni anahtar alın.', 'yurtici-kargo-integration-for-woocommerce' ) . ' (' . self::describe( $q ) . ')' );
				}
			}

			$vo = self::payload( $order, $key, $account );
			foreach ( array( 'receiverCustName', 'receiverAddress', 'cityName', 'townName' ) as $req ) {
				if ( empty( $vo[ $req ] ) ) {
					self::fail( $order, __( 'Alıcı adı, adres, il veya ilçe eksik; gönderi oluşturulmadı.', 'yurtici-kargo-integration-for-woocommerce' ) );
					return 'failed';
				}
			}

			$order->update_meta_data( self::M_STATE, 'pending' );
			$order->update_meta_data( self::M_PENDING, time() );
			$order->update_meta_data( self::M_KEY, $key );
			$order->update_meta_data( self::M_ACCOUNT, $account['id'] );
			$order->update_meta_data( self::M_LABEL, $account['label'] );
			$order->save();
			/* translators: 1: cargo key, 2: account label */
			Eru_YK_Logger::event( $order->get_id(), 'dispatch_start', sprintf( __( 'createShipment gönderiliyor (anahtar %1$s, hesap %2$s).', 'yurtici-kargo-integration-for-woocommerce' ), $key, $account['label'] ) );

			$r = $client->create( $vo, $order->get_id() );
			$order->read_meta_data( true );

			if ( $r['ok'] ) {
				self::success( $order, $account, $key, (string) ( $r['data']['jobId'] ?? '' ), null, false );
				return 'created';
			}
			if ( $r['answered'] && in_array( $r['code'], Eru_YK_Client::duplicate_codes(), true ) ) {
				$q = $client->query( $key, $order->get_id() );
				if ( 'found' === Eru_YK_Client::outcome( $q ) ) {
					self::success( $order, $account, $key, '', Eru_YK_Client::delivery( $q['data'] ), true );
					return 'recovered';
				}
				/* translators: %s: error code */
				return self::review( $order, sprintf( __( 'Yurtiçi bu kargo anahtarının kayıtlı olduğunu bildirdi (kod %s) ama kayıt sorgulanamadı.', 'yurtici-kargo-integration-for-woocommerce' ), $r['code'] ) );
			}
			if ( $r['transient'] ) {
				return self::retry( $order, $r, $manual );
			}
			self::fail( $order, self::describe( $r ) );
			return 'failed';
		} finally {
			Eru_YK_Lock::release( $lock );
		}
	}

	/** @param array $r Client result. @return string Human readable error. */
	public static function describe( array $r ) {
		$msg = trim( (string) $r['message'] );
		return trim( ( '' !== $r['code'] ? '[' . $r['code'] . '] ' : '' ) . ( '' !== $msg ? $msg : __( 'Bilinmeyen hata', 'yurtici-kargo-integration-for-woocommerce' ) ) );
	}

	/**
	 * Transient failure: reschedule through Action Scheduler (1, 5, 15 minutes), never sleep.
	 *
	 * @param WC_Order $order  Order.
	 * @param array    $r      Client result.
	 * @param bool     $manual Manual flag.
	 * @return string retry | needs_review | failed
	 */
	private static function retry( WC_Order $order, array $r, $manual ) {
		$n   = (int) $order->get_meta( self::M_TRIES );
		$msg = self::describe( $r );
		if ( $n >= count( self::RETRY_DELAYS ) ) {
			$order->delete_meta_data( self::M_TRIES );
			if ( $r['ambiguous'] ) {
				/* translators: %s: error */
				return self::review( $order, sprintf( __( 'Yeniden denemeler tükendi, son sonuç belirsiz: %s', 'yurtici-kargo-integration-for-woocommerce' ), $msg ) );
			}
			/* translators: %s: error */
			self::fail( $order, sprintf( __( 'Yeniden denemeler tükendi: %s', 'yurtici-kargo-integration-for-woocommerce' ), $msg ) );
			return 'failed';
		}
		$delay = self::RETRY_DELAYS[ $n ];
		$order->update_meta_data( self::M_TRIES, $n + 1 );
		$order->update_meta_data( self::M_STATE, $r['ambiguous'] ? 'unknown' : 'retry' );
		$order->update_meta_data( self::M_ERROR, $msg );
		$order->delete_meta_data( self::M_PENDING );
		$order->save();
		self::enqueue( $order->get_id(), $manual, $delay );
		/* translators: 1: attempt, 2: minutes, 3: error */
		Eru_YK_Logger::event( $order->get_id(), 'dispatch_retry', sprintf( __( 'Geçici hata, %1$d. yeniden deneme %2$d dakika sonra: %3$s', 'yurtici-kargo-integration-for-woocommerce' ), $n + 1, (int) ( $delay / 60 ), $msg ), 'warn', array( 'ambiguous' => $r['ambiguous'] ) );
		return 'retry';
	}

	/** @param WC_Order $order Order. @param string $msg Message. */
	private static function fail( WC_Order $order, $msg ) {
		$order->update_meta_data( self::M_STATE, 'failed' );
		$order->update_meta_data( self::M_ERROR, $msg );
		$order->delete_meta_data( self::M_PENDING );
		$order->delete_meta_data( self::M_TRIES );
		$order->save();
		Eru_YK_Logger::event( $order->get_id(), 'dispatch_failed', $msg, 'error' );
	}

	/** @param WC_Order $order Order. @param string $msg Message. @return string needs_review */
	private static function review( WC_Order $order, $msg ) {
		$order->update_meta_data( self::M_STATE, 'needs_review' );
		$order->update_meta_data( self::M_ERROR, $msg );
		$order->delete_meta_data( self::M_PENDING );
		$order->save();
		Eru_YK_Logger::event( $order->get_id(), 'needs_review', $msg, 'error' );
		return 'needs_review';
	}

	/**
	 * Applies a target status (unless test mode or "no change"), otherwise adds the note only.
	 *
	 * @param WC_Order $order   Order.
	 * @param string   $setting dispatch_status | transit_status | delivered_status.
	 * @param string   $note    Note.
	 */
	public static function advance( WC_Order $order, $setting, $note ) {
		$target = (string) Eru_YK_Settings::get( $setting );
		if ( Eru_YK_Settings::test_mode() ) {
			$note = '[' . __( 'Test', 'yurtici-kargo-integration-for-woocommerce' ) . '] ' . $note;
			$target = '';
		}
		if ( '' !== $target && $order->get_status() !== $target && array_key_exists( $target, Eru_YK_Settings::statuses() ) ) {
			$order->update_status( $target, $note );
			return;
		}
		$order->add_order_note( $note );
		$order->save();
	}

	/**
	 * @param WC_Order $order    Order.
	 * @param string   $doc_id   Tracking number (docId).
	 * @param string   $url      Tracking URL from the service.
	 */
	public static function set_tracking( WC_Order $order, $doc_id, $url = '' ) {
		$doc_id = trim( (string) $doc_id );
		if ( '' === $doc_id ) {
			return;
		}
		$order->update_meta_data( self::M_TRACKING, $doc_id );
		$default = 'https://www.yurticikargo.com/tr/online-servisler/gonderi-sorgula?code=' . rawurlencode( $doc_id );
		$url     = '' !== $url && wp_http_validate_url( $url ) ? $url : $default;
		$order->update_meta_data( self::M_URL, esc_url_raw( (string) apply_filters( 'eru_yk_tracking_url', $url, $doc_id, $order ) ) );
		if ( self::tsc_active() && ! Eru_YK_Settings::test_mode() ) {
			self::tsc_save( $order, $doc_id );
		}
	}

	/**
	 * Hands carrier + tracking number to Turkish Shipping Carriers through its public save path, so a new number gets
	 * the same customer note and `eru_tsc_tracking_saved` action as a manual entry. That path saves the order.
	 * Without the class (integration forced on by the filter) only the meta is written.
	 *
	 * @param WC_Order    $order  Order.
	 * @param string|null $number Tracking number, null for carrier only.
	 */
	private static function tsc_save( WC_Order $order, $number ) {
		if ( class_exists( 'Eru_TSC_Tracking' ) && method_exists( 'Eru_TSC_Tracking', 'save_tracking' ) ) {
			Eru_TSC_Tracking::save_tracking( $order, 'yurtici', null === $number ? null : (string) $number );
			return;
		}
		$order->update_meta_data( '_eru_tsc_carrier', 'yurtici' );
		if ( null !== $number ) {
			$order->update_meta_data( '_eru_tsc_tracking_number', (string) $number );
		}
	}

	/**
	 * @param WC_Order   $order     Order.
	 * @param array      $account   Account.
	 * @param string     $key       Cargo key.
	 * @param string     $job       Job id.
	 * @param array|null $d         Delivery record (recovered).
	 * @param bool       $recovered Recovered via queryShipment.
	 */
	private static function success( WC_Order $order, array $account, $key, $job, $d, $recovered ) {
		$now = time();
		$order->update_meta_data( self::M_STATE, 'dispatched' );
		$order->update_meta_data( self::M_KEY, $key );
		$order->update_meta_data( self::M_ACCOUNT, $account['id'] );
		$order->update_meta_data( self::M_LABEL, $account['label'] );
		if ( '' !== (string) $job ) {
			$order->update_meta_data( self::M_JOB, (string) $job );
		}
		$order->update_meta_data( self::M_SENT, $now );
		$order->update_meta_data( self::M_POLLED, 0 );
		$order->update_meta_data( self::M_NEXT, $now + 60 * (int) Eru_YK_Settings::get( 'poll_dispatched' ) );
		$order->update_meta_data( self::M_NODETAIL, 0 );
		$order->update_meta_data( self::M_ERROR, '' );
		$order->delete_meta_data( self::M_PENDING );
		$order->delete_meta_data( self::M_TRIES );
		if ( self::tsc_active() && ! Eru_YK_Settings::test_mode() ) {
			self::tsc_save( $order, null );
		}
		if ( $d ) {
			self::set_tracking( $order, $d['doc_id'], $d['tracking_url'] );
		}
		$order->save();
		$note = $recovered
			/* translators: 1: cargo key, 2: account label */
			? sprintf( __( 'Yurtiçi Kargo: bu kargo anahtarıyla kayıt zaten vardı, mevcut gönderi bağlandı (anahtar %1$s, hesap %2$s).', 'yurtici-kargo-integration-for-woocommerce' ), $key, $account['label'] )
			/* translators: 1: cargo key, 2: account label */
			: sprintf( __( 'Yurtiçi Kargo: gönderi oluşturuldu (anahtar %1$s, hesap %2$s).', 'yurtici-kargo-integration-for-woocommerce' ), $key, $account['label'] );
		self::advance( $order, 'dispatch_status', $note );
		Eru_YK_Logger::event( $order->get_id(), $recovered ? 'dispatch_recovered' : 'dispatch_ok', $note, 'success', array( 'job_id' => (string) $job ) );
	}

	/**
	 * Clears the shipment meta, bumps the sequence (the next key gets K<seq>), drops queued dispatch jobs.
	 *
	 * @param WC_Order $order Order (lock held by the caller).
	 */
	private static function clear_shipment( WC_Order $order ) {
		$tracking = (string) $order->get_meta( self::M_TRACKING );
		foreach ( array( self::M_STATE, self::M_KEY, self::M_JOB, self::M_TRACKING, self::M_URL, self::M_ERROR, self::M_PENDING, self::M_TRIES, self::M_SENT, self::M_POLLED, self::M_NEXT, self::M_NODETAIL, self::M_LASTEVENT ) as $k ) {
			$order->delete_meta_data( $k );
		}
		if ( '' !== $tracking && $tracking === (string) $order->get_meta( '_eru_tsc_tracking_number' ) ) {
			if ( class_exists( 'Eru_TSC_Tracking' ) ) {
				Eru_TSC_Tracking::set( $order, (string) $order->get_meta( '_eru_tsc_carrier' ), '' ); // Saved below; an empty number notifies nobody.
			} else {
				$order->delete_meta_data( '_eru_tsc_tracking_number' );
			}
		}
		$order->update_meta_data( self::M_SEQ, (int) $order->get_meta( self::M_SEQ ) + 1 );
		$order->save();
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, array( $order->get_id() ), self::GROUP );
			as_unschedule_all_actions( self::HOOK, array( $order->get_id(), 1 ), self::GROUP );
		}
	}

	/**
	 * Local reset without a remote call, for the dead ends where cancelShipment cannot help:
	 * - "cancelled": the tracker saw the shipment cancelled at Yurtiçi (a second cancel is rejected);
	 * - "needs_review": only when the admin confirms the shipment was never created;
	 * - "gave_up" (a shipment that was created once): only when a fresh queryShipment answers with an
	 *   explicit not-found code; any other answer refuses (use "İptal").
	 * The sequence is bumped, so the old key is never sent again.
	 *
	 * @param int  $id        Order id.
	 * @param bool $confirmed Admin confirmed "gönderi oluşmadı".
	 * @return array{ok:bool,message:string}
	 */
	public static function reset_local( $id, $confirmed = false ) {
		if ( ! Eru_YK_Settings::is_enabled() ) {
			return array( 'ok' => false, 'message' => __( 'Entegrasyon kapalı.', 'yurtici-kargo-integration-for-woocommerce' ) );
		}
		$order = wc_get_order( (int) $id );
		if ( ! $order instanceof WC_Order ) {
			return array( 'ok' => false, 'message' => __( 'Sipariş bulunamadı.', 'yurtici-kargo-integration-for-woocommerce' ) );
		}
		$lock = 'order_' . (int) $id;
		if ( ! Eru_YK_Lock::acquire( $lock, self::STALE ) ) {
			return array( 'ok' => false, 'message' => __( 'Bu sipariş için başka bir işlem sürüyor; birazdan tekrar deneyin.', 'yurtici-kargo-integration-for-woocommerce' ) );
		}
		try {
			$order->read_meta_data( true );
			$state = (string) $order->get_meta( self::M_STATE );
			$key   = (string) $order->get_meta( self::M_KEY );
			if ( ! self::can_reset( $state ) ) {
				return array( 'ok' => false, 'message' => __( 'Bu durumda yerel sıfırlama yapılamaz; canlı gönderiyi "İptal" ile iptal edin.', 'yurtici-kargo-integration-for-woocommerce' ) );
			}
			if ( 'cancelled' !== $state && ! $confirmed ) {
				return array( 'ok' => false, 'message' => __( 'Gönderinin Yurtiçi\'de oluşmadığını onaylamanız gerekiyor.', 'yurtici-kargo-integration-for-woocommerce' ) );
			}
			if ( 'gave_up' === $state ) {
				// This shipment was created once: only an explicit "not found" answer proves it is gone.
				$account = Eru_YK_Accounts::usable()[ (string) $order->get_meta( self::M_ACCOUNT ) ] ?? null;
				$outcome = 'error';
				if ( $account && '' !== $key ) {
					$q       = ( new Eru_YK_Client( $account ) )->query( $key, $order->get_id() );
					$outcome = Eru_YK_Client::outcome( $q );
				}
				if ( 'absent' !== $outcome ) {
					$msg = __( 'Yerel sıfırlama yapılmadı: Yurtiçi bu kargo anahtarı için açık bir "kayıt yok" yanıtı vermedi (kayıt var olabilir). Gönderiyi "İptal" ile iptal edin.', 'yurtici-kargo-integration-for-woocommerce' );
					Eru_YK_Logger::event( $order->get_id(), 'reset_refused', $msg, 'warn', array( 'outcome' => $outcome ) );
					return array( 'ok' => false, 'message' => $msg );
				}
			}
			self::clear_shipment( $order );
			/* translators: %s: cargo key */
			$note = sprintf( __( 'Yurtiçi Kargo: gönderi kaydı yerel olarak sıfırlandı (eski anahtar %s yeniden kullanılmaz).', 'yurtici-kargo-integration-for-woocommerce' ), '' !== $key ? $key : '—' );
			$order->add_order_note( $note, 0, true );
			Eru_YK_Logger::event( $order->get_id(), 'reset_local', $note, 'warn', array( 'state' => $state ) );
			return array( 'ok' => true, 'message' => $note );
		} finally {
			Eru_YK_Lock::release( $lock );
		}
	}

	/** @param string $state State. @return bool A local reset is possible. */
	public static function can_reset( $state ) {
		return in_array( (string) $state, array( 'cancelled', 'needs_review', 'gave_up' ), true );
	}

	/**
	 * cancelShipment, then clears the shipment meta and bumps the sequence (next key gets K<seq>).
	 *
	 * @param int $id Order id.
	 * @return array{ok:bool,message:string}
	 */
	public static function cancel( $id ) {
		$order = wc_get_order( (int) $id );
		if ( ! $order instanceof WC_Order ) {
			return array( 'ok' => false, 'message' => __( 'Sipariş bulunamadı.', 'yurtici-kargo-integration-for-woocommerce' ) );
		}
		$lock = 'order_' . (int) $id;
		if ( ! Eru_YK_Lock::acquire( $lock, self::STALE ) ) {
			return array( 'ok' => false, 'message' => __( 'Bu sipariş için başka bir işlem sürüyor; birazdan tekrar deneyin.', 'yurtici-kargo-integration-for-woocommerce' ) );
		}
		try {
			$order->read_meta_data( true );
			$key     = (string) $order->get_meta( self::M_KEY );
			$state   = (string) $order->get_meta( self::M_STATE );
			$account = Eru_YK_Accounts::usable()[ (string) $order->get_meta( self::M_ACCOUNT ) ] ?? null;
			if ( '' === $key ) {
				return array( 'ok' => false, 'message' => __( 'Bu siparişin kargo anahtarı yok.', 'yurtici-kargo-integration-for-woocommerce' ) );
			}
			if ( 'delivered' === $state ) {
				return array( 'ok' => false, 'message' => __( 'Teslim edilmiş gönderi iptal edilemez.', 'yurtici-kargo-integration-for-woocommerce' ) );
			}
			if ( ! $account ) {
				return array( 'ok' => false, 'message' => __( 'Gönderinin hesabı artık kullanılamıyor.', 'yurtici-kargo-integration-for-woocommerce' ) );
			}
			$r = ( new Eru_YK_Client( $account ) )->cancel( $key, $order->get_id() );
			if ( ! $r['ok'] ) {
				$msg = self::describe( $r );
				Eru_YK_Logger::event( $order->get_id(), 'cancel_failed', $msg, 'error' );
				return array( 'ok' => false, 'message' => $msg );
			}
			self::clear_shipment( $order );
			/* translators: %s: cargo key */
			$note = sprintf( __( 'Yurtiçi Kargo: gönderi iptal edildi (anahtar %s). Yeniden gönderimde yeni anahtar kullanılır.', 'yurtici-kargo-integration-for-woocommerce' ), $key );
			$order->add_order_note( $note );
			Eru_YK_Logger::event( $order->get_id(), 'cancelled', $note, 'warn' );
			return array( 'ok' => true, 'message' => $note );
		} finally {
			Eru_YK_Lock::release( $lock );
		}
	}
}
