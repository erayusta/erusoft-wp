<?php
/**
 * Product page selector.
 *
 * @var string[] $level1     Level 1 options.
 * @var array    $conditions Conditions keyed by key.
 * @var string   $terms      Terms HTML.
 * @var bool     $leads      Lead form enabled.
 * @var string[] $labels     Level labels.
 * @var bool     $uses_l3    Whether level 3 is in use.
 * @var bool     $serial_req Serial required.
 *
 * @package device-trade-in-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<fieldset class="eru-dti" data-l3="<?php echo $uses_l3 ? '1' : '0'; ?>">
	<legend class="eru-dti__legend"><?php esc_html_e( 'Cihazını takas et', 'device-trade-in-for-woocommerce' ); ?></legend>
	<label class="eru-dti__toggle">
		<input type="checkbox" name="eru_dti[use]" value="1" class="eru-dti__use">
		<?php esc_html_e( 'Eski cihazımı takas etmek istiyorum', 'device-trade-in-for-woocommerce' ); ?>
	</label>
	<div class="eru-dti__body" hidden>
		<div class="eru-dti__row">
			<label for="eru-dti-l1"><?php echo esc_html( $labels[0] ); ?></label>
			<select id="eru-dti-l1" class="eru-dti__l1">
				<option value=""><?php esc_html_e( 'Seçin', 'device-trade-in-for-woocommerce' ); ?></option>
				<?php foreach ( $level1 as $o ) : ?>
					<option value="<?php echo esc_attr( $o ); ?>"><?php echo esc_html( $o ); ?></option>
				<?php endforeach; ?>
			</select>
		</div>
		<div class="eru-dti__row">
			<label for="eru-dti-l2"><?php echo esc_html( $labels[1] ); ?></label>
			<select id="eru-dti-l2" class="eru-dti__l2" disabled><option value=""><?php esc_html_e( 'Seçin', 'device-trade-in-for-woocommerce' ); ?></option></select>
		</div>
		<div class="eru-dti__row eru-dti__row--l3"<?php echo $uses_l3 ? '' : ' hidden'; ?>>
			<label for="eru-dti-l3"><?php echo esc_html( $labels[2] ); ?></label>
			<select id="eru-dti-l3" class="eru-dti__l3" disabled><option value=""><?php esc_html_e( 'Seçin', 'device-trade-in-for-woocommerce' ); ?></option></select>
		</div>
		<input type="hidden" name="eru_dti[device_id]" class="eru-dti__device" value="">

		<div class="eru-dti__conds" role="radiogroup" aria-label="<?php esc_attr_e( 'Cihaz durumu', 'device-trade-in-for-woocommerce' ); ?>">
			<?php foreach ( $conditions as $key => $c ) : ?>
				<label class="eru-dti__cond">
					<input type="radio" name="eru_dti[condition]" value="<?php echo esc_attr( $key ); ?>">
					<span class="eru-dti__cond-text">
						<strong><?php echo esc_html( $c['label'] ); ?></strong>
						<?php if ( '' !== $c['description'] ) : ?>
							<small><?php echo esc_html( $c['description'] ); ?></small>
						<?php endif; ?>
					</span>
					<span class="eru-dti__cond-val" data-key="<?php echo esc_attr( $key ); ?>"></span>
				</label>
			<?php endforeach; ?>
		</div>

		<div class="eru-dti__row">
			<label for="eru-dti-serial">
				<?php esc_html_e( 'Seri numarası (IMEI)', 'device-trade-in-for-woocommerce' ); ?>
				<?php echo $serial_req ? '' : ' <small>(' . esc_html__( 'isteğe bağlı', 'device-trade-in-for-woocommerce' ) . ')</small>'; ?>
			</label>
			<input type="text" id="eru-dti-serial" name="eru_dti[serial]" maxlength="120" autocomplete="off"<?php echo $serial_req ? ' required' : ''; ?>>
		</div>

		<?php if ( '' !== trim( wp_strip_all_tags( $terms ) ) ) : ?>
			<label class="eru-dti__terms">
				<input type="checkbox" name="eru_dti[terms]" value="1">
				<span><?php echo wp_kses_post( $terms ); ?></span>
			</label>
		<?php endif; ?>

		<p class="eru-dti__estimate" aria-live="polite" hidden>
			<span class="eru-dti__estimate-label"></span>
			<strong class="eru-dti__estimate-value"></strong>
			<small><?php esc_html_e( 'Değer ürün bedeliyle sınırlıdır; cihaz teslim alındığında onaylanır.', 'device-trade-in-for-woocommerce' ); ?></small>
		</p>

		<?php if ( $leads ) : ?>
			<button type="button" class="eru-dti__lead-open"><?php esc_html_e( 'Cihazım listede yok', 'device-trade-in-for-woocommerce' ); ?></button>
			<div class="eru-dti__lead" hidden>
				<p><?php esc_html_e( 'Cihazınızı yazın, size değerlendirme için dönüş yapalım.', 'device-trade-in-for-woocommerce' ); ?></p>
				<input type="text" class="eru-dti__lead-name" placeholder="<?php esc_attr_e( 'Adınız', 'device-trade-in-for-woocommerce' ); ?>" maxlength="190">
				<input type="email" class="eru-dti__lead-email" placeholder="<?php esc_attr_e( 'E-posta', 'device-trade-in-for-woocommerce' ); ?>" maxlength="190">
				<input type="tel" class="eru-dti__lead-phone" placeholder="<?php esc_attr_e( 'Telefon (isteğe bağlı)', 'device-trade-in-for-woocommerce' ); ?>" maxlength="60">
				<input type="text" class="eru-dti__lead-device" placeholder="<?php esc_attr_e( 'Cihaz marka ve modeli', 'device-trade-in-for-woocommerce' ); ?>" maxlength="255">
				<input type="text" class="eru-dti__lead-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
				<button type="button" class="eru-dti__lead-send button"><?php esc_html_e( 'Talebi gönder', 'device-trade-in-for-woocommerce' ); ?></button>
				<p class="eru-dti__lead-msg" role="status"></p>
			</div>
		<?php endif; ?>
	</div>
</fieldset>
