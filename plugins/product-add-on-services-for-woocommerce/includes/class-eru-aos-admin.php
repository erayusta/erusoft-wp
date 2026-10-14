<?php
/**
 * Admin: group edit screen, group list columns, product "Ek hizmetler" tab, settings section.
 *
 * @package product-add-on-services-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin screens.
 */
class Eru_AOS_Admin {

	public static function init() {
		$cpt = Eru_AOS_Groups::CPT;
		add_filter( 'woocommerce_screen_ids', array( __CLASS__, 'screen_ids' ) );
		add_action( 'add_meta_boxes_' . $cpt, array( __CLASS__, 'meta_boxes' ) );
		add_action( 'save_post_' . $cpt, array( __CLASS__, 'save' ), 10, 2 );
		add_filter( 'enter_title_here', array( __CLASS__, 'title_placeholder' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'manage_' . $cpt . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . $cpt . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );

		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'product_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'product_panel' ) );
		add_action( 'woocommerce_admin_process_product_object', array( __CLASS__, 'product_save' ) );

		add_filter( 'woocommerce_get_sections_products', array( __CLASS__, 'settings_section' ) );
		add_filter( 'woocommerce_get_settings_products', array( __CLASS__, 'settings' ), 10, 2 );
	}

	public static function screen_ids( $ids ) {
		$ids[] = Eru_AOS_Groups::CPT;
		$ids[] = 'edit-' . Eru_AOS_Groups::CPT;
		return $ids;
	}

	public static function title_placeholder( $text, $post ) {
		return Eru_AOS_Groups::CPT === $post->post_type ? __( 'Müşteriye görünen grup adı (ör. Uzatılmış garanti)', 'product-add-on-services-for-woocommerce' ) : $text;
	}

	public static function assets() {
		$screen = get_current_screen();
		if ( ! $screen || Eru_AOS_Groups::CPT !== $screen->id ) {
			return;
		}
		wp_enqueue_style( 'eru-aos-admin', ERU_AOS_URL . 'assets/admin.css', array(), ERU_AOS_VERSION );
		wp_enqueue_script( 'eru-aos-admin', ERU_AOS_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), ERU_AOS_VERSION, true );
	}

	public static function meta_boxes() {
		add_meta_box( 'eru_aos_group', __( 'Grup ayarları', 'product-add-on-services-for-woocommerce' ), array( __CLASS__, 'box_group' ), Eru_AOS_Groups::CPT, 'normal', 'high' );
		add_meta_box( 'eru_aos_options', __( 'Seçenekler', 'product-add-on-services-for-woocommerce' ), array( __CLASS__, 'box_options' ), Eru_AOS_Groups::CPT, 'normal', 'high' );
	}

	/**
	 * Current group data, defaults for a new group.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	private static function data( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			return Eru_AOS_Groups::sanitize( array( 'scope' => 'all' ) );
		}
		return Eru_AOS_Groups::get( $post );
	}

	public static function box_group( $post ) {
		$g = self::data( $post );
		wp_nonce_field( 'eru_aos_save', 'eru_aos_nonce' );
		$terms    = function ( $tax, $selected ) {
			$out = '';
			foreach ( get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) ) as $t ) {
				$out .= '<option value="' . esc_attr( $t->term_id ) . '"' . selected( in_array( (int) $t->term_id, $selected, true ), true, false ) . '>' . esc_html( $t->name ) . '</option>';
			}
			return $out;
		};
		$products = function ( $ids ) {
			$out = '';
			foreach ( $ids as $id ) {
				$p = wc_get_product( $id );
				if ( $p ) {
					$out .= '<option value="' . esc_attr( $id ) . '" selected>' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
				}
			}
			return $out;
		};
		$ids      = fn( $scope ) => $g['scope'] === $scope ? $g['scope_ids'] : array();
		?>
		<table class="form-table eru-aos-form" role="presentation">
			<tr>
				<th scope="row"><label for="eru_aos_description"><?php esc_html_e( 'Açıklama', 'product-add-on-services-for-woocommerce' ); ?></label></th>
				<td><textarea id="eru_aos_description" name="eru_aos[description]" rows="2" class="large-text"><?php echo esc_textarea( $g['description'] ); ?></textarea>
				<p class="description"><?php esc_html_e( 'Ürün sayfasında grup başlığının altında görünür (isteğe bağlı).', 'product-add-on-services-for-woocommerce' ); ?></p></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Seçim türü', 'product-add-on-services-for-woocommerce' ); ?></th>
				<td><fieldset>
					<label><input type="radio" name="eru_aos[type]" value="single"<?php checked( $g['type'], 'single' ); ?>> <?php esc_html_e( 'Tek seçim (radyo düğmesi)', 'product-add-on-services-for-woocommerce' ); ?></label><br>
					<label><input type="radio" name="eru_aos[type]" value="multi"<?php checked( $g['type'], 'multi' ); ?>> <?php esc_html_e( 'Çoklu seçim (onay kutusu)', 'product-add-on-services-for-woocommerce' ); ?></label>
				</fieldset></td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Zorunlu', 'product-add-on-services-for-woocommerce' ); ?></th>
				<td><label><input type="checkbox" name="eru_aos[required]" value="1"<?php checked( $g['required'] ); ?>> <?php esc_html_e( 'Müşteri bu gruptan seçim yapmadan sepete ekleyemez', 'product-add-on-services-for-woocommerce' ); ?></label></td>
			</tr>
			<tr class="eru-aos-multi-only">
				<th scope="row"><?php esc_html_e( 'Seçim sayısı', 'product-add-on-services-for-woocommerce' ); ?></th>
				<td>
					<label><?php esc_html_e( 'En az', 'product-add-on-services-for-woocommerce' ); ?> <input type="number" min="0" step="1" class="small-text" name="eru_aos[min]" value="<?php echo esc_attr( $g['min'] ); ?>"></label>
					<label><?php esc_html_e( 'En fazla', 'product-add-on-services-for-woocommerce' ); ?> <input type="number" min="0" step="1" class="small-text" name="eru_aos[max]" value="<?php echo esc_attr( $g['max'] ); ?>"></label>
					<p class="description"><?php esc_html_e( 'Yalnızca çoklu seçimde. 0 = sınır yok. "En az", müşteri bir seçim yaptığında (veya grup zorunluysa) uygulanır.', 'product-add-on-services-for-woocommerce' ); ?></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="eru_aos_scope"><?php esc_html_e( 'Uygulanacak ürünler', 'product-add-on-services-for-woocommerce' ); ?></label></th>
				<td>
					<select id="eru_aos_scope" name="eru_aos[scope]">
						<option value="all"<?php selected( $g['scope'], 'all' ); ?>><?php esc_html_e( 'Tüm ürünler', 'product-add-on-services-for-woocommerce' ); ?></option>
						<option value="categories"<?php selected( $g['scope'], 'categories' ); ?>><?php esc_html_e( 'Seçili kategoriler', 'product-add-on-services-for-woocommerce' ); ?></option>
						<option value="tags"<?php selected( $g['scope'], 'tags' ); ?>><?php esc_html_e( 'Seçili etiketler', 'product-add-on-services-for-woocommerce' ); ?></option>
						<option value="products"<?php selected( $g['scope'], 'products' ); ?>><?php esc_html_e( 'Seçili ürünler', 'product-add-on-services-for-woocommerce' ); ?></option>
					</select>
					<p class="eru-aos-scope" data-scope="categories"><select multiple class="wc-enhanced-select" style="width:100%" name="eru_aos[scope_categories][]" aria-label="<?php esc_attr_e( 'Kategoriler', 'product-add-on-services-for-woocommerce' ); ?>" data-placeholder="<?php esc_attr_e( 'Kategori seçin', 'product-add-on-services-for-woocommerce' ); ?>"><?php echo $terms( 'product_cat', $ids( 'categories' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></select></p>
					<p class="eru-aos-scope" data-scope="tags"><select multiple class="wc-enhanced-select" style="width:100%" name="eru_aos[scope_tags][]" aria-label="<?php esc_attr_e( 'Etiketler', 'product-add-on-services-for-woocommerce' ); ?>" data-placeholder="<?php esc_attr_e( 'Etiket seçin', 'product-add-on-services-for-woocommerce' ); ?>"><?php echo $terms( 'product_tag', $ids( 'tags' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></select></p>
					<p class="eru-aos-scope" data-scope="products"><select multiple class="wc-product-search" style="width:100%" name="eru_aos[scope_products][]" aria-label="<?php esc_attr_e( 'Ürünler', 'product-add-on-services-for-woocommerce' ); ?>" data-action="woocommerce_json_search_products" data-placeholder="<?php esc_attr_e( 'Ürün arayın', 'product-add-on-services-for-woocommerce' ); ?>"><?php echo $products( $ids( 'products' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></select></p>
				</td>
			</tr>
			<tr>
				<th scope="row"><label for="eru_aos_exclude"><?php esc_html_e( 'Hariç ürünler', 'product-add-on-services-for-woocommerce' ); ?></label></th>
				<td><select id="eru_aos_exclude" multiple class="wc-product-search" style="width:100%" name="eru_aos[exclude][]" data-action="woocommerce_json_search_products" data-placeholder="<?php esc_attr_e( 'Ürün arayın', 'product-add-on-services-for-woocommerce' ); ?>"><?php echo $products( $g['exclude'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></select>
				<p class="description"><?php esc_html_e( 'Ürün düzenleme ekranındaki "Ek hizmetler" sekmesinden bir üründe tüm grupları kapatabilirsiniz.', 'product-add-on-services-for-woocommerce' ); ?></p></td>
			</tr>
		</table>
		<?php
	}

	/**
	 * One option row.
	 *
	 * @param string|int $i Row index.
	 * @param array      $o Option.
	 */
	private static function option_row( $i, array $o ) {
		$n = fn( $f ) => 'eru_aos[options][' . $i . '][' . $f . ']';
		?>
		<tr>
			<td class="eru-aos-drag" title="<?php esc_attr_e( 'Sürükleyerek sıralayın', 'product-add-on-services-for-woocommerce' ); ?>"><span class="dashicons dashicons-menu" aria-hidden="true"></span><input type="hidden" name="<?php echo esc_attr( $n( 'id' ) ); ?>" value="<?php echo esc_attr( $o['id'] ); ?>"></td>
			<td><input type="text" class="widefat" name="<?php echo esc_attr( $n( 'label' ) ); ?>" value="<?php echo esc_attr( $o['label'] ); ?>" aria-label="<?php esc_attr_e( 'Seçenek adı', 'product-add-on-services-for-woocommerce' ); ?>" placeholder="<?php esc_attr_e( 'Seçenek adı', 'product-add-on-services-for-woocommerce' ); ?>">
				<input type="text" class="widefat eru-aos-desc" name="<?php echo esc_attr( $n( 'description' ) ); ?>" value="<?php echo esc_attr( $o['description'] ); ?>" placeholder="<?php esc_attr_e( 'Kısa açıklama (isteğe bağlı)', 'product-add-on-services-for-woocommerce' ); ?>" aria-label="<?php esc_attr_e( 'Açıklama', 'product-add-on-services-for-woocommerce' ); ?>"></td>
			<td><select name="<?php echo esc_attr( $n( 'price_type' ) ); ?>" aria-label="<?php esc_attr_e( 'Fiyat türü', 'product-add-on-services-for-woocommerce' ); ?>">
				<option value="fixed"<?php selected( $o['price_type'], 'fixed' ); ?>><?php esc_html_e( 'Sabit tutar', 'product-add-on-services-for-woocommerce' ); ?></option>
				<option value="percent"<?php selected( $o['price_type'], 'percent' ); ?>><?php esc_html_e( 'Ürün fiyatının yüzdesi', 'product-add-on-services-for-woocommerce' ); ?></option>
			</select></td>
			<td><input type="text" inputmode="decimal" class="small-text" name="<?php echo esc_attr( $n( 'amount' ) ); ?>" value="<?php echo esc_attr( wc_format_localized_decimal( $o['amount'] ) ); ?>" aria-label="<?php esc_attr_e( 'Tutar', 'product-add-on-services-for-woocommerce' ); ?>"></td>
			<td><select name="<?php echo esc_attr( $n( 'charge' ) ); ?>" aria-label="<?php esc_attr_e( 'Ücretlendirme', 'product-add-on-services-for-woocommerce' ); ?>">
				<option value="per_unit"<?php selected( $o['charge'], 'per_unit' ); ?>><?php esc_html_e( 'Adet başına', 'product-add-on-services-for-woocommerce' ); ?></option>
				<option value="per_line"<?php selected( $o['charge'], 'per_line' ); ?>><?php esc_html_e( 'Satır başına bir kez', 'product-add-on-services-for-woocommerce' ); ?></option>
			</select></td>
			<td class="eru-aos-check"><input type="checkbox" name="<?php echo esc_attr( $n( 'default' ) ); ?>" value="1"<?php checked( $o['default'] ); ?> aria-label="<?php esc_attr_e( 'Varsayılan seçili', 'product-add-on-services-for-woocommerce' ); ?>"></td>
			<td class="eru-aos-check"><input type="hidden" name="<?php echo esc_attr( $n( 'enabled' ) ); ?>" value="0"><input type="checkbox" name="<?php echo esc_attr( $n( 'enabled' ) ); ?>" value="1"<?php checked( $o['enabled'] ); ?> aria-label="<?php esc_attr_e( 'Etkin', 'product-add-on-services-for-woocommerce' ); ?>"></td>
			<td><button type="button" class="button-link eru-aos-remove" aria-label="<?php esc_attr_e( 'Seçeneği sil', 'product-add-on-services-for-woocommerce' ); ?>"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button></td>
		</tr>
		<?php
	}

	public static function box_options( $post ) {
		$g     = self::data( $post );
		$blank = array(
			'id'          => '',
			'label'       => '',
			'description' => '',
			'price_type'  => 'fixed',
			'amount'      => 0,
			'charge'      => 'per_unit',
			'default'     => false,
			'enabled'     => true,
		);
		?>
		<table class="widefat striped eru-aos-options">
			<thead><tr>
				<th class="eru-aos-drag"><span class="screen-reader-text"><?php esc_html_e( 'Sıra', 'product-add-on-services-for-woocommerce' ); ?></span></th>
				<th><?php esc_html_e( 'Seçenek', 'product-add-on-services-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Fiyat türü', 'product-add-on-services-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Tutar / %', 'product-add-on-services-for-woocommerce' ); ?></th>
				<th><?php esc_html_e( 'Ücretlendirme', 'product-add-on-services-for-woocommerce' ); ?></th>
				<th class="eru-aos-check"><?php esc_html_e( 'Varsayılan', 'product-add-on-services-for-woocommerce' ); ?></th>
				<th class="eru-aos-check"><?php esc_html_e( 'Etkin', 'product-add-on-services-for-woocommerce' ); ?></th>
				<th><span class="screen-reader-text"><?php esc_html_e( 'Sil', 'product-add-on-services-for-woocommerce' ); ?></span></th>
			</tr></thead>
			<tbody>
			<?php
			foreach ( $g['options'] as $i => $o ) {
				self::option_row( $i, $o );
			}
			if ( ! $g['options'] ) {
				self::option_row( 0, $blank );
			}
			?>
			</tbody>
		</table>
		<p><button type="button" class="button eru-aos-add"><?php esc_html_e( 'Seçenek ekle', 'product-add-on-services-for-woocommerce' ); ?></button></p>
		<p class="description"><?php esc_html_e( 'Tutarları ürün fiyatlarını girdiğiniz gibi girin (WooCommerce vergi ayarınıza göre KDV dahil ya da hariç). Yüzde, sepetteki ürünün o anki fiyatından hesaplanır (ayarlardan normal fiyat da seçilebilir). "Adet başına" tutar adetle çarpılır; "Satır başına bir kez" sepette ayrı bir ücret satırı olarak bir kez eklenir.', 'product-add-on-services-for-woocommerce' ); ?></p>
		<script type="text/html" id="tmpl-eru-aos-row"><?php self::option_row( '__i__', $blank ); ?></script>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['eru_aos_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eru_aos_nonce'] ) ), 'eru_aos_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$in    = isset( $_POST['eru_aos'] ) && is_array( $_POST['eru_aos'] ) ? wp_unslash( $_POST['eru_aos'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Eru_AOS_Groups::sanitize() sanitises every field.
		$scope = sanitize_key( $in['scope'] ?? 'all' );
		$map   = array(
			'categories' => 'scope_categories',
			'tags'       => 'scope_tags',
			'products'   => 'scope_products',
		);

		$in['scope_ids'] = isset( $map[ $scope ] ) ? (array) ( $in[ $map[ $scope ] ] ?? array() ) : array();
		$in['options']   = array_values( (array) ( $in['options'] ?? array() ) );
		Eru_AOS_Groups::save_meta( $post_id, $in );
	}

	public static function columns( $cols ) {
		$date = $cols['date'] ?? null;
		unset( $cols['date'] );
		$cols['eru_aos_type']    = __( 'Seçim', 'product-add-on-services-for-woocommerce' );
		$cols['eru_aos_scope']   = __( 'Uygulanır', 'product-add-on-services-for-woocommerce' );
		$cols['eru_aos_options'] = __( 'Seçenekler', 'product-add-on-services-for-woocommerce' );
		if ( $date ) {
			$cols['date'] = $date;
		}
		return $cols;
	}

	public static function column( $col, $post_id ) {
		$g = Eru_AOS_Groups::get( $post_id );
		if ( ! $g ) {
			return;
		}
		switch ( $col ) {
			case 'eru_aos_type':
				echo esc_html( 'multi' === $g['type'] ? __( 'Çoklu', 'product-add-on-services-for-woocommerce' ) : __( 'Tek', 'product-add-on-services-for-woocommerce' ) );
				if ( $g['required'] ) {
					echo ' · ' . esc_html__( 'zorunlu', 'product-add-on-services-for-woocommerce' );
				}
				break;
			case 'eru_aos_scope':
				echo esc_html( Eru_AOS_Groups::scope_label( $g ) );
				if ( $g['exclude'] ) {
					/* translators: %d: number of excluded products */
					echo '<br><small>' . esc_html( sprintf( __( '%d ürün hariç', 'product-add-on-services-for-woocommerce' ), count( $g['exclude'] ) ) ) . '</small>';
				}
				break;
			case 'eru_aos_options':
				$labels = array();
				foreach ( $g['options'] as $o ) {
					$labels[] = $o['label'] . ( $o['enabled'] ? '' : ' ' . __( '(kapalı)', 'product-add-on-services-for-woocommerce' ) );
				}
				echo esc_html( implode( ', ', $labels ) );
				break;
		}
	}

	/* ------------------------------------------------------------- product tab */

	public static function product_tab( $tabs ) {
		$tabs['eru_aos'] = array(
			'label'    => __( 'Ek hizmetler', 'product-add-on-services-for-woocommerce' ),
			'target'   => 'eru_aos_product_data',
			'class'    => array( 'hide_if_external', 'hide_if_grouped' ),
			'priority' => 75,
		);
		return $tabs;
	}

	public static function product_panel() {
		global $post;
		$groups = $post ? Eru_AOS_Groups::for_product( $post->ID, false ) : array();
		echo '<div id="eru_aos_product_data" class="panel woocommerce_options_panel hidden"><div class="options_group">';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_eru_aos_disabled',
				'label'       => __( 'Ek hizmetleri kapat', 'product-add-on-services-for-woocommerce' ),
				'description' => __( 'Bu üründe hiçbir ek hizmet grubu gösterilmez.', 'product-add-on-services-for-woocommerce' ),
				'value'       => $post ? get_post_meta( $post->ID, '_eru_aos_disabled', true ) : '',
				'cbvalue'     => 'yes',
			)
		);
		echo '</div><div class="options_group"><p class="form-field"><strong>' . esc_html__( 'Bu ürüne uygulanan gruplar', 'product-add-on-services-for-woocommerce' ) . '</strong><br>';
		if ( $groups ) {
			foreach ( $groups as $g ) {
				echo '<a href="' . esc_url( get_edit_post_link( $g['id'] ) ) . '">' . esc_html( $g['title'] ) . '</a> <span class="description">(' . esc_html( Eru_AOS_Groups::scope_label( $g ) ) . ')</span><br>';
			}
		} else {
			echo esc_html__( 'Bu ürüne uygulanan hizmet grubu yok.', 'product-add-on-services-for-woocommerce' ) . '<br>';
		}
		echo '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Eru_AOS_Groups::CPT ) ) . '">' . esc_html__( 'Hizmet gruplarını yönet', 'product-add-on-services-for-woocommerce' ) . '</a></p></div></div>';
	}

	/**
	 * Saved by WooCommerce after its own nonce and capability checks.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function product_save( $product ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the product edit nonce.
		if ( isset( $_POST['_eru_aos_disabled'] ) ) {
			$product->update_meta_data( '_eru_aos_disabled', 'yes' );
		} else {
			$product->delete_meta_data( '_eru_aos_disabled' );
		}
	}

	/* ---------------------------------------------------------------- settings */

	public static function settings_section( $sections ) {
		$sections['eru_aos'] = __( 'Ek Hizmetler', 'product-add-on-services-for-woocommerce' );
		return $sections;
	}

	public static function settings( $settings, $section ) {
		if ( 'eru_aos' !== $section ) {
			return $settings;
		}
		return array(
			array(
				'type' => 'title',
				'id'   => 'eru_aos_settings',
				'name' => __( 'Ek Hizmetler', 'product-add-on-services-for-woocommerce' ),
				/* translators: %s: link to the service groups screen */
				'desc' => sprintf( __( 'Hizmet gruplarını %s ekranından yönetin.', 'product-add-on-services-for-woocommerce' ), '<a href="' . esc_url( admin_url( 'edit.php?post_type=' . Eru_AOS_Groups::CPT ) ) . '">' . esc_html__( 'WooCommerce → Ek Hizmetler', 'product-add-on-services-for-woocommerce' ) . '</a>' ),
			),
			array(
				'id'      => 'eru_aos_show_prices',
				'name'    => __( 'Seçenek fiyatları', 'product-add-on-services-for-woocommerce' ),
				'desc'    => __( 'Ürün sayfasında her seçeneğin fiyatını göster', 'product-add-on-services-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'id'      => 'eru_aos_live_total',
				'name'    => __( 'Canlı toplam', 'product-add-on-services-for-woocommerce' ),
				'desc'    => __( 'Seçimlere ve adede göre güncellenen toplamı göster (JavaScript)', 'product-add-on-services-for-woocommerce' ),
				'type'    => 'checkbox',
				'default' => 'yes',
			),
			array(
				'id'      => 'eru_aos_percent_base',
				'name'    => __( 'Yüzde hesabı', 'product-add-on-services-for-woocommerce' ),
				'type'    => 'select',
				'default' => 'current',
				'options' => array(
					'current' => __( 'Ürünün güncel fiyatı (indirim dahil)', 'product-add-on-services-for-woocommerce' ),
					'regular' => __( 'Ürünün normal fiyatı', 'product-add-on-services-for-woocommerce' ),
				),
			),
			array(
				'id'      => 'eru_aos_position',
				'name'    => __( 'Konum', 'product-add-on-services-for-woocommerce' ),
				'type'    => 'select',
				'default' => 'before_button',
				'options' => array(
					'before_button' => __( 'Sepete ekle düğmesinin üstünde', 'product-add-on-services-for-woocommerce' ),
					'manual'        => __( 'Yalnızca kısa kodla: [eru_addon_services]', 'product-add-on-services-for-woocommerce' ),
				),
				'desc'    => __( 'Kısa kod form dışına konduğunda seçimler JavaScript ile sepete ekle formuna bağlanır.', 'product-add-on-services-for-woocommerce' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'eru_aos_settings',
			),
		);
	}
}
