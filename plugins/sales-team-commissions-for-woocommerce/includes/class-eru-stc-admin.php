<?php
/**
 * Admin: the "Satış Ekibi" menu, notices, form handlers (admin-post), list bulk actions and AJAX reads.
 * Every handler checks the manage_eru_stc capability (a meta-capability of manage_woocommerce) and a
 * nonce before it reads any input. Screens are rendered by Eru_STC_Admin_Pages.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Admin {

	const CAP = 'manage_eru_stc';

	/** Admin page slugs. */
	const PAGES = array(
		'dashboard'   => 'eru-stc',
		'agents'      => 'eru-stc-agents',
		'rules'       => 'eru-stc-rules',
		'commissions' => 'eru-stc-commissions',
		'payouts'     => 'eru-stc-payouts',
		'reports'     => 'eru-stc-reports',
		'settings'    => 'eru-stc-settings',
	);

	/** admin-post actions → handler. */
	const POSTS = array(
		'eru_stc_agent_save'         => 'handle_agent_save',
		'eru_stc_agent_delete'       => 'handle_agent_delete',
		'eru_stc_link'               => 'handle_link',
		'eru_stc_unlink'             => 'handle_unlink',
		'eru_stc_rule_save'          => 'handle_rule_save',
		'eru_stc_rule_delete'        => 'handle_rule_delete',
		'eru_stc_recalc'             => 'handle_recalc',
		'eru_stc_payout_paid'        => 'handle_payout_paid',
		'eru_stc_payout_cancel'      => 'handle_payout_cancel',
		'eru_stc_payout_create'      => 'handle_payout_create',
		'eru_stc_payouts_export'     => 'handle_payouts_export',
		'eru_stc_commissions_export' => 'handle_commissions_export',
		'eru_stc_report_export'      => 'handle_report_export',
		'eru_stc_settings_save'      => 'handle_settings_save',
	);

	/** Wires the admin hooks. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		foreach ( self::POSTS as $action => $method ) {
			add_action( 'admin_post_' . $action, array( __CLASS__, $method ) );
		}
		add_action( 'wp_ajax_eru_stc_user_search', array( __CLASS__, 'ajax_user_search' ) );
		add_action( 'wp_ajax_eru_stc_agent_commissions', array( __CLASS__, 'ajax_agent_commissions' ) );
	}

	/** Top-level menu "Satış Ekibi" with its screens. */
	public static function menu() {
		$pending = current_user_can( self::CAP ) ? Eru_STC_Agents::count( array( 'status' => 'pending' ) ) : 0;
		$open    = current_user_can( self::CAP ) ? Eru_STC_Payouts::count( array( 'status' => 'requested' ) ) : 0;
		$bubble  = fn( $n ) => $n > 0 ? ' <span class="awaiting-mod count-' . (int) $n . '"><span class="pending-count">' . (int) $n . '</span></span>' : '';
		add_menu_page( __( 'Satış Ekibi', 'sales-team-commissions-for-woocommerce' ), __( 'Satış Ekibi', 'sales-team-commissions-for-woocommerce' ) . $bubble( $pending + $open ), self::CAP, self::PAGES['dashboard'], array( 'Eru_STC_Admin_Pages', 'dashboard' ), 'dashicons-groups', 56 );
		$subs = array(
			'dashboard'   => __( 'Pano', 'sales-team-commissions-for-woocommerce' ),
			'agents'      => __( 'Temsilciler', 'sales-team-commissions-for-woocommerce' ) . $bubble( $pending ),
			'rules'       => __( 'Kurallar', 'sales-team-commissions-for-woocommerce' ),
			'commissions' => __( 'Komisyonlar', 'sales-team-commissions-for-woocommerce' ),
			'payouts'     => __( 'Ödemeler', 'sales-team-commissions-for-woocommerce' ) . $bubble( $open ),
			'reports'     => __( 'Raporlar', 'sales-team-commissions-for-woocommerce' ),
			'settings'    => __( 'Ayarlar', 'sales-team-commissions-for-woocommerce' ),
		);
		foreach ( $subs as $key => $label ) {
			$hook = add_submenu_page( self::PAGES['dashboard'], wp_strip_all_tags( $label ), $label, self::CAP, self::PAGES[ $key ], array( 'Eru_STC_Admin_Pages', $key ) );
			if ( 'commissions' === $key ) {
				add_action( 'load-' . $hook, array( __CLASS__, 'commissions_bulk_request' ) );
			} elseif ( 'agents' === $key ) {
				add_action( 'load-' . $hook, array( __CLASS__, 'agents_bulk_request' ) );
			}
		}
	}

	/** @return string Current plugin screen key, '' elsewhere. */
	public static function screen() {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key  = array_search( $page, self::PAGES, true );
		return false === $key ? '' : (string) $key;
	}

	/** Styles and the small admin script on the plugin's screens only. */
	public static function assets() {
		$screen = self::screen();
		if ( '' === $screen ) {
			return;
		}
		wp_enqueue_style( 'eru-stc-admin', ERU_STC_URL . 'assets/css/admin.css', array(), ERU_STC_VERSION );
		if ( 'rules' === $screen && wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
			wp_enqueue_script( 'wc-enhanced-select' );
			wp_enqueue_style( 'woocommerce_admin_styles' );
		}
		wp_enqueue_script( 'eru-stc-admin', ERU_STC_URL . 'assets/js/admin.js', array(), ERU_STC_VERSION, true );
		wp_localize_script(
			'eru-stc-admin',
			'eruStcAdmin',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'eru_stc_admin' ),
				'more'    => __( 'Daha fazla', 'sales-team-commissions-for-woocommerce' ),
				'none'    => __( 'Başka kayıt yok.', 'sales-team-commissions-for-woocommerce' ),
				'confirm' => __( 'Tam IBAN içeren dosyayı indirmek üzeresiniz. Dosyayı güvenli saklayın ve işiniz bitince silin. Devam edilsin mi?', 'sales-team-commissions-for-woocommerce' ),
			)
		);
	}

	/** Notices on the plugin's screens: module off, encryption key, one-shot results. */
	public static function notices() {
		$screen = self::screen();
		if ( '' === $screen || ! current_user_can( self::CAP ) ) {
			return;
		}
		$flash = get_transient( 'eru_stc_notice_' . get_current_user_id() );
		if ( is_array( $flash ) && isset( $flash['text'] ) ) {
			delete_transient( 'eru_stc_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>', esc_attr( 'error' === $flash['type'] ? 'error' : 'success' ), esc_html( $flash['text'] ) );
		}
		if ( ! Eru_STC_Settings::enabled() && in_array( $screen, array( 'dashboard', 'settings', 'commissions' ), true ) ) {
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Modül kapalı.', 'sales-team-commissions-for-woocommerce' ) . '</strong> ' . esc_html__( 'Yeni komisyon yazılmaz, davet linki çerezi ve Hesabım paneli çalışmaz, onaylar duraklar: bekleyen komisyonlar bekleme süresi dolsa da modül açılana kadar onaylanmaz. İade ve iptaller mevcut satırları düzeltmeye devam eder.', 'sales-team-commissions-for-woocommerce' ) . '</p></div>';
		}
		if ( 'yes' === Eru_STC_Settings::get( 'payout_details_form' ) && ! Eru_STC_Crypto::available() ) {
			$state = Eru_STC_Crypto::state();
			$why   = 'invalid' === $state ? __( 'ERU_STC_ENCRYPTION_KEY tanımlı ama geçersiz (32 baytlık değerin base64 hâli olmalı).', 'sales-team-commissions-for-woocommerce' ) : ( 'no_sodium' === $state ? __( 'Sunucuda PHP sodium eklentisi yok.', 'sales-team-commissions-for-woocommerce' ) : __( 'ERU_STC_ENCRYPTION_KEY tanımlı değil.', 'sales-team-commissions-for-woocommerce' ) );
			echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Ödeme bilgileri kapalı:', 'sales-team-commissions-for-woocommerce' ) . '</strong> ' . esc_html( $why ) . ' ' . esc_html__( 'Temsilciler IBAN ve T.C. kimlik no giremez; kayıtlı değerler "anahtar yok" olarak görünür. Anahtarı wp-config.php dosyasına ekleyin (bir kez üretin, yedekleyin; değişirse kayıtlı değerler okunamaz):', 'sales-team-commissions-for-woocommerce' ) . '</p><p><code>wp config set ERU_STC_ENCRYPTION_KEY "$(php -r \'echo base64_encode(random_bytes(32));\')" --type=constant</code></p></div>';
		}
	}

	/**
	 * Capability, then nonce. Dies with 403 on either.
	 *
	 * @param string $nonce_action Nonce action.
	 */
	public static function guard( $nonce_action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'sales-team-commissions-for-woocommerce' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $nonce_action );
	}

	/**
	 * Stores a one-shot notice for the current user and redirects.
	 *
	 * @param string $page  Page key.
	 * @param array  $args  Query args.
	 * @param string $text  Message.
	 * @param string $type  success | error.
	 */
	public static function back( $page, array $args = array(), $text = '', $type = 'success' ) {
		if ( '' !== $text ) {
			set_transient(
				'eru_stc_notice_' . get_current_user_id(),
				array(
					'type' => $type,
					'text' => $text,
				),
				120
			);
		}
		wp_safe_redirect( add_query_arg( array_merge( array( 'page' => self::PAGES[ $page ] ), $args ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/** @param string $key POST field. @return string Unslashed, sanitised text. */
	private static function post( $key ) {
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- callers run guard() first.
	}

	/**
	 * A user from an id, e-mail or login typed by an admin.
	 *
	 * @param string $raw Input.
	 * @return WP_User|false
	 */
	private static function find_user( $raw ) {
		$raw = trim( (string) $raw );
		if ( '' === $raw ) {
			return false;
		}
		if ( ctype_digit( $raw ) ) {
			return get_userdata( (int) $raw );
		}
		return is_email( $raw ) ? get_user_by( 'email', $raw ) : get_user_by( 'login', $raw );
	}

	/** Create or update an agent. */
	public static function handle_agent_save() {
		self::guard( 'eru_stc_agent_save' );
		$id     = absint( self::post( 'agent_id' ) );
		$fields = array(
			'status'          => self::post( 'status' ),
			'parent_agent_id' => absint( self::post( 'parent_agent_id' ) ),
		);
		$name   = self::post( 'display_name' );
		$code   = self::post( 'referral_code' );
		if ( $id ) {
			$agent = Eru_STC_Agents::get( $id );
			if ( ! $agent ) {
				self::back( 'agents', array(), __( 'Temsilci bulunamadı.', 'sales-team-commissions-for-woocommerce' ), 'error' );
			}
			$settings                 = Eru_STC_Agents::settings( $agent );
			$settings['display_name'] = $name;
			$fields['settings']       = array_filter( $settings, fn( $v ) => '' !== $v && null !== $v );
			if ( '' !== $code ) {
				$fields['referral_code'] = $code;
			}
			$r = Eru_STC_Agents::update( $id, $fields );
			if ( is_wp_error( $r ) ) {
				self::back( 'agents', array( 'agent' => $id ), $r->get_error_message(), 'error' );
			}
			self::back( 'agents', array( 'agent' => $id ), __( 'Temsilci kaydedildi.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$user = self::find_user( self::post( 'user' ) );
		if ( ! $user ) {
			self::back( 'agents', array( 'action' => 'new' ), __( 'Kullanıcı bulunamadı: e-posta, kullanıcı adı veya kullanıcı numarası girin.', 'sales-team-commissions-for-woocommerce' ), 'error' );
		}
		$new = Eru_STC_Agents::create(
			$user->ID,
			array(
				'status'          => in_array( $fields['status'], Eru_STC_Agents::STATUSES, true ) ? $fields['status'] : 'active',
				'parent_agent_id' => $fields['parent_agent_id'],
				'referral_code'   => $code,
				'settings'        => array( 'display_name' => '' !== $name ? $name : $user->display_name ),
			)
		);
		if ( is_wp_error( $new ) ) {
			self::back( 'agents', array( 'action' => 'new' ), $new->get_error_message(), 'error' );
		}
		self::back( 'agents', array( 'agent' => $new ), __( 'Temsilci oluşturuldu.', 'sales-team-commissions-for-woocommerce' ) );
	}

	/** Delete an agent without history. */
	public static function handle_agent_delete() {
		self::guard( 'eru_stc_agent_delete' );
		$id = absint( self::post( 'agent_id' ) );
		$r  = Eru_STC_Agents::delete( $id );
		if ( is_wp_error( $r ) ) {
			self::back( 'agents', array( 'agent' => $id ), $r->get_error_message(), 'error' );
		}
		self::back( 'agents', array(), __( 'Temsilci silindi.', 'sales-team-commissions-for-woocommerce' ) );
	}

	/** Link a customer to an agent by hand (admin decision; can move a locked link with "force"). */
	public static function handle_link() {
		self::guard( 'eru_stc_link' );
		$agent_id = absint( self::post( 'agent_id' ) );
		$user     = self::find_user( self::post( 'customer' ) );
		if ( ! $user ) {
			self::back( 'agents', array( 'agent' => $agent_id ), __( 'Müşteri bulunamadı.', 'sales-team-commissions-for-woocommerce' ), 'error' );
		}
		$r = Eru_STC_Links::link(
			$user->ID,
			$agent_id,
			'manual',
			array(
				'force'      => '1' === self::post( 'force' ),
				'created_by' => get_current_user_id(),
			)
		);
		if ( is_wp_error( $r ) ) {
			self::back( 'agents', array( 'agent' => $agent_id ), $r->get_error_message(), 'error' );
		}
		self::back( 'agents', array( 'agent' => $agent_id ), __( 'Müşteri temsilciye bağlandı.', 'sales-team-commissions-for-woocommerce' ) );
	}

	/** Remove a customer link. */
	public static function handle_unlink() {
		self::guard( 'eru_stc_unlink' );
		$agent_id = absint( self::post( 'agent_id' ) );
		Eru_STC_Links::unlink( absint( self::post( 'customer_id' ) ) );
		self::back( 'agents', array( 'agent' => $agent_id ), __( 'Bağlantı kaldırıldı.', 'sales-team-commissions-for-woocommerce' ) );
	}

	/** Create or update a rule. */
	public static function handle_rule_save() {
		self::guard( 'eru_stc_rule_save' );
		$id     = absint( self::post( 'rule_id' ) );
		$scope  = self::post( 'scope' );
		$target = 0;
		if ( in_array( $scope, array( 'product', 'category', 'brand', 'agent' ), true ) ) {
			$target = absint( self::post( 'target_' . $scope ) );
		}
		$local  = fn( $d, $time ) => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d ) ? get_gmt_from_date( $d . ' ' . $time ) : '';
		$fields = array(
			'name'       => self::post( 'name' ),
			'scope'      => $scope,
			'target_id'  => $target,
			'target_key' => 'role' === $scope ? self::post( 'target_role' ) : '',
			'level'      => absint( self::post( 'level' ) ),
			'type'       => self::post( 'type' ),
			'rate'       => str_replace( ',', '.', self::post( 'rate' ) ),
			'starts_at'  => $local( self::post( 'starts_at' ), '00:00:00' ),
			'ends_at'    => $local( self::post( 'ends_at' ), '23:59:59' ),
			'priority'   => (int) self::post( 'priority' ),
			'active'     => '1' === self::post( 'active' ) ? 1 : 0,
		);
		$r      = $id ? Eru_STC_Rules::update( $id, $fields ) : Eru_STC_Rules::create( $fields );
		if ( is_wp_error( $r ) ) {
			self::back( 'rules', $id ? array( 'edit' => $id ) : array(), $r->get_error_message(), 'error' );
		}
		self::back( 'rules', array( 'edit' => $id ? $id : (int) $r ), __( 'Kural kaydedildi.', 'sales-team-commissions-for-woocommerce' ) );
	}

	/** Delete a rule (rows already written keep their rule snapshot). */
	public static function handle_rule_delete() {
		self::guard( 'eru_stc_rule_delete' );
		Eru_STC_Rules::delete( absint( self::post( 'rule_id' ) ) );
		self::back( 'rules', array(), __( 'Kural silindi.', 'sales-team-commissions-for-woocommerce' ) );
	}

	/** Recalculate one order (same as wp eru-stc recalc). */
	public static function handle_recalc() {
		self::guard( 'eru_stc_recalc' );
		$order    = wc_get_order( absint( self::post( 'order_id' ) ) );
		$agent_id = absint( self::post( 'agent_id' ) );
		if ( ! $order instanceof WC_Order || $order instanceof WC_Order_Refund ) {
			self::back( 'commissions', array(), __( 'Sipariş bulunamadı.', 'sales-team-commissions-for-woocommerce' ), 'error' );
		}
		$trigger = Eru_STC_Settings::get( 'trigger_status' );
		if ( ! ( 'completed' === $trigger ? $order->has_status( 'completed' ) : $order->has_status( array( 'processing', 'completed' ) ) ) ) {
			self::back( 'commissions', array(), __( 'Sipariş komisyon yazılan durumda değil.', 'sales-team-commissions-for-woocommerce' ), 'error' );
		}
		if ( $agent_id ) {
			if ( ! Eru_STC_Agents::is_active( Eru_STC_Agents::get( $agent_id ) ) ) {
				self::back( 'commissions', array(), __( 'Temsilci bulunamadı veya etkin değil.', 'sales-team-commissions-for-woocommerce' ), 'error' );
			}
			if ( Eru_STC_Calculator::level1_conflict( $order->get_id(), $agent_id ) ) {
				self::back( 'commissions', array( 'order_id' => $order->get_id() ), self::conflict_message(), 'error' );
			}
			$order->update_meta_data( '_eru_stc_agent_id', $agent_id );
			$order->update_meta_data( '_eru_stc_source', 'manual' );
			$order->save();
		}
		if ( Eru_STC_Calculator::agent_for_order( $order ) <= 0 ) {
			self::back( 'commissions', array(), __( 'Siparişin temsilcisi yok: müşteriyi bir temsilciye bağlayın ya da temsilci seçin.', 'sales-team-commissions-for-woocommerce' ), 'error' );
		}
		$ids = Eru_STC_Calculator::calculate( $order, true );
		Eru_STC_Refunds::reconcile( $order->get_id(), 'refund' );
		/* translators: 1: order number, 2: rows */
		self::back( 'commissions', array( 'order_id' => $order->get_id() ), sprintf( __( '#%1$d yeniden hesaplandı: %2$d yeni satır.', 'sales-team-commissions-for-woocommerce' ), $order->get_id(), count( $ids ) ) );
	}

	/** @return string Why a recalculation for another agent is refused. */
	public static function conflict_message() {
		return __( 'Siparişte başka bir temsilciye yazılmış komisyon var; önce o satırları iptal edin, sonra yeniden hesaplayın.', 'sales-team-commissions-for-woocommerce' );
	}

	/** Mark a payout paid (method, reference). */
	public static function handle_payout_paid() {
		self::guard( 'eru_stc_payout' );
		$id      = absint( self::post( 'payout_id' ) );
		$method  = sanitize_key( self::post( 'method' ) );
		$methods = Eru_STC_Labels::methods();
		$r       = Eru_STC_Payouts::mark_paid( $id, self::post( 'reference' ), isset( $methods[ $method ] ) ? $method : '' );
		if ( is_wp_error( $r ) ) {
			self::back( 'payouts', array(), $r->get_error_message(), 'error' );
		}
		/* translators: %d: payout number */
		self::back( 'payouts', array(), sprintf( __( '#%d ödendi olarak işaretlendi.', 'sales-team-commissions-for-woocommerce' ), $id ) );
	}

	/** Cancel a payout request (its rows become payable again). */
	public static function handle_payout_cancel() {
		self::guard( 'eru_stc_payout' );
		$id = absint( self::post( 'payout_id' ) );
		$r  = Eru_STC_Payouts::cancel( $id );
		if ( is_wp_error( $r ) ) {
			self::back( 'payouts', array(), $r->get_error_message(), 'error' );
		}
		/* translators: %d: payout number */
		self::back( 'payouts', array(), sprintf( __( '#%d iptal edildi; satırlar yeniden ödenebilir.', 'sales-team-commissions-for-woocommerce' ), $id ) );
	}

	/** Open a payout request for an agent's available balance (the minimum does not apply). */
	public static function handle_payout_create() {
		self::guard( 'eru_stc_payout' );
		$agent_id = absint( self::post( 'agent_id' ) );
		$r        = Eru_STC_Payouts::create_request(
			$agent_id,
			array(
				'created_by'     => get_current_user_id(),
				'ignore_minimum' => true,
				'note'           => __( 'Yönetici oluşturdu', 'sales-team-commissions-for-woocommerce' ),
			)
		);
		if ( is_wp_error( $r ) ) {
			self::back( 'agents', array( 'agent' => $agent_id ), $r->get_error_message(), 'error' );
		}
		/* translators: %d: payout number */
		self::back( 'payouts', array(), sprintf( __( 'Ödeme talebi #%d oluşturuldu.', 'sales-team-commissions-for-woocommerce' ), $r ) );
	}

	/**
	 * Server-side confirmation of a full-IBAN export: the "tam IBAN" checkbox, the "güvenli saklayacağım"
	 * checkbox and the export's own nonce field (separate from the form nonce).
	 *
	 * @param array $post Unslashed POST.
	 * @return true|WP_Error
	 */
	public static function full_export_check( array $post ) {
		if ( '1' !== (string) ( $post['full_iban'] ?? '' ) || '1' !== (string) ( $post['full_iban_ack'] ?? '' ) ) {
			return new WP_Error( 'eru_stc_full_ack', __( 'Tam IBAN dışa aktarımı için "dosyayı güvenli saklayacağım" kutusunu da işaretleyin.', 'sales-team-commissions-for-woocommerce' ) );
		}
		if ( ! wp_verify_nonce( (string) ( $post['_eru_stc_full'] ?? '' ), 'eru_stc_full_iban' ) ) {
			return new WP_Error( 'eru_stc_full_nonce', __( 'Tam IBAN onayı geçersiz; sayfayı yenileyip tekrar deneyin.', 'sales-team-commissions-for-woocommerce' ) );
		}
		return true;
	}

	/** Payout CSV: masked unless the full-IBAN export is confirmed server side (POST only, audited). */
	public static function handle_payouts_export() {
		self::guard( 'eru_stc_export' );
		$full   = '1' === self::post( 'full_iban' );
		$status = self::post( 'status' );
		$args   = in_array( $status, Eru_STC_Payouts::STATUSES, true ) ? array( 'status' => $status ) : array();
		if ( $full ) {
			$check = self::full_export_check( wp_unslash( $_POST ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- guard() ran; values are compared, not stored.
			if ( is_wp_error( $check ) ) {
				self::back( 'payouts', array(), $check->get_error_message(), 'error' );
			}
			Eru_STC_Reports::record_full_export( get_current_user_id(), $args, Eru_STC_Payouts::count( $args ) );
			/**
			 * Fires when an admin downloads payouts with full IBANs (also recorded in the export audit).
			 *
			 * @param int   $user_id Admin.
			 * @param array $args    Filters.
			 */
			do_action( 'eru_stc_full_iban_exported', get_current_user_id(), $args );
		}
		Eru_STC_CSV::send( 'odemeler-' . gmdate( 'Ymd-His' ) . ( $full ? '-tam' : '' ) . '.csv', Eru_STC_Reports::payouts_csv( $args, $full ) );
		exit;
	}

	/** Commissions CSV with the list filters. */
	public static function handle_commissions_export() {
		self::guard( 'eru_stc_export' );
		$args   = array( 'agent_id' => absint( self::post( 'agent_id' ) ) );
		$status = self::post( 'status' );
		if ( in_array( $status, Eru_STC_Commissions::STATUSES, true ) ) {
			$args['status'] = $status;
		}
		$month = self::post( 'month' );
		if ( 1 === preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			list( $args['from'], $args['to'] ) = Eru_STC_Reports::month_range( $month );
		}
		Eru_STC_CSV::send( 'komisyonlar-' . gmdate( 'Ymd-His' ) . '.csv', Eru_STC_Reports::commissions_csv( $args ) );
		exit;
	}

	/** Monthly report CSV (one row per agent). */
	public static function handle_report_export() {
		self::guard( 'eru_stc_export' );
		$month = self::post( 'month' );
		Eru_STC_CSV::send( 'rapor-' . ( 1 === preg_match( '/^\d{4}-\d{2}$/', $month ) ? $month : gmdate( 'Y-m' ) ) . '.csv', Eru_STC_Reports::agents_csv( $month, absint( self::post( 'agent_id' ) ) ) );
		exit;
	}

	/** Settings form. */
	public static function handle_settings_save() {
		self::guard( 'eru_stc_settings' );
		$before = Eru_STC_Settings::get();
		$in     = array();
		foreach ( array( 'enabled', 'include_shipping', 'payout_details_form', 'self_registration', 'customer_invites' ) as $k ) {
			$in[ $k ] = '1' === self::post( $k ) ? 'yes' : 'no';
		}
		foreach ( array( 'trigger_status', 'refund_policy', 'assignment_mode', 'max_levels', 'hold_days', 'cookie_days' ) as $k ) {
			$in[ $k ] = self::post( $k );
		}
		$in['min_payout'] = str_replace( ',', '.', self::post( 'min_payout' ) );
		foreach ( array_keys( Eru_STC_Settings::ENDPOINTS ) as $k ) {
			$in[ 'endpoint_' . $k ] = self::post( 'endpoint_' . $k );
		}
		$after   = Eru_STC_Settings::sanitize( array_merge( $before, $in ) );
		$rewrite = $after['enabled'] !== $before['enabled'];
		foreach ( array_keys( Eru_STC_Settings::ENDPOINTS ) as $k ) {
			$rewrite = $rewrite || $after[ 'endpoint_' . $k ] !== $before[ 'endpoint_' . $k ];
		}
		if ( $rewrite ) {
			$in['rewrite_pending'] = 1;
		}
		Eru_STC_Settings::update( $in );
		if ( 'yes' === $after['enabled'] && 'yes' !== $before['enabled'] ) {
			delete_option( 'eru_stc_watchdog_at' ); // The approval job is armed on the next Action Scheduler run.
		} elseif ( 'yes' !== $after['enabled'] && 'yes' === $before['enabled'] ) {
			Eru_STC_Eligibility::unschedule_all();
		}
		self::back( 'settings', array(), __( 'Ayarlar kaydedildi.', 'sales-team-commissions-for-woocommerce' ) );
	}

	/** @return string Bulk action from the list form ('' when none). */
	private static function bulk_action() {
		// phpcs:disable WordPress.Security.NonceVerification -- read only to decide whether a bulk action was sent; guard() verifies.
		foreach ( array( 'action', 'action2' ) as $k ) {
			if ( isset( $_REQUEST[ $k ] ) && is_string( $_REQUEST[ $k ] ) && '-1' !== $_REQUEST[ $k ] && '' !== $_REQUEST[ $k ] ) {
				return sanitize_key( wp_unslash( $_REQUEST[ $k ] ) );
			}
		}
		// phpcs:enable
		return '';
	}

	/** @return int[] Selected row ids. */
	private static function bulk_ids() {
		return isset( $_REQUEST['ids'] ) ? array_values( array_filter( array_map( 'absint', (array) wp_unslash( $_REQUEST['ids'] ) ) ) ) : array(); // phpcs:ignore WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint; guard() ran.
	}

	/** Bulk approve / void on the commissions list (load-<page>). */
	public static function commissions_bulk_request() {
		$action = self::bulk_action();
		if ( ! in_array( $action, array( 'approve', 'void' ), true ) ) {
			return;
		}
		self::guard( 'bulk-commissions' );
		$done = 0;
		foreach ( self::bulk_ids() as $id ) {
			$done += (int) ( 'approve' === $action ? Eru_STC_Commissions::approve( $id ) : Eru_STC_Commissions::void_manual( $id ) );
		}
		/* translators: %d: rows */
		self::back( 'commissions', array(), sprintf( 'approve' === $action ? __( '%d satır onaylandı.', 'sales-team-commissions-for-woocommerce' ) : __( '%d satır iptal edildi.', 'sales-team-commissions-for-woocommerce' ), $done ) );
	}

	/** Bulk approve / suspend / activate on the agents list (load-<page>). */
	public static function agents_bulk_request() {
		$action = self::bulk_action();
		$map    = array(
			'approve'  => 'active',
			'activate' => 'active',
			'suspend'  => 'suspended',
		);
		if ( ! isset( $map[ $action ] ) ) {
			return;
		}
		self::guard( 'bulk-agents' );
		$done = 0;
		foreach ( self::bulk_ids() as $id ) {
			$agent = Eru_STC_Agents::get( $id );
			if ( $agent && ( 'approve' !== $action || 'pending' === $agent->status ) && true === Eru_STC_Agents::update( $id, array( 'status' => $map[ $action ] ) ) ) {
				++$done;
			}
		}
		/* translators: %d: agents */
		self::back( 'agents', array(), sprintf( __( '%d temsilci güncellendi.', 'sales-team-commissions-for-woocommerce' ), $done ) );
	}

	/**
	 * JSON reply with an explicit status line (wp_send_json() sets it only while no output was sent).
	 *
	 * @param bool  $ok     Success.
	 * @param mixed $data   Payload.
	 * @param int   $status HTTP status.
	 */
	private static function json( $ok, $data, $status = 200 ) {
		status_header( $status );
		wp_send_json(
			array(
				'success' => (bool) $ok,
				'data'    => $data,
			),
			$status
		);
	}

	/** @return bool Capability and the admin AJAX nonce. */
	private static function ajax_allowed() {
		return current_user_can( self::CAP ) && false !== check_ajax_referer( 'eru_stc_admin', 'nonce', false );
	}

	/** Users for the "new agent" / "link customer" fields (id, name, e-mail; max 20). */
	public static function ajax_user_search() {
		if ( ! self::ajax_allowed() ) {
			self::json( false, array( 'message' => __( 'Yetkiniz yok.', 'sales-team-commissions-for-woocommerce' ) ), 403 );
		}
		$term = isset( $_REQUEST['term'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['term'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification -- checked in ajax_allowed().
		if ( mb_strlen( $term ) < 2 ) {
			self::json( true, array() );
		}
		$out = array();
		foreach ( get_users(
			array(
				'search'         => '*' . $term . '*',
				'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
				'number'         => 20,
				'fields'         => array( 'ID', 'display_name', 'user_email' ),
			)
		) as $u ) {
			$out[] = array(
				'id'    => (int) $u->ID,
				'name'  => $u->display_name,
				'email' => $u->user_email,
			);
		}
		self::json( true, $out );
	}

	/** An agent's commission rows for the detail screen ("daha fazla"). */
	public static function ajax_agent_commissions() {
		if ( ! self::ajax_allowed() ) {
			self::json( false, array( 'message' => __( 'Yetkiniz yok.', 'sales-team-commissions-for-woocommerce' ) ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- checked in ajax_allowed().
		$agent_id = isset( $_REQUEST['agent_id'] ) ? absint( $_REQUEST['agent_id'] ) : 0;
		$offset   = isset( $_REQUEST['offset'] ) ? absint( $_REQUEST['offset'] ) : 0;
		// phpcs:enable
		if ( ! Eru_STC_Agents::get( $agent_id ) ) {
			self::json( false, array( 'message' => __( 'Temsilci bulunamadı.', 'sales-team-commissions-for-woocommerce' ) ), 404 );
		}
		$rows = array();
		foreach ( Eru_STC_Commissions::list(
			array(
				'agent_id' => $agent_id,
				'limit'    => 20,
				'offset'   => $offset,
			)
		) as $r ) {
			$rows[] = array_merge(
				Eru_STC_Rest::commission_data( $r ),
				array(
					'status_label' => Eru_STC_Labels::commission_status( $r->status ),
					'date'         => get_date_from_gmt( $r->earned_at, get_option( 'date_format' ) ),
				)
			);
		}
		self::json( true, $rows );
	}
}
