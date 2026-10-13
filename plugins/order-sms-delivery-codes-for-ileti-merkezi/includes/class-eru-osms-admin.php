<?php
/**
 * WooCommerce → SMS (İleti Merkezi): Ayarlar · Kurallar · Gönderim kaydı.
 *
 * @package order-sms-delivery-codes-for-ileti-merkezi
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OSMS_Admin {

	const SLUG = 'eru-osms';
	const CAP  = 'manage_woocommerce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_eru_osms_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_eru_osms_save_rules', array( __CLASS__, 'save_rules' ) );
	}

	public static function tabs(): array {
		return array(
			'settings' => __( 'Ayarlar', 'order-sms-delivery-codes-for-ileti-merkezi' ),
			'rules'    => __( 'Kurallar', 'order-sms-delivery-codes-for-ileti-merkezi' ),
			'log'      => __( 'Gönderim kaydı', 'order-sms-delivery-codes-for-ileti-merkezi' ),
		);
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'SMS (İleti Merkezi)', 'order-sms-delivery-codes-for-ileti-merkezi' ), __( 'SMS (İleti Merkezi)', 'order-sms-delivery-codes-for-ileti-merkezi' ), self::CAP, self::SLUG, array( __CLASS__, 'page' ) );
	}

	private static function url( string $tab, array $extra = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $extra ), admin_url( 'admin.php' ) );
	}

	private static function back( string $tab, string $msg, string $type = 'success' ): void {
		set_transient( 'eru_osms_panel_' . get_current_user_id(), array( 'msg' => $msg, 'type' => $type ), 60 );
		wp_safe_redirect( self::url( $tab ) );
		exit;
	}

	private static function guard( string $nonce ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Bu işlem için yetkiniz yok.', 'order-sms-delivery-codes-for-ileti-merkezi' ) );
		}
		check_admin_referer( $nonce );
	}

	/* ---- handlers ---- */

	public static function save_settings(): void {
		self::guard( 'eru_osms_save_settings' );
		// phpcs:disable WordPress.Security.NonceVerification -- guard() verified the nonce.
		$p = wp_unslash( $_POST );
		// phpcs:enable
		Eru_OSMS_Settings::save(
			array(
				'enabled'       => ! empty( $p['enabled'] ),
				'test_mode'     => ! empty( $p['test_mode'] ),
				'sender'        => (string) ( $p['sender'] ?? '' ),
				'support_phone' => (string) ( $p['support_phone'] ?? '' ),
				'support_email' => (string) ( $p['support_email'] ?? '' ),
				'max_attempts'  => (int) ( $p['max_attempts'] ?? 5 ),
			)
		);
		Eru_OSMS_Settings::set_credentials( (string) ( $p['api_key'] ?? '' ), (string) ( $p['api_hash'] ?? '' ), ! empty( $p['clear_credentials'] ) );
		self::back( 'settings', __( 'Ayarlar kaydedildi.', 'order-sms-delivery-codes-for-ileti-merkezi' ) );
	}

	public static function save_rules(): void {
		self::guard( 'eru_osms_save_rules' );
		$rows = isset( $_POST['rules'] ) && is_array( $_POST['rules'] ) ? wp_unslash( $_POST['rules'] ) : array(); // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- guard() verified; sanitize_rules() cleans every field.
		Eru_OSMS_Settings::save_rules( (array) $rows );
		self::back( 'rules', __( 'Kurallar kaydedildi.', 'order-sms-delivery-codes-for-ileti-merkezi' ) );
	}

	/* ---- page ---- */

	public static function page(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification
		if ( ! isset( self::tabs()[ $tab ] ) ) {
			$tab = 'settings';
		}
		echo '<div class="wrap eru-osms-panel"><h1>' . esc_html__( 'SMS (İleti Merkezi)', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</h1>';
		self::status_line();
		$n = get_transient( 'eru_osms_panel_' . get_current_user_id() );
		if ( $n ) {
			delete_transient( 'eru_osms_panel_' . get_current_user_id() );
			echo '<div class="notice notice-' . esc_attr( $n['type'] ) . ' is-dismissible eru-keep"><p>' . esc_html( $n['msg'] ) . '</p></div>';
		}
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::tabs() as $k => $label ) {
			echo '<a class="nav-tab' . ( $k === $tab ? ' nav-tab-active' : '' ) . '" href="' . esc_url( self::url( $k ) ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
		call_user_func( array( __CLASS__, 'tab_' . $tab ) );
		echo '</div>';
	}

	private static function status_line(): void {
		$s = Eru_OSMS_Settings::get();
		if ( ! (int) $s['enabled'] ) {
			$st = array( 'off', __( 'Kapalı: SMS gönderilmiyor, kod üretilmiyor, teslimat kilidi devre dışı.', 'order-sms-delivery-codes-for-ileti-merkezi' ) );
		} elseif ( (int) $s['test_mode'] ) {
			$st = array( 'test', __( 'Açık · test modu: hiçbir numaraya SMS gönderilmez, yalnızca kaydedilir.', 'order-sms-delivery-codes-for-ileti-merkezi' ) );
		} else {
			$st = array( 'live', __( 'Açık · canlı: müşterilere SMS gidiyor.', 'order-sms-delivery-codes-for-ileti-merkezi' ) );
		}
		echo '<p class="eru-osms-state eru-osms-state--' . esc_attr( $st[0] ) . '"><span></span>' . esc_html( $st[1] );
		if ( ! Eru_OSMS_Settings::has_credentials() ) {
			echo ' · <strong>' . esc_html__( 'İleti Merkezi kimlik bilgileri eksik', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</strong>';
		}
		echo '</p>';
	}

	private static function tab_settings(): void {
		$s    = Eru_OSMS_Settings::get();
		$cred = Eru_OSMS_Settings::credentials();
		$saved_ph = __( '•••••••• kayıtlı; değiştirmek için yazın', 'order-sms-delivery-codes-for-ileti-merkezi' );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'eru_osms_save_settings' ); ?>
			<input type="hidden" name="action" value="eru_osms_save_settings">
			<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row"><?php esc_html_e( 'Ana şalter', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></th><td><label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'], 1 ); ?>> <?php esc_html_e( 'Kurallar çalışsın (SMS ve teslimat kodu)', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></label></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Test modu', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></th><td><label><input type="checkbox" name="test_mode" value="1" <?php checked( $s['test_mode'], 1 ); ?>> <?php esc_html_e( 'Gönderme, yalnızca kaydet', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></label>
					<p class="description"><?php esc_html_e( 'Test modunda SMS metni oluşturulur ve Gönderim kaydına yazılır; hiçbir numaraya (kendi numaranız dahil) İleti Merkezi\'ne istek gitmez. Teslimat kodu kuralları siparişi kilitlemez. Gerçek bir deneme için test modunu kapatıp kendi numaranızla verilmiş bir test siparişi kullanın.', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></p></td></tr>
				<tr><th colspan="2"><h2><?php esc_html_e( 'İleti Merkezi hesabı', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></h2></th></tr>
				<tr><th scope="row"><label for="eru-osms-key"><?php esc_html_e( 'API anahtarı (key)', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></label></th><td><input type="password" id="eru-osms-key" name="api_key" class="regular-text" autocomplete="new-password" value="" placeholder="<?php echo esc_attr( '' !== $cred['key'] ? $saved_ph : '' ); ?>"></td></tr>
				<tr><th scope="row"><label for="eru-osms-hash"><?php esc_html_e( 'API hash', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></label></th><td><input type="password" id="eru-osms-hash" name="api_hash" class="regular-text" autocomplete="new-password" value="" placeholder="<?php echo esc_attr( '' !== $cred['hash'] ? $saved_ph : '' ); ?>">
					<?php if ( '' !== $cred['key'] || '' !== $cred['hash'] ) : ?>
						<p><label><input type="checkbox" name="clear_credentials" value="1"> <?php esc_html_e( 'Kayıtlı kimlik bilgilerini sil', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></label></p>
					<?php endif; ?>
					<p class="description"><?php esc_html_e( 'İleti Merkezi panelindeki API anahtarı ve hash değeri. Ayrı bir seçenekte otomatik yüklenmeden saklanır, ekranda hiçbir zaman gösterilmez ve yalnızca https://api.iletimerkezi.com adresine gönderilir.', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></p></td></tr>
				<tr><th scope="row"><label for="eru-osms-sender"><?php esc_html_e( 'Gönderici başlığı', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></label></th><td><input type="text" id="eru-osms-sender" name="sender" maxlength="11" class="regular-text" value="<?php echo esc_attr( $s['sender'] ); ?>">
					<p class="description"><?php esc_html_e( 'İleti Merkezi hesabınızda onaylı başlık (en fazla 11 karakter).', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></p></td></tr>
				<tr><th colspan="2"><h2><?php esc_html_e( 'Şablon değişkenleri ve teslimat kodu', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></h2></th></tr>
				<tr><th scope="row"><label for="eru-osms-sp"><?php esc_html_e( 'Destek telefonu {destek_tel}', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></label></th><td><input type="text" id="eru-osms-sp" name="support_phone" class="regular-text" value="<?php echo esc_attr( $s['support_phone'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="eru-osms-se"><?php esc_html_e( 'Destek e-postası {destek_eposta}', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></label></th><td><input type="email" id="eru-osms-se" name="support_email" class="regular-text" value="<?php echo esc_attr( $s['support_email'] ); ?>"></td></tr>
				<tr><th scope="row"><label for="eru-osms-max"><?php esc_html_e( 'Kod deneme hakkı', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></label></th><td><input type="number" id="eru-osms-max" name="max_attempts" min="1" max="10" value="<?php echo esc_attr( (string) $s['max_attempts'] ); ?>" class="small-text"></td></tr>
			</tbody></table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	/** Starter rules shown (inactive) while no rule has ever been saved. */
	private static function suggestions(): array {
		return array(
			array( 'id' => '', 'name' => __( 'Sipariş alındı', 'order-sms-delivery-codes-for-ileti-merkezi' ), 'active' => 0, 'from' => '*', 'to' => 'processing', 'method' => '*', 'verify_target' => '', 'text' => __( 'Sayın {ad}, {siparis_no} numaralı siparişiniz alındı. Teşekkür ederiz. {magaza}', 'order-sms-delivery-codes-for-ileti-merkezi' ) ),
			array( 'id' => '', 'name' => __( 'Kargoya verildi', 'order-sms-delivery-codes-for-ileti-merkezi' ), 'active' => 0, 'from' => '*', 'to' => 'completed', 'method' => '*', 'verify_target' => '', 'text' => __( 'Sayın {ad}, {siparis_no} numaralı siparişiniz kargoya verildi. {kargo_firmasi} takip no: {kargo_takip} {takip_linki}', 'order-sms-delivery-codes-for-ileti-merkezi' ) ),
			array( 'id' => '', 'name' => __( 'Teslimat kodu', 'order-sms-delivery-codes-for-ileti-merkezi' ), 'active' => 0, 'from' => '*', 'to' => 'processing', 'method' => 'local_pickup', 'verify_target' => 'completed', 'text' => __( 'Sayın {ad}, {siparis_no} numaralı siparişinizi teslim alırken bu kodu yalnızca görevliye söyleyin: {teslimat_kodu}', 'order-sms-delivery-codes-for-ileti-merkezi' ) ),
		);
	}

	private static function status_options( string $selected, bool $any, bool $none = false ): string {
		$html = '';
		if ( $any ) {
			$html .= '<option value="*"' . selected( $selected, '*', false ) . '>' . esc_html__( 'Herhangi', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</option>';
		}
		if ( $none ) {
			$html .= '<option value=""' . selected( $selected, '', false ) . '>' . esc_html__( '— (kodsuz kural)', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</option>';
		}
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$s     = Eru_OSMS_Decision::status( $slug );
			$html .= '<option value="' . esc_attr( $s ) . '"' . selected( $selected, $s, false ) . '>' . esc_html( $label ) . '</option>';
		}
		return $html;
	}

	private static function method_options( string $selected ): string {
		$html = '<option value="*"' . selected( $selected, '*', false ) . '>' . esc_html__( 'Tüm gönderim yöntemleri', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</option>';
		foreach ( WC()->shipping()->get_shipping_methods() as $id => $m ) {
			$html .= '<option value="' . esc_attr( $id ) . '"' . selected( $selected, (string) $id, false ) . '>' . esc_html( $m->get_method_title() . ' (' . $id . ')' ) . '</option>';
		}
		return $html;
	}

	private static function tab_rules(): void {
		$saved = get_option( Eru_OSMS_Settings::RULES_OPTION, null );
		$rows  = is_array( $saved ) ? Eru_OSMS_Settings::rules() : self::suggestions();
		for ( $i = 0; $i < 2; $i++ ) {
			$rows[] = array( 'id' => '', 'name' => '', 'active' => 0, 'from' => '*', 'to' => '', 'method' => '*', 'verify_target' => '', 'text' => '' );
		}
		$vars = implode( ' ', array_map( fn( $v ) => '{' . $v . '}', Eru_OSMS_Order::variable_names() ) );
		?>
		<p><?php esc_html_e( 'Sipariş durumu "Önceki" durumdan "Yeni" duruma geçtiğinde, eşleşen her etkin kural müşteriye bir SMS gönderir (aynı kural aynı siparişe bir kez). Metinde {teslimat_kodu} geçen kural teslimat kodu kuralıdır: müşteriye tek kullanımlık kod gider ve sipariş, kod sipariş ekranında doğrulanmadan "Doğrulanınca" durumuna (ve Tamamlandı\'ya) personel tarafından alınamaz.', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></p>
		<?php /* translators: %s: placeholder list */ ?>
		<p class="description"><?php echo esc_html( sprintf( __( 'Değişkenler: %s', 'order-sms-delivery-codes-for-ileti-merkezi' ), $vars ) ); ?></p>
		<?php if ( ! is_array( $saved ) ) : ?>
			<div class="notice notice-info inline eru-keep"><p><?php esc_html_e( 'Örnek kurallar kapalı olarak listelendi. Düzenleyip "Etkin" işaretleyerek kaydedin; kaydetmeden hiçbir kural çalışmaz.', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'eru_osms_save_rules' ); ?>
			<input type="hidden" name="action" value="eru_osms_save_rules">
			<table class="widefat striped eru-osms-rules"><thead><tr>
				<th><?php esc_html_e( 'Etkin', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></th>
				<th><?php esc_html_e( 'Kural', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></th>
				<th><?php esc_html_e( 'Önceki → Yeni durum', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></th>
				<th><?php esc_html_e( 'SMS metni', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></th>
				<th><?php esc_html_e( 'Doğrulanınca (kodlu kural)', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></th>
			</tr></thead><tbody>
			<?php foreach ( $rows as $i => $r ) : $n = 'rules[' . $i . ']'; ?>
				<tr>
					<td><input type="hidden" name="<?php echo esc_attr( $n . '[id]' ); ?>" value="<?php echo esc_attr( (string) $r['id'] ); ?>"><input type="checkbox" name="<?php echo esc_attr( $n . '[active]' ); ?>" value="1" <?php checked( (int) $r['active'], 1 ); ?> aria-label="<?php esc_attr_e( 'Etkin', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?>"></td>
					<td><input type="text" name="<?php echo esc_attr( $n . '[name]' ); ?>" value="<?php echo esc_attr( (string) $r['name'] ); ?>" placeholder="<?php esc_attr_e( 'Kural adı', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?>"><br>
						<select name="<?php echo esc_attr( $n . '[method]' ); ?>"><?php echo self::method_options( (string) $r['method'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in method_options(). ?></select></td>
					<td><select name="<?php echo esc_attr( $n . '[from]' ); ?>"><?php echo self::status_options( (string) $r['from'], true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select> →<br>
						<select name="<?php echo esc_attr( $n . '[to]' ); ?>"><option value=""><?php esc_html_e( 'Seçin', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></option><?php echo self::status_options( (string) $r['to'], false ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></td>
					<td class="eru-osms-text-cell"><textarea class="large-text eru-osms-counted" rows="3" name="<?php echo esc_attr( $n . '[text]' ); ?>"><?php echo esc_textarea( (string) $r['text'] ); ?></textarea><p class="eru-osms-counter"></p></td>
					<td><select name="<?php echo esc_attr( $n . '[verify_target]' ); ?>"><?php echo self::status_options( (string) $r['verify_target'], false, true ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></select></td>
				</tr>
			<?php endforeach; ?>
			</tbody></table>
			<p class="description"><?php esc_html_e( 'Kuralı silmek için metnini boşaltıp kaydedin. Boş satırlar her kayıtta yeniden eklenir.', 'order-sms-delivery-codes-for-ileti-merkezi' ); ?></p>
			<?php submit_button( __( 'Kuralları kaydet', 'order-sms-delivery-codes-for-ileti-merkezi' ) ); ?>
		</form>
		<?php
	}

	private static function tab_log(): void {
		// phpcs:disable WordPress.Security.NonceVerification -- read-only filters.
		$status = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '';
		$page   = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$labels = Eru_OSMS_Metabox::status_labels();
		if ( '' !== $status && ! isset( $labels[ $status ] ) ) {
			$status = '';
		}
		$res = Eru_OSMS_Log::page( $status, $page );
		echo '<form method="get" class="eru-osms-filter"><input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '"><input type="hidden" name="tab" value="log"><select name="status"><option value="">' . esc_html__( 'Tüm durumlar', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</option>';
		foreach ( $labels as $k => $v ) {
			echo '<option value="' . esc_attr( $k ) . '"' . selected( $status, $k, false ) . '>' . esc_html( $v ) . '</option>';
		}
		echo '</select> <button class="button">' . esc_html__( 'Süz', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</button></form>';

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Tarih', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</th><th>' . esc_html__( 'Sipariş', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</th><th>' . esc_html__( 'Telefon', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</th><th>' . esc_html__( 'Metin', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</th><th>' . esc_html__( 'Durum', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</th></tr></thead><tbody>';
		if ( ! $res['rows'] ) {
			echo '<tr><td colspan="5">' . esc_html__( 'Kayıt yok.', 'order-sms-delivery-codes-for-ileti-merkezi' ) . '</td></tr>';
		}
		foreach ( $res['rows'] as $l ) {
			$o    = (int) $l['order_id'] ? wc_get_order( (int) $l['order_id'] ) : null;
			$link = $o ? '<a href="' . esc_url( $o->get_edit_order_url() ) . '">#' . esc_html( $o->get_order_number() ) . '</a>' : '—';
			echo '<tr><td>' . esc_html( mysql2date( 'd.m.Y H:i', $l['created_at'] ) ) . '</td><td>' . $link . '</td><td>' . esc_html( Eru_OSMS_Text::mask_phone( (string) $l['phone'] ) ) . '</td><td>' . esc_html( Eru_OSMS_Text::masked( $l['text'] ) ) . '<br><small>' . esc_html( sprintf( '%d SMS', (int) $l['segments'] ) ) . '</small></td><td><span class="eru-osms-badge eru-osms-badge--' . esc_attr( $l['status'] ) . '">' . esc_html( $labels[ $l['status'] ] ?? $l['status'] ) . '</span>' . ( '' !== (string) $l['error'] && 'sent' !== $l['status'] ? '<br><small>' . esc_html( $l['error'] ) . '</small>' : '' ) . '</td></tr>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $link built from escaped parts.
		}
		echo '</tbody></table>';
		$pages = (int) ceil( $res['total'] / 50 );
		if ( $pages > 1 ) {
			echo '<p class="eru-osms-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages ) ) ) . '</p>';
		}
	}
}
