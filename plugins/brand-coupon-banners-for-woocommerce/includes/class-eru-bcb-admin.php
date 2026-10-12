<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Banner düzenleme ekranı, ayarlar sayfası ve liste sütunları. */
class Eru_Bcb_Admin {

	public static function init(): void {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ) );
		add_action( 'save_post_' . Eru_Bcb::POST_TYPE, array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'manage_' . Eru_Bcb::POST_TYPE . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . Eru_Bcb::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'column_content' ), 10, 2 );
		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_BCB_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Eru_Bcb::POST_TYPE . '&page=eru-bcb-settings' ) ) . '">' . esc_html__( 'Ayarlar', 'brand-coupon-banners-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}

	/* ---------- Ayarlar ---------- */

	public static function menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . Eru_Bcb::POST_TYPE,
			__( 'Marka Bannerları Ayarları', 'brand-coupon-banners-for-woocommerce' ),
			__( 'Ayarlar', 'brand-coupon-banners-for-woocommerce' ),
			'manage_woocommerce',
			'eru-bcb-settings',
			array( __CLASS__, 'render_settings' )
		);
	}

	public static function register_settings(): void {
		register_setting(
			'eru_bcb',
			Eru_Bcb::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => Eru_Bcb::defaults(),
			)
		);
		add_filter( 'option_page_capability_eru_bcb', fn() => 'manage_woocommerce' );
	}

	public static function sanitize_settings( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$def   = Eru_Bcb::defaults();
		$tax   = sanitize_key( (string) ( $input['taxonomy'] ?? '' ) );
		return array(
			'taxonomy'      => isset( Eru_Bcb::selectable_taxonomies()[ $tax ] ) ? $tax : '',
			'show_archive'  => empty( $input['show_archive'] ) ? 'no' : 'yes',
			'show_single'   => empty( $input['show_single'] ) ? 'no' : 'yes',
			'bg'            => sanitize_hex_color( (string) ( $input['bg'] ?? '' ) ) ?: $def['bg'],
			'color'         => sanitize_hex_color( (string) ( $input['color'] ?? '' ) ) ?: $def['color'],
			'create_coupon' => empty( $input['create_coupon'] ) ? 'no' : 'yes',
		);
	}

	public static function render_settings(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$o    = Eru_Bcb::options();
		$name = Eru_Bcb::OPTION;
		$taxs = Eru_Bcb::selectable_taxonomies();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Marka Bannerları Ayarları', 'brand-coupon-banners-for-woocommerce' ); ?></h1>
			<form action="options.php" method="post">
				<?php settings_fields( 'eru_bcb' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="eru-bcb-tax"><?php esc_html_e( 'Marka sınıflandırması', 'brand-coupon-banners-for-woocommerce' ); ?></label></th>
						<td>
							<select id="eru-bcb-tax" name="<?php echo esc_attr( $name ); ?>[taxonomy]">
								<option value=""><?php esc_html_e( '— Marka kullanma —', 'brand-coupon-banners-for-woocommerce' ); ?></option>
								<?php foreach ( $taxs as $slug => $tax ) : ?>
									<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $o['taxonomy'], $slug ); ?>><?php echo esc_html( $tax->labels->singular_name . ' (' . $slug . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Bannerların markaya göre eşleştirileceği ürün sınıflandırması (ör. WooCommerce Markalar veya bir ürün özelliği).', 'brand-coupon-banners-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Gösterim yerleri', 'brand-coupon-banners-for-woocommerce' ); ?></th>
						<td>
							<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_archive]" value="1" <?php checked( 'yes', $o['show_archive'] ); ?>> <?php esc_html_e( 'Mağaza, kategori ve marka arşivleri', 'brand-coupon-banners-for-woocommerce' ); ?></label>
							<label style="display:block"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_single]" value="1" <?php checked( 'yes', $o['show_single'] ); ?>> <?php esc_html_e( 'Ürün sayfası (ürünün markası/kategorisi eşleşirse)', 'brand-coupon-banners-for-woocommerce' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Metin banner renkleri', 'brand-coupon-banners-for-woocommerce' ); ?></th>
						<td>
							<input type="color" name="<?php echo esc_attr( $name ); ?>[bg]" value="<?php echo esc_attr( $o['bg'] ); ?>">
							<input type="color" name="<?php echo esc_attr( $name ); ?>[color]" value="<?php echo esc_attr( $o['color'] ); ?>">
							<span class="description"><?php esc_html_e( 'Arka plan / yazı. Banner kendi rengini belirtmediyse kullanılır.', 'brand-coupon-banners-for-woocommerce' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'WooCommerce kuponu', 'brand-coupon-banners-for-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[create_coupon]" value="1" <?php checked( 'yes', $o['create_coupon'] ); ?>> <?php esc_html_e( 'Banner kaydedilince kupon kodu, indirim ve tarihlerle bir WooCommerce kuponu oluştur/güncelle', 'brand-coupon-banners-for-woocommerce' ); ?></label>
							<p class="description"><?php esc_html_e( 'Kupon yalnızca seçilen ürün kategorileriyle sınırlanır; WooCommerce kuponları marka sınıflandırmasına göre kısıtlayamaz.', 'brand-coupon-banners-for-woocommerce' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/* ---------- Banner düzenleme ---------- */

	public static function enqueue( $hook ): void {
		global $post_type;
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && Eru_Bcb::POST_TYPE === $post_type ) {
			wp_enqueue_media();
			wp_enqueue_script( 'eru-bcb-admin', ERU_BCB_URL . 'assets/admin.js', array( 'jquery' ), ERU_BCB_VERSION, true );
			wp_localize_script(
				'eru-bcb-admin',
				'eruBcb',
				array(
					'title'  => __( 'Banner seç', 'brand-coupon-banners-for-woocommerce' ),
					'button' => __( 'Bu görseli kullan', 'brand-coupon-banners-for-woocommerce' ),
				)
			);
			wp_enqueue_style( 'eru-bcb-admin', ERU_BCB_URL . 'assets/admin.css', array(), ERU_BCB_VERSION );
		}
	}

	public static function add_meta_boxes(): void {
		add_meta_box( 'eru_bcb_details', __( 'Kampanya ve kupon', 'brand-coupon-banners-for-woocommerce' ), array( __CLASS__, 'box_details' ), Eru_Bcb::POST_TYPE, 'normal', 'high' );
		add_meta_box( 'eru_bcb_banner', __( 'Banner içeriği', 'brand-coupon-banners-for-woocommerce' ), array( __CLASS__, 'box_banner' ), Eru_Bcb::POST_TYPE, 'normal', 'high' );
	}

	private static function term_checklist( string $taxonomy, string $field, array $selected ): void {
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			echo '<p class="description">' . esc_html__( 'Terim bulunamadı.', 'brand-coupon-banners-for-woocommerce' ) . '</p>';
			return;
		}
		echo '<div class="eru-bcb-checklist">';
		foreach ( $terms as $t ) {
			echo '<label><input type="checkbox" name="' . esc_attr( $field ) . '[]" value="' . (int) $t->term_id . '"' . checked( in_array( (int) $t->term_id, $selected, true ), true, false ) . '> ' . esc_html( $t->name ) . '</label>';
		}
		echo '</div>';
	}

	public static function box_details( $post ): void {
		wp_nonce_field( 'eru_bcb_save', 'eru_bcb_nonce' );
		$m     = Eru_Bcb::banner_meta( $post->ID );
		$brand = Eru_Bcb::brand_taxonomy();
		?>
		<div class="eru-bcb-box">
			<p>
				<label for="eru_bcb_code"><?php esc_html_e( 'Kupon kodu', 'brand-coupon-banners-for-woocommerce' ); ?></label>
				<input type="text" id="eru_bcb_code" name="eru_bcb_code" value="<?php echo esc_attr( $m['code'] ); ?>" class="widefat" placeholder="DEMO10">
			</p>
			<p>
				<label for="eru_bcb_type"><?php esc_html_e( 'İndirim tipi', 'brand-coupon-banners-for-woocommerce' ); ?></label>
				<select id="eru_bcb_type" name="eru_bcb_type" class="widefat">
					<option value="percent" <?php selected( $m['type'], 'percent' ); ?>><?php esc_html_e( 'Yüzde indirim', 'brand-coupon-banners-for-woocommerce' ); ?></option>
					<option value="fixed_cart" <?php selected( $m['type'], 'fixed_cart' ); ?>><?php esc_html_e( 'Sepette sabit tutar', 'brand-coupon-banners-for-woocommerce' ); ?></option>
					<option value="fixed_product" <?php selected( $m['type'], 'fixed_product' ); ?>><?php esc_html_e( 'Üründe sabit tutar', 'brand-coupon-banners-for-woocommerce' ); ?></option>
				</select>
			</p>
			<p>
				<label for="eru_bcb_amount"><?php esc_html_e( 'İndirim miktarı', 'brand-coupon-banners-for-woocommerce' ); ?></label>
				<input type="text" inputmode="decimal" id="eru_bcb_amount" name="eru_bcb_amount" value="<?php echo esc_attr( $m['amount'] ); ?>" class="widefat" placeholder="10">
			</p>
			<p>
				<label for="eru_bcb_start"><?php esc_html_e( 'Başlangıç', 'brand-coupon-banners-for-woocommerce' ); ?></label>
				<input type="datetime-local" id="eru_bcb_start" name="eru_bcb_start" value="<?php echo esc_attr( $m['start'] ); ?>" class="widefat">
			</p>
			<p>
				<label for="eru_bcb_end"><?php esc_html_e( 'Bitiş', 'brand-coupon-banners-for-woocommerce' ); ?></label>
				<input type="datetime-local" id="eru_bcb_end" name="eru_bcb_end" value="<?php echo esc_attr( $m['end'] ); ?>" class="widefat">
			</p>
			<p><strong><?php esc_html_e( 'Ürün kategorileri', 'brand-coupon-banners-for-woocommerce' ); ?></strong></p>
			<?php self::term_checklist( 'product_cat', 'eru_bcb_cats', $m['cats'] ); ?>
			<p><strong><?php esc_html_e( 'Markalar', 'brand-coupon-banners-for-woocommerce' ); ?></strong></p>
			<?php
			if ( '' === $brand ) {
				echo '<p class="description">' . esc_html__( 'Önce Ayarlar sayfasından bir marka sınıflandırması seçin.', 'brand-coupon-banners-for-woocommerce' ) . '</p>';
			} else {
				self::term_checklist( $brand, 'eru_bcb_brands', $m['brands'] );
			}
			?>
			<p class="description"><?php esc_html_e( 'Kategori ve marka boş bırakılırsa banner tüm mağaza sayfalarında gösterilir.', 'brand-coupon-banners-for-woocommerce' ); ?></p>
			<p><label><input type="checkbox" name="eru_bcb_active" value="1" <?php checked( $m['active'] ); ?>> <strong><?php esc_html_e( 'Aktif', 'brand-coupon-banners-for-woocommerce' ); ?></strong></label></p>
		</div>
		<?php
	}

	private static function image_field( string $field, int $id, string $label ): void {
		$url = $id ? wp_get_attachment_url( $id ) : '';
		?>
		<div class="eru-bcb-image">
			<strong><?php echo esc_html( $label ); ?></strong><br>
			<input type="hidden" id="<?php echo esc_attr( $field ); ?>" name="<?php echo esc_attr( $field ); ?>" value="<?php echo esc_attr( (string) $id ); ?>">
			<button type="button" class="button eru-bcb-upload" data-target="<?php echo esc_attr( $field ); ?>"><?php esc_html_e( 'Görsel seç', 'brand-coupon-banners-for-woocommerce' ); ?></button>
			<button type="button" class="button eru-bcb-remove" data-target="<?php echo esc_attr( $field ); ?>" <?php echo $url ? '' : 'style="display:none"'; ?>><?php esc_html_e( 'Kaldır', 'brand-coupon-banners-for-woocommerce' ); ?></button>
			<div class="eru-bcb-preview" id="<?php echo esc_attr( $field ); ?>_preview"><?php echo $url ? '<img src="' . esc_url( $url ) . '" alt="">' : ''; ?></div>
		</div>
		<?php
	}

	public static function box_banner( $post ): void {
		$m = Eru_Bcb::banner_meta( $post->ID );
		$o = Eru_Bcb::options();
		?>
		<div class="eru-bcb-box">
			<p>
				<label for="eru_bcb_text"><?php esc_html_e( 'Banner metni', 'brand-coupon-banners-for-woocommerce' ); ?></label>
				<input type="text" id="eru_bcb_text" name="eru_bcb_text" value="<?php echo esc_attr( $m['text'] ); ?>" class="widefat" placeholder="<?php esc_attr_e( '{code} koduyla indirim fırsatı', 'brand-coupon-banners-for-woocommerce' ); ?>">
				<span class="description"><?php esc_html_e( '{code} yerine kupon kodu yazılır. Görsel seçilmemişse bu metin renkli bir banner olarak gösterilir.', 'brand-coupon-banners-for-woocommerce' ); ?></span>
			</p>
			<p>
				<label><?php esc_html_e( 'Metin banner renkleri (arka plan / yazı)', 'brand-coupon-banners-for-woocommerce' ); ?></label>
				<input type="color" name="eru_bcb_bg" value="<?php echo esc_attr( $m['bg'] ?: $o['bg'] ); ?>">
				<input type="color" name="eru_bcb_color" value="<?php echo esc_attr( $m['color'] ?: $o['color'] ); ?>">
			</p>
			<?php self::image_field( 'eru_bcb_desktop', $m['desktop'], __( 'Masaüstü görseli', 'brand-coupon-banners-for-woocommerce' ) ); ?>
			<?php self::image_field( 'eru_bcb_mobile', $m['mobile'], __( 'Mobil görseli', 'brand-coupon-banners-for-woocommerce' ) ); ?>
			<p>
				<label for="eru_bcb_link"><?php esc_html_e( 'Banner bağlantısı (isteğe bağlı)', 'brand-coupon-banners-for-woocommerce' ); ?></label>
				<input type="url" id="eru_bcb_link" name="eru_bcb_link" value="<?php echo esc_attr( $m['link'] ); ?>" class="widefat" placeholder="https://">
			</p>
			<p>
				<label for="eru_bcb_position"><?php esc_html_e( 'Ürün listesindeki sıra', 'brand-coupon-banners-for-woocommerce' ); ?></label>
				<input type="number" id="eru_bcb_position" name="eru_bcb_position" value="<?php echo esc_attr( (string) $m['position'] ); ?>" min="0" step="1" class="small-text">
				<span class="description"><?php esc_html_e( 'Kaçıncı üründen sonra gösterilsin (0 = listenin üstünde). Blok temalarında her zaman listenin üstünde gösterilir.', 'brand-coupon-banners-for-woocommerce' ); ?></span>
			</p>
		</div>
		<?php
	}

	/* ---------- Kaydet ---------- */

	public static function save( $post_id, $post ): void {
		if (
			! isset( $_POST['eru_bcb_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eru_bcb_nonce'] ) ), 'eru_bcb_save' )
			|| ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			|| wp_is_post_revision( $post_id )
			|| ! current_user_can( 'edit_post', $post_id )
		) {
			return;
		}
		$p = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- yukarıda doğrulandı; alanlar aşağıda tek tek temizlenir.

		$type = sanitize_key( (string) ( $p['eru_bcb_type'] ?? '' ) );
		$type = in_array( $type, array( 'percent', 'fixed_cart', 'fixed_product' ), true ) ? $type : 'percent';
		$date = static fn( $v ) => preg_match( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', (string) $v ) ? (string) $v : '';

		$brand = Eru_Bcb::brand_taxonomy();
		$ids   = static fn( $v ) => array_values( array_unique( array_filter( array_map( 'absint', (array) $v ) ) ) );

		$values = array(
			'code'     => wc_format_coupon_code( sanitize_text_field( (string) ( $p['eru_bcb_code'] ?? '' ) ) ),
			'type'     => $type,
			'amount'   => wc_format_decimal( sanitize_text_field( (string) ( $p['eru_bcb_amount'] ?? '' ) ) ),
			'start'    => $date( $p['eru_bcb_start'] ?? '' ),
			'end'      => $date( $p['eru_bcb_end'] ?? '' ),
			'cats'     => $ids( $p['eru_bcb_cats'] ?? array() ),
			'brands'   => '' === $brand ? array() : $ids( $p['eru_bcb_brands'] ?? array() ),
			'active'   => empty( $p['eru_bcb_active'] ) ? '0' : '1',
			'desktop'  => absint( $p['eru_bcb_desktop'] ?? 0 ),
			'mobile'   => absint( $p['eru_bcb_mobile'] ?? 0 ),
			'link'     => esc_url_raw( (string) ( $p['eru_bcb_link'] ?? '' ) ),
			'position' => absint( $p['eru_bcb_position'] ?? 0 ),
			'text'     => trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $p['eru_bcb_text'] ?? '' ) ) ) ), // sanitize_text_field "%15" gibi ifadeleri siler.
			'bg'       => sanitize_hex_color( (string) ( $p['eru_bcb_bg'] ?? '' ) ) ?: '',
			'color'    => sanitize_hex_color( (string) ( $p['eru_bcb_color'] ?? '' ) ) ?: '',
		);
		foreach ( $values as $key => $value ) {
			update_post_meta( $post_id, '_eru_bcb_' . $key, $value );
		}

		if ( 'yes' === Eru_Bcb::options()['create_coupon'] && 'publish' === $post->post_status && current_user_can( 'manage_woocommerce' ) ) {
			self::sync_coupon( (int) $post_id, $values );
		}
	}

	/** Banner alanlarından WooCommerce kuponu oluşturur veya günceller. */
	private static function sync_coupon( int $post_id, array $v ): void {
		if ( '' === $v['code'] || '' === $v['amount'] ) {
			return;
		}
		$known    = (int) get_post_meta( $post_id, '_eru_bcb_coupon_id', true );
		$existing = (int) wc_get_coupon_id_by_code( $v['code'] );
		if ( $existing && $existing !== $known ) {
			return; // Aynı kodlu başka bir kupona dokunma.
		}
		$coupon = new WC_Coupon( $known ?: 0 );
		$coupon->set_code( $v['code'] );
		$coupon->set_discount_type( $v['type'] );
		$coupon->set_amount( $v['amount'] );
		$coupon->set_product_categories( $v['cats'] );
		$end = Eru_Bcb::parse_time( $v['end'] );
		$coupon->set_date_expires( $end );
		$saved = $coupon->save();
		if ( $saved ) {
			update_post_meta( $post_id, '_eru_bcb_coupon_id', (int) $saved );
		}
	}

	/* ---------- Liste sütunları ---------- */

	public static function columns( $columns ): array {
		return array(
			'cb'       => $columns['cb'] ?? '',
			'title'    => __( 'Başlık', 'brand-coupon-banners-for-woocommerce' ),
			'code'     => __( 'Kupon kodu', 'brand-coupon-banners-for-woocommerce' ),
			'validity' => __( 'Geçerlilik', 'brand-coupon-banners-for-woocommerce' ),
			'scope'    => __( 'Kapsam', 'brand-coupon-banners-for-woocommerce' ),
			'status'   => __( 'Durum', 'brand-coupon-banners-for-woocommerce' ),
		);
	}

	public static function column_content( $column, $post_id ): void {
		$m = Eru_Bcb::banner_meta( (int) $post_id );
		switch ( $column ) {
			case 'code':
				echo '' !== $m['code'] ? '<code>' . esc_html( $m['code'] ) . '</code>' : '—';
				break;
			case 'validity':
				$fmt   = 'd.m.Y H:i';
				$start = Eru_Bcb::parse_time( $m['start'] );
				$end   = Eru_Bcb::parse_time( $m['end'] );
				echo ( $start || $end ) ? esc_html( ( $start ? wp_date( $fmt, $start ) : '—' ) . ' – ' . ( $end ? wp_date( $fmt, $end ) : '—' ) ) : '—';
				break;
			case 'scope':
				$names = array();
				foreach ( array( 'product_cat' => $m['cats'], Eru_Bcb::brand_taxonomy() => $m['brands'] ) as $tax => $term_ids ) {
					foreach ( (array) $term_ids as $tid ) {
						$t = '' !== $tax ? get_term( (int) $tid, $tax ) : null;
						if ( $t instanceof WP_Term ) {
							$names[] = $t->name;
						}
					}
				}
				echo $names ? esc_html( implode( ', ', $names ) ) : esc_html__( 'Tüm mağaza', 'brand-coupon-banners-for-woocommerce' );
				break;
			case 'status':
				echo $m['active'] ? esc_html__( 'Aktif', 'brand-coupon-banners-for-woocommerce' ) : esc_html__( 'Pasif', 'brand-coupon-banners-for-woocommerce' );
				break;
		}
	}
}
