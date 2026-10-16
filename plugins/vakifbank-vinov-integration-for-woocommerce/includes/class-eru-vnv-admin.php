<?php
/**
 * Admin: WooCommerce → Vinov (Talimatlar, Kampanyalar, Hareketler, Bağlantı testi).
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Read-only views of the bank's data; nothing here creates or changes anything at the bank (cancelling is in
 * the order box). The instruction and campaign lists are cached for five minutes in one transient each,
 * holding only whitelisted, masked fields. Movement lists are never cached. The debtor query takes an
 * identity number from a POST form (nonce + manage_woocommerce), sends it once and keeps it nowhere: not in
 * an option, transient, log, the URL or the re-rendered form. The connection test is queued in Action
 * Scheduler; the page shows its last result.
 */
class Eru_VNV_Admin {

	const CAP          = 'manage_woocommerce';
	const SLUG         = 'eru-vnv';
	const DEBTOR_NONCE   = 'eru_vnv_debtor';
	const RECEIVER_NONCE = 'eru_vnv_receiver';
	const PER_PAGE     = 20;
	const CACHE_TTL    = 300;
	const CONN_OPTION  = 'eru_vnv_conn_result';

	/** @var string Page hook suffix. */
	private static $hook = '';

	/** Hooks. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_vnv_admin', array( __CLASS__, 'handle_action' ) );
		add_action( 'admin_post_eru_vnv_dismiss', array( __CLASS__, 'handle_dismiss' ) );
		add_action( 'admin_notices', array( __CLASS__, 'review_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	/** Menu entry under WooCommerce. */
	public static function menu() {
		self::$hook = (string) add_submenu_page( 'woocommerce', __( 'VakıfBank Vinov', 'vakifbank-vinov-integration-for-woocommerce' ), __( 'Vinov', 'vakifbank-vinov-integration-for-woocommerce' ), self::CAP, self::SLUG, array( __CLASS__, 'page' ) );
		if ( self::$hook ) {
			add_action(
				'load-' . self::$hook,
				function () {
					nocache_headers(); // The debtor query result must never be cached by a browser or proxy.
				}
			);
		}
	}

	/** @param string $hook Hook. */
	public static function assets( $hook ) {
		if ( self::$hook && self::$hook === $hook ) {
			wp_enqueue_style( 'eru-vnv-admin', ERU_VNV_URL . 'assets/admin.css', array(), ERU_VNV_VERSION );
		}
	}

	/** @return array<string,string> Tabs. */
	public static function tabs() {
		return array(
			'talimatlar'  => __( 'Talimatlar', 'vakifbank-vinov-integration-for-woocommerce' ),
			'kampanyalar' => __( 'Kampanyalar', 'vakifbank-vinov-integration-for-woocommerce' ),
			'hareketler'  => __( 'Hareketler', 'vakifbank-vinov-integration-for-woocommerce' ),
			'baglanti'    => __( 'Bağlantı testi', 'vakifbank-vinov-integration-for-woocommerce' ),
		);
	}

	/** @param string $tab Tab. @param array $args Extra args. @return string URL. */
	public static function url( $tab = 'talimatlar', array $args = array() ) {
		return add_query_arg(
			array_merge(
				array(
					'page' => self::SLUG,
					'tab'  => $tab,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	/** @return array<string,array{0:string,1:string}> Fixed result messages. */
	private static function messages() {
		return array(
			'queued'    => array( 'success', __( 'Bağlantı testi kuyruğa alındı; Action Scheduler birkaç saniye içinde çalıştırır. Sonucu görmek için sayfayı yenileyin.', 'vakifbank-vinov-integration-for-woocommerce' ) ),
			'pending'   => array( 'info', __( 'Bağlantı testi zaten kuyrukta.', 'vakifbank-vinov-integration-for-woocommerce' ) ),
			'not_ready' => array( 'warning', __( 'Önce ödeme yöntemi ayarlarında API adresini, istemci bilgilerini ve işyeri bilgilerini girin.', 'vakifbank-vinov-integration-for-woocommerce' ) ),
			'refreshed' => array( 'success', __( 'Önbellek temizlendi; liste bankadan yeniden alındı.', 'vakifbank-vinov-integration-for-woocommerce' ) ),
		);
	}

	/** admin-post handler: queue the connection test, refresh the list caches. */
	public static function handle_action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'vakifbank-vinov-integration-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_vnv_admin' );
		$do  = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$tab = sanitize_key( wp_unslash( $_POST['tab'] ?? 'talimatlar' ) );
		$tab = array_key_exists( $tab, self::tabs() ) ? $tab : 'talimatlar';
		$msg = '';
		if ( 'conn_test' === $do ) {
			$msg = ! Eru_VNV_Settings::ready() ? 'not_ready' : ( Eru_VNV_Cron::queue_connection_test() ? 'queued' : 'pending' );
		} elseif ( 'refresh' === $do ) {
			delete_transient( 'eru_vnv_cache_list' );
			delete_transient( 'eru_vnv_cache_campaigns' );
			$msg = 'refreshed';
		}
		$back = array();
		foreach ( array( 'durum', 'baslangic', 'bitis', 'talimat' ) as $k ) {
			if ( isset( $_POST[ $k ] ) && is_scalar( $_POST[ $k ] ) && '' !== $_POST[ $k ] ) {
				$back[ $k ] = sanitize_text_field( wp_unslash( $_POST[ $k ] ) );
			}
		}
		wp_safe_redirect( self::url( $tab, array_merge( $back, $msg ? array( 'eru_vnv_msg' => $msg ) : array() ) ) );
		exit;
	}

	/** Page callback. */
	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'vakifbank-vinov-integration-for-woocommerce' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification -- read-only GET filters; the debtor POST is verified in debtor_query().
		$tab    = sanitize_key( wp_unslash( $_GET['tab'] ?? 'talimatlar' ) );
		$query  = array();
		foreach ( array( 'durum', 'baslangic', 'bitis', 'talimat', 'sayfa', 'sorgu', '_eru_vnv_q', 'eru_vnv_msg' ) as $k ) {
			if ( isset( $_GET[ $k ] ) && is_scalar( $_GET[ $k ] ) ) {
				$query[ $k ] = sanitize_text_field( wp_unslash( $_GET[ $k ] ) );
			}
		}
		$debtor = null;
		if ( 'hareketler' === $tab && 'POST' === ( $_SERVER['REQUEST_METHOD'] ?? '' ) && isset( $_POST['eru_vnv_debtor_tckn'] ) ) {
			$debtor = self::debtor_query(
				array(
					'tckn'  => is_scalar( $_POST['eru_vnv_debtor_tckn'] ) ? sanitize_text_field( wp_unslash( $_POST['eru_vnv_debtor_tckn'] ) ) : '',
					'nonce' => is_scalar( $_POST['eru_vnv_debtor_nonce'] ?? null ) ? sanitize_text_field( wp_unslash( $_POST['eru_vnv_debtor_nonce'] ) ) : '',
					'start' => is_scalar( $_POST['baslangic'] ?? null ) ? sanitize_text_field( wp_unslash( $_POST['baslangic'] ) ) : '',
					'end'   => is_scalar( $_POST['bitis'] ?? null ) ? sanitize_text_field( wp_unslash( $_POST['bitis'] ) ) : '',
				)
			);
			unset( $_POST['eru_vnv_debtor_tckn'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification
		echo self::render( $tab, $query, $debtor ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/**
	 * The whole page as HTML (escaped parts).
	 *
	 * @param string     $tab    Tab.
	 * @param array      $q      Sanitised query args.
	 * @param array|null $debtor Debtor query result to show.
	 * @return string
	 */
	public static function render( $tab, array $q, $debtor = null ) {
		$tabs = self::tabs();
		$tab  = array_key_exists( $tab, $tabs ) ? $tab : 'talimatlar';
		$h    = '<div class="wrap eru-vnv-admin"><h1>' . esc_html__( 'VakıfBank Vinov', 'vakifbank-vinov-integration-for-woocommerce' ) . '</h1>';
		$h   .= '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			$h .= '<a href="' . esc_url( self::url( $k ) ) . '" class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		$h .= '</nav>';
		$msgs = self::messages();
		if ( ! empty( $q['eru_vnv_msg'] ) && isset( $msgs[ $q['eru_vnv_msg'] ] ) ) {
			$h .= self::notice( $msgs[ $q['eru_vnv_msg'] ][0], $msgs[ $q['eru_vnv_msg'] ][1] );
		}
		if ( ! Eru_VNV_Settings::ready() ) {
			$h .= self::notice( 'warning', __( 'Vinov ayarları eksik: API adresi, istemci bilgileri ve işyeri bilgileri girilmeden bankadan liste alınmaz.', 'vakifbank-vinov-integration-for-woocommerce' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=checkout&section=eru_vnv' ) ) . '">' . esc_html__( 'Ayarlara git', 'vakifbank-vinov-integration-for-woocommerce' ) . '</a>', true );
		}
		$h .= '<p class="description">' . esc_html( sprintf( /* translators: %s: Test or Canlı */ __( 'Ortam: %s', 'vakifbank-vinov-integration-for-woocommerce' ), 'live' === Eru_VNV_Settings::env() ? __( 'Canlı', 'vakifbank-vinov-integration-for-woocommerce' ) : __( 'Test', 'vakifbank-vinov-integration-for-woocommerce' ) ) ) . '</p>';
		switch ( $tab ) {
			case 'kampanyalar':
				$h .= self::tab_campaigns();
				break;
			case 'hareketler':
				$h .= self::tab_movements( $q, $debtor );
				break;
			case 'baglanti':
				$h .= self::tab_connection();
				break;
			default:
				$h .= self::tab_instructions( $q );
		}
		return $h . '</div>';
	}

	/** @return string Notice HTML. */
	private static function notice( $type, $text, $html = false ) {
		return '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . ( $html ? wp_kses_post( $text ) : esc_html( $text ) ) . '</p></div>';
	}

	/** @return string A small POST form to admin-post. */
	private static function action_form( $do, $tab, $label, array $keep = array() ) {
		$h = '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="eru-vnv-inline">';
		$h .= wp_nonce_field( 'eru_vnv_admin', '_wpnonce', true, false );
		$h .= '<input type="hidden" name="action" value="eru_vnv_admin" /><input type="hidden" name="do" value="' . esc_attr( $do ) . '" /><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
		foreach ( $keep as $k => $v ) {
			$h .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( (string) $v ) . '" />';
		}
		return $h . '<button type="submit" class="button">' . esc_html( $label ) . '</button></form>';
	}

	// ----- dates ---------------------------------------------------------------------------------------

	/** @param mixed $v Value. @param string $default Y-m-d. @return string Y-m-d. */
	private static function day( $v, $default ) {
		$v = is_scalar( $v ) ? trim( (string) $v ) : '';
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return $v;
		}
		return $default;
	}

	/** @return DateTimeImmutable Today in the bank's time zone. */
	private static function today() {
		return new DateTimeImmutable( 'today', new DateTimeZone( Eru_VNV_API_Client::TIMEZONE ) );
	}

	/**
	 * A date range (at most 92 days, end not before start).
	 *
	 * @param mixed $start Start.
	 * @param mixed $end   End.
	 * @param int   $back  Default days back.
	 * @return array{0:string,1:string,2:string,3:string} Y-m-d start, Y-m-d end, bank start, bank end.
	 */
	private static function range( $start, $end, $back = 30 ) {
		$tz = new DateTimeZone( Eru_VNV_API_Client::TIMEZONE );
		$e  = self::day( $end, self::today()->format( 'Y-m-d' ) );
		$s  = self::day( $start, self::today()->modify( '-' . (int) $back . ' days' )->format( 'Y-m-d' ) );
		$sd = new DateTimeImmutable( $s, $tz );
		$ed = new DateTimeImmutable( $e, $tz );
		if ( $sd > $ed ) {
			$sd = $ed;
		}
		if ( $sd < $ed->modify( '-92 days' ) ) {
			$sd = $ed->modify( '-92 days' );
		}
		return array( $sd->format( 'Y-m-d' ), $ed->format( 'Y-m-d' ), $sd->format( 'Y-m-d\T00:00:00P' ), $ed->format( 'Y-m-d\T23:59:59P' ) );
	}

	// ----- masking ------------------------------------------------------------------------------------------

	/** @param string $name Name. @return string Initials ("D. B."). */
	public static function initials( $name ) {
		$out = array();
		foreach ( preg_split( '/\s+/u', trim( (string) $name ) ) as $w ) {
			if ( '' !== $w ) {
				$out[] = mb_strtoupper( mb_substr( $w, 0, 1 ) ) . '.';
			}
		}
		return implode( ' ', array_slice( $out, 0, 4 ) );
	}

	/**
	 * Top-level scalar fields of a bank item, masked: identity numbers as "12*******34", personal names as
	 * initials, phones/e-mails/IBANs/accounts reduced, everything else redacted (11-digit runs, phones, IBANs)
	 * and shortened.
	 *
	 * @param array      $item  Item.
	 * @param array|null $allow Keys to keep (null: the first 12 scalar fields).
	 * @return array<string,string>
	 */
	public static function clean_item( array $item, $allow = null ) {
		$out = array();
		foreach ( $item as $k => $v ) {
			$k = (string) $k;
			if ( ( null !== $allow && ! in_array( $k, $allow, true ) ) || ( null === $allow && count( $out ) >= 12 ) || is_array( $v ) || is_object( $v ) ) {
				continue;
			}
			$v = is_bool( $v ) ? ( $v ? __( 'evet', 'vakifbank-vinov-integration-for-woocommerce' ) : __( 'hayır', 'vakifbank-vinov-integration-for-woocommerce' ) ) : (string) $v;
			if ( preg_match( '/identity|tckn|taxnumber|vkn/i', $k ) ) {
				$d = preg_replace( '/\D+/', '', $v );
				$v = '' === $d ? '' : Eru_VNV_Identity::mask( $d );
			} elseif ( preg_match( '/(debtor|customer|payer|person|full|first|last|sur)_?name|adsoyad|unvan/i', $k ) ) {
				$v = self::initials( $v );
			} elseif ( preg_match( '/phone|gsm|mobile/i', $k ) ) {
				$d = preg_replace( '/\D+/', '', $v );
				$v = strlen( $d ) >= 4 ? '*** ** ' . substr( $d, -2 ) : '';
			} elseif ( preg_match( '/mail/i', $k ) ) {
				$v = false !== strpos( $v, '@' ) ? mb_substr( $v, 0, 1 ) . '***@' . substr( strrchr( $v, '@' ), 1 ) : '';
			} elseif ( preg_match( '/iban|account/i', $k ) ) {
				$d = preg_replace( '/\s+/', '', $v );
				$v = strlen( $d ) >= 4 ? '•••• ' . substr( $d, -4 ) : '';
			} else {
				$v = Eru_VNV_Logger::redact( sanitize_text_field( $v ) );
			}
			$out[ $k ] = mb_substr( $v, 0, 120 );
		}
		return $out;
	}

	/** @param array $data Data. @return array[] The first list of items under Data. */
	private static function first_list( array $data ) {
		foreach ( $data as $v ) {
			if ( is_array( $v ) && array_values( $v ) === $v ) {
				return array_values( array_filter( $v, 'is_array' ) );
			}
		}
		return array();
	}

	/** @param array[] $rows Cleaned rows. @return string A generic table over the union of their keys. */
	private static function generic_table( array $rows ) {
		if ( ! $rows ) {
			return '<p>' . esc_html__( 'Kayıt yok.', 'vakifbank-vinov-integration-for-woocommerce' ) . '</p>';
		}
		$keys = array();
		foreach ( $rows as $r ) {
			foreach ( array_keys( $r ) as $k ) {
				$keys[ $k ] = true;
			}
		}
		$keys = array_slice( array_keys( $keys ), 0, 12 );
		$h    = '<div class="eru-vnv-scroll"><table class="widefat striped"><thead><tr>';
		foreach ( $keys as $k ) {
			$h .= '<th>' . esc_html( $k ) . '</th>';
		}
		$h .= '</tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$h .= '<tr>';
			foreach ( $keys as $k ) {
				$h .= '<td>' . esc_html( $r[ $k ] ?? '' ) . '</td>';
			}
			$h .= '</tr>';
		}
		return $h . '</tbody></table></div>';
	}

	/**
	 * A cached list (one transient per list, so filters cannot grow the cache).
	 *
	 * @param string   $name  list | campaigns.
	 * @param string   $key   Cache key of the request.
	 * @param callable $fetch fn(): array{ok:bool, items:array, error:string}.
	 * @return array{ok:bool, items:array, error:string, at:int}
	 */
	private static function cached( $name, $key, callable $fetch ) {
		$t   = 'eru_vnv_cache_' . $name;
		$hit = get_transient( $t );
		if ( is_array( $hit ) && ( $hit['key'] ?? '' ) === $key && isset( $hit['items'] ) ) {
			return array(
				'ok'    => true,
				'items' => (array) $hit['items'],
				'error' => '',
				'at'    => (int) ( $hit['at'] ?? 0 ),
			);
		}
		$r = $fetch();
		if ( $r['ok'] ) {
			set_transient(
				$t,
				array(
					'key'   => $key,
					'at'    => time(),
					'items' => $r['items'],
				),
				self::CACHE_TTL
			);
		}
		return array_merge( $r, array( 'at' => time() ) );
	}

	// ----- tabs --------------------------------------------------------------------------------------------

	/** Talimatlar: the bank's instruction list with filters, paged, cached 5 minutes. @return string */
	private static function tab_instructions( array $q ) {
		list( $s, $e, $bs, $be ) = self::range( $q['baslangic'] ?? '', $q['bitis'] ?? '' );
		$review = 'incelenmeli' === ( $q['durum'] ?? '' );
		$status = in_array( (int) ( $q['durum'] ?? 0 ), array( 1, 2, 3 ), true ) ? (int) $q['durum'] : 0;
		$iid    = preg_match( '/^\d{1,18}$/', (string) ( $q['talimat'] ?? '' ) ) ? (string) $q['talimat'] : '';
		$page   = max( 1, (int) ( $q['sayfa'] ?? 1 ) );

		$h  = '<form method="get" class="eru-vnv-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="tab" value="talimatlar" />';
		$h .= '<label>' . esc_html__( 'Durum', 'vakifbank-vinov-integration-for-woocommerce' ) . ' <select name="durum"><option value="">' . esc_html__( 'Tümü', 'vakifbank-vinov-integration-for-woocommerce' ) . '</option>';
		foreach ( array( 1, 2, 3 ) as $c ) {
			$h .= '<option value="' . esc_attr( (string) $c ) . '"' . selected( $status, $c, false ) . '>' . esc_html( Eru_VNV_Instructions::label( $c ) ) . '</option>';
		}
		$h .= '<option value="incelenmeli"' . selected( $review, true, false ) . '>' . esc_html__( 'İncelenmeli (bu mağaza)', 'vakifbank-vinov-integration-for-woocommerce' ) . '</option>';
		$h .= '</select></label> ';
		$h .= '<label>' . esc_html__( 'Talimat tarihi', 'vakifbank-vinov-integration-for-woocommerce' ) . ' <input type="date" name="baslangic" value="' . esc_attr( $s ) . '" /></label> – <input type="date" name="bitis" value="' . esc_attr( $e ) . '" aria-label="' . esc_attr__( 'Bitiş tarihi', 'vakifbank-vinov-integration-for-woocommerce' ) . '" /> ';
		$h .= '<label>' . esc_html__( 'Talimat no', 'vakifbank-vinov-integration-for-woocommerce' ) . ' <input type="text" inputmode="numeric" name="talimat" value="' . esc_attr( $iid ) . '" size="12" /></label> ';
		$h .= '<button type="submit" class="button">' . esc_html__( 'Listele', 'vakifbank-vinov-integration-for-woocommerce' ) . '</button></form>';
		if ( $review ) {
			return $h . self::review_list();
		}
		$h .= self::action_form( 'refresh', 'talimatlar', __( 'Bankadan yenile', 'vakifbank-vinov-integration-for-woocommerce' ), array_filter( array( 'durum' => $status ? (string) $status : '', 'baslangic' => $s, 'bitis' => $e, 'talimat' => $iid ) ) );

		if ( ! Eru_VNV_Settings::ready() ) {
			return $h;
		}
		$filters = array_filter(
			array(
				'id'                     => $iid,
				'status_code'            => $status,
				'instruction_start_date' => '' === $iid ? $bs : '',
				'instruction_end_date'   => '' === $iid ? $be : '',
			)
		);
		$env     = Eru_VNV_Settings::env();
		$allow   = array( 'Id', 'InvoiceNumber', 'InstructionDescription', 'InstructionAmount', 'InstructionStatusCode', 'NumberofInstallments', 'InstructionDate', 'ExpiryDate', 'DebtorName' );
		$res     = self::cached(
			'list',
			md5( $env . '|' . wp_json_encode( $filters ) ),
			function () use ( $filters, $env, $allow ) {
				$r = ( new Eru_VNV_API_Client( $env ) )->list_instructions( $filters );
				return array(
					'ok'    => $r['success'],
					'items' => $r['success'] ? array_map( fn( $it ) => self::clean_item( $it, $allow ), array_values( array_filter( (array) ( $r['data']['WorkPlaceInstruction'] ?? array() ), 'is_array' ) ) ) : array(),
					'error' => $r['error'],
				);
			}
		);
		if ( ! $res['ok'] ) {
			return $h . self::notice( 'error', sprintf( /* translators: %s: error */ __( 'Liste alınamadı: %s', 'vakifbank-vinov-integration-for-woocommerce' ), $res['error'] ) );
		}
		$items = $res['items'];
		$total = count( $items );
		$pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$page  = min( $page, $pages );
		$slice = array_slice( $items, ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$h    .= '<p class="description">' . esc_html( sprintf( /* translators: 1: count, 2: time */ __( '%1$d talimat · bankadan alındı: %2$s (5 dakika saklanır)', 'vakifbank-vinov-integration-for-woocommerce' ), $total, wp_date( 'H:i', $res['at'] ) ) ) . '</p>';
		if ( ! $slice ) {
			return $h . '<p>' . esc_html__( 'Bu filtrelerle talimat yok.', 'vakifbank-vinov-integration-for-woocommerce' ) . '</p>';
		}
		// Local orders of the listed instructions (one query).
		global $wpdb;
		$ids   = array_filter( array_map( fn( $it ) => (int) ( $it['Id'] ?? 0 ), $slice ) );
		$local = array();
		if ( $ids ) {
			foreach ( (array) $wpdb->get_results( 'SELECT instruction_id, order_id FROM ' . Eru_VNV_Instructions::table() . ' WHERE instruction_id IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')' ) as $r ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared
				$local[ (int) $r->instruction_id ] = (int) $r->order_id;
			}
		}
		$optional = array(
			'NumberofInstallments' => __( 'Taksit', 'vakifbank-vinov-integration-for-woocommerce' ),
			'InstructionDate'      => __( 'Talimat tarihi', 'vakifbank-vinov-integration-for-woocommerce' ),
			'ExpiryDate'           => __( 'Son gün', 'vakifbank-vinov-integration-for-woocommerce' ),
			'DebtorName'           => __( 'Borçlu', 'vakifbank-vinov-integration-for-woocommerce' ),
		);
		$shown    = array_filter( $optional, fn( $k ) => (bool) array_filter( $slice, fn( $it ) => '' !== ( $it[ $k ] ?? '' ) ), ARRAY_FILTER_USE_KEY );
		$h       .= '<div class="eru-vnv-scroll"><table class="widefat striped eru-vnv-list"><thead><tr><th>' . esc_html__( 'Talimat no', 'vakifbank-vinov-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Sipariş', 'vakifbank-vinov-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Açıklama', 'vakifbank-vinov-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Tutar', 'vakifbank-vinov-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Durum', 'vakifbank-vinov-integration-for-woocommerce' ) . '</th>';
		foreach ( $shown as $label ) {
			$h .= '<th>' . esc_html( $label ) . '</th>';
		}
		$h .= '</tr></thead><tbody>';
		foreach ( $slice as $it ) {
			$id    = (int) ( $it['Id'] ?? 0 );
			$order = isset( $local[ $id ] ) ? wc_get_order( $local[ $id ] ) : null;
			$cell  = $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : esc_html( $it['InvoiceNumber'] ?? '' );
			$h    .= '<tr><td>' . esc_html( (string) $id ) . '</td><td>' . $cell . '</td><td>' . esc_html( $it['InstructionDescription'] ?? '' ) . '</td><td>' . wp_kses_post( wc_price( (float) ( $it['InstructionAmount'] ?? 0 ) ) ) . '</td><td>' . esc_html( Eru_VNV_Instructions::label( (int) ( $it['InstructionStatusCode'] ?? 0 ) ) ) . '</td>';
			foreach ( array_keys( $shown ) as $k ) {
				$v  = (string) ( $it[ $k ] ?? '' );
				$h .= '<td>' . esc_html( preg_match( '/^(\d{4}-\d{2}-\d{2})T/', $v, $m ) ? $m[1] : $v ) . '</td>';
			}
			$h .= '</tr>';
		}
		$h .= '</tbody></table></div>';
		if ( $pages > 1 ) {
			$h .= '<p class="eru-vnv-pager">';
			for ( $p = 1; $p <= $pages; $p++ ) {
				$args = array_filter( array( 'durum' => $status ? (string) $status : '', 'baslangic' => $s, 'bitis' => $e, 'talimat' => $iid, 'sayfa' => (string) $p ) );
				$h   .= $p === $page ? '<span class="button disabled">' . esc_html( (string) $p ) . '</span> ' : '<a class="button" href="' . esc_url( self::url( 'talimatlar', $args ) ) . '">' . esc_html( (string) $p ) . '</a> ';
			}
			$h .= '</p>';
		}
		return $h;
	}

	/**
	 * "İncelenmeli": this shop's flagged instructions, from the local table (no bank call).
	 *
	 * @return string
	 */
	private static function review_list() {
		$rows = Eru_VNV_Instructions::reviews( false );
		$h    = '<h2>' . esc_html__( 'İncelenmeli', 'vakifbank-vinov-integration-for-woocommerce' ) . '</h2><p class="description">' . esc_html__( 'Bu mağazanın kayıtlarından: bankadaki durumu siparişle uyuşmayan talimatlar. Bankaya istek gönderilmez.', 'vakifbank-vinov-integration-for-woocommerce' ) . '</p>';
		if ( ! $rows ) {
			return $h . '<p>' . esc_html__( 'İncelenmesi gereken talimat yok.', 'vakifbank-vinov-integration-for-woocommerce' ) . '</p>';
		}
		$h .= '<div class="eru-vnv-scroll"><table class="widefat striped eru-vnv-list"><thead><tr><th>' . esc_html__( 'Talimat no', 'vakifbank-vinov-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Sipariş', 'vakifbank-vinov-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Tutar', 'vakifbank-vinov-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Neden', 'vakifbank-vinov-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Tarih', 'vakifbank-vinov-integration-for-woocommerce' ) . '</th><th>' . esc_html__( 'Uyarı', 'vakifbank-vinov-integration-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $r ) {
			$order = wc_get_order( (int) $r->order_id );
			$h    .= '<tr><td>' . esc_html( (string) $r->instruction_id ) . '</td><td>' . ( $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a> (' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . ')' : '#' . esc_html( (string) $r->order_id ) ) . '</td><td>' . wp_kses_post( wc_price( (float) $r->amount ) ) . '</td><td>' . esc_html( Eru_VNV_Instructions::review_label( $r->review ) ) . '</td><td>' . esc_html( $r->review_at ? wp_date( 'd.m.Y H:i', (int) strtotime( $r->review_at . ' UTC' ) ) : '' ) . '</td><td>' . esc_html( null === $r->review_ack ? __( 'açık', 'vakifbank-vinov-integration-for-woocommerce' ) : __( 'gizlendi', 'vakifbank-vinov-integration-for-woocommerce' ) ) . '</td></tr>';
		}
		return $h . '</tbody></table></div>';
	}

	/** @return string[] Reasons that raise the persistent notice. */
	private static function loud_reasons() {
		return array( 'paid_while_cancelled', 'paid_elsewhere', 'cancel_failed', 'deleted_unresolved' );
	}

	/**
	 * Persistent notice while an approved instruction sits on a cancelled order (or a cancelled order's
	 * instruction could not be cancelled), until dismissed per order.
	 *
	 * @return string HTML ('' when nothing is open).
	 */
	public static function review_notice_html() {
		$rows = Eru_VNV_Instructions::reviews( true, self::loud_reasons(), 20 );
		if ( ! $rows ) {
			return '';
		}
		$h = '<div class="notice notice-error eru-vnv-review-notice"><p><strong>' . esc_html__( 'VakıfBank Vinov: bu talimatla ödenmeyecek siparişlerde (iptal, iade, çöp, silinmiş ya da başka yöntemle ödenmiş) ödenebilir ya da onaylanmış talimat var.', 'vakifbank-vinov-integration-for-woocommerce' ) . '</strong> ' . esc_html__( 'Para tahsil edilmiş olabilir; siparişler otomatik olarak yeniden açılmaz. İnceleyip gerekirse talimatı veya iadeyi banka panelinden yönetin.', 'vakifbank-vinov-integration-for-woocommerce' ) . '</p><ul>';
		foreach ( $rows as $r ) {
			$order = wc_get_order( (int) $r->order_id );
			$link  = $order ? '<a href="' . esc_url( $order->get_edit_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a>' : '#' . esc_html( (string) (int) $r->order_id ) . ' (' . esc_html__( 'silinmiş sipariş', 'vakifbank-vinov-integration-for-woocommerce' ) . ')';
			$h    .= '<li>' . $link . ' · ' . esc_html( sprintf( /* translators: %s: instruction id */ __( 'talimat %s', 'vakifbank-vinov-integration-for-woocommerce' ), (string) $r->instruction_id ) ) . ' · ' . esc_html( Eru_VNV_Instructions::review_label( $r->review ) ) . ' ';
			$h .= '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="eru-vnv-inline"><input type="hidden" name="action" value="eru_vnv_dismiss" /><input type="hidden" name="order_id" value="' . esc_attr( (string) (int) $r->order_id ) . '" /><input type="hidden" name="nonce" value="' . esc_attr( wp_create_nonce( 'eru_vnv_dismiss_' . (int) $r->order_id ) ) . '" /><button type="submit" class="button-link">' . esc_html__( 'Uyarıyı gizle', 'vakifbank-vinov-integration-for-woocommerce' ) . '</button></form></li>';
		}
		return $h . '</ul><p><a href="' . esc_url( self::url( 'talimatlar', array( 'durum' => 'incelenmeli' ) ) ) . '">' . esc_html__( 'Tüm incelenecek talimatlar', 'vakifbank-vinov-integration-for-woocommerce' ) . '</a></p></div>';
	}

	/** admin_notices. */
	public static function review_notice() {
		if ( current_user_can( self::CAP ) ) {
			echo self::review_notice_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
		}
	}

	/**
	 * Dismisses one order's review notice (per-order nonce + manage_woocommerce).
	 *
	 * @param array $post {order_id, nonce}.
	 * @return array{ok:bool, code:string}
	 */
	public static function dismiss_review( array $post ) {
		$id    = absint( $post['order_id'] ?? 0 );
		$nonce = is_scalar( $post['nonce'] ?? null ) ? (string) $post['nonce'] : '';
		if ( ! $id || '' === $nonce || ! wp_verify_nonce( $nonce, 'eru_vnv_dismiss_' . $id ) ) {
			return array( 'ok' => false, 'code' => 'nonce' );
		}
		if ( ! current_user_can( self::CAP ) ) {
			return array( 'ok' => false, 'code' => 'cap' );
		}
		Eru_VNV_Instructions::ack( $id );
		return array( 'ok' => true, 'code' => 'dismissed' );
	}

	/** admin-post: eru_vnv_dismiss. */
	public static function handle_dismiss() {
		$r = self::dismiss_review(
			array(
				'order_id' => isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0, // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in dismiss_review().
				'nonce'    => isset( $_POST['nonce'] ) && is_scalar( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
			)
		);
		if ( ! $r['ok'] ) {
			wp_die( esc_html__( 'Yetkiniz yok ya da oturumunuzun süresi doldu.', 'vakifbank-vinov-integration-for-woocommerce' ), 403 );
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : self::url( 'talimatlar', array( 'durum' => 'incelenmeli' ) ) );
		exit;
	}

	/** Kampanyalar: read-only list, cached 5 minutes. @return string */
	private static function tab_campaigns() {
		$h = '<p>' . esc_html__( 'Bankanın işyerinize tanımladığı Vinov kampanyaları (yalnızca görüntüleme).', 'vakifbank-vinov-integration-for-woocommerce' ) . '</p>';
		$h .= self::action_form( 'refresh', 'kampanyalar', __( 'Bankadan yenile', 'vakifbank-vinov-integration-for-woocommerce' ) );
		if ( ! Eru_VNV_Settings::ready() ) {
			return $h;
		}
		$env = Eru_VNV_Settings::env();
		$res = self::cached(
			'campaigns',
			md5( $env ),
			function () use ( $env ) {
				$r = ( new Eru_VNV_API_Client( $env ) )->campaigns();
				return array(
					'ok'    => $r['success'],
					'items' => $r['success'] ? array_map( fn( $it ) => self::clean_item( $it ), array_values( array_filter( (array) ( $r['data']['Campaign'] ?? array() ), 'is_array' ) ) ) : array(),
					'error' => $r['error'],
				);
			}
		);
		if ( ! $res['ok'] ) {
			return $h . self::notice( 'error', sprintf( /* translators: %s: error */ __( 'Kampanyalar alınamadı: %s', 'vakifbank-vinov-integration-for-woocommerce' ), $res['error'] ) );
		}
		return $h . self::generic_table( $res['items'] );
	}

	/** Hareketler: receiver list (GET, not cached) and the debtor query (POST). @return string */
	private static function tab_movements( array $q, $debtor ) {
		list( $s, $e, $bs, $be ) = self::range( $q['baslangic'] ?? '', $q['bitis'] ?? '' );
		$iid = preg_match( '/^\d{1,18}$/', (string) ( $q['talimat'] ?? '' ) ) ? (string) $q['talimat'] : '';

		$h  = '<h2>' . esc_html__( 'Alıcı hareketleri', 'vakifbank-vinov-integration-for-woocommerce' ) . '</h2>';
		$h .= '<p class="description">' . esc_html__( 'İşyerinize yapılan Vinov tahsilatları. Sonuç saklanmaz; kişisel alanlar maskeli gösterilir.', 'vakifbank-vinov-integration-for-woocommerce' ) . '</p>';
		$h .= '<form method="get" class="eru-vnv-filters"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" /><input type="hidden" name="tab" value="hareketler" /><input type="hidden" name="sorgu" value="alici" /><input type="hidden" name="_eru_vnv_q" value="' . esc_attr( wp_create_nonce( self::RECEIVER_NONCE ) ) . '" />';
		$h .= '<label>' . esc_html__( 'Vade', 'vakifbank-vinov-integration-for-woocommerce' ) . ' <input type="date" name="baslangic" value="' . esc_attr( $s ) . '" /></label> – <input type="date" name="bitis" value="' . esc_attr( $e ) . '" aria-label="' . esc_attr__( 'Bitiş tarihi', 'vakifbank-vinov-integration-for-woocommerce' ) . '" /> ';
		$h .= '<label>' . esc_html__( 'Talimat no', 'vakifbank-vinov-integration-for-woocommerce' ) . ' <input type="text" inputmode="numeric" name="talimat" value="' . esc_attr( $iid ) . '" size="12" /></label> ';
		$h .= '<button type="submit" class="button">' . esc_html__( 'Sorgula', 'vakifbank-vinov-integration-for-woocommerce' ) . '</button></form>';
		if ( 'alici' === ( $q['sorgu'] ?? '' ) && Eru_VNV_Settings::ready() && ! wp_verify_nonce( (string) ( $q['_eru_vnv_q'] ?? '' ), self::RECEIVER_NONCE ) ) {
			// A cross-site link must not make the shop call the bank.
			$h .= self::notice( 'warning', __( 'Sorgu bağlantısının süresi dolmuş; formu yeniden gönderin.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		} elseif ( 'alici' === ( $q['sorgu'] ?? '' ) && Eru_VNV_Settings::ready() ) {
			$r = ( new Eru_VNV_API_Client() )->receiver_transactions(
				array_filter(
					array(
						'id'                => $iid,
						'expiry_start_date' => $bs,
						'expiry_end_date'   => $be,
					)
				)
			);
			$h .= $r['success'] ? self::generic_table( array_map( fn( $it ) => self::clean_item( $it ), self::first_list( $r['data'] ) ) ) : self::notice( 'error', sprintf( /* translators: %s: error */ __( 'Hareketler alınamadı: %s', 'vakifbank-vinov-integration-for-woocommerce' ), $r['error'] ) );
		}

		$h .= '<h2>' . esc_html__( 'Borçlu hareketleri', 'vakifbank-vinov-integration-for-woocommerce' ) . '</h2>';
		$h .= '<p class="description">' . esc_html__( 'Bir müşterinin Vinov borç hareketleri. TC kimlik numarası bankaya bir kez gönderilir; saklanmaz, günlüğe yazılmaz, adres satırına eklenmez ve sonuç önbelleğe alınmaz.', 'vakifbank-vinov-integration-for-woocommerce' ) . '</p>';
		$h .= '<form method="post" action="' . esc_url( self::url( 'hareketler' ) ) . '" class="eru-vnv-filters" autocomplete="off">';
		$h .= wp_nonce_field( self::DEBTOR_NONCE, 'eru_vnv_debtor_nonce', false, false );
		$h .= '<label>' . esc_html__( 'TC kimlik no', 'vakifbank-vinov-integration-for-woocommerce' ) . ' <input type="text" name="eru_vnv_debtor_tckn" value="" inputmode="numeric" maxlength="11" pattern="[0-9]{11}" autocomplete="off" required /></label> ';
		$h .= '<label>' . esc_html__( 'Vade', 'vakifbank-vinov-integration-for-woocommerce' ) . ' <input type="date" name="baslangic" value="' . esc_attr( $s ) . '" /></label> – <input type="date" name="bitis" value="' . esc_attr( $e ) . '" aria-label="' . esc_attr__( 'Bitiş tarihi', 'vakifbank-vinov-integration-for-woocommerce' ) . '" /> ';
		$h .= '<button type="submit" class="button">' . esc_html__( 'Sorgula', 'vakifbank-vinov-integration-for-woocommerce' ) . '</button></form>';
		if ( is_array( $debtor ) ) {
			$h .= $debtor['ok'] ? $debtor['html'] : self::notice( 'error', $debtor['message'] );
		}
		return $h;
	}

	/**
	 * Debtor movements for an identity number typed by the admin. The number is validated, sent once and
	 * dropped; the result is rendered (masked) and not cached.
	 *
	 * @param array $post {tckn, nonce, start, end}.
	 * @return array{ok:bool, code:string, message:string, html:string}
	 */
	public static function debtor_query( array $post ) {
		$no = fn( $code, $msg ) => array(
			'ok'      => false,
			'code'    => $code,
			'message' => $msg,
			'html'    => '',
		);
		$nonce = is_scalar( $post['nonce'] ?? null ) ? (string) $post['nonce'] : '';
		if ( '' === $nonce || ! wp_verify_nonce( $nonce, self::DEBTOR_NONCE ) ) {
			return $no( 'nonce', __( 'Oturumunuzun süresi doldu. Sayfayı yenileyip tekrar deneyin.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		if ( ! current_user_can( self::CAP ) ) {
			return $no( 'cap', __( 'Bu işlem için yetkiniz yok.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		$tckn = Eru_VNV_Identity::clean_tckn( $post['tckn'] ?? '' );
		if ( ! Eru_VNV_Identity::valid_tckn( $tckn ) ) {
			return $no( 'tckn', __( 'Geçerli bir TC kimlik numarası girin.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		if ( ! Eru_VNV_Settings::ready() ) {
			return $no( 'not_ready', __( 'Vinov ayarları eksik.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		list( , , $bs, $be ) = self::range( $post['start'] ?? '', $post['end'] ?? '' );
		$r    = ( new Eru_VNV_API_Client() )->debtor_transactions(
			array(
				'identity_number'   => $tckn,
				'expiry_start_date' => $bs,
				'expiry_end_date'   => $be,
			)
		);
		$mask = Eru_VNV_Identity::mask( $tckn );
		unset( $tckn );
		if ( ! $r['success'] ) {
			return $no( 'bank', sprintf( /* translators: %s: error */ __( 'Hareketler alınamadı: %s', 'vakifbank-vinov-integration-for-woocommerce' ), $r['error'] ) );
		}
		$rows = array_map( fn( $it ) => self::clean_item( $it ), self::first_list( $r['data'] ) );
		return array(
			'ok'      => true,
			'code'    => 'ok',
			'message' => '',
			'html'    => '<p><strong>' . esc_html( sprintf( /* translators: 1: masked identity number, 2: count */ __( '%1$s için %2$d hareket', 'vakifbank-vinov-integration-for-woocommerce' ), $mask, count( $rows ) ) ) . '</strong></p>' . self::generic_table( $rows ),
		);
	}

	/** Bağlantı testi tab. @return string */
	private static function tab_connection() {
		$h  = '<p>' . esc_html__( 'Seçili ortamda iki yetki (OTP ve Vinov) alınır ve kampanya listesi sorgulanır. SMS gönderilmez, talimat oluşturulmaz. Test kuyruğa alınır ve birkaç saniye içinde çalışır.', 'vakifbank-vinov-integration-for-woocommerce' ) . '</p>';
		$h .= self::action_form( 'conn_test', 'baglanti', __( 'Bağlantıyı test et', 'vakifbank-vinov-integration-for-woocommerce' ) );
		if ( Eru_VNV_Cron::pending_ids( Eru_VNV_Cron::CONN ) ) {
			$h .= self::notice( 'info', __( 'Test kuyrukta; sonucu görmek için sayfayı birazdan yenileyin.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		}
		$last = get_option( self::CONN_OPTION );
		if ( is_array( $last ) && ! empty( $last['checks'] ) ) {
			$h .= '<h2>' . esc_html( sprintf( /* translators: 1: date, 2: environment */ __( 'Son test: %1$s (%2$s)', 'vakifbank-vinov-integration-for-woocommerce' ), wp_date( 'd.m.Y H:i', (int) $last['at'] ), 'live' === ( $last['env'] ?? '' ) ? __( 'Canlı', 'vakifbank-vinov-integration-for-woocommerce' ) : __( 'Test', 'vakifbank-vinov-integration-for-woocommerce' ) ) ) . '</h2><table class="widefat striped eru-vnv-checks"><tbody>';
			foreach ( (array) $last['checks'] as $c ) {
				$h .= '<tr><td>' . esc_html( (string) ( $c[0] ?? '' ) ) . '</td><td>' . ( ! empty( $c[1] ) ? '<span class="eru-vnv-ok">' . esc_html__( 'Başarılı', 'vakifbank-vinov-integration-for-woocommerce' ) . '</span>' : '<span class="eru-vnv-bad">' . esc_html__( 'Başarısız', 'vakifbank-vinov-integration-for-woocommerce' ) . '</span>' ) . '</td><td>' . esc_html( (string) ( $c[2] ?? '' ) ) . '</td></tr>';
			}
			$h .= '</tbody></table>';
		}
		return $h;
	}

	/** Action Scheduler callback: the queued connection test (result stored without secrets). */
	public static function connection_test() {
		$env    = Eru_VNV_Settings::env();
		$checks = array();
		if ( ! Eru_VNV_Settings::ready( $env ) ) {
			$checks[] = array( __( 'Ayarlar', 'vakifbank-vinov-integration-for-woocommerce' ), false, __( 'API adresi, istemci veya işyeri bilgileri eksik.', 'vakifbank-vinov-integration-for-woocommerce' ) );
		} else {
			// Fresh tokens in their own keys: the tokens a running checkout uses stay cached.
			$c = new Eru_VNV_API_Client( $env );
			foreach ( array( 'oob' => __( 'OTP yetkisi', 'vakifbank-vinov-integration-for-woocommerce' ), 'payment' => __( 'Vinov yetkisi', 'vakifbank-vinov-integration-for-woocommerce' ) ) as $scope => $label ) {
				$t        = $c->token( $scope, true );
				$checks[] = array( $label, ! is_wp_error( $t ), is_wp_error( $t ) ? Eru_VNV_Logger::redact( $t->get_error_message() ) : '' );
			}
			if ( ! empty( $checks[1][1] ) ) {
				$r        = $c->campaigns();
				$checks[] = array( __( 'İşyeri sorgusu (kampanya listesi)', 'vakifbank-vinov-integration-for-woocommerce' ), $r['success'], $r['success'] ? '' : trim( $r['status_code'] . ' ' . $r['error'] ) );
			}
		}
		update_option(
			self::CONN_OPTION,
			array(
				'at'     => time(),
				'env'    => $env,
				'checks' => $checks,
			),
			false
		);
	}
}
