<?php
/**
 * WooCommerce → Sipariş Durumları: list with drag-to-reorder, add/edit form, delete with migration, groups, settings.
 *
 * @package custom-order-statuses-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_COS_Admin {

	const PAGE = 'eru-cos';
	const CAP  = 'manage_woocommerce';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		foreach ( array( 'save', 'delete', 'groups', 'settings' ) as $a ) {
			add_action( 'admin_post_eru_cos_' . $a, array( __CLASS__, 'handle_' . $a ) );
		}
		add_action( 'wp_ajax_eru_cos_reorder', array( __CLASS__, 'ajax_reorder' ) );
		add_action( 'admin_notices', array( __CLASS__, 'background_notice' ) );
		add_action( 'after_plugin_row_' . plugin_basename( ERU_COS_FILE ), array( __CLASS__, 'deactivation_warning' ), 10, 0 );
		add_filter( 'plugin_action_links_' . plugin_basename( ERU_COS_FILE ), array( __CLASS__, 'confirm_deactivate' ), 20 );
	}

	/** One-time notice left by a stopped background deletion. */
	public static function background_notice(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$msg = get_transient( Eru_COS_Statuses::NOTICE );
		if ( is_string( $msg ) && '' !== $msg ) {
			delete_transient( Eru_COS_Statuses::NOTICE );
			echo '<div class="notice notice-error eru-keep"><p>' . esc_html( $msg ) . '</p></div>';
		}
	}

	/** Custom statuses that still hold orders: label => count. */
	private static function used_statuses(): array {
		$out = array();
		foreach ( Eru_COS_Statuses::raw() as $slug => $s ) {
			$n = Eru_COS_Statuses::order_count( $slug );
			if ( $n > 0 ) {
				$out[ $s['label'] ] = $n;
			}
		}
		return $out;
	}

	private static function used_text( array $used ): string {
		$parts = array();
		foreach ( $used as $label => $n ) {
			$parts[] = $label . ' (' . number_format_i18n( $n ) . ')';
		}
		/* translators: %s: list of statuses with order counts */
		return sprintf( __( 'Bu durumlardaki siparişler eklenti kapalıyken WooCommerce tarafından tanınmaz ve sipariş listesinde görünmez: %s. Devre dışı bırakmadan önce siparişleri başka bir duruma taşıyın.', 'custom-order-statuses-for-woocommerce' ), implode( ', ', $parts ) );
	}

	/**
	 * Warning under the plugin row on Plugins. A notice after deactivation is impossible (the plugin's code no
	 * longer runs), so the warning is shown before it, here and as a confirm on the Deactivate link.
	 */
	public static function deactivation_warning(): void {
		if ( ! current_user_can( self::CAP ) || ! is_plugin_active( plugin_basename( ERU_COS_FILE ) ) ) {
			return;
		}
		$used = self::used_statuses();
		if ( $used ) {
			echo '<tr class="plugin-update-tr active"><td colspan="4" class="plugin-update colspanchange"><div class="notice inline notice-warning notice-alt"><p>' . esc_html( self::used_text( $used ) ) . '</p></div></td></tr>';
		}
	}

	public static function confirm_deactivate( $links ) {
		if ( isset( $links['deactivate'] ) && current_user_can( self::CAP ) ) {
			$used = self::used_statuses();
			if ( $used ) {
				$links['deactivate'] = str_replace( '<a ', '<a onclick="return confirm(\'' . esc_attr( esc_js( self::used_text( $used ) ) ) . '\');" ', $links['deactivate'] );
			}
		}
		return $links;
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Sipariş Durumları', 'custom-order-statuses-for-woocommerce' ), __( 'Sipariş Durumları', 'custom-order-statuses-for-woocommerce' ), self::CAP, self::PAGE, array( __CLASS__, 'render' ) );
	}

	private static function url( array $args = array() ): string {
		return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
	}

	public static function assets( $hook ): void {
		if ( 'woocommerce_page_' . self::PAGE !== $hook ) {
			return;
		}
		wp_enqueue_style( 'eru-cos-admin', ERU_COS_URL . 'assets/admin.css', array(), ERU_COS_VERSION );
		wp_enqueue_script( 'eru-cos-admin', ERU_COS_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), ERU_COS_VERSION, true );
		wp_localize_script(
			'eru-cos-admin',
			'eruCos',
			array(
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'eru_cos_reorder' ),
				'max'   => Eru_COS_Statuses::MAX_SLUG,
				'saved' => __( 'Sıra kaydedildi.', 'custom-order-statuses-for-woocommerce' ),
				'error' => __( 'Sıra kaydedilemedi.', 'custom-order-statuses-for-woocommerce' ),
			)
		);
	}

	/* ---------- handlers ---------- */

	private static function guard( string $action ): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'custom-order-statuses-for-woocommerce' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( array $args, ?WP_Error $error = null ): void {
		if ( $error ) {
			set_transient( 'eru_cos_error_' . get_current_user_id(), $error->get_error_message(), 60 );
			$args['msg'] = 'error';
		}
		wp_safe_redirect( self::url( $args ) );
		exit;
	}

	public static function handle_save(): void {
		self::guard( 'eru_cos_save' );
		$in   = isset( $_POST['eru_cos'] ) && is_array( $_POST['eru_cos'] ) ? wp_unslash( $_POST['eru_cos'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitised field by field in Eru_COS_Statuses.
		$orig = isset( $_POST['original'] ) ? sanitize_key( wp_unslash( $_POST['original'] ) ) : '';
		foreach ( array( 'is_paid', 'reduces_stock', 'editable', 'downloads_permitted', 'bulk_action', 'show_action_button', 'email_customer', 'email_admin' ) as $flag ) {
			$in[ $flag ] = empty( $in[ $flag ] ) ? 0 : 1; // Unchecked boxes are not posted.
		}
		$r = '' !== $orig ? Eru_COS_Statuses::update( $orig, $in ) : Eru_COS_Statuses::add( $in );
		if ( is_wp_error( $r ) ) {
			self::back( '' !== $orig ? array( 'action' => 'edit', 'slug' => $orig ) : array( 'action' => 'new' ), $r );
		}
		self::back( array( 'msg' => 'saved' ) );
	}

	public static function handle_delete(): void {
		self::guard( 'eru_cos_delete' );
		$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';
		$to   = isset( $_POST['to'] ) ? sanitize_key( wp_unslash( $_POST['to'] ) ) : '';
		$r    = Eru_COS_Statuses::delete( $slug, $to );
		if ( is_wp_error( $r ) ) {
			self::back( array( 'action' => 'delete', 'slug' => $slug ), $r );
		}
		self::back( array( 'msg' => 'scheduled' === $r ? 'scheduled' : 'deleted' ) );
	}

	public static function handle_groups(): void {
		self::guard( 'eru_cos_groups' );
		$labels = isset( $_POST['groups'] ) && is_array( $_POST['groups'] ) ? array_map( 'sanitize_text_field', wp_unslash( $_POST['groups'] ) ) : array();
		$groups = array();
		foreach ( $labels as $id => $label ) {
			$id = sanitize_key( (string) $id );
			if ( '' !== $id && '' !== $label ) {
				$groups[ $id ] = $label;
			}
		}
		$new = isset( $_POST['new_group'] ) ? sanitize_text_field( wp_unslash( $_POST['new_group'] ) ) : '';
		if ( '' !== $new ) {
			$base = Eru_COS_Statuses::fold( $new );
			$base = '' !== $base ? $base : 'grup';
			$id   = $base;
			for ( $i = 2; isset( $groups[ $id ] ); $i++ ) {
				$id = $base . '-' . $i;
			}
			$groups[ sanitize_key( $id ) ] = $new;
		}
		update_option( Eru_COS_Statuses::GROUPS_OPTION, $groups );
		// Statuses in a removed group become ungrouped.
		foreach ( Eru_COS_Statuses::raw() as $slug => $s ) {
			if ( '' !== $s['group'] && ! isset( $groups[ $s['group'] ] ) ) {
				Eru_COS_Statuses::update( $slug, array( 'group' => '' ) );
			}
		}
		self::back( array( 'msg' => 'saved' ) );
	}

	public static function handle_settings(): void {
		self::guard( 'eru_cos_settings' );
		update_option(
			Eru_COS_Statuses::SETTINGS_OPTION,
			array(
				'group_tabs' => empty( $_POST['group_tabs'] ) ? 0 : 1,
				'webhooks'   => empty( $_POST['webhooks'] ) ? 0 : 1,
			)
		);
		self::back( array( 'msg' => 'saved' ) );
	}

	public static function ajax_reorder(): void {
		check_ajax_referer( 'eru_cos_reorder' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		$slugs = isset( $_POST['order'] ) && is_array( $_POST['order'] ) ? array_map( 'sanitize_key', wp_unslash( $_POST['order'] ) ) : array();
		Eru_COS_Statuses::reorder( $slugs );
		wp_send_json_success();
	}

	/* ---------- screens ---------- */

	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- screen routing only.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$slug   = isset( $_GET['slug'] ) ? sanitize_key( wp_unslash( $_GET['slug'] ) ) : '';
		$msg    = isset( $_GET['msg'] ) ? sanitize_key( wp_unslash( $_GET['msg'] ) ) : '';
		// phpcs:enable
		echo '<div class="wrap eru-cos">';
		self::notice( $msg );
		$status = '' !== $slug ? ( Eru_COS_Statuses::raw()[ $slug ] ?? null ) : null;
		if ( 'new' === $action || ( 'edit' === $action && $status ) ) {
			self::render_form( $status );
		} elseif ( 'delete' === $action && $status ) {
			self::render_delete( $status );
		} else {
			self::render_list();
		}
		echo '</div>';
	}

	private static function notice( string $msg ): void {
		$text  = '';
		$class = 'success';
		if ( 'saved' === $msg ) {
			$text = __( 'Kaydedildi.', 'custom-order-statuses-for-woocommerce' );
		} elseif ( 'deleted' === $msg ) {
			$text = __( 'Durum silindi.', 'custom-order-statuses-for-woocommerce' );
		} elseif ( 'scheduled' === $msg ) {
			$text = __( 'Siparişler arka planda taşınıyor; bittiğinde durum kendiliğinden silinecek.', 'custom-order-statuses-for-woocommerce' );
		} elseif ( 'error' === $msg ) {
			$key   = 'eru_cos_error_' . get_current_user_id();
			$text  = (string) get_transient( $key );
			$class = 'error';
			delete_transient( $key );
		}
		if ( '' !== $text ) {
			echo '<div class="notice notice-' . esc_attr( $class ) . ' eru-keep"><p>' . esc_html( $text ) . '</p></div>';
		}
	}

	private static function flags(): array {
		return array(
			'is_paid'             => array( __( 'Ödendi sayılır', 'custom-order-statuses-for-woocommerce' ), __( 'Sipariş ödenmiş kabul edilir (wc_get_is_paid_statuses ve ona dayanan raporlar).', 'custom-order-statuses-for-woocommerce' ) ),
			'reduces_stock'       => array( __( 'Stoğu düşürür', 'custom-order-statuses-for-woocommerce' ), __( '"İşleniyor" gibi: siparişin stoğu henüz düşülmediyse düşülür; iki kez düşülmez.', 'custom-order-statuses-for-woocommerce' ) ),
			'editable'            => array( __( 'Düzenlenebilir', 'custom-order-statuses-for-woocommerce' ), __( 'Bu durumdaki siparişin ürünleri yönetim panelinde düzenlenebilir.', 'custom-order-statuses-for-woocommerce' ) ),
			'downloads_permitted' => array( __( 'İndirmelere izin verir', 'custom-order-statuses-for-woocommerce' ), __( 'İndirilebilir ürünlere erişim açılır.', 'custom-order-statuses-for-woocommerce' ) ),
			'bulk_action'         => array( __( 'Toplu işlemde göster', 'custom-order-statuses-for-woocommerce' ), __( 'Sipariş listesinde "Durumu değiştir" toplu işlemi.', 'custom-order-statuses-for-woocommerce' ) ),
			'show_action_button'  => array( __( 'Hızlı işlem düğmesi', 'custom-order-statuses-for-woocommerce' ), __( 'Aşağıda seçilen durumdaki siparişlerin satırında tek tıkla geçiş düğmesi.', 'custom-order-statuses-for-woocommerce' ) ),
		);
	}

	private static function render_list(): void {
		$statuses = Eru_COS_Statuses::raw();
		$groups   = Eru_COS_Statuses::groups();
		$counts   = Eru_COS_Orders::counts();
		$settings = Eru_COS_Statuses::settings();
		$labels   = wc_get_order_statuses();
		$short    = array(
			'is_paid'             => __( 'Ödendi', 'custom-order-statuses-for-woocommerce' ),
			'reduces_stock'       => __( 'Stok', 'custom-order-statuses-for-woocommerce' ),
			'editable'            => __( 'Düzenlenebilir', 'custom-order-statuses-for-woocommerce' ),
			'downloads_permitted' => __( 'İndirme', 'custom-order-statuses-for-woocommerce' ),
			'bulk_action'         => __( 'Toplu işlem', 'custom-order-statuses-for-woocommerce' ),
			'show_action_button'  => __( 'Hızlı düğme', 'custom-order-statuses-for-woocommerce' ),
			'email_customer'      => __( 'Müşteri e-postası', 'custom-order-statuses-for-woocommerce' ),
			'email_admin'         => __( 'Yönetici e-postası', 'custom-order-statuses-for-woocommerce' ),
		);
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Sipariş Durumları', 'custom-order-statuses-for-woocommerce' ); ?></h1>
		<a href="<?php echo esc_url( self::url( array( 'action' => 'new' ) ) ); ?>" class="page-title-action"><?php esc_html_e( 'Yeni durum', 'custom-order-statuses-for-woocommerce' ); ?></a>
		<hr class="wp-header-end">
		<p class="description"><?php esc_html_e( 'Durumlar sipariş durum listesinde "İşleniyor"un hemen ardından bu sırayla görünür. Sırayı değiştirmek için satırları sürükleyin.', 'custom-order-statuses-for-woocommerce' ); ?></p>
		<table class="widefat striped eru-cos-table">
			<thead><tr>
				<th class="eru-cos-handle-col"><span class="screen-reader-text"><?php esc_html_e( 'Sıra', 'custom-order-statuses-for-woocommerce' ); ?></span></th>
				<th><?php esc_html_e( 'Durum', 'custom-order-statuses-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Kısa ad', 'custom-order-statuses-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Grup', 'custom-order-statuses-for-woocommerce' ); ?></th>
				<th class="num"><?php esc_html_e( 'Sipariş', 'custom-order-statuses-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Özellikler', 'custom-order-statuses-for-woocommerce' ); ?></th>
				<th><span class="screen-reader-text"><?php esc_html_e( 'İşlemler', 'custom-order-statuses-for-woocommerce' ); ?></span></th>
			</tr></thead>
			<tbody id="eru-cos-sortable">
			<?php if ( ! $statuses ) : ?>
				<tr><td colspan="7"><?php esc_html_e( 'Henüz özel durum yok. "Yeni durum" ile ekleyin.', 'custom-order-statuses-for-woocommerce' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $statuses as $slug => $s ) : ?>
				<tr data-slug="<?php echo esc_attr( $slug ); ?>">
					<td class="eru-cos-handle" title="<?php esc_attr_e( 'Sürükleyin', 'custom-order-statuses-for-woocommerce' ); ?>"><span class="dashicons dashicons-menu" aria-hidden="true"></span></td>
					<td><span class="eru-cos-badge" style="background:<?php echo esc_attr( $s['color'] ); ?>;color:<?php echo esc_attr( Eru_COS_Statuses::text_color( $s['color'] ) ); ?>"><?php echo esc_html( $s['label'] ); ?></span>
						<?php if ( '' !== $s['pending_delete'] ) : ?>
							<br><em>
							<?php
							/* translators: %s: target status */
							echo esc_html( sprintf( __( 'Siliniyor; siparişler "%s" durumuna taşınıyor', 'custom-order-statuses-for-woocommerce' ), $labels[ 'wc-' . $s['pending_delete'] ] ?? $s['pending_delete'] ) );
							?>
							</em>
						<?php endif; ?>
					</td>
					<td><code>wc-<?php echo esc_html( $slug ); ?></code></td>
					<td><?php echo esc_html( $groups[ $s['group'] ] ?? '—' ); ?></td>
					<td class="num"><?php echo esc_html( number_format_i18n( $counts[ $slug ] ?? 0 ) ); ?></td>
					<td class="eru-cos-flags">
						<?php
						foreach ( $short as $k => $txt ) {
							if ( ! empty( $s[ $k ] ) ) {
								echo '<span>' . esc_html( $txt ) . '</span> ';
							}
						}
						?>
					</td>
					<td class="eru-cos-actions">
						<a href="<?php echo esc_url( self::url( array( 'action' => 'edit', 'slug' => $slug ) ) ); ?>"><?php esc_html_e( 'Düzenle', 'custom-order-statuses-for-woocommerce' ); ?></a> |
						<a class="eru-cos-delete" href="<?php echo esc_url( self::url( array( 'action' => 'delete', 'slug' => $slug ) ) ); ?>"><?php esc_html_e( 'Sil', 'custom-order-statuses-for-woocommerce' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="eru-cos-reorder-status" aria-live="polite"></p>

		<div class="eru-cos-columns">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eru-cos-card">
				<h2><?php esc_html_e( 'Gruplar', 'custom-order-statuses-for-woocommerce' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Gruplar sipariş listesinin üstünde sayılarıyla birlikte kutular olarak görünür. Bir grubu silmek için adını boşaltın.', 'custom-order-statuses-for-woocommerce' ); ?></p>
				<input type="hidden" name="action" value="eru_cos_groups">
				<?php wp_nonce_field( 'eru_cos_groups' ); ?>
				<?php foreach ( $groups as $id => $label ) : ?>
					<p><input type="text" class="regular-text" name="groups[<?php echo esc_attr( $id ); ?>]" value="<?php echo esc_attr( $label ); ?>" aria-label="<?php esc_attr_e( 'Grup adı', 'custom-order-statuses-for-woocommerce' ); ?>"></p>
				<?php endforeach; ?>
				<p><input type="text" class="regular-text" name="new_group" placeholder="<?php esc_attr_e( 'Yeni grup adı', 'custom-order-statuses-for-woocommerce' ); ?>" aria-label="<?php esc_attr_e( 'Yeni grup adı', 'custom-order-statuses-for-woocommerce' ); ?>"></p>
				<?php submit_button( __( 'Grupları kaydet', 'custom-order-statuses-for-woocommerce' ), 'secondary', 'submit', false ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eru-cos-card">
				<h2><?php esc_html_e( 'Ayarlar', 'custom-order-statuses-for-woocommerce' ); ?></h2>
				<input type="hidden" name="action" value="eru_cos_settings">
				<?php wp_nonce_field( 'eru_cos_settings' ); ?>
				<p><label><input type="checkbox" name="group_tabs" value="1" <?php checked( $settings['group_tabs'], 1 ); ?>> <?php esc_html_e( 'Sipariş listesinde grup kutularını göster (her kullanıcı listeden kendisi için gizleyebilir)', 'custom-order-statuses-for-woocommerce' ); ?></label></p>
				<p><label><input type="checkbox" name="webhooks" value="1" <?php checked( $settings['webhooks'], 1 ); ?>> <?php esc_html_e( 'Webhook konuları: her durum için order.status_<kısa ad> ve order.processing / completed / cancelled / refunded', 'custom-order-statuses-for-woocommerce' ); ?></label></p>
				<p class="description"><?php esc_html_e( 'Webhook\'ları WooCommerce → Ayarlar → Gelişmiş → Webhook\'lar ekranında oluşturursunuz; eklenti yalnızca konuları ekler.', 'custom-order-statuses-for-woocommerce' ); ?></p>
				<?php submit_button( __( 'Ayarları kaydet', 'custom-order-statuses-for-woocommerce' ), 'secondary', 'submit', false ); ?>
			</form>
		</div>
		<?php
	}

	private static function render_form( ?array $s ): void {
		$is_new = null === $s;
		$s      = $s ?? Eru_COS_Statuses::defaults();
		$locked = ! $is_new && Eru_COS_Statuses::order_count( $s['slug'] ) > 0;
		$groups = Eru_COS_Statuses::groups();
		$others = array_diff_key( wc_get_order_statuses(), array( 'wc-' . $s['slug'] => 1 ) );
		$n      = 'eru_cos';
		?>
		<h1><?php echo esc_html( $is_new ? __( 'Yeni sipariş durumu', 'custom-order-statuses-for-woocommerce' ) : __( 'Sipariş durumunu düzenle', 'custom-order-statuses-for-woocommerce' ) ); ?></h1>
		<p><a href="<?php echo esc_url( self::url() ); ?>">&larr; <?php esc_html_e( 'Tüm durumlar', 'custom-order-statuses-for-woocommerce' ); ?></a></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="eru-cos-form">
			<input type="hidden" name="action" value="eru_cos_save">
			<input type="hidden" name="original" value="<?php echo esc_attr( $s['slug'] ); ?>">
			<?php wp_nonce_field( 'eru_cos_save' ); ?>
			<table class="form-table" role="presentation">
				<tr><th><label for="eru-cos-label"><?php esc_html_e( 'Durum adı', 'custom-order-statuses-for-woocommerce' ); ?></label></th><td>
					<input type="text" id="eru-cos-label" class="regular-text" name="<?php echo esc_attr( $n ); ?>[label]" value="<?php echo esc_attr( $s['label'] ); ?>" required>
				</td></tr>
				<tr><th><label for="eru-cos-slug"><?php esc_html_e( 'Kısa ad', 'custom-order-statuses-for-woocommerce' ); ?></label></th><td>
					<code>wc-</code><input type="text" id="eru-cos-slug" class="regular-text code" name="<?php echo esc_attr( $n ); ?>[slug]" value="<?php echo esc_attr( $s['slug'] ); ?>" maxlength="<?php echo esc_attr( (string) Eru_COS_Statuses::MAX_SLUG ); ?>" pattern="[a-z0-9]+(-[a-z0-9]+)*" <?php echo $locked ? 'readonly' : ''; ?> data-auto="<?php echo $is_new ? '1' : '0'; ?>">
					<p class="description">
						<?php
						if ( $locked ) {
							esc_html_e( 'Bu durumda sipariş olduğu için kısa ad değiştirilemez.', 'custom-order-statuses-for-woocommerce' );
						} else {
							/* translators: %d: maximum length */
							echo esc_html( sprintf( __( 'Addan otomatik oluşur (Türkçe harfler sadeleştirilir). Küçük harf, rakam ve tire; en çok %d karakter. Siparişler bu durumu kullanmaya başladıktan sonra değiştirilemez.', 'custom-order-statuses-for-woocommerce' ), Eru_COS_Statuses::MAX_SLUG ) );
						}
						?>
					</p>
				</td></tr>
				<tr><th><label for="eru-cos-color"><?php esc_html_e( 'Renk', 'custom-order-statuses-for-woocommerce' ); ?></label></th><td>
					<input type="color" id="eru-cos-color" name="<?php echo esc_attr( $n ); ?>[color]" value="<?php echo esc_attr( $s['color'] ); ?>">
					<span class="eru-cos-badge eru-cos-preview" style="background:<?php echo esc_attr( $s['color'] ); ?>;color:<?php echo esc_attr( Eru_COS_Statuses::text_color( $s['color'] ) ); ?>"><?php echo esc_html( '' !== $s['label'] ? $s['label'] : __( 'Önizleme', 'custom-order-statuses-for-woocommerce' ) ); ?></span>
					<p class="description"><?php esc_html_e( 'Rozet arka planı; yazı rengi okunabilirliğe göre koyu ya da beyaz seçilir.', 'custom-order-statuses-for-woocommerce' ); ?></p>
				</td></tr>
				<tr><th><label for="eru-cos-group"><?php esc_html_e( 'Grup', 'custom-order-statuses-for-woocommerce' ); ?></label></th><td>
					<select id="eru-cos-group" name="<?php echo esc_attr( $n ); ?>[group]">
						<option value=""><?php esc_html_e( '— Grup yok —', 'custom-order-statuses-for-woocommerce' ); ?></option>
						<?php foreach ( $groups as $id => $label ) : ?>
							<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $s['group'], $id ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php if ( ! $groups ) : ?>
						<p class="description"><?php esc_html_e( 'Grupları durum listesinin altından ekleyebilirsiniz.', 'custom-order-statuses-for-woocommerce' ); ?></p>
					<?php endif; ?>
				</td></tr>
				<tr><th><?php esc_html_e( 'Davranış', 'custom-order-statuses-for-woocommerce' ); ?></th><td><fieldset>
					<?php foreach ( self::flags() as $k => $f ) : ?>
						<label class="eru-cos-flag"><input type="checkbox" name="<?php echo esc_attr( $n . '[' . $k . ']' ); ?>" value="1" <?php checked( $s[ $k ], 1 ); ?>> <strong><?php echo esc_html( $f[0] ); ?></strong> <span class="description"><?php echo esc_html( $f[1] ); ?></span></label>
					<?php endforeach; ?>
					<label class="eru-cos-flag eru-cos-from"><?php esc_html_e( 'Hızlı düğmenin görüneceği durum:', 'custom-order-statuses-for-woocommerce' ); ?>
						<select name="<?php echo esc_attr( $n ); ?>[action_from]">
							<?php foreach ( $others as $key => $label ) : ?>
								<option value="<?php echo esc_attr( substr( $key, 3 ) ); ?>" <?php selected( 'wc-' . $s['action_from'], $key ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</fieldset></td></tr>
				<tr><th><?php esc_html_e( 'E-postalar', 'custom-order-statuses-for-woocommerce' ); ?></th><td><fieldset>
					<label class="eru-cos-flag"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[email_customer]" value="1" <?php checked( $s['email_customer'], 1 ); ?>> <?php esc_html_e( 'Sipariş bu duruma geçince müşteriye e-posta gönder', 'custom-order-statuses-for-woocommerce' ); ?></label>
					<label class="eru-cos-flag"><input type="checkbox" name="<?php echo esc_attr( $n ); ?>[email_admin]" value="1" <?php checked( $s['email_admin'], 1 ); ?>> <?php esc_html_e( 'Yöneticiye e-posta gönder', 'custom-order-statuses-for-woocommerce' ); ?></label>
					<p><label for="eru-cos-subject"><?php esc_html_e( 'Konu', 'custom-order-statuses-for-woocommerce' ); ?></label><br>
						<input type="text" id="eru-cos-subject" class="large-text" name="<?php echo esc_attr( $n ); ?>[email_subject]" value="<?php echo esc_attr( $s['email_subject'] ); ?>" placeholder="[{site_title}] #{order_number}"></p>
					<p><label for="eru-cos-heading"><?php esc_html_e( 'Başlık', 'custom-order-statuses-for-woocommerce' ); ?></label><br>
						<input type="text" id="eru-cos-heading" class="large-text" name="<?php echo esc_attr( $n ); ?>[email_heading]" value="<?php echo esc_attr( $s['email_heading'] ); ?>"></p>
					<p><label for="eru-cos-body"><?php esc_html_e( 'Metin', 'custom-order-statuses-for-woocommerce' ); ?></label><br>
						<textarea id="eru-cos-body" class="large-text" rows="4" name="<?php echo esc_attr( $n ); ?>[email_body]"><?php echo esc_textarea( $s['email_body'] ); ?></textarea></p>
					<p class="description"><?php esc_html_e( 'Yer tutucular: {order_number}, {customer_name}, {order_total}, {order_date}, {site_title}. Boş bırakılan alanlar için varsayılan metin kullanılır. Konu ve başlık WooCommerce → Ayarlar → E-postalar ekranından da değiştirilebilir; orada girilen değer önceliklidir. Yönetici e-postasının alıcısı da oradan ayarlanır.', 'custom-order-statuses-for-woocommerce' ); ?></p>
				</fieldset></td></tr>
			</table>
			<?php submit_button( $is_new ? __( 'Durumu ekle', 'custom-order-statuses-for-woocommerce' ) : __( 'Değişiklikleri kaydet', 'custom-order-statuses-for-woocommerce' ) ); ?>
		</form>
		<?php
	}

	private static function render_delete( array $s ): void {
		$count  = Eru_COS_Statuses::order_count( $s['slug'] );
		$others = array_diff_key( wc_get_order_statuses(), array( 'wc-' . $s['slug'] => 1 ) );
		?>
		<h1>
		<?php
		/* translators: %s: status name */
		echo esc_html( sprintf( __( '"%s" durumunu sil', 'custom-order-statuses-for-woocommerce' ), $s['label'] ) );
		?>
		</h1>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="eru_cos_delete">
			<input type="hidden" name="slug" value="<?php echo esc_attr( $s['slug'] ); ?>">
			<?php wp_nonce_field( 'eru_cos_delete' ); ?>
			<?php if ( $count > 0 ) : ?>
				<p>
				<?php
				/* translators: %d: number of orders */
				echo esc_html( sprintf( _n( 'Bu durumda %d sipariş var. Silmeden önce bu siparişlerin taşınacağı durumu seçin:', 'Bu durumda %d sipariş var. Silmeden önce bu siparişlerin taşınacağı durumu seçin:', $count, 'custom-order-statuses-for-woocommerce' ), $count ) );
				?>
				</p>
				<p><select name="to" required>
					<option value=""><?php esc_html_e( '— Hedef durum seçin —', 'custom-order-statuses-for-woocommerce' ); ?></option>
					<?php foreach ( $others as $key => $label ) : ?>
						<option value="<?php echo esc_attr( substr( $key, 3 ) ); ?>"><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select></p>
				<?php if ( $count > Eru_COS_Statuses::batch_size() ) : ?>
					<p class="description"><?php esc_html_e( 'Sipariş sayısı fazla olduğu için taşıma arka planda (Action Scheduler) partiler hâlinde yapılır; durum taşıma bitince silinir.', 'custom-order-statuses-for-woocommerce' ); ?></p>
				<?php endif; ?>
				<p class="description"><?php esc_html_e( 'Taşıma normal bir durum değişikliğidir: hedef durumun e-postaları, stok ve webhook kuralları çalışır ve siparişe not düşülür.', 'custom-order-statuses-for-woocommerce' ); ?></p>
			<?php else : ?>
				<p><?php esc_html_e( 'Bu durumda sipariş yok; güvenle silinebilir.', 'custom-order-statuses-for-woocommerce' ); ?></p>
			<?php endif; ?>
			<?php submit_button( $count > 0 ? __( 'Siparişleri taşı ve durumu sil', 'custom-order-statuses-for-woocommerce' ) : __( 'Durumu sil', 'custom-order-statuses-for-woocommerce' ), 'delete' ); ?>
			<a href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Vazgeç', 'custom-order-statuses-for-woocommerce' ); ?></a>
		</form>
		<?php
	}
}
