<?php
/**
 * Admin: WooCommerce → Garanti Havale Onayı (İşlemler, Eşleşmeler, Ayarlar), buttons, manual approval,
 * ledger release and the reference pattern tester.
 *
 * @package bank-transfer-auto-approval-for-garanti-bbva
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Viewing a tab never calls the bank: "Şimdi çalıştır" and "Hareketleri getir" only enqueue an action.
 * Manual approval posts a transaction id and an order id; the transaction itself is read from the
 * server-side cache of the last fetch.
 */
class Eru_GHA_Admin {

	const CAP  = 'manage_woocommerce';
	const SLUG = 'eru-gha';

	/** Hooks. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_eru_gha_action', array( __CLASS__, 'handle_action' ) );
		add_action( 'wp_ajax_eru_gha_test_pattern', array( __CLASS__, 'ajax_test_pattern' ) );
	}

	/** Menu entry under WooCommerce. */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Garanti Havale Onayı', 'bank-transfer-auto-approval-for-garanti-bbva' ), __( 'Garanti Havale Onayı', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::CAP, self::SLUG, array( __CLASS__, 'page' ) );
	}

	/** @param string $hook Hook. */
	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, self::SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'eru-gha-admin', ERU_GHA_URL . 'assets/admin.css', array(), ERU_GHA_VERSION );
		wp_enqueue_script( 'eru-gha-admin', ERU_GHA_URL . 'assets/admin.js', array(), ERU_GHA_VERSION, true );
		wp_localize_script(
			'eru-gha-admin',
			'eruGha',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'eru_gha_test_pattern' ),
				'i18n'  => array(
					'match'   => __( 'Eşleşir: bu açıklama sipariş numarasını içeriyor.', 'bank-transfer-auto-approval-for-garanti-bbva' ),
					'nomatch' => __( 'Eşleşmez.', 'bank-transfer-auto-approval-for-garanti-bbva' ),
					'refs'    => __( 'Bulunan referanslar:', 'bank-transfer-auto-approval-for-garanti-bbva' ),
					'none'    => __( 'yok', 'bank-transfer-auto-approval-for-garanti-bbva' ),
					'error'   => __( 'Hata:', 'bank-transfer-auto-approval-for-garanti-bbva' ),
				),
			)
		);
	}

	/** @return array<string,string> Tabs. */
	private static function tabs() {
		return array(
			'transactions' => __( 'İşlemler', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'matches'      => __( 'Eşleşmeler', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'settings'     => __( 'Ayarlar', 'bank-transfer-auto-approval-for-garanti-bbva' ),
		);
	}

	/** @param string $tab Tab. @param array $args Extra. @return string URL. */
	public static function url( $tab = 'transactions', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/** @param string $gmt MySQL GMT date. @return string Local date/time. */
	private static function when_gmt( $gmt ) {
		return $gmt ? wp_date( 'd.m.Y H:i', strtotime( $gmt . ' UTC' ) ) : '—';
	}

	/** @return array<string,array> Fixed result messages. */
	private static function messages() {
		return array(
			'queued'       => array( 'success', __( 'İş kuyruğa alındı; Action Scheduler birkaç saniye içinde çalıştırır. Sonucu bu sayfayı yenileyerek görün.', 'bank-transfer-auto-approval-for-garanti-bbva' ) ),
			'pending'      => array( 'info', __( 'Bu iş zaten kuyrukta.', 'bank-transfer-auto-approval-for-garanti-bbva' ) ),
			'no_creds'     => array( 'warning', __( 'Önce Ayarlar sekmesinde kimlik bilgilerini kaydedin.', 'bank-transfer-auto-approval-for-garanti-bbva' ) ),
			'approved'     => array( 'success', __( 'Sipariş seçilen havale işlemiyle onaylandı.', 'bank-transfer-auto-approval-for-garanti-bbva' ) ),
			'eru_gha_txn'  => array( 'error', __( 'İşlem, çekilen hareket listesinde yok (liste 10 dakika saklanır). Hareketleri yeniden getirip tekrar deneyin.', 'bank-transfer-auto-approval-for-garanti-bbva' ) ),
			'eru_gha_debit' => array( 'error', __( 'Yalnızca gelen (alacak) hareketlerle onay verilebilir.', 'bank-transfer-auto-approval-for-garanti-bbva' ) ),
			'eru_gha_off'  => array( 'warning', __( 'Otomatik kontrol kapalıyken elle onay yapılmaz. Ayarlar sekmesinden açın (deneme modu açık kalabilir).', 'bank-transfer-auto-approval-for-garanti-bbva' ) ),
			'eru_gha_dry'  => array( 'warning', __( 'Deneme modu açık: onay yalnızca günlüğe yazıldı, sipariş değişmedi.', 'bank-transfer-auto-approval-for-garanti-bbva' ) ),
			'eru_gha_locked' => array( 'warning', __( 'Şu anda bir eşleştirme çalışıyor; birazdan tekrar deneyin.', 'bank-transfer-auto-approval-for-garanti-bbva' ) ),
			'eru_gha_skip' => array( 'error', __( 'Onay yapılmadı (durum, ödeme yöntemi, para birimi, tutar veya işlem daha önce kullanılmış). Ayrıntı Eşleşmeler sekmesinde.', 'bank-transfer-auto-approval-for-garanti-bbva' ) ),
			'bad_order'    => array( 'error', __( 'Geçerli bir sipariş seçin.', 'bank-transfer-auto-approval-for-garanti-bbva' ) ),
			'released'     => array( 'success', __( 'İşlem serbest bırakıldı; siparişe not düşüldü.', 'bank-transfer-auto-approval-for-garanti-bbva' ) ),
			'eru_gha_note' => array( 'error', __( 'Serbest bırakmak için bir not yazın.', 'bank-transfer-auto-approval-for-garanti-bbva' ) ),
			'eru_gha_unused' => array( 'error', __( 'Bu işlem kayıtlı değil ya da zaten serbest.', 'bank-transfer-auto-approval-for-garanti-bbva' ) ),
			'eru_gha_release' => array( 'error', __( 'Kayıt güncellenemedi.', 'bank-transfer-auto-approval-for-garanti-bbva' ) ),
		);
	}

	/** admin-post handler for every button. */
	public static function handle_action() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'bank-transfer-auto-approval-for-garanti-bbva' ), 403 );
		}
		check_admin_referer( 'eru_gha_action' );
		$do  = sanitize_key( wp_unslash( $_POST['do'] ?? '' ) );
		$tab = sanitize_key( wp_unslash( $_POST['tab'] ?? 'transactions' ) );
		$tab = array_key_exists( $tab, self::tabs() ) ? $tab : 'transactions';
		$msg = '';
		if ( in_array( $do, array( 'run_now', 'fetch' ), true ) ) {
			if ( ! Eru_GHA_Settings::has_credentials() ) {
				$msg = 'no_creds';
			} else {
				$msg = Eru_GHA_Cron::enqueue( 'run_now' === $do ? Eru_GHA_Cron::NOW : Eru_GHA_Cron::FETCH ) ? 'queued' : 'pending';
			}
		} elseif ( 'approve' === $do ) {
			$txn   = sanitize_text_field( wp_unslash( $_POST['txn_id'] ?? '' ) );
			$oid   = absint( $_POST['order_id'] ?? 0 );
			$order = $oid ? wc_get_order( $oid ) : null;
			if ( ! $order || 'shop_order' !== $order->get_type() ) {
				$msg = 'bad_order';
			} else {
				$r   = Eru_GHA_Approver::manual( $txn, $oid, ! empty( $_POST['allow_diff'] ) );
				$msg = is_wp_error( $r ) ? $r->get_error_code() : 'approved';
			}
		} elseif ( 'release' === $do ) {
			$txn = sanitize_text_field( wp_unslash( $_POST['txn_id'] ?? '' ) );
			$r   = Eru_GHA_Ledger::release( $txn, sanitize_textarea_field( wp_unslash( $_POST['note'] ?? '' ) ), get_current_user_id() );
			$msg = is_wp_error( $r ) ? $r->get_error_code() : 'released';
			$tab = 'matches';
		}
		wp_safe_redirect( self::url( $tab, $msg ? array( 'eru_gha_msg' => $msg ) : array() ) );
		exit;
	}

	/** AJAX: reference pattern tester (same PCRE engine as the matcher). */
	public static function ajax_test_pattern() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( array( 'error' => __( 'Yetkiniz yok.', 'bank-transfer-auto-approval-for-garanti-bbva' ) ), 403 );
		}
		check_ajax_referer( 'eru_gha_test_pattern', 'nonce' );
		$pattern  = substr( (string) wp_unslash( $_POST['pattern'] ?? '' ), 0, 500 ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- a regex; never output unescaped, only compiled.
		$template = sanitize_text_field( wp_unslash( $_POST['template'] ?? '' ) );
		$text     = substr( sanitize_textarea_field( wp_unslash( $_POST['text'] ?? '' ) ), 0, 500 );
		$number   = substr( sanitize_text_field( wp_unslash( $_POST['number'] ?? '' ) ), 0, 40 );
		$c        = Eru_GHA_Matcher::compile( '' === trim( $pattern ) ? Eru_GHA_Settings::DEFAULT_PATTERN : $pattern, $template );
		if ( '' !== $c['error'] ) {
			wp_send_json_success( array( 'error' => $c['error'] ) );
		}
		$refs = Eru_GHA_Matcher::references( $text, $c );
		wp_send_json_success(
			array(
				'error' => '',
				'refs'  => $refs,
				'match' => '' !== $number && in_array( ltrim( $number, '#' ), $refs, true ),
			)
		);
	}

	/** @param string $do Action. @param string $label Label. @param string $tab Return tab. @param string $class Class. */
	private static function button( $do, $label, $tab, $class = 'button' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="eru-gha-inline">';
		wp_nonce_field( 'eru_gha_action' );
		echo '<input type="hidden" name="action" value="eru_gha_action"><input type="hidden" name="do" value="' . esc_attr( $do ) . '"><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '">';
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	/** Page. */
	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'bank-transfer-auto-approval-for-garanti-bbva' ), 403 );
		}
		$tabs = self::tabs();
		$def  = Eru_GHA_Settings::has_credentials() ? 'transactions' : 'settings';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : $def; // phpcs:ignore WordPress.Security.NonceVerification
		$tab  = isset( $tabs[ $tab ] ) ? $tab : $def;

		echo '<div class="wrap eru-gha"><h1>' . esc_html__( 'Garanti BBVA Havale Onayı', 'bank-transfer-auto-approval-for-garanti-bbva' ) . ' <span class="eru-gha-beta">' . esc_html__( 'beta', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</span></h1>';
		settings_errors( Eru_GHA_Settings::OPTION );
		$code = isset( $_GET['eru_gha_msg'] ) ? sanitize_key( wp_unslash( $_GET['eru_gha_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$msgs = self::messages();
		if ( isset( $msgs[ $code ] ) ) {
			printf( '<div class="notice notice-%1$s eru-keep is-dismissible"><p>%2$s</p></div>', esc_attr( $msgs[ $code ][0] ), esc_html( $msgs[ $code ][1] ) );
		}
		self::status_bar( $tab );
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $k => $label ) {
			echo '<a href="' . esc_url( self::url( $k ) ) . '" class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		if ( 'settings' === $tab ) {
			self::tab_settings();
		} elseif ( 'matches' === $tab ) {
			self::tab_matches();
		} else {
			self::tab_transactions();
		}
		echo '</div>';
	}

	/** @param string $tab Current tab. */
	private static function status_bar( $tab ) {
		$on   = Eru_GHA_Settings::is_enabled();
		$dry  = Eru_GHA_Settings::dry_run();
		$last = get_option( 'eru_gha_last_run', array() );
		$st   = array(
			'ok'             => __( 'tamamlandı', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'aborted'        => __( 'durduruldu (banka hatası)', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'locked'         => __( 'atlandı (başka çalışma sürüyordu)', 'bank-transfer-auto-approval-for-garanti-bbva' ),
			'not_configured' => __( 'ayar eksik', 'bank-transfer-auto-approval-for-garanti-bbva' ),
		);
		echo '<div class="eru-gha-bar"><ul>';
		echo '<li><span>' . esc_html__( 'Otomatik kontrol', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</span><b class="' . ( $on ? 'ok' : 'off' ) . '">' . esc_html( $on ? sprintf( /* translators: %s: minutes */ __( 'Açık, %s dakikada bir', 'bank-transfer-auto-approval-for-garanti-bbva' ), (string) Eru_GHA_Settings::get( 'interval' ) ) : __( 'Kapalı', 'bank-transfer-auto-approval-for-garanti-bbva' ) ) . '</b></li>';
		echo '<li><span>' . esc_html__( 'Mod', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</span><b class="' . ( $dry ? 'warn' : 'ok' ) . '">' . esc_html( $dry ? __( 'Deneme (hiçbir sipariş değişmez)', 'bank-transfer-auto-approval-for-garanti-bbva' ) : __( 'Canlı', 'bank-transfer-auto-approval-for-garanti-bbva' ) ) . '</b></li>';
		if ( $last ) {
			$txt = sprintf(
				/* translators: 1: date, 2: status, 3: fetched, 4: approved, 5: review */
				__( '%1$s — %2$s; %3$d hareket, %4$d onay, %5$d inceleme', 'bank-transfer-auto-approval-for-garanti-bbva' ),
				wp_date( 'd.m.Y H:i', (int) $last['time'] ),
				$st[ $last['status'] ] ?? $last['status'],
				(int) $last['fetched'],
				(int) ( $last['dry_run'] ? $last['would'] : $last['approved'] ),
				(int) $last['review']
			);
			echo '<li><span>' . esc_html__( 'Son çalışma', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</span><b class="' . ( 'aborted' === $last['status'] ? 'bad' : '' ) . '">' . esc_html( $txt ) . '</b></li>';
		}
		echo '</ul><div class="eru-gha-actions">';
		self::button( 'run_now', __( 'Şimdi çalıştır', 'bank-transfer-auto-approval-for-garanti-bbva' ), $tab, 'button button-primary' );
		self::button( 'fetch', __( 'Hareketleri getir', 'bank-transfer-auto-approval-for-garanti-bbva' ), 'transactions' );
		echo '</div></div>';
		if ( $last && 'aborted' === $last['status'] && '' !== (string) $last['error'] ) {
			echo '<div class="notice notice-error eru-keep inline"><p>' . esc_html__( 'Son çalışma eşleştirme yapmadan durdu: ', 'bank-transfer-auto-approval-for-garanti-bbva' ) . esc_html( (string) $last['error'] ) . '</p></div>';
		}
		$reviews = Eru_GHA_Log::recent_reviews();
		if ( $reviews ) {
			echo '<div class="notice notice-warning eru-keep inline"><p>' . esc_html( sprintf( /* translators: %d: orders */ _n( 'Son 24 saatte %d sipariş inceleme bekliyor (belirsiz eşleşme).', 'Son 24 saatte %d sipariş inceleme bekliyor (belirsiz eşleşme).', $reviews, 'bank-transfer-auto-approval-for-garanti-bbva' ), $reviews ) ) . ' <a href="' . esc_url( self::url( 'matches', array( 'type' => 'review' ) ) ) . '">' . esc_html__( 'Göster', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</a></p></div>';
		}
	}

	/** @return array<int,string> Watched orders for the manual approval picker (newest 100). */
	private static function watched_choices() {
		$out = array();
		if ( ! Eru_GHA_Settings::watch_statuses() ) {
			return $out;
		}
		foreach ( wc_get_orders(
			array(
				'type'    => 'shop_order',
				'status'  => Eru_GHA_Settings::watch_statuses(),
				'limit'   => 100,
				'orderby' => 'date',
				'order'   => 'DESC',
			)
		) as $o ) {
			if ( in_array( $o->get_payment_method(), Eru_GHA_Settings::gateways(), true ) ) {
				$out[ $o->get_id() ] = '#' . $o->get_order_number() . ' — ' . wp_strip_all_tags( wc_price( $o->get_total(), array( 'currency' => $o->get_currency() ) ) ) . ' — ' . $o->get_formatted_billing_full_name();
			}
		}
		return $out;
	}

	/** İşlemler tab. */
	private static function tab_transactions() {
		$cache = get_transient( Eru_GHA_Client::CACHE );
		echo '<p class="description">' . esc_html__( 'Son çekilen hesap hareketleri (10 dakika saklanır). Sayfayı açmak bankaya istek göndermez; listeyi "Hareketleri getir" ile yenileyin.', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</p>';
		if ( ! is_array( $cache ) || empty( $cache['transactions'] ) ) {
			echo '<div class="eru-gha-empty"><p>' . esc_html__( 'Henüz çekilmiş hareket yok.', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</p></div>';
			return;
		}
		$list = $cache['transactions'];
		uasort( $list, fn( $a, $b ) => $b['time'] <=> $a['time'] );
		$rx      = Eru_GHA_Matcher::compile( (string) Eru_GHA_Settings::get( 'ref_pattern' ), (string) Eru_GHA_Settings::get( 'ref_template' ) );
		$used    = array_flip( Eru_GHA_Ledger::used_ids( array_keys( $list ) ) );
		$choices = self::watched_choices();
		/* translators: %s: time */
		echo '<p>' . esc_html( sprintf( __( 'Çekilme zamanı: %s', 'bank-transfer-auto-approval-for-garanti-bbva' ), wp_date( 'd.m.Y H:i', (int) $cache['time'] ) ) ) . '</p>';
		echo '<datalist id="eru-gha-orders">';
		foreach ( $choices as $id => $label ) {
			echo '<option value="' . esc_attr( (string) $id ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</datalist>';
		echo '<table class="widefat striped eru-gha-table"><thead><tr><th>' . esc_html__( 'Tarih', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th><th>' . esc_html__( 'Tür', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th><th class="num">' . esc_html__( 'Tutar', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th><th>' . esc_html__( 'Açıklama', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th><th>' . esc_html__( 'Gönderen', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th><th>' . esc_html__( 'Referans', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th><th>' . esc_html__( 'Durum', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th></tr></thead><tbody>';
		foreach ( $list as $t ) {
			$refs = $t['credit'] ? Eru_GHA_Matcher::references( $t['text'], $rx ) : array();
			echo '<tr class="' . ( $t['credit'] ? 'credit' : 'debit' ) . '"><td>' . esc_html( $t['date'] ) . '</td>';
			echo '<td>' . ( $t['credit'] ? '<span class="eru-gha-pill in">' . esc_html__( 'Gelen', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</span>' : '<span class="eru-gha-pill out">' . esc_html__( 'Giden', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</span>' ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( $t['amount'], 2 ) . ( '' !== $t['currency'] ? ' ' . $t['currency'] : '' ) ) . '</td>';
			echo '<td class="desc">' . esc_html( $t['text'] ) . '</td>';
			echo '<td>' . esc_html( $t['payer'] ) . ( '' !== $t['payer_iban'] ? '<br><small>' . esc_html( $t['payer_iban'] ) . '</small>' : '' ) . '</td>';
			echo '<td>' . esc_html( $refs ? implode( ', ', $refs ) : '—' ) . '</td><td>';
			if ( isset( $used[ $t['id'] ] ) ) {
				$oid = Eru_GHA_Ledger::order_for( $t['id'] );
				$o   = wc_get_order( $oid );
				echo '<span class="eru-gha-pill used">' . esc_html__( 'Kullanıldı', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</span> ';
				echo $o ? '<a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a>' : esc_html( '#' . $oid );
			} elseif ( $t['credit'] ) {
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="eru-gha-approve">';
				wp_nonce_field( 'eru_gha_action' );
				echo '<input type="hidden" name="action" value="eru_gha_action"><input type="hidden" name="do" value="approve"><input type="hidden" name="tab" value="transactions"><input type="hidden" name="txn_id" value="' . esc_attr( $t['id'] ) . '">';
				echo '<input type="number" min="1" name="order_id" list="eru-gha-orders" placeholder="' . esc_attr__( 'Sipariş ID', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '" required> ';
				echo '<label><input type="checkbox" name="allow_diff" value="1"> ' . esc_html__( 'tutar farkını onaylıyorum', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</label> ';
				echo '<button type="submit" class="button button-small">' . esc_html__( 'Bu işlemle onayla', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</button></form>';
			} else {
				echo '—';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Eşleşmeler tab: log and ledger. */
	private static function tab_matches() {
		$types = Eru_GHA_Log::types();
		$type  = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$type  = isset( $types[ $type ] ) ? $type : '';
		$pg    = max( 1, absint( $_GET['paged'] ?? 1 ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$res   = Eru_GHA_Log::page( $type, $pg );

		echo '<ul class="subsubsub"><li><a href="' . esc_url( self::url( 'matches' ) ) . '"' . ( '' === $type ? ' class="current"' : '' ) . '>' . esc_html__( 'Tümü', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</a></li>';
		foreach ( $types as $k => $label ) {
			echo '<li> | <a href="' . esc_url( self::url( 'matches', array( 'type' => $k ) ) ) . '"' . ( $k === $type ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
		}
		echo '</ul><br class="clear">';
		echo '<table class="widefat striped eru-gha-table"><thead><tr><th>' . esc_html__( 'Tarih', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th><th>' . esc_html__( 'Kayıt', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th><th>' . esc_html__( 'Sipariş', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th><th class="num">' . esc_html__( 'Tutar', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th><th>' . esc_html__( 'Ayrıntı', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th></tr></thead><tbody>';
		if ( ! $res['rows'] ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Kayıt yok.', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</td></tr>';
		}
		foreach ( $res['rows'] as $r ) {
			$o = $r['order_id'] ? wc_get_order( (int) $r['order_id'] ) : null;
			echo '<tr><td>' . esc_html( self::when_gmt( $r['created_at'] ) ) . '</td>';
			echo '<td><span class="eru-gha-pill t-' . esc_attr( $r['type'] ) . '">' . esc_html( $types[ $r['type'] ] ?? $r['type'] ) . '</span></td>';
			echo '<td>' . ( $o ? '<a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a>' : ( $r['order_id'] ? esc_html( '#' . $r['order_id'] ) : '—' ) ) . '</td>';
			echo '<td class="num">' . esc_html( null !== $r['amount'] ? number_format_i18n( (float) $r['amount'], 2 ) : '—' ) . '</td>';
			echo '<td>' . esc_html( $r['message'] );
			$ctx = $r['context'] ? json_decode( (string) $r['context'], true ) : null;
			if ( is_array( $ctx ) && ! empty( $ctx['txns'] ) ) {
				echo '<ul class="eru-gha-txns">';
				foreach ( $ctx['txns'] as $t ) {
					echo '<li><code>' . esc_html( (string) ( $t['id'] ?? '' ) ) . '</code> ' . esc_html( (string) ( $t['date'] ?? '' ) ) . ' — ' . esc_html( number_format_i18n( (float) ( $t['amount'] ?? 0 ), 2 ) ) . ' — ' . esc_html( (string) ( $t['text'] ?? '' ) ) . '</li>';
				}
				echo '</ul>';
			} elseif ( $r['txn_ids'] ) {
				echo '<br><code>' . esc_html( $r['txn_ids'] ) . '</code>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
		self::pager( $res['total'], $pg, 50, array( 'type' => $type ) );

		$l = Eru_GHA_Ledger::page( 1, 50 );
		echo '<h2>' . esc_html__( 'Kullanılan havale işlemleri', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Bir işlem burada "kullanıldı" görünürken başka bir siparişi onaylayamaz; sipariş durumu geri alınsa bile kayıt kalır. Yanlış eşleşmede işlemi bir notla serbest bırakabilirsiniz.', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</p>';
		echo '<table class="widefat striped eru-gha-table"><thead><tr><th>' . esc_html__( 'İşlem', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th><th>' . esc_html__( 'Sipariş', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th><th class="num">' . esc_html__( 'Tutar', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th><th>' . esc_html__( 'Tarih', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th><th>' . esc_html__( 'Durum', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</th></tr></thead><tbody>';
		if ( ! $l['rows'] ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Kayıt yok.', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</td></tr>';
		}
		foreach ( $l['rows'] as $r ) {
			$o = wc_get_order( (int) $r['order_id'] );
			echo '<tr><td><code>' . esc_html( $r['txn_id'] ) . '</code></td><td>' . ( $o ? '<a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a>' : esc_html( '#' . $r['order_id'] ) ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( (float) $r['amount'], 2 ) ) . '</td><td>' . esc_html( self::when_gmt( $r['used_at'] ) ) . ( 'manual' === $r['source'] ? ' <small>' . esc_html__( '(elle)', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</small>' : '' ) . '</td><td>';
			if ( '1' === (string) $r['active'] ) {
				echo '<span class="eru-gha-pill used">' . esc_html__( 'Kullanıldı', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</span>';
				if ( $o ) {
					/* translators: %s: order status */
					echo ' <small>' . esc_html( sprintf( __( 'Sipariş şu an: %s', 'bank-transfer-auto-approval-for-garanti-bbva' ), wc_get_order_status_name( $o->get_status() ) ) ) . '</small>';
					if ( $o->is_paid() ) {
						echo '<br><small class="eru-gha-warn">' . esc_html__( 'Sipariş hâlâ ödenmiş durumda: serbest bırakılan işlem başka bir siparişi onaylayabilir.', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</small>';
					}
				}
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="eru-gha-release">';
				wp_nonce_field( 'eru_gha_action' );
				echo '<input type="hidden" name="action" value="eru_gha_action"><input type="hidden" name="do" value="release"><input type="hidden" name="tab" value="matches"><input type="hidden" name="txn_id" value="' . esc_attr( $r['txn_id'] ) . '">';
				echo '<input type="text" name="note" required minlength="3" placeholder="' . esc_attr__( 'Neden? (zorunlu)', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '"> <button type="submit" class="button button-small">' . esc_html__( 'Serbest bırak', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</button></form>';
			} else {
				echo '<span class="eru-gha-pill">' . esc_html__( 'Serbest', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</span> <small>' . esc_html( self::when_gmt( (string) $r['released_at'] ) . ' — ' . (string) $r['release_note'] ) . '</small>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Pagination links. */
	private static function pager( $total, $page, $per, array $args ) {
		$pages = (int) ceil( $total / $per );
		if ( $pages < 2 ) {
			return;
		}
		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo wp_kses_post(
			paginate_links(
				array(
					'base'    => add_query_arg( 'paged', '%#%', self::url( 'matches', array_filter( $args ) ) ),
					'format'  => '',
					'current' => $page,
					'total'   => $pages,
				)
			)
		);
		echo '</div></div>';
	}

	/** @param string $key Key. @return string Field name. */
	private static function name( $key ) {
		return Eru_GHA_Settings::OPTION . '[' . $key . ']';
	}

	/** Row. */
	private static function row( $label, $html, $hint = '', $for = '' ) {
		echo '<tr><th scope="row">' . ( $for ? '<label for="' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label ) ) . '</th><td>' . $html; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		if ( $hint ) {
			echo '<p class="description">' . esc_html( $hint ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** @return string Checkbox. */
	private static function checkbox( $key, $label ) {
		return '<label><input type="checkbox" name="' . esc_attr( self::name( $key ) ) . '" value="1"' . checked( Eru_GHA_Settings::on( $key ), true, false ) . '> ' . esc_html( $label ) . '</label>';
	}

	/** @return string Input. */
	private static function input( $key, $type = 'text', $class = 'regular-text', $extra = '' ) {
		return '<input type="' . esc_attr( $type ) . '" id="eru-gha-' . esc_attr( $key ) . '" class="' . esc_attr( $class ) . '" name="' . esc_attr( self::name( $key ) ) . '" value="' . esc_attr( (string) Eru_GHA_Settings::get( $key ) ) . '"' . $extra . '>';
	}

	/** @param string $key Key. @param array $opts value => label. @param bool $multi Checkbox list. @return string */
	private static function choices( $key, array $opts, $multi ) {
		$cur  = Eru_GHA_Settings::get( $key );
		$html = $multi ? '<fieldset class="eru-gha-checks">' : '<select id="eru-gha-' . esc_attr( $key ) . '" name="' . esc_attr( self::name( $key ) ) . '">';
		foreach ( $opts as $v => $label ) {
			$html .= $multi
				? '<label><input type="checkbox" name="' . esc_attr( self::name( $key ) ) . '[]" value="' . esc_attr( (string) $v ) . '"' . checked( in_array( (string) $v, (array) $cur, true ), true, false ) . '> ' . esc_html( $label ) . '</label>'
				: '<option value="' . esc_attr( (string) $v ) . '"' . selected( (string) $cur, (string) $v, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html . ( $multi ? '</fieldset>' : '</select>' );
	}

	/** Ayarlar tab. */
	private static function tab_settings() {
		$gws = array();
		foreach ( WC()->payment_gateways() ? WC()->payment_gateways()->payment_gateways() : array() as $id => $gw ) {
			$gws[ $id ] = wp_strip_all_tags( $gw->get_method_title() ? $gw->get_method_title() : $id ) . ' (' . $id . ')';
		}
		$statuses = Eru_GHA_Settings::order_statuses();

		echo '<form method="post" action="options.php" class="eru-gha-form">';
		settings_fields( Eru_GHA_Settings::GROUP );
		echo '<h2>' . esc_html__( 'Bağlantı', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</h2><table class="form-table" role="presentation">';
		self::row( __( 'Otomatik kontrol', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::checkbox( 'enabled', __( 'Hesap hareketlerini zamanlanmış olarak kontrol et ve eşleşen siparişleri onayla', 'bank-transfer-auto-approval-for-garanti-bbva' ) ), __( 'Kapalıyken zamanlanmış iş kurulmaz; "Şimdi çalıştır" ve WP-CLI yalnızca deneme turu yapar, elle onay yapılmaz.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		self::row( __( 'Deneme modu', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::checkbox( 'dry_run', __( 'Yalnızca eşleşmeleri günlüğe yaz; sipariş, not, e-posta ve kullanılan işlem kaydı değişmez', 'bank-transfer-auto-approval-for-garanti-bbva' ) ), __( 'İlk kurulumda açıktır. Eşleşmeler sekmesinde "Eşleşirdi" satırlarını kontrol edip sonra kapatın.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		self::row( __( 'Client ID', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::input( 'client_id', 'text', 'regular-text', ' autocomplete="off"' ), '', 'eru-gha-client_id' );
		$saved = '' !== (string) Eru_GHA_Settings::get( 'client_secret' );
		$html  = '<input type="password" id="eru-gha-client_secret" class="regular-text" name="' . esc_attr( self::name( 'client_secret' ) ) . '" value="" autocomplete="new-password">';
		if ( $saved ) {
			$html .= ' <span class="eru-gha-saved">' . esc_html__( 'Kayıtlı', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</span><p class="description">' . esc_html__( 'Değiştirmek için yeni değer girin; boş bırakırsanız kayıtlı değer korunur.', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</p><label><input type="checkbox" name="' . esc_attr( self::name( 'clear_secret' ) ) . '" value="1"> ' . esc_html__( 'Kayıtlı secret\'ı sil', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</label>';
		}
		self::row( __( 'Client secret', 'bank-transfer-auto-approval-for-garanti-bbva' ), $html, '', 'eru-gha-client_secret' );
		self::row( __( 'Rıza (consent) ID', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::input( 'consent_id', 'text', 'regular-text', ' autocomplete="off"' ), __( 'Garanti BBVA geliştirici portalında hesap hareketleri için verdiğiniz rızanın kimliği.', 'bank-transfer-auto-approval-for-garanti-bbva' ), 'eru-gha-consent_id' );
		self::row( __( 'Hesap numarası', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::input( 'account_number', 'text', 'regular-text', ' inputmode="numeric" autocomplete="off"' ), '', 'eru-gha-account_number' );
		self::row( __( 'Şube (unit) numarası', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::input( 'unit_number', 'text', 'small-text', ' inputmode="numeric" autocomplete="off"' ), '', 'eru-gha-unit_number' );
		self::row( __( 'IBAN', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::input( 'iban', 'text', 'regular-text', ' autocomplete="off" placeholder="TR…"' ), __( 'TR ile başlayan 26 karakter; kontrol basamakları doğrulanır.', 'bank-transfer-auto-approval-for-garanti-bbva' ), 'eru-gha-iban' );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Eşleştirme', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</h2><table class="form-table" role="presentation">';
		self::row( __( 'İzlenen ödeme yöntemleri', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::choices( 'gateways', $gws, true ) );
		self::row( __( 'İzlenen sipariş durumları', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::choices( 'watch_statuses', Eru_GHA_Settings::watchable_statuses(), true ), __( 'Yalnızca bu durumlardaki siparişler onaylanır; onaydan hemen önce durum veritabanından yeniden okunur. Ödenmiş ve kapanmış durumlar (tamamlandı, iptal, iade, başarısız) izlenemez.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		self::row( __( 'Onaydan sonraki durum', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::choices( 'approved_status', $statuses, false ), __( 'Ödenmiş sayılan bir durumsa (ör. Hazırlanıyor) WooCommerce ödeme tamamlandı akışı çalışır.', 'bank-transfer-auto-approval-for-garanti-bbva' ), 'eru-gha-approved_status' );
		self::row( __( 'Referans deseni', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::input( 'ref_pattern', 'text', 'regular-text code' ), __( 'PCRE; 1. yakalama grubu sipariş numarasıdır. Varsayılan, daha uzun bir sayının parçası olmayan 3–10 haneli sayıları alır.', 'bank-transfer-auto-approval-for-garanti-bbva' ), 'eru-gha-ref_pattern' );
		self::row( __( 'Önek şablonu', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::input( 'ref_template', 'text', 'regular-text', ' placeholder="SIP-{number}"' ), __( 'İsteğe bağlı. Doluysa yalnızca bu şablona uyan referanslar sayılır (büyük/küçük harf duyarsız; boşluk, tire, nokta gibi ayraçlar esnek).', 'bank-transfer-auto-approval-for-garanti-bbva' ), 'eru-gha-ref_template' );
		$tester  = '<div class="eru-gha-tester"><input type="text" id="eru-gha-test-text" class="regular-text" value="SIPARIS 1234 ODEME" aria-label="' . esc_attr__( 'Örnek açıklama', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '"> ';
		$tester .= '<input type="text" id="eru-gha-test-number" class="small-text" value="1234" aria-label="' . esc_attr__( 'Sipariş no', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '"> ';
		$tester .= '<button type="button" class="button" id="eru-gha-test-btn">' . esc_html__( 'Dene', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</button><div id="eru-gha-test-out" class="eru-gha-test-out" aria-live="polite"></div></div>';
		self::row( __( 'Desen testi', 'bank-transfer-auto-approval-for-garanti-bbva' ), $tester, __( 'Örnek banka açıklaması ve sipariş numarası girin; kaydetmeden önce sunucudaki aynı motorla denenir.', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		self::row( __( 'Geriye bakış (saat)', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::input( 'lookback_hours', 'number', 'small-text', ' min="24" max="96" step="1"' ), __( '24–96 saat.', 'bank-transfer-auto-approval-for-garanti-bbva' ), 'eru-gha-lookback_hours' );
		self::row( __( 'Tutar toleransı', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::input( 'tolerance', 'number', 'small-text', ' min="0" max="10" step="0.01"' ), __( 'Sipariş tutarı ile havale tutarı arasında kabul edilen en büyük fark. 0 = birebir.', 'bank-transfer-auto-approval-for-garanti-bbva' ), 'eru-gha-tolerance' );
		self::row( __( 'Parçalı havale', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::checkbox( 'allow_split', __( 'Aynı siparişe ait iki veya daha fazla havalenin toplamı tutarı karşılıyorsa onayla', 'bank-transfer-auto-approval-for-garanti-bbva' ) ) );
		echo '</table>';

		echo '<h2>' . esc_html__( 'Bildirim ve zamanlama', 'bank-transfer-auto-approval-for-garanti-bbva' ) . '</h2><table class="form-table" role="presentation">';
		self::row( __( 'Bildirim e-postaları', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::input( 'notify_emails', 'text', 'large-text' ), __( 'Virgülle ayırın. Onaylanan ve incelenmesi gereken eşleşmeler bildirilir.', 'bank-transfer-auto-approval-for-garanti-bbva' ), 'eru-gha-notify_emails' );
		self::row( __( 'Kontrol sıklığı', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::choices( 'interval', Eru_GHA_Settings::intervals(), false ), '', 'eru-gha-interval' );
		self::row( __( 'Günlük saklama (gün)', 'bank-transfer-auto-approval-for-garanti-bbva' ), self::input( 'log_days', 'number', 'small-text', ' min="7" max="365"' ), __( 'Eşleşme günlüğü hareket açıklamalarını (gönderen adı içerebilir) bu süre kadar saklar.', 'bank-transfer-auto-approval-for-garanti-bbva' ), 'eru-gha-log_days' );
		echo '</table>';
		submit_button( __( 'Kaydet', 'bank-transfer-auto-approval-for-garanti-bbva' ) );
		echo '</form>';
	}
}
