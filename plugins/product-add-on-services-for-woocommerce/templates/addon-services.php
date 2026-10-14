<?php
/**
 * Add-on services on the product page.
 *
 * Override by copying to yourtheme/product-add-on-services-for-woocommerce/addon-services.php.
 * Keep the input names (eru_aos[group_id] / eru_aos[group_id][]) and the data attributes.
 *
 * @package product-add-on-services-for-woocommerce
 *
 * @var WC_Product  $product
 * @var array       $groups
 * @var array|null  $posted      Choices of a rejected submission (re-selected), or null.
 * @var bool        $show_prices
 * @var float|null  $pb          Display price percentages are taken from (null for variable products).
 * @var array       $config      Data for the live total script.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="eru-aos" data-config="<?php echo esc_attr( wp_json_encode( $config ) ); ?>">
	<?php
	foreach ( $groups as $g ) :
		$gid    = (int) $g['id'];
		$multi  = 'multi' === $g['type'];
		$name   = 'eru_aos[' . $gid . ']' . ( $multi ? '[]' : '' );
		$chosen = null === $posted ? null : (array) ( $posted[ $gid ] ?? array() );
		$any    = false;
		foreach ( $g['options'] as $o ) {
			$any = $any || ( null === $chosen ? $o['default'] : in_array( $o['id'], $chosen, true ) );
		}
		$hint = '';
		if ( $multi && ( $g['min'] || $g['max'] ) ) {
			if ( $g['min'] && $g['max'] ) {
				/* translators: 1: minimum, 2: maximum */
				$hint = sprintf( __( 'En az %1$d, en fazla %2$d seçim', 'product-add-on-services-for-woocommerce' ), $g['min'], $g['max'] );
			} elseif ( $g['max'] ) {
				/* translators: %d: maximum */
				$hint = sprintf( __( 'En fazla %d seçim', 'product-add-on-services-for-woocommerce' ), $g['max'] );
			} else {
				/* translators: %d: minimum */
				$hint = sprintf( __( 'En az %d seçim', 'product-add-on-services-for-woocommerce' ), $g['min'] );
			}
		}
		$desc_id = 'eru-aos-desc-' . $gid;
		?>
		<fieldset class="eru-aos-group eru-aos-group--<?php echo esc_attr( $g['type'] ); ?>" data-group="<?php echo esc_attr( $gid ); ?>"<?php echo ( $g['description'] || $hint ) ? ' aria-describedby="' . esc_attr( $desc_id ) . '"' : ''; ?>>
			<legend class="eru-aos-group__title">
				<?php echo esc_html( $g['title'] ); ?>
				<?php if ( $g['required'] ) : ?>
					<span class="eru-aos-group__required"><?php esc_html_e( '(zorunlu)', 'product-add-on-services-for-woocommerce' ); ?></span>
				<?php endif; ?>
			</legend>
			<?php if ( $g['description'] || $hint ) : ?>
				<p class="eru-aos-group__desc" id="<?php echo esc_attr( $desc_id ); ?>">
					<?php echo esc_html( $g['description'] ); ?>
					<?php if ( $hint ) : ?>
						<span class="eru-aos-group__hint"><?php echo esc_html( $hint ); ?></span>
					<?php endif; ?>
				</p>
			<?php endif; ?>
			<?php if ( ! $multi && ! $g['required'] ) : ?>
				<label class="eru-aos-option eru-aos-option--none">
					<input type="radio" name="<?php echo esc_attr( $name ); ?>" value=""<?php checked( ! $any ); ?>>
					<span class="eru-aos-option__label"><?php esc_html_e( 'Hiçbiri', 'product-add-on-services-for-woocommerce' ); ?></span>
				</label>
			<?php endif; ?>
			<?php foreach ( $g['options'] as $i => $o ) : ?>
				<?php $on = null === $chosen ? $o['default'] : in_array( $o['id'], $chosen, true ); ?>
				<label class="eru-aos-option">
					<input type="<?php echo $multi ? 'checkbox' : 'radio'; ?>" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $o['id'] ); ?>" data-key="<?php echo esc_attr( $gid . ':' . $o['id'] ); ?>"<?php checked( $on ); ?><?php echo ( ! $multi && $g['required'] && 0 === $i ) ? ' required' : ''; ?>>
					<span class="eru-aos-option__label"><?php echo esc_html( $o['label'] ); ?></span>
					<?php $price_html = $show_prices ? Eru_AOS_Front::price_html( $o, $product, $pb ) : ''; ?>
					<?php if ( '' !== $price_html ) : ?>
						<span class="eru-aos-option__price"><?php echo wp_kses_post( $price_html ); ?></span>
					<?php endif; ?>
					<?php if ( $o['description'] ) : ?>
						<span class="eru-aos-option__desc"><?php echo esc_html( $o['description'] ); ?></span>
					<?php endif; ?>
				</label>
			<?php endforeach; ?>
		</fieldset>
	<?php endforeach; ?>
	<p class="eru-aos-total" aria-live="polite" hidden>
		<span class="eru-aos-total__label"><?php esc_html_e( 'Ek hizmetlerle toplam:', 'product-add-on-services-for-woocommerce' ); ?></span>
		<strong class="eru-aos-total__amount"></strong>
	</p>
</div>
