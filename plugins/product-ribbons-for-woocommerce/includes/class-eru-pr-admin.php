<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** WooCommerce → Şeritler ayar sayfası ve ürün düzenleme alanları. */
class Eru_Pr_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_fields' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_fields' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_PR_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=eru-pr' ) ) . '">' . esc_html__( 'Ayarlar', 'product-ribbons-for-woocommerce' ) . '</a>' );
				return $links;
			}
		);
	}

	public static function menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Ürün Şeritleri', 'product-ribbons-for-woocommerce' ),
			__( 'Ürün Şeritleri', 'product-ribbons-for-woocommerce' ),
			'manage_woocommerce',
			'eru-pr',
			array( __CLASS__, 'render' )
		);
	}

	public static function register(): void {
		register_setting(
			'eru_pr',
			Eru_Pr::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Eru_Pr::defaults(),
			)
		);
		// Ayarlar sayfası manage_woocommerce ile açıldığı için kaydetme yetkisi de aynıdır.
		add_filter( 'option_page_capability_eru_pr', fn() => 'manage_woocommerce' );
	}

	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$def   = Eru_Pr::defaults();
		$out   = array(
			'bg'        => sanitize_hex_color( (string) ( $input['bg'] ?? '' ) ) ?: $def['bg'],
			'color'     => sanitize_hex_color( (string) ( $input['color'] ?? '' ) ) ?: $def['color'],
			'font_size' => max( 8, min( 24, absint( $input['font_size'] ?? $def['font_size'] ) ) ),
			'width'     => max( 80, min( 300, absint( $input['width'] ?? $def['width'] ) ) ),
			'new_days'  => max( 1, min( 365, absint( $input['new_days'] ?? $def['new_days'] ) ) ),
			'low_stock' => max( 0, min( 1000, absint( $input['low_stock'] ?? $def['low_stock'] ) ) ),
			'rules'     => array(),
		);
		foreach ( (array) ( $input['rules'] ?? array() ) as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$type  = sanitize_key( (string) ( $row['type'] ?? '' ) );
			$label = trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( (string) ( $row['label'] ?? '' ) ) ) ); // sanitize_text_field "%15" gibi ifadeleri siler.
			if ( ! in_array( $type, Eru_Pr::RULE_TYPES, true ) || '' === $label ) {
				continue;
			}
			$term = '';
			if ( in_array( $type, array( 'product_cat', 'product_tag' ), true ) ) {
				$term = sanitize_title( (string) ( $row[ 'term_' . $type ] ?? '' ) );
				if ( '' === $term ) {
					continue;
				}
			}
			$out['rules'][] = array(
				'type'  => $type,
				'term'  => $term,
				'label' => $label,
				'bg'    => sanitize_hex_color( (string) ( $row['bg'] ?? '' ) ) ?: '',
				'color' => sanitize_hex_color( (string) ( $row['color'] ?? '' ) ) ?: '',
			);
		}
		return $out;
	}

	public static function enqueue( $hook ): void {
		if ( 'woocommerce_page_eru-pr' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'eru-pr-admin', ERU_PR_URL . 'assets/admin.js', array(), ERU_PR_VERSION, true );
		wp_enqueue_style( 'eru-pr-frontend', ERU_PR_URL . 'assets/frontend.css', array(), ERU_PR_VERSION );
	}

	private static function type_labels(): array {
		return array(
			'product_cat' => __( 'Ürün kategorisi', 'product-ribbons-for-woocommerce' ),
			'product_tag' => __( 'Ürün etiketi', 'product-ribbons-for-woocommerce' ),
			'on_sale'     => __( 'İndirimde', 'product-ribbons-for-woocommerce' ),
			'new'         => __( 'Yeni ürün', 'product-ribbons-for-woocommerce' ),
			'low_stock'   => __( 'Düşük stok', 'product-ribbons-for-woocommerce' ),
		);
	}

	private static function term_select( string $taxonomy, string $name, string $selected ): void {
		$terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		echo '<select class="eru-pr-term eru-pr-term-' . esc_attr( $taxonomy ) . '" name="' . esc_attr( $name ) . '">';
		echo '<option value="">' . esc_html__( '— Seçin —', 'product-ribbons-for-woocommerce' ) . '</option>';
		if ( is_array( $terms ) ) {
			foreach ( $terms as $t ) {
				echo '<option value="' . esc_attr( $t->slug ) . '"' . selected( $selected, $t->slug, false ) . '>' . esc_html( $t->name ) . '</option>';
			}
		}
		echo '</select>';
	}

	private static function rule_row( string $name_prefix, array $rule, string $data_attr = '' ): void {
		$labels = self::type_labels();
		$type   = $rule['type'] ?? 'on_sale';
		?>
		<tr<?php echo $data_attr; // phpcs:ignore WordPress.Security.EscapeOutput -- sabit. ?>>
			<td>
				<select class="eru-pr-type" name="<?php echo esc_attr( $name_prefix ); ?>[type]" data-field="type">
					<?php foreach ( $labels as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $type, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<?php self::term_select( 'product_cat', $name_prefix . '[term_product_cat]', 'product_cat' === $type ? (string) ( $rule['term'] ?? '' ) : '' ); ?>
				<?php self::term_select( 'product_tag', $name_prefix . '[term_product_tag]', 'product_tag' === $type ? (string) ( $rule['term'] ?? '' ) : '' ); ?>
				<span class="eru-pr-term-none description"><?php esc_html_e( 'Ek koşul yok', 'product-ribbons-for-woocommerce' ); ?></span>
			</td>
			<td><input type="text" class="regular-text" name="<?php echo esc_attr( $name_prefix ); ?>[label]" value="<?php echo esc_attr( $rule['label'] ?? '' ); ?>" placeholder="<?php esc_attr_e( 'Şerit metni', 'product-ribbons-for-woocommerce' ); ?>"></td>
			<td><input type="color" name="<?php echo esc_attr( $name_prefix ); ?>[bg]" value="<?php echo esc_attr( ( $rule['bg'] ?? '' ) ?: Eru_Pr::options()['bg'] ); ?>"></td>
			<td><input type="color" name="<?php echo esc_attr( $name_prefix ); ?>[color]" value="<?php echo esc_attr( ( $rule['color'] ?? '' ) ?: Eru_Pr::options()['color'] ); ?>"></td>
			<td><button type="button" class="button eru-pr-remove"><?php esc_html_e( 'Kaldır', 'product-ribbons-for-woocommerce' ); ?></button></td>
		</tr>
		<?php
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$o    = Eru_Pr::options();
		$name = Eru_Pr::OPTION;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Ürün Şeritleri', 'product-ribbons-for-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Kurallar yukarıdan aşağıya denenir; ürüne uyan ilk kuralın şeridi gösterilir. Ürün düzenleme ekranındaki elle girilen şerit her zaman kuralların önüne geçer. Kural yoksa hiçbir şerit gösterilmez.', 'product-ribbons-for-woocommerce' ); ?></p>
			<form action="options.php" method="post">
				<?php settings_fields( 'eru_pr' ); ?>
				<h2><?php esc_html_e( 'Kurallar', 'product-ribbons-for-woocommerce' ); ?></h2>
				<table id="eru-pr-rules" class="widefat striped" style="max-width:980px" data-name="<?php echo esc_attr( $name ); ?>[rules]" data-next="<?php echo (int) count( $o['rules'] ); ?>">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Koşul', 'product-ribbons-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Terim', 'product-ribbons-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Şerit metni', 'product-ribbons-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Arka plan', 'product-ribbons-for-woocommerce' ); ?></th>
							<th><?php esc_html_e( 'Yazı', 'product-ribbons-for-woocommerce' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $o['rules'] as $i => $rule ) : ?>
							<?php self::rule_row( $name . '[rules][' . (int) $i . ']', $rule ); ?>
						<?php endforeach; ?>
					</tbody>
				</table>
				<template id="eru-pr-row">
					<?php self::rule_row( $name . '[rules][__i__]', array( 'type' => 'on_sale' ) ); ?>
				</template>
				<p><button type="button" class="button" id="eru-pr-add"><?php esc_html_e( 'Kural ekle', 'product-ribbons-for-woocommerce' ); ?></button></p>

				<h2><?php esc_html_e( 'Görünüm ve eşikler', 'product-ribbons-for-woocommerce' ); ?></h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Varsayılan renkler', 'product-ribbons-for-woocommerce' ); ?></th>
						<td>
							<input type="color" name="<?php echo esc_attr( $name ); ?>[bg]" value="<?php echo esc_attr( $o['bg'] ); ?>">
							<input type="color" name="<?php echo esc_attr( $name ); ?>[color]" value="<?php echo esc_attr( $o['color'] ); ?>">
							<span class="description"><?php esc_html_e( 'Arka plan / yazı. Kural kendi rengini belirtmediyse kullanılır.', 'product-ribbons-for-woocommerce' ); ?></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-pr-font"><?php esc_html_e( 'Yazı boyutu (px)', 'product-ribbons-for-woocommerce' ); ?></label></th>
						<td><input id="eru-pr-font" type="number" min="8" max="24" name="<?php echo esc_attr( $name ); ?>[font_size]" value="<?php echo esc_attr( (string) $o['font_size'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-pr-width"><?php esc_html_e( 'Şerit genişliği (px)', 'product-ribbons-for-woocommerce' ); ?></label></th>
						<td><input id="eru-pr-width" type="number" min="80" max="300" name="<?php echo esc_attr( $name ); ?>[width]" value="<?php echo esc_attr( (string) $o['width'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-pr-new"><?php esc_html_e( '"Yeni ürün" süresi (gün)', 'product-ribbons-for-woocommerce' ); ?></label></th>
						<td><input id="eru-pr-new" type="number" min="1" max="365" name="<?php echo esc_attr( $name ); ?>[new_days]" value="<?php echo esc_attr( (string) $o['new_days'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="eru-pr-low"><?php esc_html_e( '"Düşük stok" eşiği (adet)', 'product-ribbons-for-woocommerce' ); ?></label></th>
						<td>
							<input id="eru-pr-low" type="number" min="0" max="1000" name="<?php echo esc_attr( $name ); ?>[low_stock]" value="<?php echo esc_attr( (string) $o['low_stock'] ); ?>">
							<span class="description"><?php esc_html_e( 'Yalnızca stok yönetimi açık ürünlerde, stok bu sayıya eşit veya altındaysa.', 'product-ribbons-for-woocommerce' ); ?></span>
						</td>
					</tr>
				</table>

				<?php if ( $o['rules'] ) : ?>
					<h2><?php esc_html_e( 'Önizleme', 'product-ribbons-for-woocommerce' ); ?></h2>
					<div style="display:flex;gap:16px;flex-wrap:wrap;margin-bottom:16px">
						<?php foreach ( $o['rules'] as $rule ) : ?>
							<div style="position:relative;width:170px;height:170px;background:#f0f0f1;border-radius:6px;overflow:hidden;--eru-pr-width:<?php echo (int) $o['width']; ?>px">
								<span class="eru-pr-wrap"><span class="eru-pr-ribbon" style="background:<?php echo esc_attr( ( $rule['bg'] ?: $o['bg'] ) ); ?>;color:<?php echo esc_attr( ( $rule['color'] ?: $o['color'] ) ); ?>;font-size:<?php echo (int) $o['font_size']; ?>px"><?php echo esc_html( $rule['label'] ); ?></span></span>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	/** Ürün düzenleme: elle şerit. */
	public static function product_fields(): void {
		global $post;
		if ( ! $post ) {
			return;
		}
		$o = Eru_Pr::options();
		echo '<div class="options_group">';
		wp_nonce_field( 'eru_pr_product', 'eru_pr_nonce' );
		woocommerce_wp_checkbox(
			array(
				'id'          => '_eru_pr_enabled',
				'label'       => __( 'Şerit', 'product-ribbons-for-woocommerce' ),
				'description' => __( 'Bu ürün için elle şerit göster (kuralların önüne geçer)', 'product-ribbons-for-woocommerce' ),
				'value'       => get_post_meta( $post->ID, '_eru_pr_enabled', true ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => '_eru_pr_text',
				'label' => __( 'Şerit metni', 'product-ribbons-for-woocommerce' ),
				'value' => get_post_meta( $post->ID, '_eru_pr_text', true ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => '_eru_pr_bg',
				'label' => __( 'Şerit arka plan rengi', 'product-ribbons-for-woocommerce' ),
				'type'  => 'color',
				'value' => get_post_meta( $post->ID, '_eru_pr_bg', true ) ?: $o['bg'],
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'    => '_eru_pr_color',
				'label' => __( 'Şerit yazı rengi', 'product-ribbons-for-woocommerce' ),
				'type'  => 'color',
				'value' => get_post_meta( $post->ID, '_eru_pr_color', true ) ?: $o['color'],
			)
		);
		echo '</div>';
	}

	public static function save_product_fields( $post_id ): void {
		if (
			! isset( $_POST['eru_pr_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['eru_pr_nonce'] ) ), 'eru_pr_product' )
			|| ! current_user_can( 'edit_post', $post_id )
		) {
			return;
		}
		update_post_meta( $post_id, '_eru_pr_enabled', isset( $_POST['_eru_pr_enabled'] ) ? 'yes' : 'no' );
		update_post_meta( $post_id, '_eru_pr_text', trim( (string) preg_replace( '/\s+/', ' ', wp_strip_all_tags( wp_unslash( (string) ( $_POST['_eru_pr_text'] ?? '' ) ) ) ) ) );
		foreach ( array( '_eru_pr_bg', '_eru_pr_color' ) as $key ) {
			$val = sanitize_hex_color( wp_unslash( $_POST[ $key ] ?? '' ) );
			if ( $val ) {
				update_post_meta( $post_id, $key, $val );
			} else {
				delete_post_meta( $post_id, $key ); // Boş bırakılan renk varsayılana döner.
			}
		}
	}
}
