<?php
/**
 * WooCommerce → İYS İzinleri: consents, audit trail, queue, settings and tools.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tabs: İzinler (list, detail with history, admin change with a mandatory note), Denetim kaydı, Kuyruk (per
 * environment, bulk retry/skip, last API errors, pull and reconcile summaries), Ayarlar (Bağlantı, Toplama,
 * Senkron ve saklama, Harici API) and Araçlar (CSV import/export, consent-log table import with a column mapping).
 *
 * Settings are saved through options.php (capability mapped to manage_woocommerce). Passwords and the API
 * signing secret are write-only. Every other form posts to admin-post.php (or, for the queue's bulk actions,
 * to the page itself) and is checked for manage_woocommerce and a nonce by a handler that returns WP_Error
 * instead of acting; GET requests only display. Recipients are masked on every screen.
 */
class Eru_CMI_Admin {

	const CAP        = 'manage_woocommerce';
	const SLUG       = 'eru-cmi';
	const BULK_NONCE = 'bulk-eru-cmi-queue';
	const IMPORT_MAX = 1000;

	/** Hooks. */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_cmi_test', array( __CLASS__, 'handle_test' ) );
		foreach ( array( 'change', 'import', 'export', 'table', 'keys', 'action' ) as $a ) {
			add_action( 'admin_post_eru_cmi_' . $a, array( __CLASS__, 'http_' . $a ) );
		}
		add_action( 'load-woocommerce_page_' . self::SLUG, array( __CLASS__, 'http_bulk' ) );
	}

	/** Menu entry. */
	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'İYS İzinleri', 'consent-manager-for-iys' ), __( 'İYS İzinleri', 'consent-manager-for-iys' ), self::CAP, self::SLUG, array( __CLASS__, 'page' ) );
	}

	/** @return array<string,string> Tab => label. */
	public static function tabs() {
		return array(
			'consents' => __( 'İzinler', 'consent-manager-for-iys' ),
			'audit'    => __( 'Denetim kaydı', 'consent-manager-for-iys' ),
			'queue'    => __( 'Kuyruk', 'consent-manager-for-iys' ),
			'settings' => __( 'Ayarlar', 'consent-manager-for-iys' ),
			'tools'    => __( 'Araçlar', 'consent-manager-for-iys' ),
		);
	}

	/** @return array<string,string> Settings section => label. */
	public static function sections() {
		return array(
			'connection' => __( 'Bağlantı', 'consent-manager-for-iys' ),
			'collect'    => __( 'Toplama', 'consent-manager-for-iys' ),
			'sync'       => __( 'Senkron ve saklama', 'consent-manager-for-iys' ),
			'external'   => __( 'Harici API', 'consent-manager-for-iys' ),
		);
	}

	/** @return array{0:string,1:string} Current tab and settings section (old ?tab=connection links still work). */
	private static function tab() {
		// phpcs:disable WordPress.Security.NonceVerification
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'consents';
		$section = isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : 'connection';
		// phpcs:enable
		if ( isset( self::sections()[ $tab ] ) ) {
			$section = $tab;
			$tab     = 'settings';
		}
		return array(
			isset( self::tabs()[ $tab ] ) ? $tab : 'consents',
			isset( self::sections()[ $section ] ) ? $section : 'connection',
		);
	}

	/** @param array $args Query args. @return string Page URL. */
	public static function url( array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'admin.php' ) );
	}

	/** @return array<string,string> Queue/İYS state labels. */
	public static function iys_labels() {
		return array(
			'known'      => __( 'İYS\'de kayıtlı', 'consent-manager-for-iys' ),
			'none'       => __( 'Gönderilmedi', 'consent-manager-for-iys' ),
			'queued'     => __( 'Bekliyor', 'consent-manager-for-iys' ),
			'sent'       => __( 'İYS sonucu bekleniyor', 'consent-manager-for-iys' ),
			'accepted'   => __( 'Kabul edildi', 'consent-manager-for-iys' ),
			'rejected'   => __( 'Reddedildi', 'consent-manager-for-iys' ),
			'skipped'    => __( 'Atlandı', 'consent-manager-for-iys' ),
			'failed'     => __( 'Başarısız', 'consent-manager-for-iys' ),
			'superseded' => __( 'Yenisiyle geçersiz', 'consent-manager-for-iys' ),
		);
	}

	/** @return array<string,string> Actor labels. */
	public static function actor_labels() {
		return array(
			'user'   => __( 'Alıcı', 'consent-manager-for-iys' ),
			'admin'  => __( 'Yönetici', 'consent-manager-for-iys' ),
			'api'    => __( 'Program (API)', 'consent-manager-for-iys' ),
			'iys'    => __( 'İYS', 'consent-manager-for-iys' ),
			'import' => __( 'İçe aktarma', 'consent-manager-for-iys' ),
		);
	}

	/** @param object $c Consent row (with env_status when known). @return string İYS state in the selected environment. */
	public static function iys_state( $c ) {
		$env    = Eru_CMI_Settings::env();
		$labels = self::iys_labels();
		$st     = isset( $c->env_status ) ? (string) $c->env_status : '';
		if ( '' !== $st ) {
			return $labels[ $st ] ?? $st;
		}
		return false !== strpos( (string) $c->iys_known, ',' . $env . ',' ) ? $labels['known'] : $labels['none'];
	}

	/** @param object $h History row. @return string Evidence summary (no personal data). */
	public static function evidence( $h ) {
		$parts = array( ! empty( $h->verified ) ? __( 'bağlantıyla doğrulandı', 'consent-manager-for-iys' ) : __( 'doğrulanmadı', 'consent-manager-for-iys' ) );
		if ( ! empty( $h->text_hash ) ) {
			$parts[] = __( 'metin', 'consent-manager-for-iys' ) . ' ' . substr( (string) $h->text_hash, 0, 10 ) . '…';
		}
		if ( ! empty( $h->ip_hash ) ) {
			$parts[] = __( 'IP özeti', 'consent-manager-for-iys' );
		}
		return implode( ' · ', $parts );
	}

	/** @param string $env Environment. @return array<string,int> Queue rows per status (admin lists, tests). */
	public static function queue_counts( $env ) {
		return Eru_CMI_Queue::counts( $env );
	}

	/** @param string $key Key. @return string Field name. */
	private static function name( $key ) {
		return Eru_CMI_Settings::OPTION . '[' . $key . ']';
	}

	/** Text input row. */
	private static function text( $key, $label, $hint = '', $type = 'text', $placeholder = '' ) {
		$id = 'eru-cmi-' . $key;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" type="' . esc_attr( $type ) . '" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::name( $key ) ) . '" value="' . esc_attr( Eru_CMI_Settings::get( $key ) ) . '" placeholder="' . esc_attr( $placeholder ) . '" autocomplete="off">';
		if ( $hint ) {
			echo '<p class="description">' . esc_html( $hint ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** Checkbox row. */
	private static function check( $key, $label, $hint = '' ) {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><label><input type="checkbox" name="' . esc_attr( self::name( $key ) ) . '" value="1"' . checked( Eru_CMI_Settings::on( $key ), true, false ) . '> ' . esc_html__( 'Etkin', 'consent-manager-for-iys' ) . '</label>';
		if ( $hint ) {
			echo '<p class="description">' . esc_html( $hint ) . '</p>';
		}
		echo '</td></tr>';
	}

	/** Write-only password row. */
	private static function password( $env ) {
		$key    = $env . '_password';
		$stored = '' !== Eru_CMI_Settings::get( $key );
		$id     = 'eru-cmi-' . $key;
		echo '<tr><th scope="row"><label for="' . esc_attr( $id ) . '">' . esc_html__( 'Şifre', 'consent-manager-for-iys' ) . '</label></th><td>';
		echo '<input class="regular-text" type="password" id="' . esc_attr( $id ) . '" name="' . esc_attr( self::name( $key ) ) . '" value="" autocomplete="new-password" placeholder="' . esc_attr( $stored ? __( 'Kayıtlı — değiştirmek için yazın', 'consent-manager-for-iys' ) : '' ) . '">';
		if ( $stored ) {
			echo ' <span class="description">' . esc_html__( 'Kayıtlı', 'consent-manager-for-iys' ) . '</span>';
			echo '<p><label><input type="checkbox" name="' . esc_attr( self::name( $env . '_clear_password' ) ) . '" value="1"> ' . esc_html__( 'Kayıtlı şifreyi sil', 'consent-manager-for-iys' ) . '</label></p>';
		}
		echo '<p class="description">' . esc_html__( 'Şifre ekranda gösterilmez ve kayıtlara yazılmaz. Boş bırakırsanız kayıtlı şifre korunur.', 'consent-manager-for-iys' ) . '</p></td></tr>';
	}

	/** Small inline styles for badges and the detail panel. */
	private static function styles() {
		echo '<style>.eru-cmi-badge{display:inline-block;padding:1px 8px;border-radius:10px;background:#f0f0f1;font-size:12px}.eru-cmi-badge.is-onay,.eru-cmi-badge.is-accepted{background:#d7f0dd;color:#1e5a2b}.eru-cmi-badge.is-ret,.eru-cmi-badge.is-rejected,.eru-cmi-badge.is-failed{background:#fbe1e1;color:#8a1f1f}.eru-cmi-badge.is-queued,.eru-cmi-badge.is-sent{background:#fcf0d4;color:#6b4d00}.eru-cmi-panel{background:#fff;border:1px solid #dcdcde;border-left:4px solid #2271b1;padding:12px 16px;margin:16px 0}.eru-cmi-panel h2{margin-top:0}.eru-cmi-panel table.widefat{margin:8px 0 16px}.eru-cmi-summary td,.eru-cmi-summary th{padding:4px 12px 4px 0;text-align:left}.eru-cmi-cols{display:flex;gap:24px;flex-wrap:wrap}.eru-cmi-cols>div{flex:1 1 360px}table.eru-cmi-consents .column-recipient{width:20%}table.eru-cmi-consents .column-consent_date,table.eru-cmi-consents .column-updated_at{width:12%}table.eru-cmi-queue .column-id{width:5%}table.eru-cmi-queue .column-recipient{width:13%}table.eru-cmi-queue .column-record{width:18%}table.eru-cmi-queue .column-status{width:9%}table.eru-cmi-queue .column-attempts{width:5%}table.eru-cmi-queue .column-next_try_at,table.eru-cmi-queue .column-updated_at{width:10%}table.eru-cmi-queue .column-last_error{width:20%}</style>';
	}

	/** Page. */
	public static function page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'consent-manager-for-iys' ) );
		}
		require_once ERU_CMI_PATH . 'includes/class-eru-cmi-admin-tables.php';
		list( $tab, $section ) = self::tab();
		self::styles();
		echo '<div class="wrap"><h1>' . esc_html__( 'İYS İzinleri', 'consent-manager-for-iys' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::tabs() as $k => $label ) {
			echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( array( 'tab' => $k ) ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		self::notice();
		switch ( $tab ) {
			case 'audit':
				self::audit_tab();
				break;
			case 'queue':
				self::queue_tab();
				break;
			case 'settings':
				self::settings_tab( $section );
				break;
			case 'tools':
				self::tools_tab();
				break;
			default:
				self::consents_tab();
		}
		echo '</div>';
	}

	/** Result notice after a redirect (codes only; nothing personal travels in the URL). */
	private static function notice() {
		$code = isset( $_GET['eru_cmi_msg'] ) ? sanitize_key( wp_unslash( $_GET['eru_cmi_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$n    = isset( $_GET['n'] ) ? absint( $_GET['n'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		$msgs = array(
			/* translators: %d: rows */
			'retried'  => array( 'success', sprintf( __( '%d satır yeniden kuyruğa alındı.', 'consent-manager-for-iys' ), $n ) ),
			/* translators: %d: rows */
			'skipped'  => array( 'success', sprintf( __( '%d satır atlandı; İYS\'ye gönderilmeyecek.', 'consent-manager-for-iys' ), $n ) ),
			'changed'  => array( 'success', __( 'İzin güncellendi; değişiklik ve notunuz denetim kaydına yazıldı.', 'consent-manager-for-iys' ) ),
			'table'    => array( 'info', __( 'Aktarım arka planda başladı. Sonuç aşağıda görünür; birkaç saniye sonra sayfayı yenileyin.', 'consent-manager-for-iys' ) ),
			'queued'   => array( 'info', __( 'İş kuyruğa alındı; birkaç saniye içinde arka planda çalışır.', 'consent-manager-for-iys' ) ),
			'notqueued' => array( 'warning', __( 'İş başlatılamadı: İYS bağlantısı ve ilgili anahtar açık olmalı.', 'consent-manager-for-iys' ) ),
		);
		if ( isset( $msgs[ $code ] ) ) {
			echo '<div class="notice notice-' . esc_attr( $msgs[ $code ][0] ) . ' is-dismissible"><p>' . esc_html( $msgs[ $code ][1] ) . '</p></div>';
		}
	}

	/** İzinler tab: list, or a consent's detail with history and the admin change form. */
	private static function consents_tab() {
		$id = isset( $_GET['consent'] ) ? absint( $_GET['consent'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
		if ( $id ) {
			self::detail( $id );
		}
		$t = new Eru_CMI_Consents_Table();
		$t->prepare_items();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="consents">';
		$t->search_box( __( 'Tam adres ya da numara ile ara', 'consent-manager-for-iys' ), 'eru-cmi-search' );
		$t->display();
		echo '</form>';
	}

	/** @param int $id Consent. Detail panel. */
	private static function detail( $id ) {
		$c = Eru_CMI_Repository::by_id( $id );
		if ( ! $c ) {
			echo '<div class="notice notice-warning"><p>' . esc_html__( 'Kayıt bulunamadı.', 'consent-manager-for-iys' ) . '</p></div>';
			return;
		}
		$placeholder = Eru_CMI_Repository::is_placeholder( $c );
		echo '<div class="eru-cmi-panel"><h2>' . esc_html( ( $placeholder ? __( '(anonimleştirildi)', 'consent-manager-for-iys' ) : Eru_CMI_Recipient::mask( $c->recipient ) ) . ' — ' . Eru_CMI_Links::channel_label( $c->type ) ) . '</h2>';
		echo '<div class="eru-cmi-cols"><div><table class="eru-cmi-summary">';
		$known = array();
		foreach ( Eru_CMI_Settings::ENVS as $e ) {
			if ( false !== strpos( (string) $c->iys_known, ',' . $e . ',' ) ) {
				$known[] = 'live' === $e ? __( 'Canlı', 'consent-manager-for-iys' ) : __( 'Test', 'consent-manager-for-iys' );
			}
		}
		$rows = array(
			__( 'Durum', 'consent-manager-for-iys' )       => $c->status,
			__( 'Alıcı tipi', 'consent-manager-for-iys' )  => $c->recipient_type,
			__( 'Kaynak', 'consent-manager-for-iys' )      => $c->source,
			__( 'İzin tarihi', 'consent-manager-for-iys' ) => wp_date( 'Y-m-d H:i', (int) strtotime( $c->consent_date . ' UTC' ) ),
			__( 'İYS\'de kayıtlı', 'consent-manager-for-iys' ) => $known ? implode( ', ', $known ) : '—',
			__( 'Hesap', 'consent-manager-for-iys' )       => $c->user_id ? '#' . (int) $c->user_id : '—',
		);
		foreach ( $rows as $k => $v ) {
			echo '<tr><th>' . esc_html( $k ) . '</th><td>' . esc_html( (string) $v ) . '</td></tr>';
		}
		echo '</table></div><div>';
		if ( $placeholder ) {
			echo '<p>' . esc_html__( 'Bu kayıt kişisel veri silme talebiyle anonimleştirildi; adres bilinmediği için yönetici değişikliği yapılamaz. Durum ve geçmiş yasal kayıt olarak saklanır.', 'consent-manager-for-iys' ) . '</p>';
		} else {
			echo '<h3>' . esc_html__( 'Yönetici değişikliği', 'consent-manager-for-iys' ) . '</h3>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'eru_cmi_change_' . (int) $c->id );
			echo '<input type="hidden" name="action" value="eru_cmi_change"><input type="hidden" name="consent" value="' . (int) $c->id . '">';
			echo '<p><label>' . esc_html__( 'Yeni durum', 'consent-manager-for-iys' ) . ' <select name="status"><option value="ONAY"' . selected( $c->status, 'ONAY', false ) . '>ONAY</option><option value="RET"' . selected( $c->status, 'RET', false ) . '>RET</option></select></label> ';
			echo '<label>' . esc_html__( 'İYS kaynağı', 'consent-manager-for-iys' ) . ' <select name="iys_source">';
			foreach ( Eru_CMI_Rules::IYS_SOURCES as $src ) {
				echo '<option value="' . esc_attr( $src ) . '"' . selected( 'HS_WEB', $src, false ) . '>' . esc_html( $src ) . '</option>';
			}
			echo '</select></label></p>';
			echo '<p><label for="eru-cmi-note">' . esc_html__( 'Gerekçe (zorunlu, denetim kaydına yazılır)', 'consent-manager-for-iys' ) . '</label><br><textarea id="eru-cmi-note" name="note" rows="2" cols="50" maxlength="255" required></textarea></p>';
			echo '<p class="description">' . esc_html__( 'Geri çekilmiş bir izni (RET) ONAY yapmak yalnızca alıcının size verdiği yeni bir izne dayanmalıdır; gerekçeye izni nasıl aldığınızı yazın.', 'consent-manager-for-iys' ) . '</p>';
			submit_button( __( 'Değişikliği kaydet', 'consent-manager-for-iys' ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div></div>';
		echo '<h3>' . esc_html__( 'Geçmiş', 'consent-manager-for-iys' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Zaman', 'consent-manager-for-iys' ) . '</th><th>' . esc_html__( 'Değişiklik', 'consent-manager-for-iys' ) . '</th><th>' . esc_html__( 'Kim', 'consent-manager-for-iys' ) . '</th><th>' . esc_html__( 'Kaynak', 'consent-manager-for-iys' ) . '</th><th>' . esc_html__( 'Kanıt', 'consent-manager-for-iys' ) . '</th><th>' . esc_html__( 'Not', 'consent-manager-for-iys' ) . '</th></tr></thead><tbody>';
		foreach ( array_reverse( Eru_CMI_History::for_consent( (int) $c->id ) ) as $h ) {
			echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) strtotime( $h->created_at . ' UTC' ) ) ) . '</td><td>' . esc_html( ( '' === $h->previous_status ? '—' : $h->previous_status ) . ' → ' . $h->new_status ) . '</td><td>' . esc_html( self::actor_labels()[ $h->actor ] ?? $h->actor ) . '</td><td>' . esc_html( $h->source ) . '</td><td>' . esc_html( self::evidence( $h ) ) . '</td><td>' . esc_html( $h->note ) . '</td></tr>';
		}
		echo '</tbody></table>';
		global $wpdb;
		$q = $wpdb->get_results( $wpdb->prepare( 'SELECT id, env, status, attempts, last_error, updated_at FROM ' . Eru_CMI_Installer::table( 'queue' ) . ' WHERE consent_id = %d ORDER BY id DESC LIMIT 20', (int) $c->id ) ) ?: array(); // phpcs:ignore
		if ( $q ) {
			echo '<h3>' . esc_html__( 'İYS kuyruğu', 'consent-manager-for-iys' ) . '</h3><table class="widefat striped"><thead><tr><th>#</th><th>' . esc_html__( 'Ortam', 'consent-manager-for-iys' ) . '</th><th>' . esc_html__( 'Durum', 'consent-manager-for-iys' ) . '</th><th>' . esc_html__( 'Deneme', 'consent-manager-for-iys' ) . '</th><th>' . esc_html__( 'Son hata / not', 'consent-manager-for-iys' ) . '</th><th>' . esc_html__( 'Güncellendi', 'consent-manager-for-iys' ) . '</th></tr></thead><tbody>';
			foreach ( $q as $r ) {
				echo '<tr><td>' . (int) $r->id . '</td><td>' . esc_html( 'live' === $r->env ? __( 'Canlı', 'consent-manager-for-iys' ) : __( 'Test', 'consent-manager-for-iys' ) ) . '</td><td>' . esc_html( self::iys_labels()[ $r->status ] ?? $r->status ) . '</td><td>' . (int) $r->attempts . '</td><td>' . esc_html( Eru_CMI_Logger::redact( mb_substr( (string) $r->last_error, 0, 200 ) ) ) . '</td><td>' . esc_html( wp_date( 'Y-m-d H:i', (int) strtotime( $r->updated_at . ' UTC' ) ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '<p><a href="' . esc_url( self::url( array( 'tab' => 'consents' ) ) ) . '">' . esc_html__( '← Listeye dön', 'consent-manager-for-iys' ) . '</a></p></div>';
	}

	/** Denetim kaydı tab. */
	private static function audit_tab() {
		echo '<p>' . esc_html__( 'Her izin değişikliği burada kalır: kim, ne zaman, hangi kaynaktan. IP ve tarayıcı bilgisi yalnızca tuzlanmış özet olarak saklanır.', 'consent-manager-for-iys' ) . '</p>';
		$t = new Eru_CMI_Audit_Table();
		$t->prepare_items();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="audit">';
		$t->display();
		echo '</form>';
	}

	/** Kuyruk tab. */
	private static function queue_tab() {
		$sel = Eru_CMI_Settings::env();
		$env = isset( $_GET['env'] ) && in_array( $_GET['env'], Eru_CMI_Settings::ENVS, true ) ? sanitize_key( wp_unslash( $_GET['env'] ) ) : $sel; // phpcs:ignore WordPress.Security.NonceVerification
		$lbl = fn( $e ) => 'live' === $e ? __( 'Canlı', 'consent-manager-for-iys' ) : __( 'Test (sandbox)', 'consent-manager-for-iys' );
		echo '<p>' . esc_html__( 'Ortam:', 'consent-manager-for-iys' ) . ' ';
		foreach ( Eru_CMI_Settings::ENVS as $e ) {
			$text = $lbl( $e ) . ( $e === $sel ? ' ' . __( '(seçili)', 'consent-manager-for-iys' ) : '' );
			echo $e === $env ? '<strong>' . esc_html( $text ) . '</strong> ' : '<a href="' . esc_url( self::url( array( 'tab' => 'queue', 'env' => $e ) ) ) . '">' . esc_html( $text ) . '</a> ';
		}
		echo '</p>';
		if ( $env !== $sel ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Bu ortam şu anda seçili değil: satırları gönderilmez ve toplu işlem yapılamaz.', 'consent-manager-for-iys' ) . '</p></div>';
		}
		if ( ! Eru_CMI_Settings::enabled() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'İYS gönderimi kapalı ya da bağlantı bilgileri eksik: kuyruk yalnızca birikir.', 'consent-manager-for-iys' ) . '</p></div>';
		}
		echo '<div class="eru-cmi-cols"><div>';
		self::pull_summary();
		echo '</div><div>';
		self::reconcile_summary( $env );
		echo '</div></div>';

		$t = new Eru_CMI_Queue_Table( $env );
		$t->prepare_items();
		$t->views();
		echo '<form method="post" action="' . esc_url( self::url( array( 'tab' => 'queue', 'env' => $env ) ) ) . '">';
		$t->display();
		echo '</form>';

		$errors = Eru_CMI_Logger::recent_errors( $env, 10 );
		echo '<h2>' . esc_html__( 'Son API hataları', 'consent-manager-for-iys' ) . '</h2>';
		if ( ! $errors ) {
			echo '<p>' . esc_html__( 'Hata yok.', 'consent-manager-for-iys' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Zaman', 'consent-manager-for-iys' ) . '</th><th>' . esc_html__( 'İstek', 'consent-manager-for-iys' ) . '</th><th>HTTP</th><th>' . esc_html__( 'Yanıt (maskeli)', 'consent-manager-for-iys' ) . '</th></tr></thead><tbody>';
		foreach ( $errors as $e ) {
			echo '<tr><td>' . esc_html( wp_date( 'Y-m-d H:i:s', (int) strtotime( $e->created_at . ' UTC' ) ) ) . '</td><td><code>' . esc_html( $e->method . ' ' . $e->endpoint ) . '</code></td><td>' . (int) $e->http_code . '</td><td>' . esc_html( mb_substr( (string) $e->response, 0, 240 ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** Pull summary and button. */
	private static function pull_summary() {
		echo '<h2>' . esc_html__( 'İYS\'den değişiklik çekme', 'consent-manager-for-iys' ) . '</h2>';
		if ( ! Eru_CMI_Settings::on( 'pull_enabled' ) ) {
			echo '<p>' . esc_html__( 'Kapalı (Ayarlar → Senkron ve saklama).', 'consent-manager-for-iys' ) . '</p>';
			return;
		}
		$last = get_option( Eru_CMI_Scheduler::PULL_LAST );
		if ( is_array( $last ) ) {
			/* translators: 1: time, 2: applied, 3: ignored */
			$text = sprintf( __( 'Son çekme: %1$s — %2$d değişiklik uygulandı, %3$d yok sayıldı.', 'consent-manager-for-iys' ), wp_date( 'Y-m-d H:i', (int) $last['time'] ), (int) $last['applied'], (int) $last['ignored'] );
			echo '<p>' . esc_html( $text ) . '</p>';
			if ( empty( $last['ok'] ) && '' !== (string) $last['error'] ) {
				echo '<p class="description">' . esc_html( (string) $last['error'] ) . '</p>';
			}
		} else {
			echo '<p>' . esc_html__( 'Henüz çekme yapılmadı. Saatte bir çalışır.', 'consent-manager-for-iys' ) . '</p>';
		}
		self::action_button( 'pull', __( 'Şimdi çek', 'consent-manager-for-iys' ) );
	}

	/** @param string $env Environment shown. Reconcile summary and button. */
	private static function reconcile_summary( $env ) {
		echo '<h2>' . esc_html__( 'Gece mutabakatı', 'consent-manager-for-iys' ) . '</h2>';
		if ( ! Eru_CMI_Settings::on( 'reconcile_enabled' ) ) {
			echo '<p>' . esc_html__( 'Kapalı (Ayarlar → Senkron ve saklama).', 'consent-manager-for-iys' ) . '</p>';
			return;
		}
		$s = get_option( 'eru_cmi_reconcile_' . $env );
		if ( ! is_array( $s ) ) {
			/* translators: %s: hour */
			echo '<p>' . esc_html( sprintf( __( 'Henüz çalışmadı. Her gece %s\'da (site saati) başlar.', 'consent-manager-for-iys' ), sprintf( '%02d:00', Eru_CMI_Settings::reconcile_hour() ) ) ) . '</p>';
		} else {
			/* translators: 1: date, 2: state */
			echo '<p>' . esc_html( sprintf( __( 'Son tur: %1$s — %2$s', 'consent-manager-for-iys' ), (string) $s['night'], ! empty( $s['done'] ) ? __( 'tamamlandı', 'consent-manager-for-iys' ) : ( ! empty( $s['limited'] ) ? __( 'İYS hız sınırı; bir saat sonra devam eder', 'consent-manager-for-iys' ) : __( 'sürüyor', 'consent-manager-for-iys' ) ) ) ) . '</p>';
			echo '<table class="eru-cmi-summary"><tbody>';
			$rows = array(
				__( 'Denetlenen', 'consent-manager-for-iys' )                   => $s['checked'],
				__( 'Uyumlu', 'consent-manager-for-iys' )                       => $s['match'],
				__( 'İYS\'de yok / eski: yeniden kuyrukta', 'consent-manager-for-iys' ) => $s['requeued'],
				__( 'Yeniden kuyruğa alındı ama kurallar gereği atlandı', 'consent-manager-for-iys' ) => $s['held'] ?? 0,
				__( 'İYS\'deki daha yeni durum uygulandı', 'consent-manager-for-iys' ) => $s['applied'],
				__( 'Tarihsiz farklılık (dokunulmadı)', 'consent-manager-for-iys' ) => $s['conflict'],
				__( 'Sorulamadı', 'consent-manager-for-iys' )                   => $s['errors'],
			);
			foreach ( $rows as $k => $v ) {
				echo '<tr><th>' . esc_html( $k ) . '</th><td>' . (int) $v . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		self::action_button( 'reconcile', __( 'Turu şimdi sürdür', 'consent-manager-for-iys' ) );
	}

	/** @param string $do pull | reconcile. @param string $label Button. */
	private static function action_button( $do, $label ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'eru_cmi_action' );
		echo '<input type="hidden" name="action" value="eru_cmi_action"><input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		submit_button( $label, 'secondary', 'submit', false, Eru_CMI_Settings::enabled() ? array() : array( 'disabled' => 'disabled' ) );
		echo '</form>';
	}

	/** @param string $section Section. Ayarlar tab. */
	private static function settings_tab( $section ) {
		echo '<ul class="subsubsub">';
		$i = 0;
		foreach ( self::sections() as $k => $label ) {
			echo '<li>' . ( $i++ ? ' | ' : '' ) . '<a href="' . esc_url( self::url( array( 'tab' => 'settings', 'section' => $k ) ) ) . '"' . ( $k === $section ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
		}
		echo '</ul><br class="clear">';
		settings_errors();
		switch ( $section ) {
			case 'collect':
				self::collect_tab();
				break;
			case 'sync':
				self::sync_tab();
				break;
			case 'external':
				self::external_tab();
				break;
			default:
				self::connection_tab();
		}
	}

	/** Toplama section: where consent is collected on the site. */
	private static function collect_tab() {
		echo '<p>' . esc_html__( 'İzin kutuları varsayılan olarak işaretsizdir. İşaretlenmeyen kutu ret sayılmaz; hiçbir kayıt yazılmaz. Daha önce izni geri çekmiş bir adres için kutu işaretlenirse izin hemen verilmez, adrese onay e-postası gönderilir.', 'consent-manager-for-iys' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( Eru_CMI_Settings::GROUP );
		echo '<input type="hidden" name="' . esc_attr( self::name( '_tab' ) ) . '" value="collect">';
		echo '<table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'Sunulan kanallar', 'consent-manager-for-iys' ) . '</th><td><fieldset>';
		$chosen = Eru_CMI_Settings::channels();
		foreach ( Eru_CMI_Recipient::TYPES as $t ) {
			echo '<label style="margin-right:1.5em"><input type="checkbox" name="' . esc_attr( self::name( 'channels' ) ) . '[]" value="' . esc_attr( $t ) . '"' . checked( in_array( $t, $chosen, true ), true, false ) . '> ' . esc_html( Eru_CMI_Links::channel_label( $t ) ) . '</label>';
		}
		echo '<p class="description">' . esc_html__( 'E-posta fatura e-postasına, SMS ve arama fatura telefonuna yazılır. SMS için cep telefonu gerekir.', 'consent-manager-for-iys' ) . '</p></fieldset></td></tr>';
		self::check( 'checkout_box', __( 'Ödeme sayfasında izin kutusu', 'consent-manager-for-iys' ), __( 'Klasik ödeme sayfasında sipariş düğmesinin üstünde, Ödeme bloğunda sipariş bölümünde görünür.', 'consent-manager-for-iys' ) );
		self::text( 'checkout_label', __( 'Kutu metni', 'consent-manager-for-iys' ), __( 'Boş bırakılırsa varsayılan metin kullanılır.', 'consent-manager-for-iys' ), 'text', Eru_CMI_Settings::box_label() );
		echo '<tr><th scope="row"><label for="eru-cmi-policy_page">' . esc_html__( 'Aydınlatma / izin metni sayfası', 'consent-manager-for-iys' ) . '</label></th><td>';
		wp_dropdown_pages(
			array(
				'name'              => esc_attr( self::name( 'policy_page' ) ),
				'id'                => 'eru-cmi-policy_page',
				'selected'          => (int) Eru_CMI_Settings::get( 'policy_page' ),
				'show_option_none'  => esc_html__( '— Yok —', 'consent-manager-for-iys' ),
				'option_none_value' => '0',
			)
		);
		echo '<p class="description">' . esc_html__( 'Kutunun yanında bağlantı olarak gösterilir. Eklenti sayfa ya da yasal metin oluşturmaz; metni kendiniz hazırlayın.', 'consent-manager-for-iys' ) . '</p></td></tr>';
		self::check( 'register_box', __( 'Üyelik formunda izin kutusu', 'consent-manager-for-iys' ), __( 'Hesabım sayfasındaki WooCommerce üyelik formu; yalnızca e-posta kanalı.', 'consent-manager-for-iys' ) );
		self::check( 'account_tab', __( 'Hesabım sekmesi', 'consent-manager-for-iys' ), __( 'Müşteri kendi e-posta adresi ve fatura telefonu için kanalları açıp kapatabilir.', 'consent-manager-for-iys' ) );
		self::text( 'account_endpoint', __( 'Sekme adresi', 'consent-manager-for-iys' ), __( 'Hesabım altındaki adres parçası. Kalıcı bağlantılar kayıttan sonra kendiliğinden yenilenir.', 'consent-manager-for-iys' ) );
		self::text( 'token_days', __( 'Geri çekme bağlantısı süresi (gün)', 'consent-manager-for-iys' ), __( 'eru_cmi_optout_url() ile e-posta ve SMS altlarına eklenen imzalı bağlantıların geçerlilik süresi (1–365).', 'consent-manager-for-iys' ), 'number' );
		echo '</table>';
		submit_button();
		echo '</form>';
	}

	/** Senkron ve saklama section. */
	private static function sync_tab() {
		echo '<form method="post" action="options.php">';
		settings_fields( Eru_CMI_Settings::GROUP );
		echo '<input type="hidden" name="' . esc_attr( self::name( '_tab' ) ) . '" value="sync">';
		echo '<table class="form-table" role="presentation">';
		self::check( 'pull_enabled', __( 'İYS\'den değişiklik çek (saatte bir)', 'consent-manager-for-iys' ), __( 'Alıcının İYS\'nin kendi kanallarından (web sitesi, mobil uygulama, kısa numara) yaptığı değişiklikler buraya işlenir; tekrar İYS\'ye gönderilmez. Sayfa başına en fazla 999 kayıt.', 'consent-manager-for-iys' ) );
		self::check( 'reconcile_enabled', __( 'Gece mutabakatı', 'consent-manager-for-iys' ), __( 'Bu sitede İYS\'ye kaydedilmiş görünen izinler tek tek İYS\'ye sorulur. İYS\'de olmayan ya da daha eski olan izin bir kez yeniden kuyruğa alınır; İYS\'deki daha yeni durum buraya işlenir. Tur başına 500 kayıt.', 'consent-manager-for-iys' ) );
		echo '<tr><th scope="row"><label for="eru-cmi-reconcile_hour">' . esc_html__( 'Mutabakat saati', 'consent-manager-for-iys' ) . '</label></th><td><select id="eru-cmi-reconcile_hour" name="' . esc_attr( self::name( 'reconcile_hour' ) ) . '">';
		for ( $h = 0; $h < 24; $h++ ) {
			echo '<option value="' . (int) $h . '"' . selected( Eru_CMI_Settings::reconcile_hour(), $h, false ) . '>' . esc_html( sprintf( '%02d:00', $h ) ) . '</option>';
		}
		/* translators: %s: time zone */
		echo '</select><p class="description">' . esc_html( sprintf( __( 'Site saat dilimi: %s (Ayarlar → Genel).', 'consent-manager-for-iys' ), wp_timezone_string() ) ) . '</p></td></tr>';
		self::text( 'log_days', __( 'API kaydı saklama süresi (gün)', 'consent-manager-for-iys' ), __( 'İYS istek ve yanıtlarının maskeli kaydı (1–90, varsayılan 7).', 'consent-manager-for-iys' ), 'number' );
		self::text( 'hash_days', __( 'IP ve tarayıcı özetlerini sakla (gün)', 'consent-manager-for-iys' ), __( 'Denetim kaydındaki tuzlanmış IP ve tarayıcı özetleri bu süreden sonra silinir; değişikliğin kendisi (kim, ne zaman, kaynak, metin özeti) kalır. 0 = silme.', 'consent-manager-for-iys' ), 'number' );
		echo '</table>';
		submit_button();
		echo '</form>';
	}

	/** Harici API section. */
	private static function external_tab() {
		$has = 64 === strlen( Eru_CMI_Settings::get( 'ext_key_hash' ) );
		echo '<p>' . esc_html__( 'Başka bir sisteminizin (CRM, çağrı merkezi, mağaza) topladığı izinleri buraya yazması için yalnızca yazma yapan bir uç. Kimin izni olduğunu okumaya yarayan bir uç yoktur; yanıt yalnızca sayıları ve satır hata kodlarını içerir.', 'consent-manager-for-iys' ) . '</p>';
		echo '<form method="post" action="options.php">';
		settings_fields( Eru_CMI_Settings::GROUP );
		echo '<input type="hidden" name="' . esc_attr( self::name( '_tab' ) ) . '" value="external">';
		echo '<table class="form-table" role="presentation">';
		self::check( 'ext_enabled', __( 'Harici API', 'consent-manager-for-iys' ), __( 'Kapalıyken uç hiç yoktur (404). Açmak için bir anahtar üretin.', 'consent-manager-for-iys' ) );
		echo '<tr><th scope="row">' . esc_html__( 'Anahtar', 'consent-manager-for-iys' ) . '</th><td>';
		if ( $has ) {
			/* translators: %s: last 4 characters */
			echo esc_html( sprintf( __( 'Kayıtlı (…%s). Anahtar ve imza sırrı yalnızca üretildiklerinde bir kez gösterilir.', 'consent-manager-for-iys' ), Eru_CMI_Settings::get( 'ext_key_hint' ) ) );
			echo '<p><label><input type="checkbox" name="' . esc_attr( self::name( 'ext_revoke' ) ) . '" value="1"> ' . esc_html__( 'Anahtarı iptal et (uç kapanır)', 'consent-manager-for-iys' ) . '</label></p>';
		} else {
			esc_html_e( 'Yok', 'consent-manager-for-iys' );
		}
		echo '</td></tr><tr><th scope="row">' . esc_html__( 'Adres', 'consent-manager-for-iys' ) . '</th><td><code>POST ' . esc_html( rest_url( Eru_CMI_Rest::NS . Eru_CMI_External::ROUTE ) ) . '</code>';
		echo '<p class="description">' . esc_html__( 'Başlıklar: X-Eru-Cmi-Key, X-Eru-Cmi-Timestamp (Unix zamanı, ±5 dk), X-Eru-Cmi-Signature (gövdenin "zaman.gövde" biçiminde HMAC-SHA256 imzası, onaltılık). Gövde: en fazla 999 kayıtlık JSON listesi; consentDate zorunlu.', 'consent-manager-for-iys' ) . '</p></td></tr>';
		echo '</table>';
		submit_button();
		echo '</form>';
		echo '<h2>' . esc_html__( 'Yeni anahtar üret', 'consent-manager-for-iys' ) . '</h2><p class="description">' . esc_html__( 'Yeni anahtar ve imza sırrı üretilir; eskileri hemen geçersiz olur.', 'consent-manager-for-iys' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'eru_cmi_keys' );
		echo '<input type="hidden" name="action" value="eru_cmi_keys">';
		submit_button( $has ? __( 'Anahtarı yenile', 'consent-manager-for-iys' ) : __( 'Anahtar üret', 'consent-manager-for-iys' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/** Bağlantı section. */
	private static function connection_tab() {
		$counts = Eru_CMI_Queue::counts();
		$env    = Eru_CMI_Settings::env();
		if ( Eru_CMI_Settings::on( 'enabled' ) && ! Eru_CMI_Settings::has_credentials() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Ana anahtar açık ama seçili ortamın bağlantı bilgileri eksik; kuyruk gönderim yapmıyor.', 'consent-manager-for-iys' ) . '</p></div>';
		}
		echo '<p>' . esc_html(
			sprintf(
				/* translators: 1: queued, 2: sent, 3: accepted, 4: rejected, 5: skipped, 6: failed */
				__( 'Kuyruk: %1$d bekliyor, %2$d İYS sonucunu bekliyor, %3$d kabul edildi, %4$d reddedildi, %5$d atlandı, %6$d başarısız.', 'consent-manager-for-iys' ),
				$counts['queued'],
				$counts['sent'],
				$counts['accepted'],
				$counts['rejected'],
				$counts['skipped'],
				$counts['failed']
			)
		) . '</p>';

		echo '<form method="post" action="options.php">';
		settings_fields( Eru_CMI_Settings::GROUP );
		echo '<input type="hidden" name="' . esc_attr( self::name( '_tab' ) ) . '" value="connection">';
		echo '<table class="form-table" role="presentation">';
		self::check( 'enabled', __( 'İYS gönderimi (ana anahtar)', 'consent-manager-for-iys' ), __( 'Kapalıyken izinler yalnızca bu sitede saklanır; İYS\'ye istek gönderilmez ve zamanlanmış iş oluşturulmaz.', 'consent-manager-for-iys' ) );
		echo '<tr><th scope="row"><label for="eru-cmi-environment">' . esc_html__( 'Ortam', 'consent-manager-for-iys' ) . '</label></th><td><select id="eru-cmi-environment" name="' . esc_attr( self::name( 'environment' ) ) . '">';
		foreach ( array( 'sandbox' => __( 'Test (sandbox)', 'consent-manager-for-iys' ), 'live' => __( 'Canlı', 'consent-manager-for-iys' ) ) as $k => $label ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $env, $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select><p class="description">' . esc_html__( 'Kuyruk satırları kuyruğa alındıkları ortama aittir; ortam değişince diğer ortamın satırları gönderilmez, Kuyruk sekmesinde ayrı görünür.', 'consent-manager-for-iys' ) . '</p></td></tr>';
		self::text( 'batch_size', __( 'Parti boyutu', 'consent-manager-for-iys' ), __( 'Bir İYS isteğindeki en fazla kayıt (1–1000). Her 30 saniyede bir parti gönderilir.', 'consent-manager-for-iys' ), 'number' );
		self::check( 'restamp_old', __( 'Eski tarihli ONAY\'ları yeniden damgala', 'consent-manager-for-iys' ), __( 'İYS, 3 iş gününden eski tarihli kaydı kabul etmez (H467). Geri çekmeler (RET) her zaman gönderim anının tarihiyle gider. Açıkken bu sitede verilen eski tarihli bir ONAY da gönderim anının tarihiyle bildirilir; göndermeden önce İYS\'ye sorulur ve İYS\'de daha yeni bir RET varsa gönderilmez. Sitedeki asıl tarih değişmez. İçe aktarılan ONAY\'lar yeniden damgalanmaz.', 'consent-manager-for-iys' ) );
		self::check( 'tacir_from_company', __( 'Fatura firma adı doluysa TACIR', 'consent-manager-for-iys' ), __( 'Alıcı tipini, fatura adresinde firma adı yazılıysa TACIR olarak bildirir; aksi halde BIREYSEL.', 'consent-manager-for-iys' ) );
		echo '</table>';

		foreach ( array( 'sandbox' => __( 'Test (sandbox) bilgileri', 'consent-manager-for-iys' ), 'live' => __( 'Canlı ortam bilgileri', 'consent-manager-for-iys' ) ) as $e => $title ) {
			echo '<h2>' . esc_html( $title ) . '</h2><table class="form-table" role="presentation">';
			self::text( $e . '_username', __( 'Kullanıcı adı', 'consent-manager-for-iys' ) );
			self::password( $e );
			self::text( $e . '_iys_code', __( 'İYS kodu', 'consent-manager-for-iys' ) );
			self::text( $e . '_brand_code', __( 'Marka kodu', 'consent-manager-for-iys' ) );
			self::text( $e . '_base_url', __( 'API adresi', 'consent-manager-for-iys' ), __( 'Boş bırakılırsa İYS\'nin yayımladığı adres kullanılır.', 'consent-manager-for-iys' ), 'url', Eru_CMI_Settings::DEFAULT_BASE[ $e ] );
			echo '</table>';
		}
		submit_button();
		echo '</form>';

		echo '<h2>' . esc_html__( 'Bağlantı testi', 'consent-manager-for-iys' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'İYS, istekleri yalnızca beyaz listeye eklenmiş IP adreslerinden kabul eder. Sunucunuzun çıkış IP adresini barındırma sağlayıcınızın panelinden öğrenip İYS\'ye bildirin.', 'consent-manager-for-iys' ) . '</p>';
		$result = get_option( Eru_CMI_Scheduler::TEST_RESULT );
		if ( is_array( $result ) ) {
			echo '<div class="notice inline ' . ( ! empty( $result['ok'] ) ? 'notice-success' : 'notice-error' ) . '"><p>' . esc_html( wp_date( 'Y-m-d H:i', (int) $result['time'] ) . ' — ' . ( 'live' === ( $result['env'] ?? '' ) ? __( 'Canlı', 'consent-manager-for-iys' ) : __( 'Test', 'consent-manager-for-iys' ) ) . ': ' . (string) $result['message'] ) . '</p></div>';
		} elseif ( isset( $_GET['eru_cmi_test'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			echo '<div class="notice inline notice-info"><p>' . esc_html__( 'Test kuyruğa alındı. Birkaç saniye sonra sayfayı yenileyin.', 'consent-manager-for-iys' ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'eru_cmi_test' );
		echo '<input type="hidden" name="action" value="eru_cmi_test">';
		submit_button( __( 'Bağlantıyı test et', 'consent-manager-for-iys' ), 'secondary', 'submit', false, Eru_CMI_Settings::has_credentials() ? array() : array( 'disabled' => 'disabled' ) );
		echo '</form>';
	}

	/** Araçlar tab. */
	private static function tools_tab() {
		$uid    = get_current_user_id();
		$result = get_transient( 'eru_cmi_import_' . $uid );
		if ( is_array( $result ) ) {
			delete_transient( 'eru_cmi_import_' . $uid );
			/* translators: 1: imported, 2: unchanged, 3: older, 4: kept RET, 5: errors */
			echo '<div class="notice notice-info"><p>' . esc_html( sprintf( __( 'İçe aktarma: %1$d kayıt yazıldı, %2$d değişmedi, %3$d sitedeki kayıttan eski olduğu için atlandı, %4$d ret korundu, %5$d satır hatalı.', 'consent-manager-for-iys' ), $result['imported'], $result['unchanged'], $result['older'], $result['kept_ret'], count( $result['errors'] ) ) ) . '</p>';
			if ( $result['errors'] ) {
				echo '<ul style="list-style:disc;margin-left:2em">';
				foreach ( array_slice( $result['errors'], 0, 50 ) as $e ) {
					/* translators: 1: line number, 2: message */
					echo '<li>' . esc_html( sprintf( __( 'Satır %1$d: %2$s', 'consent-manager-for-iys' ), (int) $e['line'], (string) $e['message'] ) ) . '</li>';
				}
				echo '</ul>';
			}
			echo '</div>';
		}

		echo '<h2>' . esc_html__( 'CSV ile içe aktar', 'consent-manager-for-iys' ) . '</h2>';
		echo '<p>' . esc_html__( 'Sütunlar: recipient, type, status, consent_date, source, recipient_type. İlk dört sütun zorunludur; tarih dosyadan alınır (Türkiye saati) ve boşsa satır reddedilir. İçe aktarma bir reddi (RET) asla kaldırmaz; sitedeki kayıttan eski satırlar atlanır.', 'consent-manager-for-iys' ) . '</p>';
		/* translators: %d: lines */
		echo '<p class="description">' . esc_html( sprintf( __( 'Bir dosyada en fazla %d satır; daha büyük dosyalar için: wp eru-cmi import --file=<dosya>', 'consent-manager-for-iys' ), self::IMPORT_MAX ) ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'eru_cmi_import' );
		echo '<input type="hidden" name="action" value="eru_cmi_import"><p><input type="file" name="eru_cmi_csv" accept=".csv,text/csv,text/plain" required></p>';
		echo '<p><label><input type="checkbox" name="at_iys" value="1"> ' . esc_html__( 'Bu kayıtlar İYS\'de zaten var (başka bir entegrasyonla gönderildi): kuyruğa alma, İYS\'de kayıtlı say', 'consent-manager-for-iys' ) . '</label></p>';
		submit_button( __( 'İçe aktar', 'consent-manager-for-iys' ), 'secondary', 'submit', false );
		echo '</form>';

		echo '<h2>' . esc_html__( 'CSV olarak dışa aktar', 'consent-manager-for-iys' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'eru_cmi_export' );
		echo '<input type="hidden" name="action" value="eru_cmi_export">';
		echo '<p>' . esc_html__( 'Alıcılar varsayılan olarak maskelenir. İYS sütunu seçili ortama göredir.', 'consent-manager-for-iys' ) . '</p>';
		echo '<p><label><input type="checkbox" name="full" value="1"> ' . esc_html__( 'Tam adreslerle', 'consent-manager-for-iys' ) . '</label><br><label><input type="checkbox" name="confirm_full" value="1"> ' . esc_html__( 'Dosyanın kişisel veri içerdiğini ve güvenli saklayacağımı onaylıyorum', 'consent-manager-for-iys' ) . '</label></p>';
		submit_button( __( 'Dışa aktar', 'consent-manager-for-iys' ), 'secondary', 'submit', false );
		echo '</form>';

		self::table_section();
	}

	/** @return array<string,string> Field => label of the table import mapping. */
	private static function map_fields() {
		return array(
			'id'             => __( 'Sıra sütunu (artan tam sayı, ör. birincil anahtar)', 'consent-manager-for-iys' ),
			'recipient'      => __( 'Alıcı (e-posta adresi ya da telefon)', 'consent-manager-for-iys' ),
			'type'           => __( 'Kanal (EPOSTA, MESAJ, ARAMA)', 'consent-manager-for-iys' ),
			'status'         => __( 'Durum (ONAY, RET)', 'consent-manager-for-iys' ),
			'consent_date'   => __( 'İzin tarihi (Türkiye saati)', 'consent-manager-for-iys' ),
			'source'         => __( 'İYS kaynağı (isteğe bağlı)', 'consent-manager-for-iys' ),
			'recipient_type' => __( 'Alıcı tipi: BIREYSEL, TACIR (isteğe bağlı)', 'consent-manager-for-iys' ),
		);
	}

	/** Araçlar: import from a consent-log table; step 1 names the table, step 2 maps its columns. */
	private static function table_section() {
		echo '<h2>' . esc_html__( 'İzin kayıt tablosundan aktar', 'consent-manager-for-iys' ) . '</h2>';
		echo '<p>' . esc_html__( 'Bu veritabanında, her izin değişikliğini yeni bir satır olarak yazan bir tablodan aktarır. Tablonun sütunlarını aşağıdaki alanlarla eşleştirirsiniz. Her alıcı ve kanal için sıra sütunu en büyük satır geçerlidir; geçmişe "içe aktarma" olarak yazılır. Ret kayıtları kaldırılmaz. Arka planda 500\'er satır işlenir.', 'consent-manager-for-iys' ) . '</p>';
		$job = get_option( Eru_CMI_Import::JOB );
		if ( is_array( $job ) ) {
			$state = ! empty( $job['done'] ) ? __( 'bitti', 'consent-manager-for-iys' ) : __( 'sürüyor', 'consent-manager-for-iys' );
			/* translators: 1: table, 2: dry run marker, 3: state, 4: read, 5: imported, 6: unchanged, 7: older, 8: kept RET, 9: invalid */
			echo '<div class="notice notice-info inline"><p>' . esc_html( sprintf( __( 'Son aktarım (%1$s%2$s): %3$s — %4$d satır okundu, %5$d yazıldı, %6$d değişmedi, %7$d eski, %8$d ret korundu, %9$d geçersiz.', 'consent-manager-for-iys' ), $job['table'], ! empty( $job['dry'] ) ? ', ' . __( 'deneme', 'consent-manager-for-iys' ) : '', $state, $job['read'], $job['imported'], $job['unchanged'], $job['older'], $job['kept_ret'], $job['invalid'] ) ) . '</p></div>';
		}
		$table = isset( $_GET['eru_cmi_table'] ) ? sanitize_text_field( wp_unslash( $_GET['eru_cmi_table'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only: lists a table's column names.
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="tools">';
		echo '<p><label>' . esc_html__( 'Tablo adı', 'consent-manager-for-iys' ) . ' <input type="text" name="eru_cmi_table" class="regular-text" pattern="[A-Za-z0-9_]+" value="' . esc_attr( $table ) . '" required></label> ';
		submit_button( __( 'Sütunları getir', 'consent-manager-for-iys' ), 'secondary', '', false );
		echo '</p></form>';
		if ( '' === $table ) {
			return;
		}
		$cols = Eru_CMI_Import::table_columns( $table );
		if ( is_wp_error( $cols ) ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $cols->get_error_message() ) . '</p></div>';
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'eru_cmi_table' );
		echo '<input type="hidden" name="action" value="eru_cmi_table"><input type="hidden" name="table" value="' . esc_attr( $table ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( self::map_fields() as $field => $label ) {
			echo '<tr><th scope="row"><label for="eru-cmi-map-' . esc_attr( $field ) . '">' . esc_html( $label ) . '</label></th><td><select id="eru-cmi-map-' . esc_attr( $field ) . '" name="map[' . esc_attr( $field ) . ']"><option value="">' . esc_html__( '— seçin —', 'consent-manager-for-iys' ) . '</option>';
			foreach ( $cols as $c ) {
				echo '<option value="' . esc_attr( $c ) . '">' . esc_html( $c ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '<tr><th scope="row"><label for="eru-cmi-map-fixed">' . esc_html__( 'Kanal sütunu yoksa tüm satırların kanalı', 'consent-manager-for-iys' ) . '</label></th><td><select id="eru-cmi-map-fixed" name="map[fixed_type]"><option value="">—</option>';
		foreach ( array( 'EPOSTA', 'MESAJ', 'ARAMA' ) as $t ) {
			echo '<option value="' . esc_attr( $t ) . '">' . esc_html( $t ) . '</option>';
		}
		echo '</select></td></tr></tbody></table>';
		echo '<p><label><input type="checkbox" name="dry" value="1" checked> ' . esc_html__( 'Deneme: yalnızca say, hiçbir şey yazma', 'consent-manager-for-iys' ) . '</label></p>';
		submit_button( __( 'Aktarımı başlat', 'consent-manager-for-iys' ), 'secondary', 'submit', false );
		echo '</form>';
	}

	/**
	 * Capability and nonce gate of every handler.
	 *
	 * @param array  $req    Request.
	 * @param string $action Nonce action.
	 * @return true|WP_Error
	 */
	private static function guard( array $req, $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			return new WP_Error( 'eru_cmi_forbidden', __( 'Bu işlem için yetkiniz yok.', 'consent-manager-for-iys' ), array( 'status' => 403 ) );
		}
		if ( ! wp_verify_nonce( sanitize_text_field( (string) ( $req['_wpnonce'] ?? '' ) ), $action ) ) {
			return new WP_Error( 'eru_cmi_nonce', __( 'Oturumun süresi dolmuş olabilir; sayfayı yenileyip tekrar deneyin.', 'consent-manager-for-iys' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * Kuyruk bulk action.
	 *
	 * @param array $req bulk (retry | skip), ids[], _wpnonce.
	 * @return int|WP_Error Rows changed.
	 */
	public static function queue_bulk( array $req ) {
		$ok = self::guard( $req, self::BULK_NONCE );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$ids = array_values( array_filter( array_map( 'absint', (array) ( $req['ids'] ?? array() ) ) ) );
		$do  = sanitize_key( (string) ( $req['bulk'] ?? '' ) );
		if ( 'retry' === $do ) {
			return Eru_CMI_Queue::retry_ids( $ids );
		}
		if ( 'skip' === $do ) {
			return Eru_CMI_Queue::skip( $ids, __( 'Yönetici tarafından atlandı; İYS\'ye gönderilmeyecek.', 'consent-manager-for-iys' ) );
		}
		return new WP_Error( 'eru_cmi_invalid', __( 'Geçersiz işlem.', 'consent-manager-for-iys' ), array( 'status' => 400 ) );
	}

	/**
	 * Admin change of one consent; a note is required (it is kept in the history). A RET → ONAY lift is
	 * allowed only this way (or through İYS / the owner's confirmation link).
	 *
	 * @param array $req consent, status, note, iys_source, _wpnonce.
	 * @return array|WP_Error set() result.
	 */
	public static function change_consent( array $req ) {
		$id = absint( $req['consent'] ?? 0 );
		$ok = self::guard( $req, 'eru_cmi_change_' . $id );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$row = Eru_CMI_Repository::by_id( $id );
		if ( ! $row || Eru_CMI_Repository::is_placeholder( $row ) ) {
			return new WP_Error( 'eru_cmi_invalid', __( 'Bu kayıt değiştirilemez.', 'consent-manager-for-iys' ), array( 'status' => 400 ) );
		}
		$note = trim( sanitize_text_field( (string) ( $req['note'] ?? '' ) ) );
		if ( '' === $note ) {
			return new WP_Error( 'eru_cmi_note', __( 'Yönetici değişikliği için gerekçe yazın.', 'consent-manager-for-iys' ), array( 'status' => 400 ) );
		}
		$status = strtoupper( sanitize_key( (string) ( $req['status'] ?? '' ) ) );
		if ( ! in_array( $status, Eru_CMI_Recipient::STATUSES, true ) ) {
			return new WP_Error( 'eru_cmi_invalid', __( 'Geçersiz durum.', 'consent-manager-for-iys' ), array( 'status' => 400 ) );
		}
		return Eru_CMI_Repository::set(
			$row->recipient,
			$row->type,
			$status,
			array(
				'actor'      => 'admin',
				'source'     => 'admin',
				'actor_id'   => get_current_user_id(),
				'note'       => $note,
				'iys_source' => Eru_CMI_Rules::iys_source( sanitize_text_field( (string) ( $req['iys_source'] ?? 'HS_WEB' ) ) ),
			) + Eru_CMI_Links::client()
		);
	}

	/**
	 * CSV upload.
	 *
	 * @param array $req  _wpnonce, at_iys.
	 * @param array $file $_FILES entry.
	 * @return array|WP_Error Import result.
	 */
	public static function import_request( array $req, array $file ) {
		$ok = self::guard( $req, 'eru_cmi_import' );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$tmp = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || ! is_uploaded_file( $tmp ) ) {
			return new WP_Error( 'eru_cmi_file', __( 'Dosya yüklenemedi.', 'consent-manager-for-iys' ), array( 'status' => 400 ) );
		}
		if ( (int) ( $file['size'] ?? 0 ) > 2 * MB_IN_BYTES || ! preg_match( '/\.(csv|txt)$/i', (string) ( $file['name'] ?? '' ) ) ) {
			return new WP_Error( 'eru_cmi_file', __( 'En fazla 2 MB boyutunda bir .csv dosyası yükleyin.', 'consent-manager-for-iys' ), array( 'status' => 400 ) );
		}
		return Eru_CMI_Import::csv(
			$tmp,
			array(
				'at_iys'    => ! empty( $req['at_iys'] ),
				'max_lines' => self::IMPORT_MAX,
			)
		);
	}

	/**
	 * Starts the background table import.
	 *
	 * @param array $req table, map (field => column, fixed_type), dry, _wpnonce.
	 * @return array|WP_Error Job.
	 */
	public static function table_request( array $req ) {
		$ok = self::guard( $req, 'eru_cmi_table' );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$table = trim( sanitize_text_field( (string) ( $req['table'] ?? '' ) ) );
		$map   = array_map( fn( $v ) => sanitize_text_field( (string) $v ), array_filter( (array) ( $req['map'] ?? array() ), 'is_scalar' ) );
		$check = Eru_CMI_Import::table_check( $table, $map );
		if ( is_wp_error( $check ) ) {
			return new WP_Error( $check->get_error_code(), $check->get_error_message(), array( 'status' => 400 ) );
		}
		$cur = get_option( Eru_CMI_Import::JOB );
		if ( is_array( $cur ) && empty( $cur['done'] ) && (int) $cur['started'] > time() - HOUR_IN_SECONDS ) {
			return new WP_Error( 'eru_cmi_running', __( 'Bir aktarım zaten sürüyor.', 'consent-manager-for-iys' ), array( 'status' => 409 ) );
		}
		$job = Eru_CMI_Import::table_job( $table, $check, ! empty( $req['dry'] ) );
		update_option( Eru_CMI_Import::JOB, $job, false );
		Eru_CMI_Scheduler::queue_table();
		return $job;
	}

	/**
	 * Generates a new external API key and signing secret.
	 *
	 * @param array $req _wpnonce.
	 * @return array{key:string,secret:string}|WP_Error Shown once.
	 */
	public static function keys_request( array $req ) {
		$ok = self::guard( $req, 'eru_cmi_keys' );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$key = Eru_CMI_External::generate();
		return array(
			'key'    => $key,
			'secret' => Eru_CMI_Settings::get( 'ext_secret' ),
		);
	}

	/**
	 * Export request check (the HTTP handler streams the file).
	 *
	 * @param array $req full, confirm_full, _wpnonce.
	 * @return array{full:bool}|WP_Error
	 */
	public static function export_request( array $req ) {
		$ok = self::guard( $req, 'eru_cmi_export' );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		if ( ! empty( $req['full'] ) && empty( $req['confirm_full'] ) ) {
			return new WP_Error( 'eru_cmi_confirm', __( 'Tam adreslerle dışa aktarmak için onay kutusunu da işaretleyin.', 'consent-manager-for-iys' ), array( 'status' => 400 ) );
		}
		return array( 'full' => ! empty( $req['full'] ) );
	}

	/**
	 * Pull / reconcile soon (Kuyruk tab buttons).
	 *
	 * @param array $req do, _wpnonce.
	 * @return bool|WP_Error Queued.
	 */
	public static function action_request( array $req ) {
		$ok = self::guard( $req, 'eru_cmi_action' );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$do = sanitize_key( (string) ( $req['do'] ?? '' ) );
		if ( ! in_array( $do, array( 'pull', 'reconcile' ), true ) ) {
			return new WP_Error( 'eru_cmi_invalid', __( 'Geçersiz işlem.', 'consent-manager-for-iys' ), array( 'status' => 400 ) );
		}
		return Eru_CMI_Scheduler::run_soon( $do );
	}

	/** @param WP_Error $e Error. Stops with the error's status. */
	private static function fail( WP_Error $e ) {
		$data = $e->get_error_data();
		wp_die( esc_html( $e->get_error_message() ), '', array( 'response' => (int) ( is_array( $data ) ? ( $data['status'] ?? 400 ) : 400 ), 'back_link' => true ) );
	}

	/** @return array Unslashed POST. */
	private static function post() {
		$p = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked by guard().
		return is_array( $p ) ? $p : array();
	}

	/** Bulk actions of the Kuyruk list (POST to the page, before output). */
	public static function http_bulk() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '';
		if ( 'post' !== $method ) {
			return;
		}
		$p    = self::post();
		$bulk = (string) ( ( $p['action'] ?? '-1' ) !== '-1' ? $p['action'] : ( $p['action2'] ?? '-1' ) );
		if ( ! in_array( $bulk, array( 'retry', 'skip' ), true ) ) {
			return;
		}
		$r = self::queue_bulk( array( 'bulk' => $bulk ) + $p );
		if ( is_wp_error( $r ) ) {
			self::fail( $r );
		}
		$env = isset( $_GET['env'] ) ? sanitize_key( wp_unslash( $_GET['env'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		wp_safe_redirect( self::url( array( 'tab' => 'queue', 'env' => $env, 'eru_cmi_msg' => 'retry' === $bulk ? 'retried' : 'skipped', 'n' => (int) $r ) ) );
		exit;
	}

	/** admin-post: consent change. */
	public static function http_change() {
		$p = self::post();
		$r = self::change_consent( $p );
		if ( is_wp_error( $r ) ) {
			self::fail( $r );
		}
		wp_safe_redirect( self::url( array( 'tab' => 'consents', 'consent' => absint( $p['consent'] ?? 0 ), 'eru_cmi_msg' => 'changed' ) ) );
		exit;
	}

	/** admin-post: CSV import; the result (counts, line numbers, codes) is shown once on Araçlar. */
	public static function http_import() {
		$file = isset( $_FILES['eru_cmi_csv'] ) && is_array( $_FILES['eru_cmi_csv'] ) ? $_FILES['eru_cmi_csv'] : array(); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput -- checked by guard(); only tmp_name/size/name/error are read.
		$r    = self::import_request( self::post(), $file );
		if ( is_wp_error( $r ) ) {
			self::fail( $r );
		}
		set_transient( 'eru_cmi_import_' . get_current_user_id(), $r, 10 * MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url( array( 'tab' => 'tools' ) ) );
		exit;
	}

	/** admin-post: CSV export (streamed). */
	public static function http_export() {
		$r = self::export_request( self::post() );
		if ( is_wp_error( $r ) ) {
			self::fail( $r );
		}
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="iys-izinleri-' . gmdate( 'Ymd-His' ) . ( $r['full'] ? '-tam' : '' ) . '.csv"' );
		$fh = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		fwrite( $fh, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- BOM for spreadsheet programs.
		Eru_CMI_Import::export( $fh, $r['full'] );
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		exit;
	}

	/** admin-post: table import start. */
	public static function http_table() {
		$r = self::table_request( self::post() );
		if ( is_wp_error( $r ) ) {
			self::fail( $r );
		}
		wp_safe_redirect( self::url( array( 'tab' => 'tools', 'eru_cmi_msg' => 'table' ) ) );
		exit;
	}

	/** admin-post: new API key; shown once on this response, never stored in clear. */
	public static function http_keys() {
		$r = self::keys_request( self::post() );
		if ( is_wp_error( $r ) ) {
			self::fail( $r );
		}
		nocache_headers();
		$html  = '<h1>' . esc_html__( 'Yeni API anahtarı', 'consent-manager-for-iys' ) . '</h1>';
		$html .= '<p>' . esc_html__( 'Bu değerler yalnızca şimdi gösterilir. Karşı sisteme kaydedin; bu sayfadan çıkınca bir daha görüntülenemez.', 'consent-manager-for-iys' ) . '</p>';
		$html .= '<p><strong>X-Eru-Cmi-Key</strong><br><code style="user-select:all">' . esc_html( $r['key'] ) . '</code></p>';
		$html .= '<p><strong>' . esc_html__( 'İmza sırrı (HMAC-SHA256)', 'consent-manager-for-iys' ) . '</strong><br><code style="user-select:all">' . esc_html( $r['secret'] ) . '</code></p>';
		$html .= '<p><a class="button" href="' . esc_url( self::url( array( 'tab' => 'settings', 'section' => 'external' ) ) ) . '">' . esc_html__( 'Ayarlara dön', 'consent-manager-for-iys' ) . '</a></p>';
		wp_die( wp_kses_post( $html ), esc_html__( 'Yeni API anahtarı', 'consent-manager-for-iys' ), array( 'response' => 200 ) );
	}

	/** admin-post: pull / reconcile soon. */
	public static function http_action() {
		$r = self::action_request( self::post() );
		if ( is_wp_error( $r ) ) {
			self::fail( $r );
		}
		wp_safe_redirect( self::url( array( 'tab' => 'queue', 'eru_cmi_msg' => $r ? 'queued' : 'notqueued' ) ) );
		exit;
	}

	/** admin-post: queue the connection test. */
	public static function handle_test() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'consent-manager-for-iys' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'eru_cmi_test' );
		if ( Eru_CMI_Settings::has_credentials() ) {
			Eru_CMI_Scheduler::queue_test();
		}
		wp_safe_redirect( self::url( array( 'tab' => 'settings', 'section' => 'connection', 'eru_cmi_test' => 1 ) ) );
		exit;
	}
}
