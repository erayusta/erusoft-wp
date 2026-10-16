<?php
/**
 * My Account panel for agents. Endpoints (slugs are settings): panel, team, commissions, payouts and
 * payout details. Every form posts to the endpoint and is handled here, before any output
 * (template_redirect), never in a template. Templates only print the prepared values and can be
 * overridden in a theme under sales-team-commissions-for-woocommerce/myaccount/.
 *
 * Nothing is registered while the module is off. Pending and suspended agents see the panel with their
 * status only; with self-registration on, customers who are not agents see an application form there.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Account {

	/** Endpoint key → WooCommerce query var key (the URL part is the setting). */
	const KEYS = array(
		'panel'       => 'eru-stc-panel',
		'team'        => 'eru-stc-team',
		'commissions' => 'eru-stc-commissions',
		'payouts'     => 'eru-stc-payouts',
		'details'     => 'eru-stc-details',
	);

	const ACTIONS  = array( 'payout_request', 'details_save', 'details_delete', 'invite', 'apply' );
	const PER_PAGE = 20;

	/** @var array<int,object|null> Agent row per user for this request. */
	private static $agents = array();

	/** Wires the endpoints while the module is on. */
	public static function init() {
		if ( ! Eru_STC_Settings::enabled() ) {
			return;
		}
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 20 );
		foreach ( self::KEYS as $key => $var ) {
			add_action( 'woocommerce_account_' . $var . '_endpoint', array( __CLASS__, 'endpoint_' . $key ) );
			add_filter( 'woocommerce_endpoint_' . $var . '_title', array( __CLASS__, 'title_' . $key ) );
		}
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 5 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** One rewrite flush after the slugs or the switch changed (flag in the autoloaded settings). */
	public static function maybe_flush() {
		if ( 1 !== (int) Eru_STC_Settings::get( 'rewrite_pending' ) ) {
			return;
		}
		Eru_STC_Settings::update( array( 'rewrite_pending' => 0 ) );
		flush_rewrite_rules( false );
	}

	/** @param array $vars Query vars. @return array */
	public static function query_vars( $vars ) {
		$vars = (array) $vars;
		foreach ( self::KEYS as $key => $var ) {
			$vars[ $var ] = Eru_STC_Settings::endpoint( $key );
		}
		return $vars;
	}

	/** @param int $user_id User. @return object|null Agent row (cached per request). */
	public static function agent_of( $user_id ) {
		$user_id = (int) $user_id;
		if ( ! array_key_exists( $user_id, self::$agents ) ) {
			self::$agents[ $user_id ] = $user_id > 0 ? Eru_STC_Agents::get_by_user( $user_id ) : null;
		}
		return self::$agents[ $user_id ];
	}

	/** Drops the per-request agent cache (after a change). */
	public static function flush_cache() {
		self::$agents = array();
	}

	/** @return string[] Menu labels per key. */
	public static function labels() {
		return array(
			'panel'       => __( 'Temsilci paneli', 'sales-team-commissions-for-woocommerce' ),
			'team'        => __( 'Ekibim', 'sales-team-commissions-for-woocommerce' ),
			'commissions' => __( 'Komisyonlar', 'sales-team-commissions-for-woocommerce' ),
			'payouts'     => __( 'Ödemeler', 'sales-team-commissions-for-woocommerce' ),
			'details'     => __( 'Ödeme bilgileri', 'sales-team-commissions-for-woocommerce' ),
		);
	}

	/**
	 * Adds the agent items before "Çıkış yap".
	 *
	 * @param array $items Menu items.
	 * @return array
	 */
	public static function menu( $items ) {
		$items = (array) $items;
		$agent = self::agent_of( get_current_user_id() );
		$add   = array();
		$label = self::labels();
		if ( Eru_STC_Agents::is_active( $agent ) ) {
			foreach ( self::KEYS as $key => $var ) {
				if ( 'details' === $key && 'yes' !== Eru_STC_Settings::get( 'payout_details_form' ) ) {
					continue;
				}
				$add[ $var ] = $label[ $key ];
			}
		} elseif ( $agent ) {
			$add[ self::KEYS['panel'] ] = $label['panel'];
		} elseif ( 'yes' === Eru_STC_Settings::get( 'self_registration' ) && get_current_user_id() ) {
			$add[ self::KEYS['panel'] ] = __( 'Temsilci başvurusu', 'sales-team-commissions-for-woocommerce' );
		}
		if ( ! $add ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $key => $text ) {
			if ( 'customer-logout' === $key ) {
				$out = array_merge( $out, $add );
				$add = array();
			}
			$out[ $key ] = $text;
		}
		return array_merge( $out, $add );
	}

	/** Titles. */
	public static function title_panel() {
		$agent = self::agent_of( get_current_user_id() );
		return $agent || 'yes' !== Eru_STC_Settings::get( 'self_registration' ) ? self::labels()['panel'] : __( 'Temsilci başvurusu', 'sales-team-commissions-for-woocommerce' );
	}
	/** @return string */
	public static function title_team() {
		return self::labels()['team'];
	}
	/** @return string */
	public static function title_commissions() {
		return self::labels()['commissions'];
	}
	/** @return string */
	public static function title_payouts() {
		return self::labels()['payouts'];
	}
	/** @return string */
	public static function title_details() {
		return self::labels()['details'];
	}

	/** Styles (and the copy button) on the agent endpoints only. */
	public static function assets() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}
		foreach ( self::KEYS as $var ) {
			if ( is_wc_endpoint_url( $var ) ) {
				wp_enqueue_style( 'eru-stc-account', ERU_STC_URL . 'assets/css/account.css', array(), ERU_STC_VERSION );
				wp_enqueue_script( 'eru-stc-account', ERU_STC_URL . 'assets/js/account.js', array(), ERU_STC_VERSION, true );
				return;
			}
		}
	}

	/** @param string $key Endpoint key. @return string Endpoint URL. */
	public static function url( $key ) {
		return wc_get_account_endpoint_url( self::KEYS[ $key ] );
	}

	/** @param string $action Form action. @return string Nonce action. */
	public static function nonce_action( $action ) {
		return 'eru_stc_account_' . $action;
	}

	/** Handles a My Account form POST, then redirects back (POST-redirect-GET). */
	public static function handle() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified in process().
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) || empty( $_POST['eru_stc_action'] ) || ! is_user_logged_in() ) {
			return;
		}
		$action = sanitize_key( wp_unslash( $_POST['eru_stc_action'] ) );
		$post   = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is sanitised in process().
		// phpcs:enable
		$result = self::process( $action, is_array( $post ) ? $post : array() );
		wc_add_notice( esc_html( $result['message'] ), $result['ok'] ? 'success' : 'error' );
		$back = array(
			'payout_request' => 'payouts',
			'details_save'   => 'details',
			'details_delete' => 'details',
			'invite'         => 'panel',
			'apply'          => 'panel',
		);
		wp_safe_redirect( self::url( $back[ $action ] ?? 'panel' ) );
		exit;
	}

	/**
	 * Runs one My Account form for the current user.
	 *
	 * @param string $action payout_request | details_save | details_delete | invite | apply.
	 * @param array  $post   Unslashed POST fields (with _wpnonce).
	 * @return array{ok:bool,message:string}
	 */
	public static function process( $action, array $post ) {
		$fail = fn( $m ) => array(
			'ok'      => false,
			'message' => (string) $m,
		);
		$done = fn( $m ) => array(
			'ok'      => true,
			'message' => (string) $m,
		);
		$user = get_current_user_id();
		if ( ! in_array( $action, self::ACTIONS, true ) || $user <= 0 || ! Eru_STC_Settings::enabled() ) {
			return $fail( __( 'Geçersiz istek.', 'sales-team-commissions-for-woocommerce' ) );
		}
		if ( ! wp_verify_nonce( (string) ( $post['_wpnonce'] ?? '' ), self::nonce_action( $action ) ) ) {
			return $fail( __( 'Oturumunuzun süresi dolmuş olabilir. Sayfayı yenileyip tekrar deneyin.', 'sales-team-commissions-for-woocommerce' ) );
		}
		self::flush_cache();
		$agent = self::agent_of( $user );

		if ( 'apply' === $action ) {
			if ( 'yes' !== Eru_STC_Settings::get( 'self_registration' ) ) {
				return $fail( __( 'Temsilci başvurusu kapalı.', 'sales-team-commissions-for-woocommerce' ) );
			}
			if ( $agent ) {
				return $fail( __( 'Zaten bir temsilci kaydınız var.', 'sales-team-commissions-for-woocommerce' ) );
			}
			$u  = wp_get_current_user();
			$id = Eru_STC_Agents::create(
				$user,
				array(
					'status'   => 'pending',
					'settings' => array(
						'display_name' => $u->display_name,
						'applied_at'   => time(),
					),
				)
			);
			self::flush_cache();
			return is_wp_error( $id ) ? $fail( $id->get_error_message() ) : $done( __( 'Başvurunuz alındı. Mağaza onayladığında temsilci paneliniz açılır.', 'sales-team-commissions-for-woocommerce' ) );
		}
		if ( ! $agent ) {
			return $fail( __( 'Bu işlem yalnızca temsilciler içindir.', 'sales-team-commissions-for-woocommerce' ) );
		}
		switch ( $action ) {
			case 'payout_request':
				if ( ! Eru_STC_Agents::is_active( $agent ) ) {
					return $fail( __( 'Hesabınız etkin değil; ödeme talebi oluşturulamaz.', 'sales-team-commissions-for-woocommerce' ) );
				}
				if ( self::details_required() && ! Eru_STC_Payout_Details::masked( $agent )['complete'] ) {
					return $fail( __( 'Önce ödeme bilgilerinizi (hesap sahibi ve IBAN) kaydedin.', 'sales-team-commissions-for-woocommerce' ) );
				}
				$id = Eru_STC_Payouts::create_request(
					(int) $agent->id,
					array(
						'method'     => 'bank_transfer',
						'created_by' => $user,
					)
				);
				return is_wp_error( $id ) ? $fail( $id->get_error_message() ) : $done( __( 'Ödeme talebiniz alındı.', 'sales-team-commissions-for-woocommerce' ) );
			case 'details_save':
				if ( ! Eru_STC_Agents::is_active( $agent ) ) {
					return $fail( __( 'Hesabınız etkin değil; ödeme bilgileri değiştirilemez.', 'sales-team-commissions-for-woocommerce' ) );
				}
				if ( 'yes' !== Eru_STC_Settings::get( 'payout_details_form' ) ) {
					return $fail( __( 'Ödeme bilgileri formu kapalı.', 'sales-team-commissions-for-woocommerce' ) );
				}
				$r = Eru_STC_Payout_Details::save(
					(int) $agent->id,
					array(
						'holder' => (string) ( $post['eru_stc_holder'] ?? '' ),
						'iban'   => (string) ( $post['eru_stc_iban'] ?? '' ),
						'tckn'   => (string) ( $post['eru_stc_tckn'] ?? '' ),
					)
				);
				return is_wp_error( $r ) ? $fail( $r->get_error_message() ) : $done( __( 'Ödeme bilgileriniz kaydedildi.', 'sales-team-commissions-for-woocommerce' ) );
			case 'details_delete':
				if ( ! Eru_STC_Agents::is_active( $agent ) ) {
					return $fail( __( 'Hesabınız etkin değil; ödeme bilgileri değiştirilemez.', 'sales-team-commissions-for-woocommerce' ) );
				}
				if ( Eru_STC_Payout_Details::wipe( (int) $agent->id ) ) {
					Eru_STC_Payout_Details::notify( Eru_STC_Agents::get( (int) $agent->id ), true );
				}
				return $done( __( 'Kayıtlı ödeme bilgileriniz silindi.', 'sales-team-commissions-for-woocommerce' ) );
			case 'invite':
				return Eru_STC_Invitations::request( (int) $agent->id, (string) ( $post['eru_stc_email'] ?? '' ) );
		}
		return $fail( __( 'Geçersiz istek.', 'sales-team-commissions-for-woocommerce' ) );
	}

	/** @return bool Payout requests need stored details (form on and the key present). */
	public static function details_required() {
		return 'yes' === Eru_STC_Settings::get( 'payout_details_form' ) && Eru_STC_Crypto::available();
	}

	/**
	 * Endpoint HTML for the current user (used by the endpoints and by tests).
	 *
	 * @param string $key panel | team | commissions | payouts | details.
	 * @return string
	 */
	public static function render( $key ) {
		self::flush_cache();
		ob_start();
		call_user_func( array( __CLASS__, 'endpoint_' . $key ) );
		return (string) ob_get_clean();
	}

	/** @param string $name Template. @param array $vars Values. */
	private static function template( $name, array $vars ) {
		wc_get_template( 'myaccount/' . $name . '.php', array( 'eru_stc' => $vars ), 'sales-team-commissions-for-woocommerce/', ERU_STC_PATH . 'templates/' );
	}

	/** @return object|null Active agent of the current user, or prints the reason and returns null. */
	private static function active_agent_or_notice() {
		$agent = self::agent_of( get_current_user_id() );
		if ( Eru_STC_Agents::is_active( $agent ) ) {
			return $agent;
		}
		self::template(
			'status',
			array(
				'agent'      => $agent,
				'panel_url'  => self::url( 'panel' ),
				'can_apply'  => ! $agent && 'yes' === Eru_STC_Settings::get( 'self_registration' ),
				'nonce'      => wp_create_nonce( self::nonce_action( 'apply' ) ),
			)
		);
		return null;
	}

	/**
	 * Previous / next links for a paged list (?sayfa=).
	 *
	 * @param string $base  List URL.
	 * @param int    $page  Current page.
	 * @param int    $pages Page count.
	 */
	public static function pager( $base, $page, $pages ) {
		if ( $pages <= 1 ) {
			return;
		}
		echo '<nav class="eru-stc-pager">';
		if ( $page > 1 ) {
			echo '<a class="button" href="' . esc_url( add_query_arg( 'sayfa', $page - 1, $base ) ) . '">' . esc_html__( '← Önceki', 'sales-team-commissions-for-woocommerce' ) . '</a> ';
		}
		/* translators: 1: page, 2: pages */
		echo '<span>' . esc_html( sprintf( __( 'Sayfa %1$d / %2$d', 'sales-team-commissions-for-woocommerce' ), $page, $pages ) ) . '</span>';
		if ( $page < $pages ) {
			echo ' <a class="button" href="' . esc_url( add_query_arg( 'sayfa', $page + 1, $base ) ) . '">' . esc_html__( 'Sonraki →', 'sales-team-commissions-for-woocommerce' ) . '</a>';
		}
		echo '</nav>';
	}

	/** @return int Current page from ?sayfa=. */
	private static function page_no() {
		return max( 1, absint( $_GET['sayfa'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** Panel: balance, referral link, invite form, recent commissions. */
	public static function endpoint_panel() {
		$agent = self::active_agent_or_notice();
		if ( ! $agent ) {
			return;
		}
		self::template(
			'panel',
			array(
				'agent'        => $agent,
				'label'        => Eru_STC_Agents::label( $agent ),
				'balance'      => Eru_STC_Commissions::balance( (int) $agent->id ),
				'currency'     => get_woocommerce_currency(),
				'referral_url' => add_query_arg( Eru_STC_Referral::param(), $agent->referral_code, home_url( '/' ) ),
				'customers'    => Eru_STC_Links::count_for( (int) $agent->id ),
				'team'         => count( Eru_STC_Agents::downline( (int) $agent->id, (int) Eru_STC_Settings::get( 'max_levels' ) ) ),
				'invites'      => 'yes' === Eru_STC_Settings::get( 'customer_invites' ),
				'invite_nonce' => wp_create_nonce( self::nonce_action( 'invite' ) ),
				'recent'       => Eru_STC_Commissions::list(
					array(
						'agent_id' => (int) $agent->id,
						'limit'    => 5,
					)
				),
				'hold_days'    => (int) Eru_STC_Settings::get( 'hold_days' ),
				'urls'         => array_map( array( __CLASS__, 'url' ), array_combine( array_keys( self::KEYS ), array_keys( self::KEYS ) ) ),
			)
		);
	}

	/** Team: own customers (masked e-mail) and the downline to the level cap. */
	public static function endpoint_team() {
		$agent = self::active_agent_or_notice();
		if ( ! $agent ) {
			return;
		}
		$page      = self::page_no();
		$customers = array();
		foreach ( Eru_STC_Links::customers_of( (int) $agent->id, self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) as $link ) {
			$u           = get_userdata( (int) $link->customer_id );
			$customers[] = array(
				'name'  => $u ? trim( $u->first_name . ' ' . $u->last_name ) : '',
				'email' => $u ? Eru_STC_Crypto::mask_email( $u->user_email ) : '',
				'since' => get_date_from_gmt( $link->created_at, get_option( 'date_format' ) ),
				'type'  => Eru_STC_Labels::link_type( $link->link_type ),
			);
		}
		$max  = (int) Eru_STC_Settings::get( 'max_levels' );
		$team = array();
		foreach ( Eru_STC_Agents::downline( (int) $agent->id, max( 1, $max ) ) as $d ) {
			$team[] = array(
				'depth'     => $d['depth'],
				'name'      => Eru_STC_Agents::label( $d['agent'] ),
				'status'    => Eru_STC_Labels::agent_status( $d['agent']->status ),
				'customers' => Eru_STC_Links::count_for( (int) $d['agent']->id ),
				'earns'     => $d['depth'] + 1 <= $max,
			);
		}
		$total = Eru_STC_Links::count_for( (int) $agent->id );
		self::template(
			'team',
			array(
				'customers' => $customers,
				'total'     => $total,
				'page'      => $page,
				'pages'     => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
				'team'      => $team,
				'max'       => $max,
				'base_url'  => self::url( 'team' ),
			)
		);
	}

	/** Commissions of the current agent, filterable by status. */
	public static function endpoint_commissions() {
		$agent = self::active_agent_or_notice();
		if ( ! $agent ) {
			return;
		}
		$status = isset( $_GET['durum'] ) ? sanitize_key( wp_unslash( $_GET['durum'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = in_array( $status, Eru_STC_Commissions::STATUSES, true ) ? $status : '';
		$page   = self::page_no();
		$args   = array(
			'agent_id' => (int) $agent->id,
			'status'   => $status,
		);
		$total  = Eru_STC_Commissions::count( $args );
		self::template(
			'commissions',
			array(
				'rows'     => Eru_STC_Commissions::list(
					array_merge(
						$args,
						array(
							'limit'  => self::PER_PAGE,
							'offset' => ( $page - 1 ) * self::PER_PAGE,
						)
					)
				),
				'status'   => $status,
				'total'    => $total,
				'page'     => $page,
				'pages'    => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
				'base_url' => self::url( 'commissions' ),
			)
		);
	}

	/** Payouts: balance, request form, history. */
	public static function endpoint_payouts() {
		$agent = self::active_agent_or_notice();
		if ( ! $agent ) {
			return;
		}
		$balance = Eru_STC_Commissions::balance( (int) $agent->id );
		$min     = (string) Eru_STC_Settings::get( 'min_payout' );
		$open    = Eru_STC_Payouts::count(
			array(
				'agent_id' => (int) $agent->id,
				'status'   => 'requested',
			)
		);
		$reason  = '';
		$needs   = false;
		if ( $open ) {
			$reason = __( 'Bekleyen bir ödeme talebiniz var; ödendiğinde yeni talep oluşturabilirsiniz.', 'sales-team-commissions-for-woocommerce' );
		} elseif ( Eru_STC_Money::to_minor( $balance['available'] ) <= 0 ) {
			$reason = __( 'Ödenebilir bakiyeniz yok. Komisyonlar bekleme süresi dolunca onaylanır.', 'sales-team-commissions-for-woocommerce' );
		} elseif ( Eru_STC_Money::to_minor( $balance['available'] ) < Eru_STC_Money::to_minor( $min ) ) {
			/* translators: %s: minimum amount */
			$reason = sprintf( __( 'En düşük ödeme tutarı %s.', 'sales-team-commissions-for-woocommerce' ), Eru_STC_Labels::money( $min ) );
		} elseif ( self::details_required() && ! Eru_STC_Payout_Details::masked( $agent )['complete'] ) {
			$reason = __( 'Talep oluşturmak için önce ödeme bilgilerinizi kaydedin.', 'sales-team-commissions-for-woocommerce' );
			$needs  = true;
		}
		$page  = self::page_no();
		$total = Eru_STC_Payouts::count( array( 'agent_id' => (int) $agent->id ) );
		self::template(
			'payouts',
			array(
				'balance'     => $balance,
				'currency'    => get_woocommerce_currency(),
				'min'         => $min,
				'can_request' => '' === $reason,
				'reason'      => $reason,
				'needs_details' => $needs,
				'nonce'       => wp_create_nonce( self::nonce_action( 'payout_request' ) ),
				'rows'        => Eru_STC_Payouts::list(
					array(
						'agent_id' => (int) $agent->id,
						'limit'    => self::PER_PAGE,
						'offset'   => ( $page - 1 ) * self::PER_PAGE,
					)
				),
				'page'        => $page,
				'pages'       => max( 1, (int) ceil( $total / self::PER_PAGE ) ),
				'base_url'    => self::url( 'payouts' ),
				'details_url' => self::url( 'details' ),
			)
		);
	}

	/** Payout details: masks of what is stored and a write-only form. */
	public static function endpoint_details() {
		$agent = Eru_STC_Agents::get_by_user( get_current_user_id() ); // Fresh row: it carries the payout columns.
		if ( ! $agent ) {
			self::active_agent_or_notice();
			return;
		}
		self::template(
			'details',
			array(
				'enabled'      => 'yes' === Eru_STC_Settings::get( 'payout_details_form' ),
				'available'    => Eru_STC_Crypto::available(),
				'masked'       => Eru_STC_Payout_Details::masked( $agent ),
				'nonce'        => wp_create_nonce( self::nonce_action( 'details_save' ) ),
				'delete_nonce' => wp_create_nonce( self::nonce_action( 'details_delete' ) ),
				'action_url'   => self::url( 'details' ),
			)
		);
	}
}
