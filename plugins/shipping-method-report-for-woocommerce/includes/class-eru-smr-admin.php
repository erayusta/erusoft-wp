<?php
/** WooCommerce > Kargo Yöntemi Raporu. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SMR_Admin {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_eru_smr_csv', array( __CLASS__, 'csv' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'Kargo Yöntemi Raporu', 'shipping-method-report-for-woocommerce' ), __( 'Kargo Yöntemi Raporu', 'shipping-method-report-for-woocommerce' ), 'manage_woocommerce', 'eru-smr', array( __CLASS__, 'render' ) );
	}

	/** @return array{from:string,to:string,statuses:string[]} */
	private static function input( array $src ): array {
		$from = isset( $src['from'] ) ? sanitize_text_field( wp_unslash( $src['from'] ) ) : '';
		$to   = isset( $src['to'] ) ? sanitize_text_field( wp_unslash( $src['to'] ) ) : '';
		$ok   = static fn( $d ) => (bool) preg_match( '/^\d{4}-\d{2}-\d{2}$/', $d );
		if ( ! $ok( $to ) ) {
			$to = wp_date( 'Y-m-d' );
		}
		if ( ! $ok( $from ) ) {
			$from = wp_date( 'Y-m-d', strtotime( '-30 days', current_time( 'timestamp' ) ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
		}
		$st = isset( $src['statuses'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $src['statuses'] ) ) : array();
		return array( 'from' => $from, 'to' => $to, 'statuses' => $st );
	}

	public static function csv(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Yetkiniz yok.', 'shipping-method-report-for-woocommerce' ), 403 );
		}
		check_admin_referer( 'eru_smr_csv' );
		$in  = self::input( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- verified above.
		$rep = Eru_SMR_Report::build( $in['from'], $in['to'], $in['statuses'] );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="shipping-methods-' . $in['from'] . '_' . $in['to'] . '.csv"' );
		echo Eru_SMR_Report::to_csv( $rep['rows'] ); // phpcs:ignore WordPress.Security.EscapeOutput -- CSV download.
		exit;
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$run = isset( $_GET['from'] ) || isset( $_GET['to'] ); // phpcs:ignore WordPress.Security.NonceVerification -- read-only report filter.
		$in  = self::input( $_GET ); // phpcs:ignore WordPress.Security.NonceVerification
		$all = wc_get_order_statuses();
		$sel = $in['statuses'] ? $in['statuses'] : array( 'wc-processing', 'wc-on-hold', 'wc-completed' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kargo Yöntemi Raporu', 'shipping-method-report-for-woocommerce' ); ?></h1>
			<form method="get">
				<input type="hidden" name="page" value="eru-smr">
				<label><?php esc_html_e( 'Başlangıç', 'shipping-method-report-for-woocommerce' ); ?> <input type="date" name="from" value="<?php echo esc_attr( $in['from'] ); ?>"></label>
				<label><?php esc_html_e( 'Bitiş', 'shipping-method-report-for-woocommerce' ); ?> <input type="date" name="to" value="<?php echo esc_attr( $in['to'] ); ?>"></label>
				<p>
				<?php foreach ( $all as $key => $label ) : ?>
					<label style="margin-right:12px"><input type="checkbox" name="statuses[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $sel, true ) ); ?>> <?php echo esc_html( $label ); ?></label>
				<?php endforeach; ?>
				</p>
				<?php submit_button( __( 'Raporla', 'shipping-method-report-for-woocommerce' ), 'primary', '', false ); ?>
			</form>
		<?php
		if ( $run ) {
			$rep = Eru_SMR_Report::build( $in['from'], $in['to'], $sel );
			?>
			<h2 style="margin-top:20px"><?php echo esc_html( sprintf( /* translators: 1: from, 2: to */ __( '%1$s – %2$s', 'shipping-method-report-for-woocommerce' ), $in['from'], $in['to'] ) ); ?></h2>
			<table class="widefat striped" style="max-width:900px">
				<thead><tr>
					<th><?php esc_html_e( 'Kargo yöntemi', 'shipping-method-report-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Teslim noktası', 'shipping-method-report-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Sipariş', 'shipping-method-report-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Ürün adedi', 'shipping-method-report-for-woocommerce' ); ?></th>
					<th><?php esc_html_e( 'Kargo tutarı', 'shipping-method-report-for-woocommerce' ); ?></th>
				</tr></thead>
				<tbody>
				<?php if ( ! $rep['rows'] ) : ?>
					<tr><td colspan="5"><?php esc_html_e( 'Bu aralıkta sipariş bulunamadı.', 'shipping-method-report-for-woocommerce' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rep['rows'] as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $r['label'] ); ?> <code><?php echo esc_html( $r['method_id'] ); ?></code></td>
						<td><?php echo esc_html( '' !== $r['location'] ? $r['location'] : '—' ); ?></td>
						<td><?php echo esc_html( (string) $r['orders'] ); ?></td>
						<td><?php echo esc_html( (string) $r['items'] ); ?></td>
						<td><?php echo wp_kses_post( wc_price( $r['shipping_total'] ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
				<tfoot><tr><th colspan="2"><?php esc_html_e( 'Toplam', 'shipping-method-report-for-woocommerce' ); ?></th><th><?php echo esc_html( (string) $rep['orders'] ); ?></th><th><?php echo esc_html( (string) $rep['items'] ); ?></th><th></th></tr></tfoot>
			</table>
			<?php if ( $rep['truncated'] ) : ?>
				<p class="description"><?php esc_html_e( 'Sonuç üst sınıra ulaştı; tarih aralığını daraltın.', 'shipping-method-report-for-woocommerce' ); ?></p>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
				<input type="hidden" name="action" value="eru_smr_csv">
				<input type="hidden" name="from" value="<?php echo esc_attr( $in['from'] ); ?>">
				<input type="hidden" name="to" value="<?php echo esc_attr( $in['to'] ); ?>">
				<?php foreach ( $sel as $s ) : ?>
					<input type="hidden" name="statuses[]" value="<?php echo esc_attr( $s ); ?>">
				<?php endforeach; ?>
				<?php wp_nonce_field( 'eru_smr_csv' ); ?>
				<?php submit_button( __( 'CSV indir', 'shipping-method-report-for-woocommerce' ), 'secondary', 'submit', false ); ?>
			</form>
		<?php } ?>
		</div>
		<?php
	}
}
