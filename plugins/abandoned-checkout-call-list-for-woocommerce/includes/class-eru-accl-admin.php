<?php
/** WooCommerce > Terk Edilen Ödemeler: follow-up list and settings. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_ACCL_Admin {

	const PER_PAGE = 30;

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_eru_accl_update', array( __CLASS__, 'handle_update' ) );
		add_action( 'admin_post_eru_accl_delete', array( __CLASS__, 'handle_delete' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Terk Edilen Ödemeler', 'abandoned-checkout-call-list-for-woocommerce' ), __( 'Arama Listesi', 'abandoned-checkout-call-list-for-woocommerce' ), 'manage_woocommerce', 'eru-accl', array( __CLASS__, 'render' ) );
	}

	public static function register(): void {
		// Saved through options.php: let the menu's capability (not manage_options) save it.
		add_filter( 'option_page_capability_eru_accl', fn() => 'manage_woocommerce' );
		register_setting(
			'eru_accl',
			Eru_ACCL_DB::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Eru_ACCL_DB::defaults(),
			)
		);
	}

	public static function sanitize( $in ): array {
		$in = is_array( $in ) ? $in : array();
		return array(
			'enabled'          => empty( $in['enabled'] ) ? 0 : 1,
			'consent_text'     => isset( $in['consent_text'] ) ? wp_kses( $in['consent_text'], array( 'a' => array( 'href' => true, 'target' => true, 'rel' => true ), 'strong' => array(), 'em' => array() ) ) : '',
			'require_checkbox' => empty( $in['require_checkbox'] ) ? 0 : 1,
			'retention_days'   => max( 1, min( 365, (int) ( $in['retention_days'] ?? 30 ) ) ),
		);
	}

	private static function guard( string $action ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'abandoned-checkout-call-list-for-woocommerce' ), 403 );
		}
		check_admin_referer( $action );
	}

	public static function handle_update(): void {
		self::guard( 'eru_accl_update' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id ) {
			Eru_ACCL_DB::update_follow_up(
				$id,
				isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '',
				isset( $_POST['note'] ) ? wp_unslash( $_POST['note'] ) : '' // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- sanitised in update_follow_up().
			);
		}
		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=eru-accl' ) );
		exit;
	}

	public static function handle_delete(): void {
		self::guard( 'eru_accl_delete' );
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		if ( $id ) {
			Eru_ACCL_DB::delete( $id );
		}
		wp_safe_redirect( admin_url( 'admin.php?page=eru-accl' ) );
		exit;
	}

	private static function counts(): array {
		global $wpdb;
		$t   = Eru_ACCL_DB::table();
		$out = array_fill_keys( array_keys( Eru_ACCL_DB::statuses() ), 0 );
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) c FROM {$t} GROUP BY status", ARRAY_A ) as $r ) { // phpcs:ignore WordPress.DB
			$out[ $r['status'] ] = (int) $r['c'];
		}
		return $out;
	}


	/** The classic [woocommerce_checkout] shortcode is required; warn when the checkout page uses the block. */
	private static function block_checkout_notice(): void {
		$id   = wc_get_page_id( 'checkout' );
		$page = $id > 0 ? get_post( $id ) : null;
		if ( $page && has_block( 'woocommerce/checkout', $page ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Ödeme sayfanız blok tabanlı. Bu eklenti klasik ödeme sayfasını ([woocommerce_checkout] kısa kodu) gerektirir; blok ödemede bu özellik çalışmaz.', 'abandoned-checkout-call-list-for-woocommerce' ) . '</p></div>';
		}
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) && 'settings' === $_GET['tab'] ? 'settings' : 'list'; // phpcs:ignore WordPress.Security.NonceVerification
		$base = admin_url( 'admin.php?page=eru-accl' );
		echo '<div class="wrap"><h1>' . esc_html__( 'Terk Edilen Ödemeler – Arama Listesi', 'abandoned-checkout-call-list-for-woocommerce' ) . '</h1>';
		self::block_checkout_notice();
		echo '<nav class="nav-tab-wrapper"><a class="nav-tab ' . ( 'list' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $base ) . '">' . esc_html__( 'Liste', 'abandoned-checkout-call-list-for-woocommerce' ) . '</a><a class="nav-tab ' . ( 'settings' === $tab ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $base . '&tab=settings' ) . '">' . esc_html__( 'Ayarlar', 'abandoned-checkout-call-list-for-woocommerce' ) . '</a></nav>';
		'settings' === $tab ? self::render_settings() : self::render_list();
		echo '</div>';
	}

	private static function render_settings(): void {
		$s   = Eru_ACCL_DB::settings();
		$opt = Eru_ACCL_DB::OPTION;
		?>
		<form method="post" action="options.php">
			<?php settings_fields( 'eru_accl' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><?php esc_html_e( 'Etkin', 'abandoned-checkout-call-list-for-woocommerce' ); ?></th><td>
					<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[enabled]" value="1" <?php checked( $s['enabled'], 1 ); ?>> <?php esc_html_e( 'Ödeme sayfasında onayla kayıt almayı başlat', 'abandoned-checkout-call-list-for-woocommerce' ); ?></label>
					<?php if ( ! Eru_ACCL_Capture::is_ready() && $s['enabled'] ) : ?>
						<p class="description" style="color:#b32d2e"><?php esc_html_e( 'Onay metni boş ve mağazanın Gizlilik Politikası sayfası seçili değil: kayıt alınmaz. Aşağıya onay metni yazın veya Ayarlar → Gizlilik\'ten sayfa seçin.', 'abandoned-checkout-call-list-for-woocommerce' ); ?></p>
					<?php endif; ?>
				</td></tr>
				<tr><th><label for="eru-accl-consent-text"><?php esc_html_e( 'Onay metni', 'abandoned-checkout-call-list-for-woocommerce' ); ?></label></th><td>
					<textarea id="eru-accl-consent-text" name="<?php echo esc_attr( $opt ); ?>[consent_text]" rows="4" class="large-text"><?php echo esc_textarea( $s['consent_text'] ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Boş bırakılırsa Gizlilik Politikası bağlantılı varsayılan metin kullanılır. Basit bağlantı (a) kullanabilirsiniz.', 'abandoned-checkout-call-list-for-woocommerce' ); ?></p>
				</td></tr>
				<tr><th><?php esc_html_e( 'Onay kutusu', 'abandoned-checkout-call-list-for-woocommerce' ); ?></th><td>
					<label><input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[require_checkbox]" value="1" <?php checked( $s['require_checkbox'], 1 ); ?>> <?php esc_html_e( 'Ziyaretçi onay kutusunu işaretlemeden kayıt alma (önerilir)', 'abandoned-checkout-call-list-for-woocommerce' ); ?></label>
				</td></tr>
				<tr><th><label for="eru-accl-days"><?php esc_html_e( 'Saklama süresi (gün)', 'abandoned-checkout-call-list-for-woocommerce' ); ?></label></th><td>
					<input type="number" id="eru-accl-days" min="1" max="365" name="<?php echo esc_attr( $opt ); ?>[retention_days]" value="<?php echo esc_attr( (string) $s['retention_days'] ); ?>" class="small-text">
					<p class="description"><?php esc_html_e( 'Bu süre içinde güncellenmeyen kayıtlar günlük olarak silinir (varsayılan 30).', 'abandoned-checkout-call-list-for-woocommerce' ); ?></p>
				</td></tr>
			</table>
			<?php submit_button(); ?>
		</form>
		<?php
	}

	private static function render_list(): void {
		global $wpdb;
		$t      = Eru_ACCL_DB::table();
		$labels = Eru_ACCL_DB::statuses();
		$filter = isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$filter = isset( $labels[ $filter ] ) ? $filter : '';
		$paged  = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 ); // phpcs:ignore WordPress.Security.NonceVerification
		$counts = self::counts();
		$base   = admin_url( 'admin.php?page=eru-accl' );

		$where = $filter ? $wpdb->prepare( 'WHERE status = %s', $filter ) : '';
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} {$where}" ); // phpcs:ignore WordPress.DB
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} {$where} ORDER BY updated_gmt DESC, id DESC LIMIT %d OFFSET %d", self::PER_PAGE, ( $paged - 1 ) * self::PER_PAGE ), ARRAY_A ); // phpcs:ignore WordPress.DB

		$sum   = array_sum( $counts );
		echo '<ul class="subsubsub" style="margin:12px 0"><li><a href="' . esc_url( $base ) . '"' . ( '' === $filter ? ' class="current"' : '' ) . '>' . esc_html__( 'Tümü', 'abandoned-checkout-call-list-for-woocommerce' ) . ' <span class="count">(' . (int) $sum . ')</span></a></li>';
		foreach ( $labels as $k => $l ) {
			echo ' | <li><a href="' . esc_url( add_query_arg( 'status', $k, $base ) ) . '"' . ( $filter === $k ? ' class="current"' : '' ) . '>' . esc_html( $l ) . ' <span class="count">(' . (int) $counts[ $k ] . ')</span></a></li>';
		}
		echo '</ul><br class="clear">';
		if ( ! Eru_ACCL_Capture::is_ready() ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'Kayıt alma kapalı. Ayarlar sekmesinden etkinleştirin; onay metni gösterilmeden hiçbir veri alınmaz.', 'abandoned-checkout-call-list-for-woocommerce' ) . '</p></div>';
		}
		?>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Müşteri', 'abandoned-checkout-call-list-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Sepet', 'abandoned-checkout-call-list-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Son hareket', 'abandoned-checkout-call-list-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Takip', 'abandoned-checkout-call-list-for-woocommerce' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $rows ) : ?>
				<tr><td colspan="4"><?php esc_html_e( 'Kayıt yok.', 'abandoned-checkout-call-list-for-woocommerce' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $rows as $r ) : ?>
				<?php $cart = (array) json_decode( (string) $r['cart'], true ); ?>
				<tr>
					<td>
						<strong><?php echo esc_html( $r['first_name'] ? $r['first_name'] : '—' ); ?></strong><br>
						<?php if ( $r['phone'] ) : ?><a href="tel:<?php echo esc_attr( $r['phone'] ); ?>"><?php echo esc_html( $r['phone'] ); ?></a><br><?php endif; ?>
						<?php if ( $r['email'] ) : ?><a href="mailto:<?php echo esc_attr( $r['email'] ); ?>"><?php echo esc_html( $r['email'] ); ?></a><?php endif; ?>
					</td>
					<td>
						<?php foreach ( $cart as $line ) : ?>
							<?php echo esc_html( ( $line['name'] ?? '' ) . ' × ' . (int) ( $line['qty'] ?? 0 ) ); ?><br>
						<?php endforeach; ?>
						<strong><?php echo wp_kses_post( wc_price( (float) $r['cart_total'] ) ); ?></strong>
					</td>
					<td>
						<?php echo esc_html( get_date_from_gmt( $r['updated_gmt'], 'd.m.Y H:i' ) ); ?>
						<?php if ( (int) $r['order_id'] ) : ?><br><?php echo wp_kses_post( sprintf( /* translators: %s: order link */ __( 'Sipariş: %s', 'abandoned-checkout-call-list-for-woocommerce' ), '<a href="' . esc_url( ( ( $o = wc_get_order( (int) $r['order_id'] ) ) ? $o->get_edit_order_url() : '#' ) ) . '">#' . (int) $r['order_id'] . '</a>' ) ); ?><?php endif; ?>
					</td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
							<input type="hidden" name="action" value="eru_accl_update">
							<input type="hidden" name="id" value="<?php echo esc_attr( (string) $r['id'] ); ?>">
							<?php wp_nonce_field( 'eru_accl_update' ); ?>
							<select name="status">
								<?php foreach ( $labels as $k => $l ) : ?>
									<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $r['status'], $k ); ?>><?php echo esc_html( $l ); ?></option>
								<?php endforeach; ?>
							</select>
							<input type="text" name="note" value="<?php echo esc_attr( (string) $r['note'] ); ?>" placeholder="<?php esc_attr_e( 'Not', 'abandoned-checkout-call-list-for-woocommerce' ); ?>" style="min-width:160px">
							<button class="button button-small" type="submit"><?php esc_html_e( 'Kaydet', 'abandoned-checkout-call-list-for-woocommerce' ); ?></button>
						</form>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:4px" onsubmit="return confirm('<?php echo esc_js( __( 'Kayıt silinsin mi?', 'abandoned-checkout-call-list-for-woocommerce' ) ); ?>');">
							<input type="hidden" name="action" value="eru_accl_delete">
							<input type="hidden" name="id" value="<?php echo esc_attr( (string) $r['id'] ); ?>">
							<?php wp_nonce_field( 'eru_accl_delete' ); ?>
							<button class="button-link button-link-delete" type="submit"><?php esc_html_e( 'Sil', 'abandoned-checkout-call-list-for-woocommerce' ); ?></button>
						</form>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$pages = (int) ceil( $total / self::PER_PAGE );
		if ( $pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( 'paged', '%#%', $filter ? add_query_arg( 'status', $filter, $base ) : $base ), 'format' => '', 'current' => $paged, 'total' => $pages ) ) ) . '</div></div>';
		}
	}
}
