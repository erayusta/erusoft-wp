<?php
/**
 * Admin screens of the "Satış Ekibi" menu. Rendering only; every form posts to admin-post.php (or to the
 * list's own page for bulk actions) and is handled by Eru_STC_Admin.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Admin_Pages {

	/** @param string $key Page key. @param array $args Query args. @return string Admin URL. */
	private static function url( $key, array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => Eru_STC_Admin::PAGES[ $key ] ), $args ), admin_url( 'admin.php' ) );
	}

	/** @param string $action admin-post action (also the nonce action unless given). @param string $nonce Nonce action. */
	private static function form_open( $action, $nonce = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( '' !== $nonce ? $nonce : $action );
	}

	/** @param string $amount Decimal. @param string $currency Currency. @return string */
	private static function money( $amount, $currency = '' ) {
		return wp_kses_post( wc_price( (float) $amount, array( 'currency' => $currency ? $currency : get_woocommerce_currency() ) ) );
	}

	/** Page wrapper start. */
	private static function head( $title, $action_html = '' ) {
		echo '<div class="wrap eru-stc-wrap"><h1 class="wp-heading-inline">' . esc_html( $title ) . '</h1>' . $action_html . '<hr class="wp-header-end">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts.
	}

	/** @param array $tiles [ label, value_html, class ]. */
	private static function tiles( array $tiles ) {
		echo '<div class="eru-stc-tiles">';
		foreach ( $tiles as $t ) {
			echo '<div class="eru-stc-tile ' . esc_attr( $t[2] ?? '' ) . '"><span class="eru-stc-tile__label">' . esc_html( $t[0] ) . '</span><strong class="eru-stc-tile__value">' . wp_kses_post( $t[1] ) . '</strong>' . ( isset( $t[3] ) ? '<span class="eru-stc-tile__sub">' . wp_kses_post( $t[3] ) . '</span>' : '' ) . '</div>';
		}
		echo '</div>';
	}

	/** Agents for a select. @return object[] */
	private static function agent_options() {
		return Eru_STC_Agents::list( array( 'limit' => 500 ) );
	}

	/** @param string $name Field. @param int $selected Agent id. @param string $none Label of the empty option. @param int $exclude Agent to leave out. */
	private static function agent_select( $name, $selected, $none, $exclude = 0, $id = '' ) {
		echo '<select name="' . esc_attr( $name ) . '"' . ( $id ? ' id="' . esc_attr( $id ) . '"' : '' ) . '><option value="0">' . esc_html( $none ) . '</option>';
		foreach ( self::agent_options() as $a ) {
			if ( (int) $a->id === (int) $exclude ) {
				continue;
			}
			echo '<option value="' . esc_attr( (string) $a->id ) . '"' . selected( (int) $selected, (int) $a->id, false ) . '>' . esc_html( Eru_STC_Agents::label( $a ) . ' (' . $a->referral_code . ')' ) . '</option>';
		}
		echo '</select>';
	}

	/* ------------------------------------------------------------------ Dashboard */

	/** Dashboard: tiles, open payouts, applications, latest commissions. */
	public static function dashboard() {
		list( $from, $to, $month ) = Eru_STC_Reports::month_range();
		$overall                   = Eru_STC_Reports::overall();
		$this_month                = Eru_STC_Reports::totals( $from, $to );
		$open                      = Eru_STC_Payouts::list(
			array(
				'status' => 'requested',
				'limit'  => 10,
			)
		);
		$open_sum                  = 0;
		foreach ( Eru_STC_Payouts::list( array( 'status' => 'requested', 'limit' => 500 ) ) as $p ) {
			$open_sum += Eru_STC_Money::to_minor( $p->amount );
		}
		self::head( __( 'Satış Ekibi', 'sales-team-commissions-for-woocommerce' ) );
		self::tiles(
			array(
				array( __( 'Etkin temsilci', 'sales-team-commissions-for-woocommerce' ), (string) Eru_STC_Agents::count( array( 'status' => 'active' ) ), '', esc_html( sprintf( /* translators: %d: pending */ __( '%d başvuru bekliyor', 'sales-team-commissions-for-woocommerce' ), Eru_STC_Agents::count( array( 'status' => 'pending' ) ) ) ) ),
				array( __( 'Bu ay kazanılan', 'sales-team-commissions-for-woocommerce' ), self::money( $this_month['total'] ), 'is-accent', esc_html( sprintf( /* translators: %d: orders */ __( '%d sipariş', 'sales-team-commissions-for-woocommerce' ), $this_month['orders'] ) ) ),
				array( __( 'Beklemede', 'sales-team-commissions-for-woocommerce' ), self::money( $overall['pending'] ), '', esc_html__( 'bekleme süresinde', 'sales-team-commissions-for-woocommerce' ) ),
				array( __( 'Ödenebilir', 'sales-team-commissions-for-woocommerce' ), self::money( $overall['available'] ), '', esc_html__( 'onaylı, talep edilmemiş', 'sales-team-commissions-for-woocommerce' ) ),
				array( __( 'Ödeme talepleri', 'sales-team-commissions-for-woocommerce' ), self::money( Eru_STC_Money::from_minor( $open_sum ) ), Eru_STC_Payouts::count( array( 'status' => 'requested' ) ) ? 'is-warn' : '', esc_html( sprintf( /* translators: %d: open requests */ __( '%d açık talep', 'sales-team-commissions-for-woocommerce' ), Eru_STC_Payouts::count( array( 'status' => 'requested' ) ) ) ) ),
				array( __( 'Ödendi (toplam)', 'sales-team-commissions-for-woocommerce' ), self::money( $overall['paid'] ) ),
			)
		);
		echo '<div class="eru-stc-columns"><div class="eru-stc-col">';
		echo '<h2>' . esc_html__( 'Açık ödeme talepleri', 'sales-team-commissions-for-woocommerce' ) . '</h2>';
		if ( $open ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'No', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Temsilci', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Tutar', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Talep', 'sales-team-commissions-for-woocommerce' ) . '</th></tr></thead><tbody>';
			foreach ( $open as $p ) {
				echo '<tr><td>#' . (int) $p->id . '</td><td>' . Eru_STC_Table_Links::agent( (int) $p->agent_id ) . '</td><td>' . self::money( $p->amount, $p->currency ) . '</td><td>' . esc_html( get_date_from_gmt( $p->requested_at, get_option( 'date_format' ) ) ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped helpers.
			}
			echo '</tbody></table><p><a href="' . esc_url( self::url( 'payouts', array( 'status' => 'requested' ) ) ) . '">' . esc_html__( 'Tüm talepler', 'sales-team-commissions-for-woocommerce' ) . '</a></p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Açık talep yok.', 'sales-team-commissions-for-woocommerce' ) . '</p>';
		}
		$apps = Eru_STC_Agents::list(
			array(
				'status' => 'pending',
				'limit'  => 10,
			)
		);
		echo '<h2>' . esc_html__( 'Onay bekleyen temsilciler', 'sales-team-commissions-for-woocommerce' ) . '</h2>';
		if ( $apps ) {
			echo '<ul class="eru-stc-list">';
			foreach ( $apps as $a ) {
				echo '<li>' . Eru_STC_Table_Links::agent( (int) $a->id ) . ' <span class="description">' . esc_html( get_date_from_gmt( $a->created_at, get_option( 'date_format' ) ) ) . '</span></li>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped helper.
			}
			echo '</ul>';
		} else {
			echo '<p class="description">' . esc_html__( 'Bekleyen başvuru yok.', 'sales-team-commissions-for-woocommerce' ) . '</p>';
		}
		echo '</div><div class="eru-stc-col">';
		echo '<h2>' . esc_html__( 'Son komisyonlar', 'sales-team-commissions-for-woocommerce' ) . '</h2>';
		$recent = Eru_STC_Commissions::list( array( 'limit' => 10 ) );
		if ( $recent ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Sipariş', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Temsilci', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Seviye', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Komisyon', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Durum', 'sales-team-commissions-for-woocommerce' ) . '</th></tr></thead><tbody>';
			foreach ( $recent as $r ) {
				echo '<tr><td>' . Eru_STC_Table_Links::order( (int) $r->order_id ) . '</td><td>' . Eru_STC_Table_Links::agent( (int) $r->agent_id ) . '</td><td>' . (int) $r->level . '</td><td>' . self::money( $r->amount, $r->currency ) . '</td><td><span class="eru-stc-badge eru-stc-badge--' . esc_attr( $r->status ) . '">' . esc_html( Eru_STC_Labels::commission_status( $r->status ) ) . '</span></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped helpers.
			}
			echo '</tbody></table><p><a href="' . esc_url( self::url( 'commissions' ) ) . '">' . esc_html__( 'Tüm komisyonlar', 'sales-team-commissions-for-woocommerce' ) . '</a></p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Henüz komisyon yok. Önce Kurallar ekranından bir kural ekleyin ve Ayarlar\'dan modülü açın.', 'sales-team-commissions-for-woocommerce' ) . '</p>';
		}
		echo '</div></div>';
		/* translators: 1: month, 2: hold days, 3: level cap */
		echo '<p class="description">' . esc_html( sprintf( __( 'Ay: %1$s · Bekleme süresi: %2$d gün · Seviye sınırı: %3$d', 'sales-team-commissions-for-woocommerce' ), $month, (int) Eru_STC_Settings::get( 'hold_days' ), (int) Eru_STC_Settings::get( 'max_levels' ) ) ) . '</p>';
		echo '</div>';
	}

	/* ------------------------------------------------------------------ Agents */

	/** Agents: list, detail or new-agent form. */
	public static function agents() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
		$id  = isset( $_GET['agent'] ) ? absint( $_GET['agent'] ) : 0;
		$new = isset( $_GET['action'] ) && 'new' === $_GET['action'];
		// phpcs:enable
		if ( $id ) {
			self::agent_detail( $id );
			return;
		}
		if ( $new ) {
			self::agent_new();
			return;
		}
		require_once ERU_STC_PATH . 'includes/class-eru-stc-admin-tables.php';
		$table = new Eru_STC_Agents_Table();
		$table->prepare_items();
		self::head( __( 'Temsilciler', 'sales-team-commissions-for-woocommerce' ), ' <a href="' . esc_url( self::url( 'agents', array( 'action' => 'new' ) ) ) . '" class="page-title-action">' . esc_html__( 'Temsilci ekle', 'sales-team-commissions-for-woocommerce' ) . '</a>' );
		$table->views();
		echo '<form method="get"><input type="hidden" name="page" value="' . esc_attr( Eru_STC_Admin::PAGES['agents'] ) . '">';
		$table->search_box( __( 'Ara (ad, e-posta, kod)', 'sales-team-commissions-for-woocommerce' ), 'eru-stc-agent' );
		echo '</form><form method="post">';
		$table->display();
		echo '</form><p class="description">' . esc_html__( 'Sipariş: ödeme sırasında bu temsilciyle işaretlenen siparişler. Komisyon geçmişi olan temsilci silinemez; askıya alın.', 'sales-team-commissions-for-woocommerce' ) . '</p></div>';
	}

	/** New agent form. */
	private static function agent_new() {
		self::head( __( 'Temsilci ekle', 'sales-team-commissions-for-woocommerce' ) );
		self::form_open( 'eru_stc_agent_save' );
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th><label for="eru-stc-user">' . esc_html__( 'Kullanıcı', 'sales-team-commissions-for-woocommerce' ) . '</label></th><td><input type="text" class="regular-text" name="user" id="eru-stc-user" list="eru-stc-user-list" required autocomplete="off" data-eru-stc-user-search><datalist id="eru-stc-user-list"></datalist><p class="description">' . esc_html__( 'Mevcut bir hesabın e-postası, kullanıcı adı veya numarası. Yazdıkça öneri gelir.', 'sales-team-commissions-for-woocommerce' ) . '</p></td></tr>';
		echo '<tr><th><label for="eru-stc-name">' . esc_html__( 'Görünen ad', 'sales-team-commissions-for-woocommerce' ) . '</label></th><td><input type="text" class="regular-text" name="display_name" id="eru-stc-name"><p class="description">' . esc_html__( 'Boşsa hesabın görünen adı kullanılır. Ekip listelerinde ve davet e-postasında görünür.', 'sales-team-commissions-for-woocommerce' ) . '</p></td></tr>';
		echo '<tr><th><label for="eru-stc-parent">' . esc_html__( 'Üst temsilci', 'sales-team-commissions-for-woocommerce' ) . '</label></th><td>';
		self::agent_select( 'parent_agent_id', 0, __( '— Yok —', 'sales-team-commissions-for-woocommerce' ), 0, 'eru-stc-parent' );
		echo '</td></tr>';
		echo '<tr><th><label for="eru-stc-code">' . esc_html__( 'Davet kodu', 'sales-team-commissions-for-woocommerce' ) . '</label></th><td><input type="text" name="referral_code" id="eru-stc-code" maxlength="32" class="regular-text code"><p class="description">' . esc_html__( 'Boşsa 8 karakterlik kod üretilir. A–Z, 0–9 ve tire.', 'sales-team-commissions-for-woocommerce' ) . '</p></td></tr>';
		echo '<tr><th><label for="eru-stc-status">' . esc_html__( 'Durum', 'sales-team-commissions-for-woocommerce' ) . '</label></th><td><select name="status" id="eru-stc-status">';
		foreach ( Eru_STC_Agents::STATUSES as $s ) {
			echo '<option value="' . esc_attr( $s ) . '"' . selected( 'active', $s, false ) . '>' . esc_html( Eru_STC_Labels::agent_status( $s ) ) . '</option>';
		}
		echo '</select></td></tr></tbody></table>';
		submit_button( __( 'Temsilci oluştur', 'sales-team-commissions-for-woocommerce' ) );
		echo '</form></div>';
	}

	/** @param int $id Agent. */
	private static function agent_detail( $id ) {
		$agent = Eru_STC_Agents::get( $id );
		if ( ! $agent ) {
			self::head( __( 'Temsilci', 'sales-team-commissions-for-woocommerce' ) );
			echo '<p>' . esc_html__( 'Temsilci bulunamadı.', 'sales-team-commissions-for-woocommerce' ) . '</p></div>';
			return;
		}
		$user     = get_userdata( (int) $agent->user_id );
		$balance  = Eru_STC_Commissions::balance( (int) $agent->id );
		$settings = Eru_STC_Agents::settings( $agent );
		$orders   = Eru_STC_Reports::order_counts( array( (int) $agent->id ) );
		$ct       = Eru_STC_Commissions::t();
		global $wpdb;
		$commissioned = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT order_id) FROM {$ct} WHERE agent_id = %d AND level = 1 AND entry_key = ''", $agent->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$back         = ' <a href="' . esc_url( self::url( 'agents' ) ) . '" class="page-title-action">' . esc_html__( 'Tüm temsilciler', 'sales-team-commissions-for-woocommerce' ) . '</a>';
		self::head( Eru_STC_Agents::label( $agent ), $back );
		echo '<p class="eru-stc-sub"><span class="eru-stc-badge eru-stc-badge--' . esc_attr( $agent->status ) . '">' . esc_html( Eru_STC_Labels::agent_status( $agent->status ) ) . '</span> <code>' . esc_html( $agent->referral_code ) . '</code> ' . ( $user ? esc_html( $user->user_email ) : '' ) . ' · <span class="description">' . esc_html( add_query_arg( Eru_STC_Referral::param(), $agent->referral_code, home_url( '/' ) ) ) . '</span></p>';
		self::tiles(
			array(
				array( __( 'Beklemede', 'sales-team-commissions-for-woocommerce' ), self::money( $balance['pending'] ) ),
				array( __( 'Ödenebilir', 'sales-team-commissions-for-woocommerce' ), self::money( $balance['available'] ), 'is-accent' ),
				array( __( 'Talep edildi', 'sales-team-commissions-for-woocommerce' ), self::money( $balance['requested'] ) ),
				array( __( 'Ödendi', 'sales-team-commissions-for-woocommerce' ), self::money( $balance['paid'] ) ),
				/* translators: %d: orders with commission */
				array( __( 'Siparişler', 'sales-team-commissions-for-woocommerce' ), (string) (int) ( $orders[ (int) $agent->id ] ?? 0 ), '', esc_html( sprintf( __( '%d komisyonlu (1. seviye)', 'sales-team-commissions-for-woocommerce' ), $commissioned ) ) ),
				array( __( 'Müşteri', 'sales-team-commissions-for-woocommerce' ), (string) Eru_STC_Links::count_for( (int) $agent->id ) ),
			)
		);
		echo '<div class="eru-stc-columns"><div class="eru-stc-col">';

		// Profile.
		echo '<div class="eru-stc-card"><h2>' . esc_html__( 'Profil', 'sales-team-commissions-for-woocommerce' ) . '</h2>';
		self::form_open( 'eru_stc_agent_save' );
		echo '<input type="hidden" name="agent_id" value="' . esc_attr( (string) $agent->id ) . '"><table class="form-table" role="presentation"><tbody>';
		echo '<tr><th><label for="eru-stc-dn">' . esc_html__( 'Görünen ad', 'sales-team-commissions-for-woocommerce' ) . '</label></th><td><input type="text" class="regular-text" id="eru-stc-dn" name="display_name" value="' . esc_attr( (string) ( $settings['display_name'] ?? '' ) ) . '"></td></tr>';
		echo '<tr><th><label for="eru-stc-st">' . esc_html__( 'Durum', 'sales-team-commissions-for-woocommerce' ) . '</label></th><td><select name="status" id="eru-stc-st">';
		foreach ( Eru_STC_Agents::STATUSES as $s ) {
			echo '<option value="' . esc_attr( $s ) . '"' . selected( $agent->status, $s, false ) . '>' . esc_html( Eru_STC_Labels::agent_status( $s ) ) . '</option>';
		}
		echo '</select></td></tr><tr><th><label for="eru-stc-pa">' . esc_html__( 'Üst temsilci', 'sales-team-commissions-for-woocommerce' ) . '</label></th><td>';
		self::agent_select( 'parent_agent_id', (int) $agent->parent_agent_id, __( '— Yok —', 'sales-team-commissions-for-woocommerce' ), (int) $agent->id, 'eru-stc-pa' );
		echo '</td></tr><tr><th><label for="eru-stc-cd">' . esc_html__( 'Davet kodu', 'sales-team-commissions-for-woocommerce' ) . '</label></th><td><input type="text" class="code" id="eru-stc-cd" name="referral_code" maxlength="32" value="' . esc_attr( $agent->referral_code ) . '"></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Kaydet', 'sales-team-commissions-for-woocommerce' ), 'primary', 'submit', false );
		echo '</form></div>';

		// Payout details (masked) and admin payout.
		$m = Eru_STC_Payout_Details::masked( $agent );
		echo '<div class="eru-stc-card"><h2>' . esc_html__( 'Ödeme bilgileri', 'sales-team-commissions-for-woocommerce' ) . '</h2>';
		if ( 'empty' === $m['state'] ) {
			echo '<p class="description">' . esc_html__( 'Temsilci henüz ödeme bilgisi girmedi.', 'sales-team-commissions-for-woocommerce' ) . '</p>';
		} else {
			echo '<dl class="eru-stc-masked"><dt>' . esc_html__( 'Hesap sahibi', 'sales-team-commissions-for-woocommerce' ) . '</dt><dd>' . esc_html( $m['holder'] ? $m['holder'] : '—' ) . '</dd><dt>IBAN</dt><dd><code>' . esc_html( $m['iban'] ? $m['iban'] : '—' ) . '</code></dd><dt>' . esc_html__( 'T.C. kimlik no', 'sales-team-commissions-for-woocommerce' ) . '</dt><dd><code>' . esc_html( $m['tckn'] ? $m['tckn'] : '—' ) . '</code></dd></dl>';
			echo '<p class="description">' . esc_html__( 'Değerler maskelidir. Havale için tam IBAN, Ödemeler ekranındaki "tam IBAN" dışa aktarımında bulunur.', 'sales-team-commissions-for-woocommerce' ) . '</p>';
		}
		if ( Eru_STC_Money::to_minor( $balance['available'] ) > 0 ) {
			self::form_open( 'eru_stc_payout_create', 'eru_stc_payout' );
			echo '<input type="hidden" name="agent_id" value="' . esc_attr( (string) $agent->id ) . '">';
			/* translators: %s: amount */
			submit_button( sprintf( __( 'Ödenebilir bakiye için talep oluştur (%s)', 'sales-team-commissions-for-woocommerce' ), Eru_STC_Labels::money( $balance['available'] ) ), 'secondary', 'submit', false );
			echo '</form>';
		}
		echo '</div>';

		// Downline.
		echo '<div class="eru-stc-card"><h2>' . esc_html__( 'Ekip', 'sales-team-commissions-for-woocommerce' ) . '</h2>';
		$up = $agent->parent_agent_id ? Eru_STC_Table_Links::agent( (int) $agent->parent_agent_id ) : '—';
		echo '<p>' . esc_html__( 'Üst temsilci:', 'sales-team-commissions-for-woocommerce' ) . ' ' . wp_kses_post( $up ) . '</p>';
		$down = Eru_STC_Agents::downline( (int) $agent->id, (int) Eru_STC_Settings::get( 'max_levels' ) );
		if ( $down ) {
			echo '<ul class="eru-stc-list">';
			foreach ( $down as $d ) {
				echo '<li>' . esc_html( str_repeat( '— ', (int) $d['depth'] ) ) . wp_kses_post( Eru_STC_Table_Links::agent( (int) $d['agent']->id ) ) . ' <span class="description">' . esc_html( Eru_STC_Labels::agent_status( $d['agent']->status ) ) . '</span></li>';
			}
			echo '</ul>';
		} else {
			echo '<p class="description">' . esc_html__( 'Alt temsilci yok.', 'sales-team-commissions-for-woocommerce' ) . '</p>';
		}
		echo '</div>';

		if ( ! $commissioned && ! Eru_STC_Commissions::count( array( 'agent_id' => (int) $agent->id ) ) ) {
			echo '<div class="eru-stc-card">';
			self::form_open( 'eru_stc_agent_delete' );
			echo '<input type="hidden" name="agent_id" value="' . esc_attr( (string) $agent->id ) . '"><button type="submit" class="button-link eru-stc-danger">' . esc_html__( 'Temsilciyi sil (komisyon geçmişi yok)', 'sales-team-commissions-for-woocommerce' ) . '</button></form></div>';
		}
		echo '</div><div class="eru-stc-col">';

		// Customers.
		$links = Eru_STC_Links::customers_of( (int) $agent->id, 20 );
		echo '<div class="eru-stc-card"><h2>' . esc_html__( 'Müşteriler', 'sales-team-commissions-for-woocommerce' ) . '</h2>';
		if ( $links ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Müşteri', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Bağlantı', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Tarih', 'sales-team-commissions-for-woocommerce' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $links as $l ) {
				$u = get_userdata( (int) $l->customer_id );
				echo '<tr><td>' . ( $u ? '<a href="' . esc_url( get_edit_user_link( $u->ID ) ) . '">' . esc_html( $u->display_name ) . '</a><br><span class="description">' . esc_html( $u->user_email ) . '</span>' : '#' . (int) $l->customer_id ) . '</td><td>' . esc_html( Eru_STC_Labels::link_type( $l->link_type ) ) . ( $l->locked_at ? ' <span class="dashicons dashicons-lock" title="' . esc_attr__( 'İlk temsilciye kilitli', 'sales-team-commissions-for-woocommerce' ) . '"></span>' : '' ) . '</td><td>' . esc_html( get_date_from_gmt( $l->created_at, get_option( 'date_format' ) ) ) . '</td><td>';
				self::form_open( 'eru_stc_unlink' );
				echo '<input type="hidden" name="agent_id" value="' . esc_attr( (string) $agent->id ) . '"><input type="hidden" name="customer_id" value="' . esc_attr( (string) $l->customer_id ) . '"><button type="submit" class="button-link eru-stc-danger">' . esc_html__( 'Kaldır', 'sales-team-commissions-for-woocommerce' ) . '</button></form></td></tr>';
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="description">' . esc_html__( 'Bağlı müşteri yok.', 'sales-team-commissions-for-woocommerce' ) . '</p>';
		}
		self::form_open( 'eru_stc_link' );
		echo '<p class="eru-stc-inline"><input type="hidden" name="agent_id" value="' . esc_attr( (string) $agent->id ) . '"><label class="screen-reader-text" for="eru-stc-link-user">' . esc_html__( 'Müşteri', 'sales-team-commissions-for-woocommerce' ) . '</label><input type="text" name="customer" id="eru-stc-link-user" list="eru-stc-user-list" data-eru-stc-user-search placeholder="' . esc_attr__( 'Müşteri e-postası veya numarası', 'sales-team-commissions-for-woocommerce' ) . '" autocomplete="off"><datalist id="eru-stc-user-list"></datalist> <label><input type="checkbox" name="force" value="1"> ' . esc_html__( 'Kilitli bağlantıyı da taşı', 'sales-team-commissions-for-woocommerce' ) . '</label> <button type="submit" class="button">' . esc_html__( 'Müşteri bağla', 'sales-team-commissions-for-woocommerce' ) . '</button></p></form>';
		echo '<p class="description">' . esc_html__( 'Yönetici kararıyla bağlama. Temsilciler müşteri ekleyemez; yalnızca müşterinin onaylayacağı bir davet gönderebilir.', 'sales-team-commissions-for-woocommerce' ) . '</p></div>';

		// Commissions.
		$rows = Eru_STC_Commissions::list(
			array(
				'agent_id' => (int) $agent->id,
				'limit'    => 20,
			)
		);
		echo '<div class="eru-stc-card"><h2>' . esc_html__( 'Son komisyonlar', 'sales-team-commissions-for-woocommerce' ) . '</h2>';
		if ( $rows ) {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Tarih', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Sipariş', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Seviye', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Komisyon', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Durum', 'sales-team-commissions-for-woocommerce' ) . '</th></tr></thead><tbody data-eru-stc-rows>';
			foreach ( $rows as $r ) {
				echo '<tr><td>' . esc_html( get_date_from_gmt( $r->earned_at, get_option( 'date_format' ) ) ) . '</td><td>' . Eru_STC_Table_Links::order( (int) $r->order_id ) . ( '' !== $r->entry_key ? ' <span class="description">' . esc_html__( 'düzeltme', 'sales-team-commissions-for-woocommerce' ) . '</span>' : '' ) . '</td><td>' . (int) $r->level . '</td><td>' . self::money( $r->amount, $r->currency ) . '</td><td><span class="eru-stc-badge eru-stc-badge--' . esc_attr( $r->status ) . '">' . esc_html( Eru_STC_Labels::commission_status( $r->status ) ) . '</span></td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped helpers.
			}
			echo '</tbody></table>';
			if ( count( $rows ) >= 20 ) {
				echo '<p><button type="button" class="button" data-eru-stc-more="' . esc_attr( (string) $agent->id ) . '" data-offset="20">' . esc_html__( 'Daha fazla', 'sales-team-commissions-for-woocommerce' ) . '</button></p>';
			}
		} else {
			echo '<p class="description">' . esc_html__( 'Komisyon yok.', 'sales-team-commissions-for-woocommerce' ) . '</p>';
		}
		echo '<p><a href="' . esc_url( self::url( 'commissions', array( 'agent_id' => (int) $agent->id ) ) ) . '">' . esc_html__( 'Komisyonlar ekranında aç', 'sales-team-commissions-for-woocommerce' ) . '</a> · <a href="' . esc_url( self::url( 'payouts', array( 'agent_id' => (int) $agent->id ) ) ) . '">' . esc_html__( 'Ödemeleri', 'sales-team-commissions-for-woocommerce' ) . '</a></p></div>';
		echo '</div></div></div>';
	}

	/* ------------------------------------------------------------------ Rules */

	/** Rules: edit form and list on one screen. */
	public static function rules() {
		$edit = isset( $_GET['edit'] ) ? Eru_STC_Rules::get( absint( $_GET['edit'] ) ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$r    = $edit ? $edit : (object) array(
			'id'         => 0,
			'name'       => '',
			'scope'      => 'global',
			'target_id'  => 0,
			'target_key' => '',
			'level'      => 1,
			'type'       => 'percent',
			'rate'       => '',
			'starts_at'  => null,
			'ends_at'    => null,
			'priority'   => 0,
			'active'     => 1,
		);
		self::head( __( 'Komisyon kuralları', 'sales-team-commissions-for-woocommerce' ), $edit ? ' <a href="' . esc_url( self::url( 'rules' ) ) . '" class="page-title-action">' . esc_html__( 'Yeni kural', 'sales-team-commissions-for-woocommerce' ) . '</a>' : '' );
		echo '<p class="description">' . esc_html__( 'Bir satış satırı için her seviyede tek kural uygulanır: ürün > kategori > marka > müşteri rolü > temsilci > genel. Aynı kapsamda önceliği büyük olan, eşitse en yeni kural kazanır. Tarih aralığı sipariş tarihine göre denetlenir.', 'sales-team-commissions-for-woocommerce' ) . '</p>';
		echo '<div id="col-container" class="wp-clearfix"><div id="col-left"><div class="col-wrap"><div class="form-wrap">';
		echo '<h2>' . esc_html( $edit ? sprintf( /* translators: %d: rule id */ __( 'Kural #%d', 'sales-team-commissions-for-woocommerce' ), (int) $edit->id ) : __( 'Yeni kural', 'sales-team-commissions-for-woocommerce' ) ) . '</h2>';
		self::form_open( 'eru_stc_rule_save' );
		echo '<input type="hidden" name="rule_id" value="' . esc_attr( (string) $r->id ) . '">';
		echo '<div class="form-field"><label for="eru-stc-rn">' . esc_html__( 'Ad', 'sales-team-commissions-for-woocommerce' ) . '</label><input type="text" id="eru-stc-rn" name="name" value="' . esc_attr( $r->name ) . '" maxlength="190"></div>';
		echo '<div class="form-field"><label for="eru-stc-scope">' . esc_html__( 'Kapsam', 'sales-team-commissions-for-woocommerce' ) . '</label><select id="eru-stc-scope" name="scope" data-eru-stc-scope>';
		foreach ( array_keys( Eru_STC_Rules::SCOPES ) as $s ) {
			echo '<option value="' . esc_attr( $s ) . '"' . selected( $r->scope, $s, false ) . '>' . esc_html( Eru_STC_Labels::scope( $s ) ) . '</option>';
		}
		echo '</select></div>';
		// Targets (one visible at a time).
		$product = 'product' === $r->scope && $r->target_id ? wc_get_product( (int) $r->target_id ) : null;
		echo '<div class="form-field" data-eru-stc-target="product"><label for="eru-stc-tp">' . esc_html__( 'Ürün', 'sales-team-commissions-for-woocommerce' ) . '</label><select id="eru-stc-tp" class="wc-product-search" name="target_product" data-placeholder="' . esc_attr__( 'Ürün arayın…', 'sales-team-commissions-for-woocommerce' ) . '" data-action="woocommerce_json_search_products_and_variations" style="width:100%">' . ( $product ? '<option value="' . esc_attr( (string) $product->get_id() ) . '" selected>' . esc_html( wp_strip_all_tags( $product->get_formatted_name() ) ) . '</option>' : '' ) . '</select></div>';
		echo '<div class="form-field" data-eru-stc-target="category"><label for="eru-stc-tc">' . esc_html__( 'Kategori (alt kategoriler dahil)', 'sales-team-commissions-for-woocommerce' ) . '</label>';
		wp_dropdown_categories(
			array(
				'taxonomy'         => 'product_cat',
				'name'             => 'target_category',
				'id'               => 'eru-stc-tc',
				'hide_empty'       => false,
				'hierarchical'     => true,
				'selected'         => 'category' === $r->scope ? (int) $r->target_id : 0,
				'show_option_none' => __( '— Seçin —', 'sales-team-commissions-for-woocommerce' ),
				'option_none_value' => 0,
			)
		);
		echo '</div><div class="form-field" data-eru-stc-target="brand"><label for="eru-stc-tb">' . esc_html__( 'Marka (alt markalar dahil)', 'sales-team-commissions-for-woocommerce' ) . '</label>';
		if ( taxonomy_exists( 'product_brand' ) ) {
			wp_dropdown_categories(
				array(
					'taxonomy'          => 'product_brand',
					'name'              => 'target_brand',
					'id'                => 'eru-stc-tb',
					'hide_empty'        => false,
					'hierarchical'      => true,
					'selected'          => 'brand' === $r->scope ? (int) $r->target_id : 0,
					'show_option_none'  => __( '— Seçin —', 'sales-team-commissions-for-woocommerce' ),
					'option_none_value' => 0,
				)
			);
		} else {
			echo '<input type="number" min="1" name="target_brand" id="eru-stc-tb" value="' . esc_attr( 'brand' === $r->scope ? (string) $r->target_id : '' ) . '"><p>' . esc_html__( 'Marka terim numarası (eru_stc_brand_taxonomies filtresindeki taksonomi).', 'sales-team-commissions-for-woocommerce' ) . '</p>';
		}
		echo '</div><div class="form-field" data-eru-stc-target="role"><label for="eru-stc-tr">' . esc_html__( 'Müşteri rolü', 'sales-team-commissions-for-woocommerce' ) . '</label><select name="target_role" id="eru-stc-tr">';
		wp_dropdown_roles( 'role' === $r->scope ? $r->target_key : 'customer' );
		echo '</select></div><div class="form-field" data-eru-stc-target="agent"><label for="eru-stc-ta">' . esc_html__( 'Kazanan temsilci', 'sales-team-commissions-for-woocommerce' ) . '</label>';
		self::agent_select( 'target_agent', 'agent' === $r->scope ? (int) $r->target_id : 0, __( '— Seçin —', 'sales-team-commissions-for-woocommerce' ), 0, 'eru-stc-ta' );
		echo '</div>';
		echo '<div class="form-field eru-stc-row"><span><label for="eru-stc-lv">' . esc_html__( 'Seviye', 'sales-team-commissions-for-woocommerce' ) . '</label><select id="eru-stc-lv" name="level">';
		for ( $i = 1; $i <= 5; $i++ ) {
			echo '<option value="' . (int) $i . '"' . selected( (int) $r->level, $i, false ) . '>' . (int) $i . '</option>';
		}
		echo '</select></span><span><label for="eru-stc-ty">' . esc_html__( 'Tür', 'sales-team-commissions-for-woocommerce' ) . '</label><select id="eru-stc-ty" name="type"><option value="percent"' . selected( $r->type, 'percent', false ) . '>' . esc_html__( 'Yüzde (%)', 'sales-team-commissions-for-woocommerce' ) . '</option><option value="fixed"' . selected( $r->type, 'fixed', false ) . '>' . esc_html__( 'Adet başına tutar', 'sales-team-commissions-for-woocommerce' ) . '</option></select></span>';
		echo '<span><label for="eru-stc-rt">' . esc_html__( 'Oran / tutar', 'sales-team-commissions-for-woocommerce' ) . '</label><input type="text" id="eru-stc-rt" name="rate" inputmode="decimal" value="' . esc_attr( '' === (string) $r->rate ? '' : rtrim( rtrim( (string) $r->rate, '0' ), '.' ) ) . '" required></span></div>';
		$d = fn( $v ) => $v ? get_date_from_gmt( $v, 'Y-m-d' ) : '';
		echo '<div class="form-field eru-stc-row"><span><label for="eru-stc-sa">' . esc_html__( 'Başlangıç', 'sales-team-commissions-for-woocommerce' ) . '</label><input type="date" id="eru-stc-sa" name="starts_at" value="' . esc_attr( $d( $r->starts_at ) ) . '"></span><span><label for="eru-stc-ea">' . esc_html__( 'Bitiş', 'sales-team-commissions-for-woocommerce' ) . '</label><input type="date" id="eru-stc-ea" name="ends_at" value="' . esc_attr( $d( $r->ends_at ) ) . '"></span><span><label for="eru-stc-pr">' . esc_html__( 'Öncelik', 'sales-team-commissions-for-woocommerce' ) . '</label><input type="number" id="eru-stc-pr" name="priority" value="' . esc_attr( (string) (int) $r->priority ) . '"></span></div>';
		echo '<div class="form-field"><label><input type="checkbox" name="active" value="1"' . checked( (int) $r->active, 1, false ) . '> ' . esc_html__( 'Etkin', 'sales-team-commissions-for-woocommerce' ) . '</label></div>';
		submit_button( $edit ? __( 'Kuralı güncelle', 'sales-team-commissions-for-woocommerce' ) : __( 'Kural ekle', 'sales-team-commissions-for-woocommerce' ) );
		echo '</form>';
		if ( $edit ) {
			self::form_open( 'eru_stc_rule_delete' );
			echo '<input type="hidden" name="rule_id" value="' . esc_attr( (string) $edit->id ) . '"><button type="submit" class="button-link eru-stc-danger">' . esc_html__( 'Kuralı sil', 'sales-team-commissions-for-woocommerce' ) . '</button><p class="description">' . esc_html__( 'Yazılmış komisyon satırları değişmez; kuralın o anki oranı satırda saklıdır.', 'sales-team-commissions-for-woocommerce' ) . '</p></form>';
		}
		echo '</div></div></div><div id="col-right"><div class="col-wrap">';
		$rules = Eru_STC_Rules::all( array( 'limit' => 500 ) );
		echo '<table class="widefat striped eru-stc-rules"><thead><tr><th>' . esc_html__( 'Kural', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Kapsam', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Seviye', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Oran', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Tarih', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Öncelik', 'sales-team-commissions-for-woocommerce' ) . '</th></tr></thead><tbody>';
		if ( ! $rules ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Henüz kural yok. Kural olmadan komisyon yazılmaz.', 'sales-team-commissions-for-woocommerce' ) . '</td></tr>';
		}
		foreach ( $rules as $rule ) {
			$rate = 'percent' === $rule->type ? rtrim( rtrim( (string) $rule->rate, '0' ), '.' ) . ' %' : Eru_STC_Labels::money( $rule->rate ) . ' ' . __( '/ adet', 'sales-team-commissions-for-woocommerce' );
			$date = ( $rule->starts_at ? get_date_from_gmt( $rule->starts_at, 'd.m.Y' ) : '…' ) . ' – ' . ( $rule->ends_at ? get_date_from_gmt( $rule->ends_at, 'd.m.Y' ) : '…' );
			$cls  = $edit && (int) $edit->id === (int) $rule->id ? ' class="is-editing"' : '';
			echo '<tr' . $cls . '><td><strong><a href="' . esc_url( self::url( 'rules', array( 'edit' => (int) $rule->id ) ) ) . '">' . esc_html( '' !== $rule->name ? $rule->name : '#' . $rule->id ) . '</a></strong>' . ( $rule->active ? '' : ' <span class="eru-stc-badge eru-stc-badge--void">' . esc_html__( 'Kapalı', 'sales-team-commissions-for-woocommerce' ) . '</span>' ) . '</td><td>' . esc_html( Eru_STC_Labels::scope( $rule->scope ) ) . '<br><span class="description">' . esc_html( self::target_label( $rule ) ) . '</span></td><td>' . (int) $rule->level . '</td><td>' . esc_html( $rate ) . '</td><td>' . esc_html( $date ) . '</td><td>' . (int) $rule->priority . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $cls is a constant string.
		}
		echo '</tbody></table></div></div></div></div>';
	}

	/** @param object $rule Rule. @return string Target name. */
	private static function target_label( $rule ) {
		switch ( $rule->scope ) {
			case 'product':
				$p = wc_get_product( (int) $rule->target_id );
				return $p ? wp_strip_all_tags( $p->get_name() ) : '#' . (int) $rule->target_id;
			case 'category':
			case 'brand':
				$t = get_term( (int) $rule->target_id );
				return $t && ! is_wp_error( $t ) ? $t->name : '#' . (int) $rule->target_id;
			case 'role':
				$roles = wp_roles()->get_names();
				return isset( $roles[ $rule->target_key ] ) ? translate_user_role( $roles[ $rule->target_key ] ) : $rule->target_key;
			case 'agent':
				$a = Eru_STC_Agents::get( (int) $rule->target_id );
				return $a ? Eru_STC_Agents::label( $a ) : '#' . (int) $rule->target_id;
		}
		return __( 'Tüm satışlar', 'sales-team-commissions-for-woocommerce' );
	}

	/* ------------------------------------------------------------------ Commissions */

	/** Commission rows with filters, bulk approve/void, recalculation and CSV. */
	public static function commissions() {
		require_once ERU_STC_PATH . 'includes/class-eru-stc-admin-tables.php';
		$table = new Eru_STC_Commissions_Table();
		$table->prepare_items();
		$f = $table->filters();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$month = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';
		self::head( __( 'Komisyonlar', 'sales-team-commissions-for-woocommerce' ) );
		$table->views();
		echo '<form method="get" class="eru-stc-filters"><input type="hidden" name="page" value="' . esc_attr( Eru_STC_Admin::PAGES['commissions'] ) . '">';
		if ( ! empty( $f['status'] ) ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $f['status'] ) . '">';
		}
		self::agent_select( 'agent_id', (int) $f['agent_id'], __( 'Tüm temsilciler', 'sales-team-commissions-for-woocommerce' ) );
		echo ' <input type="month" name="month" value="' . esc_attr( $month ) . '" aria-label="' . esc_attr__( 'Ay', 'sales-team-commissions-for-woocommerce' ) . '"> <input type="number" name="order_id" min="1" placeholder="' . esc_attr__( 'Sipariş no', 'sales-team-commissions-for-woocommerce' ) . '" value="' . esc_attr( $f['order_id'] ? (string) $f['order_id'] : '' ) . '"> <button class="button">' . esc_html__( 'Süz', 'sales-team-commissions-for-woocommerce' ) . '</button></form>';
		echo '<form method="post">';
		$table->display();
		echo '</form>';
		echo '<div class="eru-stc-columns"><div class="eru-stc-col eru-stc-card"><h2>' . esc_html__( 'Siparişi yeniden hesapla', 'sales-team-commissions-for-woocommerce' ) . '</h2><p class="description">' . esc_html__( 'Komisyon yazılan durumdaki bir siparişte, canlı satırı olmayan satır/seviye boşluklarını doldurur, ardından iadeleri yeniden uygular. Aynı satır ve seviyeye hiçbir zaman ikinci bir temsilci yazılmaz. Tamamlandığında temsilcisi olmayan sipariş için temsilci seçin; siparişi başka bir temsilciye geçirmek için önce mevcut satırları iptal edin.', 'sales-team-commissions-for-woocommerce' ) . '</p>';
		self::form_open( 'eru_stc_recalc' );
		echo '<p class="eru-stc-inline"><input type="number" name="order_id" min="1" required placeholder="' . esc_attr__( 'Sipariş no', 'sales-team-commissions-for-woocommerce' ) . '"> ';
		self::agent_select( 'agent_id', 0, __( 'Temsilci: siparişteki / müşterinin', 'sales-team-commissions-for-woocommerce' ) );
		echo ' <button type="submit" class="button">' . esc_html__( 'Yeniden hesapla', 'sales-team-commissions-for-woocommerce' ) . '</button></p></form></div>';
		echo '<div class="eru-stc-col eru-stc-card"><h2>' . esc_html__( 'CSV', 'sales-team-commissions-for-woocommerce' ) . '</h2><p class="description">' . esc_html__( 'Yukarıdaki süzgeçlerle en fazla 5.000 satır.', 'sales-team-commissions-for-woocommerce' ) . '</p>';
		self::form_open( 'eru_stc_commissions_export', 'eru_stc_export' );
		echo '<input type="hidden" name="agent_id" value="' . esc_attr( (string) $f['agent_id'] ) . '"><input type="hidden" name="status" value="' . esc_attr( (string) ( $f['status'] ?? '' ) ) . '"><input type="hidden" name="month" value="' . esc_attr( $month ) . '"><button type="submit" class="button">' . esc_html__( 'CSV indir', 'sales-team-commissions-for-woocommerce' ) . '</button></form></div></div></div>';
	}

	/* ------------------------------------------------------------------ Payouts */

	/** Payout requests: pay / cancel, CSV (masked or full IBAN). */
	public static function payouts() {
		require_once ERU_STC_PATH . 'includes/class-eru-stc-admin-tables.php';
		$table = new Eru_STC_Payouts_Table();
		$table->prepare_items();
		self::head( __( 'Ödemeler', 'sales-team-commissions-for-woocommerce' ) );
		$table->views();
		$table->display();
		echo '<div class="eru-stc-card"><h2>' . esc_html__( 'Toplu ödeme listesi (CSV)', 'sales-team-commissions-for-woocommerce' ) . '</h2>';
		self::form_open( 'eru_stc_payouts_export', 'eru_stc_export' );
		echo '<p class="eru-stc-inline"><select name="status"><option value="requested">' . esc_html__( 'Talep edilenler', 'sales-team-commissions-for-woocommerce' ) . '</option><option value="paid">' . esc_html__( 'Ödenenler', 'sales-team-commissions-for-woocommerce' ) . '</option><option value="">' . esc_html__( 'Tümü', 'sales-team-commissions-for-woocommerce' ) . '</option></select> <label><input type="checkbox" name="full_iban" value="1" data-eru-stc-confirm> ' . esc_html__( 'Tam IBAN ve hesap sahibi (havale için)', 'sales-team-commissions-for-woocommerce' ) . '</label> <label><input type="checkbox" name="full_iban_ack" value="1"> ' . esc_html__( 'Dosyayı güvenli saklayacağım ve işim bitince sileceğim', 'sales-team-commissions-for-woocommerce' ) . '</label>' . wp_nonce_field( 'eru_stc_full_iban', '_eru_stc_full', false, false ) . ' <button type="submit" class="button">' . esc_html__( 'CSV indir', 'sales-team-commissions-for-woocommerce' ) . '</button></p></form>';
		echo '<p class="description">' . esc_html__( 'Varsayılan dosya maskelidir ve bütün talepleri içerir. Tam IBAN yalnızca iki kutu da işaretlenince yazılır ve Raporlar ekranındaki denetim kaydına geçer; T.C. kimlik no her zaman maskelidir. Tutar, talebin tuttuğu komisyon satırlarının (düzeltmeler dahil) toplamıdır.', 'sales-team-commissions-for-woocommerce' ) . '</p></div></div>';
	}

	/* ------------------------------------------------------------------ Reports */

	/** Reports by month and by agent, CSV. */
	public static function reports() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
		$year  = isset( $_GET['year'] ) ? max( 2000, min( 2100, absint( $_GET['year'] ) ) ) : (int) wp_date( 'Y' );
		$month = isset( $_GET['month'] ) ? sanitize_text_field( wp_unslash( $_GET['month'] ) ) : '';
		$agent = isset( $_GET['agent_id'] ) ? absint( $_GET['agent_id'] ) : 0;
		// phpcs:enable
		list( $from, $to, $month ) = Eru_STC_Reports::month_range( $month );
		self::head( __( 'Raporlar', 'sales-team-commissions-for-woocommerce' ) );
		echo '<form method="get" class="eru-stc-filters"><input type="hidden" name="page" value="' . esc_attr( Eru_STC_Admin::PAGES['reports'] ) . '"><label>' . esc_html__( 'Yıl', 'sales-team-commissions-for-woocommerce' ) . ' <input type="number" name="year" min="2000" max="2100" value="' . esc_attr( (string) $year ) . '"></label> <label>' . esc_html__( 'Ay', 'sales-team-commissions-for-woocommerce' ) . ' <input type="month" name="month" value="' . esc_attr( $month ) . '"></label> ';
		self::agent_select( 'agent_id', $agent, __( 'Tüm temsilciler', 'sales-team-commissions-for-woocommerce' ) );
		echo ' <button class="button">' . esc_html__( 'Göster', 'sales-team-commissions-for-woocommerce' ) . '</button></form>';
		echo '<div class="eru-stc-columns"><div class="eru-stc-col"><h2>' . esc_html( sprintf( /* translators: %d: year */ __( '%d aylara göre', 'sales-team-commissions-for-woocommerce' ), $year ) ) . '</h2><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Ay', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Sipariş', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Toplam', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Ödendi', 'sales-team-commissions-for-woocommerce' ) . '</th></tr></thead><tbody>';
		foreach ( Eru_STC_Reports::by_month( $year, $agent ) as $m ) {
			echo '<tr><td><a href="' . esc_url( self::url( 'reports', array( 'year' => $year, 'month' => $m['month'], 'agent_id' => $agent ) ) ) . '">' . esc_html( $m['month'] ) . '</a></td><td>' . (int) $m['orders'] . '</td><td>' . self::money( $m['total'] ) . '</td><td>' . self::money( $m['paid'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped helper.
		}
		echo '</tbody></table></div><div class="eru-stc-col"><h2>' . esc_html( sprintf( /* translators: %s: month */ __( '%s temsilcilere göre', 'sales-team-commissions-for-woocommerce' ), $month ) ) . '</h2>';
		$rows = Eru_STC_Reports::by_agent( $from, $to, $agent );
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Temsilci', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Sipariş', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Toplam', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Beklemede', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Onaylı', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Ödendi', 'sales-team-commissions-for-woocommerce' ) . '</th></tr></thead><tbody>';
		if ( ! $rows ) {
			echo '<tr><td colspan="6">' . esc_html__( 'Bu ay komisyon yok.', 'sales-team-commissions-for-woocommerce' ) . '</td></tr>';
		}
		foreach ( $rows as $r ) {
			echo '<tr><td>' . Eru_STC_Table_Links::agent( $r['agent_id'] ) . '</td><td>' . (int) $r['orders'] . '</td><td>' . self::money( $r['total'] ) . '</td><td>' . self::money( $r['pending'] ) . '</td><td>' . self::money( $r['approved'] ) . '</td><td>' . self::money( $r['paid'] ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped helpers.
		}
		echo '</tbody></table>';
		self::form_open( 'eru_stc_report_export', 'eru_stc_export' );
		echo '<input type="hidden" name="month" value="' . esc_attr( $month ) . '"><input type="hidden" name="agent_id" value="' . esc_attr( (string) $agent ) . '"><p><button type="submit" class="button">' . esc_html__( 'Bu ayı CSV indir', 'sales-team-commissions-for-woocommerce' ) . '</button></p></form>';
		echo '<p class="description">' . esc_html__( 'Toplamlar iptal edilmeyen satırlardır; iade düzeltmeleri yazıldıkları aya sayılır. WP-CLI: wp eru-stc report --month=YYYY-AA', 'sales-team-commissions-for-woocommerce' ) . '</p></div></div>';
		$audit = Eru_STC_Reports::export_audit();
		echo '<h2>' . esc_html__( 'Tam IBAN dışa aktarımları', 'sales-team-commissions-for-woocommerce' ) . '</h2><p class="description">' . esc_html__( 'Son 50 kayıt: kim, ne zaman, hangi süzgeçle, kaç talep.', 'sales-team-commissions-for-woocommerce' ) . '</p>';
		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Tarih', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Kullanıcı', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Süzgeç', 'sales-team-commissions-for-woocommerce' ) . '</th><th>' . esc_html__( 'Talep', 'sales-team-commissions-for-woocommerce' ) . '</th></tr></thead><tbody>';
		if ( ! $audit ) {
			echo '<tr><td colspan="4">' . esc_html__( 'Henüz tam IBAN dışa aktarımı yok.', 'sales-team-commissions-for-woocommerce' ) . '</td></tr>';
		}
		foreach ( $audit as $e ) {
			$u = get_userdata( (int) $e['user'] );
			echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' H:i', (int) $e['time'] ) ) . '</td><td>' . esc_html( $u ? $u->display_name . ' (#' . $u->ID . ')' : '#' . (int) $e['user'] ) . '</td><td>' . esc_html( '' !== $e['status'] ? Eru_STC_Labels::payout_status( $e['status'] ) : __( 'Tümü', 'sales-team-commissions-for-woocommerce' ) ) . '</td><td>' . (int) $e['rows'] . '</td></tr>';
		}
		echo '</tbody></table></div>';
	}

	/* ------------------------------------------------------------------ Settings */

	/** Settings form (every setting key). */
	public static function settings() {
		$s  = Eru_STC_Settings::get();
		$cb = fn( $k, $label, $help = '' ) => '<label><input type="checkbox" name="' . esc_attr( $k ) . '" value="1"' . checked( $s[ $k ], 'yes', false ) . '> ' . esc_html( $label ) . '</label>' . ( $help ? '<p class="description">' . esc_html( $help ) . '</p>' : '' );
		$sel = function ( $k, array $opts ) use ( $s ) {
			$o = '<select name="' . esc_attr( $k ) . '" id="eru-stc-' . esc_attr( $k ) . '">';
			foreach ( $opts as $v => $l ) {
				$o .= '<option value="' . esc_attr( (string) $v ) . '"' . selected( (string) $s[ $k ], (string) $v, false ) . '>' . esc_html( $l ) . '</option>';
			}
			return $o . '</select>';
		};
		$row = fn( $label, $html, $for = '' ) => '<tr><th scope="row">' . ( $for ? '<label for="eru-stc-' . esc_attr( $for ) . '">' . esc_html( $label ) . '</label>' : esc_html( $label ) ) . '</th><td>' . $html . '</td></tr>';
		$num = fn( $k, $min, $max ) => '<input type="number" class="small-text" name="' . esc_attr( $k ) . '" id="eru-stc-' . esc_attr( $k ) . '" min="' . (int) $min . '" max="' . (int) $max . '" value="' . esc_attr( (string) $s[ $k ] ) . '">';
		self::head( __( 'Satış Ekibi ayarları', 'sales-team-commissions-for-woocommerce' ) );
		self::form_open( 'eru_stc_settings_save', 'eru_stc_settings' );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- rows are built from escaped parts above.
		echo '<h2>' . esc_html__( 'Komisyon', 'sales-team-commissions-for-woocommerce' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo $row( __( 'Modül', 'sales-team-commissions-for-woocommerce' ), $cb( 'enabled', __( 'Açık', 'sales-team-commissions-for-woocommerce' ), __( 'Kapalıyken komisyon yazılmaz, çerez yazılmaz, Hesabım paneli ve REST kapalıdır; onaylar duraklar.', 'sales-team-commissions-for-woocommerce' ) ) );
		echo $row( __( 'Komisyon yazılan durum', 'sales-team-commissions-for-woocommerce' ), $sel( 'trigger_status', array( 'completed' => __( 'Tamamlandı', 'sales-team-commissions-for-woocommerce' ), 'processing' => __( 'Hazırlanıyor (ödeme alındı)', 'sales-team-commissions-for-woocommerce' ) ) ), 'trigger_status' );
		echo $row( __( 'Kargo', 'sales-team-commissions-for-woocommerce' ), $cb( 'include_shipping', __( 'Kargo ücretini (KDV hariç) matraha ekle', 'sales-team-commissions-for-woocommerce' ), __( 'Matrah: indirim sonrası, KDV hariç satır tutarı. Kargo satırlara tutarlarına göre bölünür.', 'sales-team-commissions-for-woocommerce' ) ) );
		echo $row( __( 'Seviye sınırı', 'sales-team-commissions-for-woocommerce' ), $num( 'max_levels', 1, 5 ) . '<p class="description">' . esc_html__( '1 = yalnızca müşterinin temsilcisi; 2 = onun üst temsilcisi de (varsayılan). En fazla 5.', 'sales-team-commissions-for-woocommerce' ) . '</p>', 'max_levels' );
		echo $row( __( 'Bekleme süresi (gün)', 'sales-team-commissions-for-woocommerce' ), $num( 'hold_days', 0, 365 ) . '<p class="description">' . esc_html__( 'Komisyon bu süre dolunca onaylanır ve ödenebilir olur (günlük iş).', 'sales-team-commissions-for-woocommerce' ) . '</p>', 'hold_days' );
		echo $row( __( 'İade politikası', 'sales-team-commissions-for-woocommerce' ), $sel( 'refund_policy', array( 'prorata' => __( 'İade oranında azalt', 'sales-team-commissions-for-woocommerce' ), 'void' => __( 'İade edilen satırı iptal et', 'sales-team-commissions-for-woocommerce' ) ) ), 'refund_policy' );
		echo '</tbody></table><h2>' . esc_html__( 'Müşteri atama', 'sales-team-commissions-for-woocommerce' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo $row( __( 'Atama yöntemi', 'sales-team-commissions-for-woocommerce' ), $sel( 'assignment_mode', array( 'referral' => __( 'Davet linki (en son kod geçerli)', 'sales-team-commissions-for-woocommerce' ), 'first_touch' => __( 'Davet linki (ilk temsilciye kilitli)', 'sales-team-commissions-for-woocommerce' ), 'round_robin' => __( 'Sırayla dağıtım (link yoksa)', 'sales-team-commissions-for-woocommerce' ), 'manual' => __( 'Yalnızca elle', 'sales-team-commissions-for-woocommerce' ) ) ), 'assignment_mode' );
		echo $row( __( 'Çerez süresi (gün)', 'sales-team-commissions-for-woocommerce' ), $num( 'cookie_days', 1, 365 ), 'cookie_days' );
		echo '</tbody></table><h2>' . esc_html__( 'Ödeme', 'sales-team-commissions-for-woocommerce' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo $row( __( 'En düşük ödeme tutarı', 'sales-team-commissions-for-woocommerce' ), '<input type="text" class="small-text" name="min_payout" id="eru-stc-min_payout" inputmode="decimal" value="' . esc_attr( (string) $s['min_payout'] ) . '"> ' . esc_html( get_woocommerce_currency() ) . '<p class="description">' . esc_html__( '0 = en düşük tutar yok.', 'sales-team-commissions-for-woocommerce' ) . '</p>', 'min_payout' );
		echo $row( __( 'Ödeme bilgileri formu', 'sales-team-commissions-for-woocommerce' ), $cb( 'payout_details_form', __( 'Temsilciler Hesabım\'da hesap sahibi, IBAN ve T.C. kimlik no girer', 'sales-team-commissions-for-woocommerce' ), __( 'Değerler ERU_STC_ENCRYPTION_KEY ile şifrelenir; anahtar yoksa IBAN ve T.C. kimlik no alanları gizlenir.', 'sales-team-commissions-for-woocommerce' ) ) );
		echo '</tbody></table><h2>' . esc_html__( 'Temsilciler', 'sales-team-commissions-for-woocommerce' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		echo $row( __( 'Temsilci başvurusu', 'sales-team-commissions-for-woocommerce' ), $cb( 'self_registration', __( 'Müşteriler Hesabım\'dan başvurabilir (yönetici onaylar)', 'sales-team-commissions-for-woocommerce' ) ) );
		echo $row( __( 'Müşteri daveti', 'sales-team-commissions-for-woocommerce' ), $cb( 'customer_invites', __( 'Temsilciler kayıtlı müşterilere onay bağlantısı gönderebilir', 'sales-team-commissions-for-woocommerce' ), __( 'Müşteri bağlantıyı açıp onaylamadan hiçbir şey değişmez.', 'sales-team-commissions-for-woocommerce' ) ) );
		echo '</tbody></table><h2>' . esc_html__( 'Hesabım adresleri', 'sales-team-commissions-for-woocommerce' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		$labels = Eru_STC_Account::labels();
		foreach ( array_keys( Eru_STC_Settings::ENDPOINTS ) as $k ) {
			echo $row( $labels[ $k ], '<code>' . esc_html( untrailingslashit( wc_get_page_permalink( 'myaccount' ) ) ) . '/</code><input type="text" class="regular-text code" name="endpoint_' . esc_attr( $k ) . '" id="eru-stc-endpoint_' . esc_attr( $k ) . '" value="' . esc_attr( (string) $s[ 'endpoint_' . $k ] ) . '">', 'endpoint_' . $k );
		}
		echo '</tbody></table>';
		// phpcs:enable
		submit_button( __( 'Ayarları kaydet', 'sales-team-commissions-for-woocommerce' ) );
		echo '</form></div>';
	}
}

/** Escaped links used by the screens. */
class Eru_STC_Table_Links {

	/** @param int $id Order. @return string */
	public static function order( $id ) {
		$url = class_exists( \Automattic\WooCommerce\Utilities\OrderUtil::class ) ? \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( (int) $id ) : admin_url( 'post.php?post=' . (int) $id . '&action=edit' );
		return '<a href="' . esc_url( $url ) . '">#' . esc_html( (string) $id ) . '</a>';
	}

	/** @param int $id Agent. @return string */
	public static function agent( $id ) {
		$agent = Eru_STC_Agents::get( (int) $id );
		if ( ! $agent ) {
			return '#' . (int) $id;
		}
		return '<a href="' . esc_url( add_query_arg( array( 'page' => Eru_STC_Admin::PAGES['agents'], 'agent' => (int) $id ), admin_url( 'admin.php' ) ) ) . '">' . esc_html( Eru_STC_Agents::label( $agent ) ) . '</a>';
	}
}
