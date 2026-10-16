<?php
/**
 * The payment gateway (classic checkout).
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gateway "eru_vnv". Settings live in the standard gateway option (read everywhere through Eru_VNV_Settings);
 * client ids and secrets are write-only fields. The checkout form collects the identity number, the mobile
 * number, the installment count and the SMS code; the code is sent and verified through the OTP controller,
 * and process_payment() hands everything to Eru_VNV_Payment, which trusts nothing the browser says about
 * verification.
 */
class Eru_VNV_Gateway extends WC_Payment_Gateway {

	/** Sets the gateway up. */
	public function __construct() {
		$this->id                 = Eru_VNV_Settings::GATEWAY_ID;
		$this->method_title       = __( 'VakıfBank Vinov', 'vakifbank-vinov-integration-for-woocommerce' );
		$this->method_description = __( 'Müşterinin TC kimlik numarasına banka SMS kodu gönderir, kodu ve borçlu kontrolünü sunucu tarafında bankaya doğrulatır ve sipariş tutarında Vinov talimatı oluşturur. Yalnızca klasik ödeme sayfasında çalışır.', 'vakifbank-vinov-integration-for-woocommerce' );
		$this->has_fields         = true;
		// No refunds through the bank in 1.0.0: cancelling a waiting instruction is a separate admin action.
		$this->supports           = array( 'products' );

		$this->init_form_fields();
		$this->init_settings();
		$this->title       = (string) $this->get_option( 'title' );
		$this->description = (string) $this->get_option( 'description' );
		$this->enabled     = (string) $this->get_option( 'enabled' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	}

	/** Static hooks (registered once from the bootstrap). */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'admin_notices', array( __CLASS__, 'blocks_notice' ) );
	}

	/** Settings form. */
	public function init_form_fields() {
		// Category and status choices are loaded only when the form is rendered (admin_options()).
		$statuses = array( 'on-hold' => __( 'Beklemede', 'vakifbank-vinov-integration-for-woocommerce' ) );
		$cats     = array();
		$max = array();
		for ( $i = 1; $i <= Eru_VNV_Settings::MAX_INSTALLMENTS; $i++ ) {
			$max[ (string) $i ] = 1 === $i ? __( 'Yalnızca tek çekim', 'vakifbank-vinov-integration-for-woocommerce' ) : (string) $i;
		}
		$d = Eru_VNV_Settings::defaults();

		$this->form_fields = array(
			'enabled'              => array(
				'title'   => __( 'Etkin', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'    => 'checkbox',
				'label'   => __( 'Vinov ile ödemeyi aç', 'vakifbank-vinov-integration-for-woocommerce' ),
				'default' => 'no',
			),
			'title'                => array(
				'title'   => __( 'Başlık', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'    => 'text',
				'default' => $d['title'],
			),
			'description'          => array(
				'title'   => __( 'Açıklama', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'    => 'textarea',
				'default' => $d['description'],
			),
			'testmode'             => array(
				'title'       => __( 'Test ortamı', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'        => 'checkbox',
				'label'       => __( 'Bankanın test ortamını kullan', 'vakifbank-vinov-integration-for-woocommerce' ),
				'description' => __( 'Test modunda da SMS kodu bankada doğrulanır; yalnızca test ortamı adresi ve test istemci bilgileri kullanılır.', 'vakifbank-vinov-integration-for-woocommerce' ),
				'default'     => 'yes',
			),
			'test_section'         => array(
				'title' => __( 'Test ortamı bilgileri', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'  => 'title',
			),
			'test_client_id'       => array(
				'title' => __( 'Client ID (test)', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'  => 'eru_vnv_secret',
			),
			'test_client_secret'   => array(
				'title' => __( 'Client secret (test)', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'  => 'eru_vnv_secret',
			),
			'test_base_url'        => array(
				'title'       => __( 'API adresi (test)', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => 'https://',
				'description' => __( 'Bankanın size verdiği API adresini girin (yalnızca https). Adres girilmeden ödeme yöntemi gösterilmez.', 'vakifbank-vinov-integration-for-woocommerce' ),
			),
			'live_section'         => array(
				'title' => __( 'Canlı ortam bilgileri', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'  => 'title',
			),
			'live_client_id'       => array(
				'title' => __( 'Client ID (canlı)', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'  => 'eru_vnv_secret',
			),
			'live_client_secret'   => array(
				'title' => __( 'Client secret (canlı)', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'  => 'eru_vnv_secret',
			),
			'live_base_url'        => array(
				'title'       => __( 'API adresi (canlı)', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'        => 'text',
				'placeholder' => 'https://',
				'description' => __( 'Bankanın size verdiği API adresini girin (yalnızca https). Adres girilmeden ödeme yöntemi gösterilmez.', 'vakifbank-vinov-integration-for-woocommerce' ),
			),
			'merchant_section'     => array(
				'title'       => __( 'İşyeri bilgileri', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Bu değerleri bankanız verir.', 'vakifbank-vinov-integration-for-woocommerce' ),
			),
			'workplace_id'         => array(
				'title'             => __( 'WorkPlace ID', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => '1' ),
			),
			'consent_id'           => array(
				'title' => __( 'Consent ID', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'  => 'text',
			),
			'payment_purpose'      => array(
				'title'             => __( 'Ödeme amacı kodu', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'min' => '1',
					'max' => '99',
				),
			),
			'otp_transaction_code' => array(
				'title' => __( 'OTP işlem kodu', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'  => 'text',
			),
			'expiry_days'          => array(
				'title'             => __( 'Tek çekim vadesi (gün)', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'              => 'number',
				'default'           => '30',
				'custom_attributes' => array(
					'min' => '1',
					'max' => '365',
				),
			),
			'installment_section'  => array(
				'title' => __( 'Taksit ve taksit farkı', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'  => 'title',
			),
			'max_installments'     => array(
				'title'   => __( 'En fazla taksit', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'    => 'select',
				'options' => $max,
				'default' => '12',
			),
			'installment_rates'    => array(
				'title'       => __( 'Taksit farkı oranları', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'        => 'eru_vnv_rates',
				'description' => __( 'Yalnızca işaretli taksit sayıları sunulur. Oran, ürünler ve kargo toplamına uygulanır ve siparişe "Vinov taksit farkı" satırı olarak eklenir; talimat tutarı sipariş toplamına eşittir.', 'vakifbank-vinov-integration-for-woocommerce' ),
				'default'     => array(),
			),
			'restrict_section'     => array(
				'title'       => __( 'Ürün kısıtlaması', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Boş bırakılırsa tüm ürünlerde sunulur. Doluysa sepetteki her ürün listelenen bir kategoride ya da ürünlerde olmalıdır.', 'vakifbank-vinov-integration-for-woocommerce' ),
			),
			'allowed_categories'   => array(
				'title'   => __( 'İzin verilen kategoriler', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'    => 'eru_vnv_terms',
				'options' => $cats,
				'default' => array(),
			),
			'allowed_products'     => array(
				'title'       => __( 'İzin verilen ürün ID\'leri', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'        => 'text',
				'description' => __( 'Virgülle ayrılmış ürün ID\'leri.', 'vakifbank-vinov-integration-for-woocommerce' ),
			),
			'order_section'        => array(
				'title' => __( 'Sipariş ve günlük', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'  => 'title',
			),
			'pending_status'       => array(
				'title'       => __( 'Talimat sonrası sipariş durumu', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'        => 'select',
				'options'     => $statuses,
				'default'     => 'on-hold',
				'description' => __( 'Müşteri talimatı bankada onaylayana kadar siparişin bekleyeceği durum.', 'vakifbank-vinov-integration-for-woocommerce' ),
			),
			'log_level'            => array(
				'title'       => __( 'Günlük düzeyi', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'        => 'select',
				'options'     => array(
					'off'   => __( 'Kapalı', 'vakifbank-vinov-integration-for-woocommerce' ),
					'error' => __( 'Yalnızca hatalar', 'vakifbank-vinov-integration-for-woocommerce' ),
					'info'  => __( 'Bilgi', 'vakifbank-vinov-integration-for-woocommerce' ),
					'debug' => __( 'Ayrıntılı (karartılmış istek/yanıt)', 'vakifbank-vinov-integration-for-woocommerce' ),
				),
				'default'     => 'error',
				'description' => __( 'WooCommerce → Durum → Günlükler, kaynak "eru-vnv". TC kimlik no, kod ve kimlik bilgileri günlüğe yazılmaz.', 'vakifbank-vinov-integration-for-woocommerce' ),
			),
			'sync_section'         => array(
				'title'       => __( 'Talimat takibi ve saklama', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'        => 'title',
				'description' => __( 'Açık talimatların durumu bankadan zamanlanmış olarak sorgulanır: onaylanan talimatın siparişi ödendi sayılır, iptal edilen talimatın siparişi iptal edilir.', 'vakifbank-vinov-integration-for-woocommerce' ),
			),
			'sync_interval'        => array(
				'title'   => __( 'Durum sorgulama aralığı', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'    => 'select',
				'options' => array(
					'15' => __( '15 dakika', 'vakifbank-vinov-integration-for-woocommerce' ),
					'30' => __( '30 dakika', 'vakifbank-vinov-integration-for-woocommerce' ),
					'60' => __( '60 dakika', 'vakifbank-vinov-integration-for-woocommerce' ),
				),
				'default' => '30',
			),
			'giveup_days'          => array(
				'title'             => __( 'Takibi bırakma süresi (gün)', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'              => 'number',
				'default'           => '30',
				'custom_attributes' => array(
					'min' => '1',
					'max' => '180',
				),
				'description'       => __( 'Bu süre içinde onaylanmayan talimatlar ve sonucu belirsiz kalan denemeler artık sorgulanmaz; siparişe banka panelinden kontrol edilmesi için not düşülür. Süre en az "Tek çekim vadesi" + 1 gün olur (varsayılanlarla 31 gün); daha kısa bir değer bu alt sınıra yükseltilir.', 'vakifbank-vinov-integration-for-woocommerce' ),
			),
			'retention_days'       => array(
				'title'             => __( 'Kişisel veri saklama süresi (gün)', 'vakifbank-vinov-integration-for-woocommerce' ),
				'type'              => 'number',
				'default'           => '365',
				'custom_attributes' => array(
					'min' => '30',
					'max' => '3650',
				),
				'description'       => __( 'Siparişteki maskeli TC kimlik no ve telefon ile kapanmış talimat kayıtları bu süreden sonra silinir. Sipariş notları korunur.', 'vakifbank-vinov-integration-for-woocommerce' ),
			),
		);
	}

	/** Fills the category and status choices (only when the settings form is shown). */
	private function load_admin_choices() {
		foreach ( wc_get_order_statuses() as $slug => $label ) {
			$slug = substr( $slug, 3 );
			if ( ! in_array( $slug, array( 'pending', 'processing', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft' ), true ) ) {
				$this->form_fields['pending_status']['options'][ $slug ] = $label;
			}
		}
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => 500,
			)
		);
		foreach ( is_array( $terms ) ? $terms : array() as $t ) {
			$this->form_fields['allowed_categories']['options'][ (string) $t->term_id ] = $t->name;
		}
	}

	/** Admin form with the classic-checkout note. */
	public function admin_options() {
		$this->load_admin_choices();
		if ( self::checkout_uses_blocks() ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'Ödeme sayfanız blok tabanlı. Vinov 1.0.0 yalnızca klasik ödeme sayfasında ([woocommerce_checkout] kısa kodu) gösterilir; blok ödeme sayfasında kullanılamaz.', 'vakifbank-vinov-integration-for-woocommerce' ) . '</p></div>';
		}
		parent::admin_options();
	}

	/** @return bool The checkout page uses the Checkout block. */
	public static function checkout_uses_blocks() {
		$id = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'checkout' ) : 0;
		/**
		 * Filters whether the store's checkout is the Checkout block (Vinov is then hidden).
		 *
		 * @param bool $uses_blocks The checkout page contains the woocommerce/checkout block.
		 */
		return (bool) apply_filters( 'eru_vnv_checkout_uses_blocks', $id > 0 && has_block( 'woocommerce/checkout', $id ) );
	}

	/** Site-wide note while the gateway is on and the checkout is the block. */
	public static function blocks_notice() {
		if ( ! Eru_VNV_Settings::active_flag() || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || false === strpos( (string) $screen->id, 'woocommerce' ) || ! self::checkout_uses_blocks() ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'VakıfBank Vinov açık, ancak ödeme sayfanız blok tabanlı: Vinov yalnızca klasik ödeme sayfasında gösterilir.', 'vakifbank-vinov-integration-for-woocommerce' ) . '</p></div>';
	}

	/**
	 * Write-only field: renders empty with a "Kayıtlı" hint; blank keeps the stored value.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_eru_vnv_secret_html( $key, $data ) {
		$field = $this->get_field_key( $key );
		$saved = '' !== (string) $this->get_option( $key );
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $data['title'] ?? '' ); ?></label></th>
			<td class="forminp">
				<input class="input-text regular-input" type="password" name="<?php echo esc_attr( $field ); ?>" id="<?php echo esc_attr( $field ); ?>" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $saved ? __( 'Kayıtlı — değiştirmek için yeni değer girin', 'vakifbank-vinov-integration-for-woocommerce' ) : '' ); ?>" />
				<?php if ( $saved ) : ?>
					<p class="description"><strong><?php esc_html_e( 'Kayıtlı', 'vakifbank-vinov-integration-for-woocommerce' ); ?></strong> ·
						<label><input type="checkbox" name="<?php echo esc_attr( $field ); ?>_clear" value="1" /> <?php esc_html_e( 'Kayıtlı değeri sil', 'vakifbank-vinov-integration-for-woocommerce' ); ?></label>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param string $key   Field key.
	 * @param mixed  $value Posted value.
	 * @return string Stored value.
	 */
	public function validate_eru_vnv_secret_field( $key, $value ) {
		$post = $this->get_post_data();
		if ( ! empty( $post[ $this->get_field_key( $key ) . '_clear' ] ) ) {
			return '';
		}
		$value = trim( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', stripslashes( is_scalar( $value ) ? (string) $value : '' ) ) );
		return '' === $value ? (string) $this->get_option( $key ) : substr( $value, 0, 500 );
	}

	/**
	 * Installment rate table.
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_eru_vnv_rates_html( $key, $data ) {
		$field = $this->get_field_key( $key );
		$rows  = $this->get_option( $key );
		$rows  = is_array( $rows ) ? $rows : array();
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><?php echo esc_html( $data['title'] ?? '' ); ?></th>
			<td class="forminp">
				<table class="widefat striped" style="max-width:420px">
					<thead><tr><th><?php esc_html_e( 'Taksit', 'vakifbank-vinov-integration-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Sun', 'vakifbank-vinov-integration-for-woocommerce' ); ?></th><th><?php esc_html_e( 'Taksit farkı (%)', 'vakifbank-vinov-integration-for-woocommerce' ); ?></th></tr></thead>
					<tbody>
					<?php for ( $n = 2; $n <= Eru_VNV_Settings::MAX_INSTALLMENTS; $n++ ) : ?>
						<?php $row = is_array( $rows[ $n ] ?? null ) ? $rows[ $n ] : array(); ?>
						<tr>
							<td><?php echo esc_html( (string) $n ); ?></td>
							<td><input type="checkbox" name="<?php echo esc_attr( $field . '[' . $n . '][enabled]' ); ?>" value="yes" <?php checked( 'yes', $row['enabled'] ?? '' ); ?> aria-label="<?php echo esc_attr( sprintf( /* translators: %d: installment count */ __( '%d taksit sun', 'vakifbank-vinov-integration-for-woocommerce' ), $n ) ); ?>" /></td>
							<td><input type="number" step="0.01" min="0" max="100" style="width:7em" name="<?php echo esc_attr( $field . '[' . $n . '][rate]' ); ?>" value="<?php echo esc_attr( (string) ( $row['rate'] ?? '0' ) ); ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: installment count */ __( '%d taksit farkı', 'vakifbank-vinov-integration-for-woocommerce' ), $n ) ); ?>" /></td>
						</tr>
					<?php endfor; ?>
					</tbody>
				</table>
				<?php echo $this->get_description_html( wp_parse_args( $data, array( 'desc_tip' => false, 'description' => '' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param string $key   Field key.
	 * @param mixed  $value Posted rows.
	 * @return array<int, array{enabled:string, rate:string}>
	 */
	public function validate_eru_vnv_rates_field( $key, $value ) {
		$out = array();
		$in  = is_array( $value ) ? $value : array();
		for ( $n = 2; $n <= Eru_VNV_Settings::MAX_INSTALLMENTS; $n++ ) {
			$row  = is_array( $in[ $n ] ?? null ) ? $in[ $n ] : array();
			$rate = (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( (string) ( $row['rate'] ?? '0' ) ) ) );
			$out[ $n ] = array(
				'enabled' => 'yes' === ( $row['enabled'] ?? '' ) ? 'yes' : 'no',
				'rate'    => wc_format_decimal( max( 0, min( 100, $rate ) ), 2 ),
			);
		}
		return $out;
	}

	/**
	 * Category allow-list (several values).
	 *
	 * @param string $key  Field key.
	 * @param array  $data Field data.
	 * @return string
	 */
	public function generate_eru_vnv_terms_html( $key, $data ) {
		$field = $this->get_field_key( $key );
		$saved = array_map( 'strval', (array) $this->get_option( $key, array() ) );
		ob_start();
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label for="<?php echo esc_attr( $field ); ?>"><?php echo esc_html( $data['title'] ?? '' ); ?></label></th>
			<td class="forminp">
				<select multiple="multiple" class="wc-enhanced-select" style="width:400px" name="<?php echo esc_attr( $field ); ?>[]" id="<?php echo esc_attr( $field ); ?>">
					<?php foreach ( (array) ( $data['options'] ?? array() ) as $id => $label ) : ?>
						<option value="<?php echo esc_attr( (string) $id ); ?>" <?php selected( in_array( (string) $id, $saved, true ) ); ?>><?php echo esc_html( (string) $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * @param string $key   Field key.
	 * @param mixed  $value Posted ids.
	 * @return string[] Term ids.
	 */
	public function validate_eru_vnv_terms_field( $key, $value ) {
		return array_values( array_map( 'strval', array_filter( array_map( 'absint', is_array( $value ) ? $value : array() ) ) ) );
	}

	/**
	 * API base URL: empty clears it; an invalid one keeps the stored value and shows a settings error.
	 *
	 * @param string $key   Key.
	 * @param mixed  $value Posted.
	 * @return string
	 */
	private function validate_base_url( $key, $value ) {
		$raw   = trim( is_scalar( $value ) ? stripslashes( (string) $value ) : '' );
		$clean = Eru_VNV_Settings::clean_base_url( $raw );
		if ( '' !== $raw && '' === $clean ) {
			$msg = __( 'API adresi geçersiz (yalnızca https, kullanıcı/sorgu içermeyen bir adres). Önceki değer korundu.', 'vakifbank-vinov-integration-for-woocommerce' );
			$this->add_error( $msg );
			if ( class_exists( 'WC_Admin_Settings' ) ) {
				WC_Admin_Settings::add_error( $msg );
			}
			return (string) $this->get_option( $key );
		}
		return $clean;
	}

	/** @param string $key Key. @param mixed $value Posted. @return string */
	public function validate_test_base_url_field( $key, $value ) {
		return $this->validate_base_url( $key, $value );
	}

	/** @param string $key Key. @param mixed $value Posted. @return string */
	public function validate_live_base_url_field( $key, $value ) {
		return $this->validate_base_url( $key, $value );
	}

	/** @return bool Shown at checkout. */
	public function is_available() {
		if ( ! parent::is_available() || ! Eru_VNV_Settings::ready() || 'TRY' !== get_woocommerce_currency() ) {
			return false;
		}
		if ( is_checkout_pay_page() || self::checkout_uses_blocks() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( function_exists( 'WC' ) && method_exists( WC(), 'is_rest_api_request' ) && WC()->is_rest_api_request() ) ) {
			// Order-pay and the block checkout (page or Store API, also through ?rest_route= with plain
			// permalinks) are not supported in 1.0.0.
			return false;
		}
		return self::cart_allowed();
	}

	/** @return bool Every cart item passes the allow-list (empty lists allow everything). */
	public static function cart_allowed() {
		$cats  = Eru_VNV_Settings::allowed_categories();
		$prods = Eru_VNV_Settings::allowed_products();
		if ( ! $cats && ! $prods ) {
			return true;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
			return true;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$pid = (int) ( $item['product_id'] ?? 0 );
			$vid = (int) ( $item['variation_id'] ?? 0 );
			$ok  = in_array( $pid, $prods, true ) || ( $vid && in_array( $vid, $prods, true ) ) || ( $cats && array_intersect( wc_get_product_term_ids( $pid, 'product_cat' ), $cats ) );
			if ( ! $ok ) {
				return false;
			}
		}
		return true;
	}

	/** Checkout fields. */
	public function payment_fields() {
		if ( '' !== $this->description ) {
			echo wp_kses_post( wpautop( wptexturize( $this->description ) ) );
		}
		$sel      = Eru_VNV_Installments::selected();
		$verified = Eru_VNV_Session::verified_for_cart();
		?>
		<fieldset id="eru-vnv-fields" class="eru-vnv-fields" data-verified="<?php echo $verified ? '1' : '0'; ?>">
			<p class="form-row form-row-wide">
				<label for="eru_vnv_tckn"><?php esc_html_e( 'TC kimlik no', 'vakifbank-vinov-integration-for-woocommerce' ); ?>&nbsp;<abbr class="required" title="<?php esc_attr_e( 'zorunlu', 'vakifbank-vinov-integration-for-woocommerce' ); ?>">*</abbr></label>
				<input type="text" class="input-text" id="eru_vnv_tckn" name="eru_vnv_tckn" inputmode="numeric" pattern="[0-9]{11}" maxlength="11" autocomplete="off" placeholder="<?php esc_attr_e( '11 haneli TC kimlik no', 'vakifbank-vinov-integration-for-woocommerce' ); ?>" />
			</p>
			<p class="form-row form-row-wide">
				<label for="eru_vnv_phone"><?php esc_html_e( 'Cep telefonu', 'vakifbank-vinov-integration-for-woocommerce' ); ?>&nbsp;<abbr class="required" title="<?php esc_attr_e( 'zorunlu', 'vakifbank-vinov-integration-for-woocommerce' ); ?>">*</abbr></label>
				<input type="tel" class="input-text" id="eru_vnv_phone" name="eru_vnv_phone" autocomplete="tel" placeholder="5XX XXX XX XX" />
			</p>
			<p class="form-row form-row-wide">
				<label for="eru_vnv_installments"><?php esc_html_e( 'Taksit', 'vakifbank-vinov-integration-for-woocommerce' ); ?></label>
				<select id="eru_vnv_installments" name="eru_vnv_installments">
					<?php foreach ( Eru_VNV_Installments::allowed() as $n ) : ?>
						<option value="<?php echo esc_attr( (string) $n ); ?>" <?php selected( $sel, $n ); ?>>
							<?php
							if ( 1 === $n ) {
								esc_html_e( 'Tek çekim', 'vakifbank-vinov-integration-for-woocommerce' );
							} else {
								$rate = Eru_VNV_Installments::rate( $n );
								echo esc_html( $rate > 0 ? sprintf( /* translators: 1: count, 2: rate */ __( '%1$d taksit (+%%%2$s)', 'vakifbank-vinov-integration-for-woocommerce' ), $n, rtrim( rtrim( number_format( $rate, 2, ',', '' ), '0' ), ',' ) ) : sprintf( /* translators: %d: count */ __( '%d taksit', 'vakifbank-vinov-integration-for-woocommerce' ), $n ) );
							}
							?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>
			<p class="eru-vnv-hp" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden">
				<label><?php esc_html_e( 'Bu alanı boş bırakın', 'vakifbank-vinov-integration-for-woocommerce' ); ?> <input type="text" name="eru_vnv_website" value="" tabindex="-1" autocomplete="off" /></label>
			</p>
			<p class="form-row form-row-wide">
				<button type="button" class="button" id="eru-vnv-send"><?php esc_html_e( 'Kod gönder', 'vakifbank-vinov-integration-for-woocommerce' ); ?></button>
			</p>
			<div id="eru-vnv-otp-row" hidden>
				<p class="form-row form-row-wide">
					<label for="eru_vnv_otp"><?php esc_html_e( 'SMS doğrulama kodu', 'vakifbank-vinov-integration-for-woocommerce' ); ?></label>
					<input type="text" class="input-text" id="eru_vnv_otp" name="eru_vnv_otp" inputmode="numeric" maxlength="10" autocomplete="one-time-code" />
				</p>
				<p class="form-row form-row-wide">
					<button type="button" class="button" id="eru-vnv-verify"><?php esc_html_e( 'Kodu doğrula', 'vakifbank-vinov-integration-for-woocommerce' ); ?></button>
				</p>
			</div>
			<p id="eru-vnv-status" class="eru-vnv-status" role="status" aria-live="polite"></p>
			<p id="eru-vnv-verified" class="eru-vnv-verified" <?php echo $verified ? '' : 'hidden'; ?>><strong><?php esc_html_e( 'Doğrulandı', 'vakifbank-vinov-integration-for-woocommerce' ); ?></strong></p>
		</fieldset>
		<?php
	}

	/** @param string $key Posted key. @return string */
	private static function posted( $key ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified the checkout nonce.
		return isset( $_POST[ $key ] ) && is_scalar( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	/** @return bool Fields look right (the bank verification is re-checked in process_payment). */
	public function validate_fields() {
		if ( ! Eru_VNV_Identity::valid_tckn( Eru_VNV_Identity::clean_tckn( self::posted( 'eru_vnv_tckn' ) ) ) ) {
			wc_add_notice( __( 'Geçerli bir TC kimlik numarası girin.', 'vakifbank-vinov-integration-for-woocommerce' ), 'error' );
			return false;
		}
		if ( '' === Eru_VNV_Identity::normalize_phone( self::posted( 'eru_vnv_phone' ) ) ) {
			wc_add_notice( __( 'Geçerli bir cep telefonu numarası girin (5XX XXX XX XX).', 'vakifbank-vinov-integration-for-woocommerce' ), 'error' );
			return false;
		}
		if ( 0 === Eru_VNV_Installments::normalize( self::posted( 'eru_vnv_installments' ) ) ) {
			wc_add_notice( __( 'Geçersiz taksit seçimi.', 'vakifbank-vinov-integration-for-woocommerce' ), 'error' );
			return false;
		}
		if ( ! Eru_VNV_Session::verified() ) {
			wc_add_notice( __( 'Lütfen önce SMS doğrulamasını tamamlayın.', 'vakifbank-vinov-integration-for-woocommerce' ), 'error' );
			return false;
		}
		return true;
	}

	/**
	 * @param int $order_id Order id.
	 * @return array
	 */
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_add_notice( __( 'Sipariş bulunamadı.', 'vakifbank-vinov-integration-for-woocommerce' ), 'error' );
			return array( 'result' => 'failure' );
		}
		$r = Eru_VNV_Payment::process(
			$order,
			array(
				'tckn'         => self::posted( 'eru_vnv_tckn' ),
				'phone'        => self::posted( 'eru_vnv_phone' ),
				'installments' => self::posted( 'eru_vnv_installments' ),
			)
		);
		if ( ! $r['ok'] ) {
			wc_add_notice( esc_html( $r['message'] ), 'error' );
			Eru_VNV_Logger::info( sprintf( 'ödeme reddedildi: sipariş %d, neden %s', $order->get_id(), $r['code'] ) );
			/**
			 * Fires when process_payment refuses (reason codes: not_verified, expired, tckn_mismatch,
			 * phone_mismatch, installments_invalid, installments_changed, total_changed, cart_changed, busy,
			 * bank_refused, ambiguous, already_created, disabled).
			 *
			 * @param int    $order_id Order id.
			 * @param string $code     Reason code.
			 */
			do_action( 'eru_vnv_payment_refused', $order->get_id(), $r['code'] );
			// Refresh the payment box: a consumed verification must not keep showing "Doğrulandı".
			return array(
				'result'  => 'failure',
				'refresh' => true,
			);
		}
		return array(
			'result'   => 'success',
			'redirect' => $this->get_return_url( $order ),
		);
	}

	/** Checkout script (only while the gateway is on). */
	public static function enqueue() {
		if ( ! Eru_VNV_Settings::active_flag() || ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() || is_checkout_pay_page() || self::checkout_uses_blocks() ) {
			return;
		}
		wp_enqueue_script( 'eru-vnv-checkout', ERU_VNV_URL . 'assets/checkout.js', array( 'jquery', 'wc-checkout' ), ERU_VNV_VERSION, true );
		wp_localize_script(
			'eru-vnv-checkout',
			'eruVnv',
			array(
				'sendUrl'   => WC_AJAX::get_endpoint( 'eru_vnv_send_otp' ),
				'verifyUrl' => WC_AJAX::get_endpoint( 'eru_vnv_verify_otp' ),
				'nonce'     => wp_create_nonce( Eru_VNV_OTP_Controller::NONCE ),
				'i18n'      => array(
					'sending'   => __( 'Kod gönderiliyor…', 'vakifbank-vinov-integration-for-woocommerce' ),
					'verifying' => __( 'Doğrulanıyor…', 'vakifbank-vinov-integration-for-woocommerce' ),
					'tckn'      => __( '11 haneli TC kimlik numaranızı girin.', 'vakifbank-vinov-integration-for-woocommerce' ),
					'phone'     => __( 'Cep telefonu numaranızı girin.', 'vakifbank-vinov-integration-for-woocommerce' ),
					'otp'       => __( 'SMS ile gelen kodu girin.', 'vakifbank-vinov-integration-for-woocommerce' ),
					'error'     => __( 'Bir hata oluştu. Lütfen tekrar deneyin.', 'vakifbank-vinov-integration-for-woocommerce' ),
					'changed'   => __( 'Taksit seçimi değişti; lütfen yeniden kod isteyip doğrulayın.', 'vakifbank-vinov-integration-for-woocommerce' ),
				),
			)
		);
	}
}
