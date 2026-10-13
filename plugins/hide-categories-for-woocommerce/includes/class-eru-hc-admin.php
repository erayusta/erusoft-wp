<?php
/**
 * Settings page.
 *
 * @package hide-categories-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_HC_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
	}

	public static function menu() {
		add_submenu_page( 'woocommerce', __( 'Kategori Gizle', 'hide-categories-for-woocommerce' ), __( 'Kategori Gizle', 'hide-categories-for-woocommerce' ), 'manage_woocommerce', 'eru-hc', array( __CLASS__, 'render' ) );
	}

	public static function register() {
		// Saved through options.php: let the menu's capability (not manage_options) save it.
		add_filter( 'option_page_capability_eru_hc', fn() => 'manage_woocommerce' );
		register_setting(
			'eru_hc',
			Eru_HC::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => array(),
			)
		);
	}

	public static function sanitize( $in ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return get_option( Eru_HC::OPTION, array() );
		}
		$in  = is_array( $in ) ? $in : array();
		$ids = isset( $in['ids'] ) && is_array( $in['ids'] ) ? array_values( array_unique( array_filter( array_map( 'absint', $in['ids'] ) ) ) ) : array();
		return array(
			'ids'   => $ids,
			'block' => empty( $in['block'] ) ? 'no' : 'yes',
		);
	}

	private static function checklist( $terms, $parent, $selected, $depth = 0 ) {
		foreach ( $terms as $t ) {
			if ( (int) $t->parent !== (int) $parent ) {
				continue;
			}
			printf(
				'<li style="margin-left:%dpx"><label><input type="checkbox" name="%s[ids][]" value="%d" %s> %s <span class="description">(%d)</span></label></li>',
				(int) $depth * 18,
				esc_attr( Eru_HC::OPTION ),
				(int) $t->term_id,
				checked( in_array( (int) $t->term_id, $selected, true ), true, false ),
				esc_html( $t->name ),
				(int) $t->count
			);
			self::checklist( $terms, $t->term_id, $selected, $depth + 1 );
		}
	}

	public static function render() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Bu sayfaya erişim yetkiniz yok.', 'hide-categories-for-woocommerce' ) );
		}
		$opt   = get_option( Eru_HC::OPTION, array() );
		$sel   = array_map( 'intval', isset( $opt['ids'] ) && is_array( $opt['ids'] ) ? $opt['ids'] : array() );
		$terms = get_terms( array( 'taxonomy' => 'product_cat', 'hide_empty' => false ) );
		$terms = is_wp_error( $terms ) ? array() : $terms;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kategori Gizle', 'hide-categories-for-woocommerce' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'eru_hc' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Gizlenecek kategoriler', 'hide-categories-for-woocommerce' ); ?></th>
						<td>
							<ul style="max-height:320px;overflow:auto;margin:0;padding:8px 12px;border:1px solid #c3c4c7;background:#fff;max-width:480px">
								<?php self::checklist( $terms, 0, $sel ); ?>
							</ul>
							<p class="description"><?php esc_html_e( 'Seçilen kategoriler ve alt kategorileri mağaza listelerinde, bileşenlerde, kategori listelerinde ve aramada görünmez. Birini de seçmezseniz hiçbir şey değişmez.', 'hide-categories-for-woocommerce' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Doğrudan erişim', 'hide-categories-for-woocommerce' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( Eru_HC::OPTION ); ?>[block]" value="1" <?php checked( isset( $opt['block'] ) ? $opt['block'] : 'no', 'yes' ); ?>>
								<?php esc_html_e( 'Gizli kategori sayfaları ve yalnızca gizli kategorilerde olan ürünler için 404 döndür', 'hide-categories-for-woocommerce' ); ?></label>
							<p class="description"><?php esc_html_e( 'Kapalıyken adresini bilen biri kategori sayfasına yine ulaşabilir. Mağaza yöneticileri her zaman görebilir.', 'hide-categories-for-woocommerce' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
