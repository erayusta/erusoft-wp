<?php
/**
 * Hesabım → İletişim tercihleri tab, and consent follow-up when an account changes its e-mail or phone.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The signed-in user switches each offered channel on or off for their own account e-mail and billing phone
 * only (nonce + POST). Unticking a channel without an earlier ONAY stores a local RET; the queue skips it
 * (H175). Actor "user", no company key (the stored recipient type is kept), never "verified": WooCommerce
 * does not verify the account e-mail and the billing phone is free text. So a RET is never lifted here:
 * ticking e-mail over a RET sends a confirmation link to that address, and a phone channel with RET is shown
 * disabled with a note that consent can be given again through İYS.
 *
 * Address changes:
 * - E-mail: the old address's status moves to the new address (an ONAY is not carried onto an address that
 *   withdrew earlier; that address gets a confirmation e-mail instead) and the old address gets RET.
 * - Billing phone: the old number gets RET for each phone channel where it had ONAY.
 */
class Eru_CMI_Account {

	const NONCE = 'eru_cmi_account';

	/** @var array<int,string> Billing phone before an update, per user. */
	private static $old_phone = array();

	/** Hooks. */
	public static function init() {
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 20 );
		$slug = Eru_CMI_Settings::front()['endpoint'];
		add_action( 'woocommerce_account_' . $slug . '_endpoint', array( __CLASS__, 'render' ) );
		add_filter( 'woocommerce_endpoint_' . $slug . '_title', array( __CLASS__, 'title' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_post' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'profile_update', array( __CLASS__, 'email_changed' ), 20, 2 );
		add_action( 'update_user_meta', array( __CLASS__, 'phone_before' ), 10, 4 );
		add_action( 'updated_user_meta', array( __CLASS__, 'phone_after' ), 10, 4 );
	}

	/** @return bool Tab switched on (autoloaded flag). */
	private static function on() {
		return (bool) Eru_CMI_Settings::front()['account'];
	}

	/** @return string Endpoint slug. */
	private static function slug() {
		return Eru_CMI_Settings::front()['endpoint'];
	}

	/** @param array $vars WooCommerce query vars. @return array */
	public static function query_vars( $vars ) {
		if ( self::on() ) {
			$vars[ self::slug() ] = self::slug();
		}
		return $vars;
	}

	/** @param array $items Menu items. @return array */
	public static function menu( $items ) {
		if ( ! self::on() || ! is_array( $items ) ) {
			return $items;
		}
		$label  = __( 'İletişim tercihleri', 'consent-manager-for-iys' );
		$out    = array();
		$placed = false;
		foreach ( $items as $k => $v ) {
			if ( 'customer-logout' === $k && ! $placed ) {
				$out[ self::slug() ] = $label;
				$placed              = true;
			}
			$out[ $k ] = $v;
		}
		if ( ! $placed ) {
			$out[ self::slug() ] = $label;
		}
		return $out;
	}

	/** @return string Page title. */
	public static function title() {
		return __( 'İletişim tercihleri', 'consent-manager-for-iys' );
	}

	/**
	 * Recipients of an account per channel (normalised; '' when missing or not valid for the channel).
	 *
	 * @param int $user_id User.
	 * @return array<string,string>
	 */
	public static function recipients( $user_id ) {
		$user  = get_userdata( (int) $user_id );
		$phone = (string) get_user_meta( (int) $user_id, 'billing_phone', true );
		$out   = array();
		foreach ( Eru_CMI_Recipient::TYPES as $t ) {
			$raw       = 'EPOSTA' === $t ? ( $user ? $user->user_email : '' ) : $phone;
			$n         = Eru_CMI_Recipient::normalize( $raw, $t );
			$out[ $t ] = is_wp_error( $n ) ? '' : $n;
		}
		return $out;
	}

	/** Front-end assets, on the tab only. */
	public static function assets() {
		if ( ! self::on() || ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( self::slug() ) ) {
			return;
		}
		wp_enqueue_style( 'eru-cmi-account', ERU_CMI_URL . 'assets/account.css', array(), ERU_CMI_VERSION );
		wp_enqueue_script( 'eru-cmi-account', ERU_CMI_URL . 'assets/account.js', array(), ERU_CMI_VERSION, true );
	}

	/** Tab content. */
	public static function render() {
		if ( ! self::on() || ! is_user_logged_in() ) {
			return;
		}
		$uid  = get_current_user_id();
		$rcpt = self::recipients( $uid );
		$rows = array();
		foreach ( Eru_CMI_Settings::channels() as $t ) {
			$r          = '' === $rcpt[ $t ] ? null : Eru_CMI_Repository::get( $rcpt[ $t ], $t );
			$rows[ $t ] = array(
				'type'      => $t,
				'label'     => Eru_CMI_Links::channel_label( $t ),
				'recipient' => $rcpt[ $t ],
				'status'    => $r ? $r->status : '',
				'date'      => $r ? $r->consent_date : '',
				'locked'    => $r && 'RET' === $r->status && Eru_CMI_Recipient::is_phone_type( $t ),
			);
		}
		$page = (int) Eru_CMI_Settings::get( 'policy_page' );
		wc_get_template(
			'account-tab.php',
			array(
				'eru_cmi_rows'    => $rows,
				'eru_cmi_action'  => wc_get_account_endpoint_url( self::slug() ),
				'eru_cmi_policy'  => $page && 'publish' === get_post_status( $page ) ? array( get_permalink( $page ), get_the_title( $page ) ) : null,
				'eru_cmi_address' => wc_get_account_endpoint_url( 'edit-address' ),
			),
			'consent-manager-for-iys/',
			ERU_CMI_PATH . 'templates/'
		);
	}

	/** POST handler of the tab (redirects back with a notice). */
	public static function handle_post() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in process().
		if ( empty( $_POST['eru_cmi_account_save'] ) || ! is_user_logged_in() || ! self::on() ) {
			return;
		}
		$posted = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- verified and sanitised in process().
		$r      = self::process( get_current_user_id(), is_array( $posted ) ? $posted : array() );
		if ( is_wp_error( $r ) ) {
			wc_add_notice( $r->get_error_message(), 'error' );
		} else {
			wc_add_notice( __( 'İletişim tercihleriniz kaydedildi.', 'consent-manager-for-iys' ) );
			if ( in_array( 'confirm_sent', $r, true ) || in_array( 'confirm_pending', $r, true ) ) {
				wc_add_notice( __( 'E-posta iznini daha önce geri çekmiştiniz. Yeniden vermek için e-posta adresinize gönderdiğimiz bağlantıyı açıp onaylayın.', 'consent-manager-for-iys' ), 'notice' );
			}
			if ( in_array( 'confirm_failed', $r, true ) ) {
				wc_add_notice( __( 'E-posta iznini daha önce geri çekmiştiniz. Onay bağlantısı şu anda gönderilemedi; lütfen biraz sonra tekrar deneyin.', 'consent-manager-for-iys' ), 'error' );
			}
			if ( in_array( 'blocked', $r, true ) ) {
				wc_add_notice( self::phone_notice(), 'notice' );
			}
		}
		wp_safe_redirect( wc_get_account_endpoint_url( self::slug() ) );
		exit;
	}

	/**
	 * Saves the tab for its owner.
	 *
	 * @param int   $user_id User (must be the current user).
	 * @param array $posted  Unslashed POST: _eru_cmi_nonce, eru_cmi_channels[].
	 * @return array|WP_Error Channel => ONAY | RET (written), confirm_sent | confirm_pending | confirm_failed
	 *                        (e-mail RET: a confirmation link was mailed, one already went out today, or none could
	 *                        be sent now) or blocked (phone RET).
	 */
	public static function process( $user_id, array $posted ) {
		$user_id = (int) $user_id;
		if ( ! $user_id || get_current_user_id() !== $user_id || ! wp_verify_nonce( sanitize_text_field( (string) ( $posted['_eru_cmi_nonce'] ?? '' ) ), self::NONCE ) ) {
			return new WP_Error( 'eru_cmi_nonce', __( 'Oturumunuzun süresi dolmuş olabilir. Sayfayı yenileyip tekrar deneyin.', 'consent-manager-for-iys' ) );
		}
		if ( ! self::on() ) {
			return new WP_Error( 'eru_cmi_off', __( 'İletişim tercihleri şu anda değiştirilemiyor.', 'consent-manager-for-iys' ) );
		}
		$wanted  = array_map( 'strval', (array) ( $posted['eru_cmi_channels'] ?? array() ) );
		$rcpt    = self::recipients( $user_id );
		$changed = array();
		foreach ( Eru_CMI_Settings::channels() as $t ) {
			if ( '' === $rcpt[ $t ] ) {
				continue;
			}
			$row  = Eru_CMI_Repository::get( $rcpt[ $t ], $t );
			$want = in_array( $t, $wanted, true ) ? 'ONAY' : 'RET';
			if ( $row && $row->status === $want ) {
				continue;
			}
			if ( $row && 'RET' === $row->status ) { // ONAY wanted over a RET: needs proof the site cannot get here.
				if ( 'EPOSTA' === $t ) {
					$sent          = Eru_CMI_Links::request_confirm( $rcpt[ $t ] );
					$changed[ $t ] = 'sent' === $sent ? 'confirm_sent' : ( 'pending' === $sent ? 'confirm_pending' : 'confirm_failed' );
				} else {
					$changed[ $t ] = 'blocked';
				}
				continue;
			}
			$r = Eru_CMI_Repository::set(
				$rcpt[ $t ],
				$t,
				$want,
				array(
					'actor'    => 'user',
					'source'   => 'account',
					'actor_id' => $user_id,
					'user_id'  => $user_id,
				) + Eru_CMI_Links::client()
			);
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$changed[ $t ] = $want;
		}
		return $changed;
	}

	/** @return string Note shown for a phone channel whose consent was withdrawn. */
	public static function phone_notice() {
		return __( 'Bu numara için izni daha önce geri çektiniz. Yeniden izin vermek isterseniz İYS üzerinden (İleti Yönetim Sistemi) yeniden izin verebilirsiniz.', 'consent-manager-for-iys' );
	}

	/** @param int $user_id User whose address changed. @return string Actor for the change. */
	private static function actor_for( $user_id ) {
		if ( get_current_user_id() === (int) $user_id ) {
			return 'user';
		}
		return current_user_can( 'edit_users' ) ? 'admin' : 'api';
	}

	/**
	 * E-mail change.
	 *
	 * @param int     $user_id  User.
	 * @param WP_User $old_user User before the update.
	 */
	public static function email_changed( $user_id, $old_user ) {
		$user = get_userdata( (int) $user_id );
		if ( ! $user || ! $old_user instanceof WP_User || 0 === strcasecmp( (string) $old_user->user_email, (string) $user->user_email ) ) {
			return;
		}
		$old = Eru_CMI_Repository::get( $old_user->user_email, 'EPOSTA' );
		if ( ! $old || Eru_CMI_Repository::is_placeholder( $old ) ) {
			return;
		}
		$ctx = array(
			'actor'   => self::actor_for( $user_id ),
			'source'  => 'account',
			'user_id' => (int) $user_id,
		) + Eru_CMI_Links::client();
		$new = Eru_CMI_Repository::get( $user->user_email, 'EPOSTA' );
		if ( 'ONAY' === $old->status ) {
			if ( $new && 'RET' === $new->status ) {
				Eru_CMI_Links::send_confirm( $user->user_email );
			} elseif ( ! $new ) {
				Eru_CMI_Repository::set( $user->user_email, 'EPOSTA', 'ONAY', $ctx );
			}
			Eru_CMI_Repository::set( $old_user->user_email, 'EPOSTA', 'RET', $ctx );
		} elseif ( ! $new ) {
			Eru_CMI_Repository::set( $user->user_email, 'EPOSTA', 'RET', $ctx ); // A refusal follows the person.
		}
	}

	/**
	 * Remembers the billing phone before it is updated.
	 *
	 * @param int    $meta_id   Meta id.
	 * @param int    $user_id   User.
	 * @param string $key       Meta key.
	 * @param mixed  $value     New value.
	 */
	public static function phone_before( $meta_id, $user_id, $key, $value ) {
		if ( 'billing_phone' === $key ) {
			self::$old_phone[ (int) $user_id ] = (string) get_user_meta( (int) $user_id, 'billing_phone', true );
		}
	}

	/**
	 * Billing phone changed: RET for the old number where it had ONAY.
	 *
	 * @param int    $meta_id   Meta id.
	 * @param int    $user_id   User.
	 * @param string $key       Meta key.
	 * @param mixed  $value     New value.
	 */
	public static function phone_after( $meta_id, $user_id, $key, $value ) {
		if ( 'billing_phone' !== $key || ! isset( self::$old_phone[ (int) $user_id ] ) ) {
			return;
		}
		$old = self::$old_phone[ (int) $user_id ];
		unset( self::$old_phone[ (int) $user_id ] );
		$o = Eru_CMI_Recipient::normalize( $old, 'ARAMA' );
		$n = Eru_CMI_Recipient::normalize( (string) $value, 'ARAMA' );
		if ( is_wp_error( $o ) || ( ! is_wp_error( $n ) && $n === $o ) ) {
			return;
		}
		foreach ( array( 'MESAJ', 'ARAMA' ) as $t ) {
			$row = Eru_CMI_Repository::get( $o, $t );
			if ( $row && 'ONAY' === $row->status ) {
				Eru_CMI_Repository::set(
					$o,
					$t,
					'RET',
					array(
						'actor'   => self::actor_for( $user_id ),
						'source'  => 'account',
						'user_id' => (int) $user_id,
					) + Eru_CMI_Links::client()
				);
			}
		}
	}
}
