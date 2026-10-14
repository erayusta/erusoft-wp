<?php
/**
 * Product Add-On Services — pricing, validation, order meta and tax check.
 *   wp eval-file tests/php/aos-pricing.php   (plugin active; creates and removes its own data)
 */

// Stops at the first failure (cleanup still runs in finally, then exit 1).
$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
		throw new RuntimeException( $label );
	}
};
$near = fn( $a, $b ) => abs( (float) $a - (float) $b ) < 0.001;

if ( ! class_exists( 'Eru_AOS_Groups' ) ) {
	echo "FAIL: class Eru_AOS_Groups missing (plugin not active?)\n";
	exit( 1 );
}

wc_load_cart();
$ids       = array();
$group_ids = array();
$order_ids = array();
$rate_ids  = array();
$old_opts  = array();
foreach ( array( 'woocommerce_calc_taxes', 'woocommerce_prices_include_tax', 'eru_aos_percent_base' ) as $o ) {
	$old_opts[ $o ] = get_option( $o, null );
}

$hooks = array();
try {
	$mk = function ( $name, $price, $sale = '' ) use ( &$ids ) {
		$p = new WC_Product_Simple();
		$p->set_name( $name );
		$p->set_regular_price( (string) $price );
		if ( '' !== $sale ) {
			$p->set_sale_price( (string) $sale );
		}
		$p->set_manage_stock( false );
		$p->set_status( 'publish' );
		return $ids[] = $p->save();
	};
	$simple = $mk( 'AOS Demo Basit', 1000 );
	$other  = $mk( 'AOS Demo Başka', 300 );
	$onsale = $mk( 'AOS Demo İndirimli', 1000, 900 );

	$var  = new WC_Product_Variable();
	$var->set_name( 'AOS Demo Varyasyonlu' );
	$attr = new WC_Product_Attribute();
	$attr->set_name( 'Boyut' );
	$attr->set_options( array( 'Küçük', 'Büyük' ) );
	$attr->set_visible( true );
	$attr->set_variation( true );
	$var->set_attributes( array( $attr ) );
	$var->set_status( 'publish' );
	$ids[] = $var_id = $var->save();
	$vids  = array();
	foreach ( array( 'Küçük' => 400, 'Büyük' => 600 ) as $size => $price ) {
		$v = new WC_Product_Variation();
		$v->set_parent_id( $var_id );
		$v->set_attributes( array( 'boyut' => $size ) );
		$v->set_regular_price( (string) $price );
		$ids[] = $vids[ $price ] = $v->save();
	}
	WC_Product_Variable::sync( $var_id );

	$g_warranty = Eru_AOS_Groups::create( array(
		'title'     => 'Garanti',
		'type'      => 'single',
		'required'  => true,
		'scope'     => 'products',
		'scope_ids' => array( $simple, $var_id, $onsale ),
		'options'   => array(
			array( 'id' => 'g2y', 'label' => '2 yıl', 'price_type' => 'percent', 'amount' => '10', 'charge' => 'per_unit' ),
		),
	) );
	$g_extra = Eru_AOS_Groups::create( array(
		'title'     => 'Ekstra',
		'type'      => 'multi',
		'required'  => false,
		'scope'     => 'products',
		'scope_ids' => array( $simple, $var_id ),
		'options'   => array(
			array( 'id' => 'gift', 'label' => 'Hediye paketi', 'price_type' => 'fixed', 'amount' => '50', 'charge' => 'per_unit' ),
			array( 'id' => 'setup', 'label' => 'Kurulum', 'price_type' => 'fixed', 'amount' => '200', 'charge' => 'per_line' ),
			array( 'id' => 'pline', 'label' => 'Yerinde servis', 'price_type' => 'percent', 'amount' => '10', 'charge' => 'per_line' ),
			array( 'id' => 'off', 'label' => 'Kapalı seçenek', 'price_type' => 'fixed', 'amount' => '5', 'charge' => 'per_unit', 'enabled' => false ),
		),
	) );
	$g_foreign = Eru_AOS_Groups::create( array(
		'title'     => 'Başka grup',
		'type'      => 'single',
		'scope'     => 'products',
		'scope_ids' => array( $other ),
		'options'   => array(
			array( 'id' => 'xx', 'label' => 'Yabancı', 'price_type' => 'fixed', 'amount' => '5', 'charge' => 'per_unit' ),
		),
	) );
	$group_ids = array( $g_warranty, $g_extra, $g_foreign );
	$check( 'groups created', ! in_array( true, array_map( 'is_wp_error', $group_ids ), true ) && min( $group_ids ) > 0 );

	$applies = wp_list_pluck( Eru_AOS_Groups::for_product( $simple ), 'id' );
	$check( 'simple product gets Garanti + Ekstra, not the foreign group', array( $g_warranty, $g_extra ) === array_map( 'intval', $applies ) );
	$check( 'inert: product outside every scope has no groups', array() === Eru_AOS_Groups::for_product( $mk( 'AOS Demo Kapsam Dışı', 10 ) ) );

	$post = function ( $product_id, $choices, $variation_id = 0 ) {
		$_POST = $_REQUEST = array( 'add-to-cart' => $product_id, 'eru_aos' => $choices ) + ( $variation_id ? array( 'variation_id' => $variation_id ) : array() );
	};
	$validate = fn( $pid, $qty, $vid = 0, $vars = array() ) => apply_filters( 'woocommerce_add_to_cart_validation', true, $pid, $qty, $vid, $vars );
	$line     = function () {
		$items = WC()->cart->get_cart();
		return reset( $items );
	};

	// (a) simple 1000 x2, Garanti 10 % + Hediye paketi 50 -> 2 x 1150 = 2300.
	WC()->cart->empty_cart();
	wc_clear_notices();
	$post( $simple, array( $g_warranty => 'g2y', $g_extra => array( 'gift' ) ) );
	$check( '(a) validation passes with required choice', true === $validate( $simple, 2 ) );
	WC()->cart->add_to_cart( $simple, 2 );
	WC()->cart->calculate_totals();
	$l = $line();
	$check( '(a) line subtotal 2300', $l && $near( $l['line_subtotal'], 2300 ) );
	$check( '(a) no fee without a per_line option', 0 === count( WC()->cart->get_fees() ) );
	$check( '(a) base price read once = 1000, kept out of the cart item', $l && $near( Eru_AOS_Cart::base_for( $l ), 1000 ) && ! array_key_exists( '_eru_aos_base', $l ) );

	// (b) + Kurulum (per_line 200) -> one fee of 200, line stays 2300.
	WC()->cart->empty_cart();
	$post( $simple, array( $g_warranty => 'g2y', $g_extra => array( 'gift', 'setup' ) ) );
	WC()->cart->add_to_cart( $simple, 2 );
	WC()->cart->calculate_totals();
	$fees = WC()->cart->get_fees();
	$fee  = reset( $fees );
	$check( '(b) one fee of 200 (not x2)', 1 === count( $fees ) && $near( $fee->amount, 200 ) );
	$check( '(b) fee label names group, option and product', $fee && false !== strpos( $fee->name, 'Ekstra' ) && false !== strpos( $fee->name, 'Kurulum' ) && false !== strpos( $fee->name, 'AOS Demo Basit' ) );
	$check( '(b) line subtotal still 2300', $near( $line()['line_subtotal'], 2300 ) );

	// (c) recalculating twice gives identical totals.
	$t1 = array( WC()->cart->get_subtotal(), WC()->cart->get_fee_total(), WC()->cart->get_total( 'edit' ) );
	WC()->cart->calculate_totals();
	WC()->cart->calculate_totals();
	$t2 = array( WC()->cart->get_subtotal(), WC()->cart->get_fee_total(), WC()->cart->get_total( 'edit' ) );
	$check( '(c) calculate_totals twice -> identical totals', $t1 == $t2 && 1 === count( WC()->cart->get_fees() ) );
	$check( '(c) unit price is 1150, not stacked', $near( $line()['data']->get_price(), 1150 ) );
	WC()->cart->set_quantity( array_key_first( WC()->cart->get_cart() ), 3 );
	WC()->cart->calculate_totals();
	$check( '(c) qty 3 -> line 3450, fee still 200', $near( $line()['line_subtotal'], 3450 ) && $near( WC()->cart->get_fee_total(), 200 ) );

	// (d) variation 600 with Garanti -> unit 660; 400 -> 440.
	WC()->cart->empty_cart();
	$post( $var_id, array( $g_warranty => 'g2y' ), $vids[600] );
	$check( '(d) variable validation passes', true === $validate( $var_id, 1, $vids[600], array( 'attribute_boyut' => 'Büyük' ) ) );
	WC()->cart->add_to_cart( $var_id, 1, $vids[600], array( 'attribute_boyut' => 'Büyük' ) );
	$post( $var_id, array( $g_warranty => 'g2y' ), $vids[400] );
	WC()->cart->add_to_cart( $var_id, 2, $vids[400], array( 'attribute_boyut' => 'Küçük' ) );
	WC()->cart->calculate_totals();
	$units = array();
	foreach ( WC()->cart->get_cart() as $it ) {
		$units[ $it['variation_id'] ] = (float) $it['data']->get_price();
	}
	$check( '(d) variation 600 -> unit 660', isset( $units[ $vids[600] ] ) && $near( $units[ $vids[600] ], 660 ) );
	$check( '(d) variation 400 x2 -> unit 440, subtotal 660 + 880', isset( $units[ $vids[400] ] ) && $near( $units[ $vids[400] ], 440 ) && $near( WC()->cart->get_subtotal(), 1540 ) );

	// (e) required group missing -> false + notice, cart unchanged.
	$count = count( WC()->cart->get_cart() );
	wc_clear_notices();
	$post( $simple, array( $g_extra => array( 'gift' ) ) );
	$check( '(e) required group missing -> validation false', false === $validate( $simple, 1 ) );
	$check( '(e) an error notice is shown', wc_notice_count( 'error' ) > 0 );
	$check( '(e) cart unchanged', count( WC()->cart->get_cart() ) === $count );
	wc_clear_notices();
	$post( $simple, array() );
	$check( '(e) nothing posted at all -> validation false (server-side, no JS)', false === $validate( $simple, 1 ) );

	// (f) foreign / unknown option IDs are rejected.
	wc_clear_notices();
	$post( $simple, array( $g_warranty => 'nope' ) );
	$check( '(f) unknown option id -> rejected', false === $validate( $simple, 1 ) );
	$post( $simple, array( $g_warranty => 'g2y', $g_foreign => 'xx' ) );
	$check( '(f) option of a group that does not apply -> rejected', false === $validate( $simple, 1 ) );
	$post( $simple, array( $g_warranty => 'g2y', $g_extra => array( 'xx' ) ) );
	$check( '(f) foreign option id inside a valid group -> rejected', false === $validate( $simple, 1 ) );
	$post( $simple, array( $g_warranty => array( 'g2y', 'g2y' ) ) );
	$check( '(f) array posted to a single-choice group -> rejected', false === $validate( $simple, 1 ) );
	$post( $simple, array( $g_warranty => 'g2y', $g_extra => array( 'off' ) ) );
	$check( '(f) disabled option id -> rejected', false === $validate( $simple, 1 ) );
	wc_clear_notices();

	// (g) order line item: visible meta + hidden _eru_aos_choices; order again restores the choices.
	WC()->cart->empty_cart();
	$post( $simple, array( $g_warranty => 'g2y', $g_extra => array( 'gift', 'setup' ) ) );
	WC()->cart->add_to_cart( $simple, 2 );
	WC()->cart->calculate_totals();
	$item_data = apply_filters( 'woocommerce_get_item_data', array(), $line() );
	$check( '(g) cart shows the choices (woocommerce_get_item_data)', 3 === count( $item_data ) );
	$order = wc_create_order();
	WC()->checkout()->create_order_line_items( $order, WC()->cart );
	WC()->checkout()->create_order_fee_lines( $order, WC()->cart );
	$order->calculate_totals( false );
	$order->save();
	$order_ids[] = $order->get_id();
	$items       = $order->get_items();
	$oi          = reset( $items );
	$visible     = array_filter( $oi->get_formatted_meta_data(), fn( $m ) => 'Ek hizmet' === $m->key );
	$choices     = $oi->get_meta( '_eru_aos_choices' );
	$check( '(g) 3 visible "Ek hizmet" meta rows', 3 === count( $visible ) );
	$check( '(g) visible meta shows label and price', (bool) array_filter( $visible, fn( $m ) => false !== strpos( wp_strip_all_tags( $m->display_value ), 'Hediye paketi' ) ) );
	$check( '(g) hidden _eru_aos_choices saved and not displayed', is_array( $choices ) && 3 === count( $choices ) && ! array_filter( $oi->get_formatted_meta_data(), fn( $m ) => '_eru_aos_choices' === $m->key ) );
	$check( '(g) order total 2300 + 200 fee', $near( $order->get_total(), 2500 ) && 1 === count( $order->get_fees() ) );
	$again = apply_filters( 'woocommerce_order_again_cart_item_data', array(), $oi, $order );
	$check( '(g) order again restores the 3 choices', isset( $again['eru_aos'] ) && 3 === count( $again['eru_aos'] ) );
	$_POST = $_REQUEST = array();
	$check( '(g) order again passes validation with restored choices', true === apply_filters( 'woocommerce_add_to_cart_validation', true, $simple, 1, 0, array(), $again ) );

	// (s) order again only uses the current group definitions, never the stored snapshot.
	$edit_group = function ( $gid, callable $fn ) {
		Eru_AOS_Groups::save_meta( $gid, $fn( Eru_AOS_Groups::get( $gid ) ) );
	};
	$set_opt = function ( $gid, $oid, $key, $val ) use ( $edit_group ) {
		$edit_group( $gid, function ( $g ) use ( $oid, $key, $val ) {
			foreach ( $g['options'] as &$o ) {
				if ( $oid === $o['id'] ) {
					$o[ $key ] = $val;
				}
			}
			return $g;
		} );
	};
	$reorder = function () use ( $oi, $order, $simple ) {
		wc_clear_notices();
		$data = apply_filters( 'woocommerce_order_again_cart_item_data', array(), $oi, $order );
		$ok   = apply_filters( 'woocommerce_add_to_cart_validation', true, $simple, 2, 0, array(), $data );
		WC()->cart->empty_cart();
		if ( $ok ) {
			WC()->cart->add_to_cart( $simple, 2, 0, array(), $data );
			WC()->cart->calculate_totals();
		}
		$notices = implode( ' ', wp_list_pluck( wc_get_notices( 'notice' ), 'notice' ) );
		$errors  = wc_notice_count( 'error' );
		wc_clear_notices();
		return array( $data, $ok, $notices, $errors );
	};
	$dropped_msg = 'Bazı ek hizmetler artık sunulmadığı için sepete eklenmedi.';
	$_POST       = $_REQUEST = array();

	// (s1) option price changed (Hediye paketi 50 -> 80): new price used. 2 x (1000 + 100 + 80) = 2360, fee 200.
	$set_opt( $g_extra, 'gift', 'amount', '80' );
	list( $data, $ok, $notices ) = $reorder();
	$check( '(s1) price change: order again uses the new option price (line 2360, fee 200)', $ok && $near( $line()['line_subtotal'], 2360 ) && $near( WC()->cart->get_fee_total(), 200 ) && '' === $notices );
	$set_opt( $g_extra, 'gift', 'amount', '50' );

	// (s2) one option removed (Kurulum disabled): the rest is kept, a notice explains, no fee.
	$set_opt( $g_extra, 'setup', 'enabled', false );
	list( $data, $ok, $notices ) = $reorder();
	$check( '(s2) removed option dropped, others kept: line 2300, no fee, notice shown', $ok && 2 === count( $data['eru_aos'] ?? array() ) && $near( $line()['line_subtotal'], 2300 ) && 0 === count( WC()->cart->get_fees() ) && false !== strpos( $notices, $dropped_msg ) );
	$set_opt( $g_extra, 'setup', 'enabled', true );

	// (s3) add-ons disabled on the product: line added without add-ons, total = base price only.
	update_post_meta( $simple, '_eru_aos_disabled', 'yes' );
	list( $data, $ok, $notices ) = $reorder();
	$check( '(s3) add-ons disabled: no eru_aos in cart item data', ! isset( $data['eru_aos'] ) );
	$check( '(s3) add-ons disabled: line added at base price only (2 x 1000, no fee)', $ok && 1 === count( WC()->cart->get_cart() ) && empty( $line()['eru_aos'] ) && $near( WC()->cart->get_total( 'edit' ), 2000 ) && 0 === count( WC()->cart->get_fees() ) );
	$check( '(s3) add-ons disabled: customer is told', false !== strpos( $notices, $dropped_msg ) );
	wc_clear_notices();
	$check( '(s3) validate rejects provided add-ons on a product without groups', false === apply_filters( 'woocommerce_add_to_cart_validation', true, $simple, 1, 0, array(), array( 'eru_aos' => $choices ) ) );
	wc_clear_notices();
	delete_post_meta( $simple, '_eru_aos_disabled' );

	// (s4) the required group's chosen option is gone: the line is not added, with an error notice.
	$set_opt( $g_warranty, 'g2y', 'enabled', false );
	$edit_group( $g_warranty, function ( $g ) {
		$g['options'][] = array( 'id' => 'g3y', 'label' => '3 yıl', 'price_type' => 'percent', 'amount' => '15', 'charge' => 'per_unit' );
		return $g;
	} );
	list( $data, $ok, $notices, $errors ) = $reorder();
	$check( '(s4) required group no longer satisfied: line not added, error notice', false === $ok && 0 === count( WC()->cart->get_cart() ) && $errors > 0 );
	$edit_group( $g_warranty, function ( $g ) {
		$g['options'] = array_values( array_filter( $g['options'], fn( $o ) => 'g3y' !== $o['id'] ) );
		return $g;
	} );
	$set_opt( $g_warranty, 'g2y', 'enabled', true );
	WC()->cart->empty_cart();

	// (h) percent base: current price (sale) by default, regular price when set.
	WC()->cart->empty_cart();
	delete_option( 'eru_aos_percent_base' );
	$post( $onsale, array( $g_warranty => 'g2y' ) );
	WC()->cart->add_to_cart( $onsale, 1 );
	WC()->cart->calculate_totals();
	$check( '(h) sale 900 + 10 % of current price = 990', $near( $line()['line_subtotal'], 990 ) );
	update_option( 'eru_aos_percent_base', 'regular' );
	WC()->cart->calculate_totals();
	$check( '(h) percent base "regular" -> 900 + 100 = 1000', $near( $line()['line_subtotal'], 1000 ) );
	delete_option( 'eru_aos_percent_base' );

	// (i) taxes: 20 % standard rate; per_line fee taxed like the product.
	update_option( 'woocommerce_calc_taxes', 'yes' );
	update_option( 'woocommerce_prices_include_tax', 'no' );
	$rate_ids[] = WC_Tax::_insert_tax_rate( array( 'tax_rate_country' => '', 'tax_rate' => '20.0000', 'tax_rate_name' => 'Demo KDV', 'tax_rate_priority' => 1, 'tax_rate_compound' => 0, 'tax_rate_shipping' => 0, 'tax_rate_order' => 1, 'tax_rate_class' => '' ) );
	WC_Cache_Helper::invalidate_cache_group( 'taxes' );
	WC()->cart->empty_cart();
	$post( $simple, array( $g_warranty => 'g2y', $g_extra => array( 'gift', 'setup' ) ) );
	WC()->cart->add_to_cart( $simple, 2 );
	WC()->cart->calculate_totals();
	WC()->cart->calculate_totals();
	$check( '(i) prices excl. tax: line 2300 + tax 460', $near( $line()['line_subtotal'], 2300 ) && $near( $line()['line_subtotal_tax'], 460 ) );
	$check( '(i) prices excl. tax: fee 200 + tax 40', $near( WC()->cart->get_fee_total(), 200 ) && $near( WC()->cart->get_fee_tax(), 40 ) );
	update_option( 'woocommerce_prices_include_tax', 'yes' );
	WC()->cart->empty_cart();
	$post( $simple, array( $g_warranty => 'g2y', $g_extra => array( 'gift', 'setup' ) ) );
	WC()->cart->add_to_cart( $simple, 2 );
	WC()->cart->calculate_totals();
	WC()->cart->calculate_totals();
	$gross_line = (float) $line()['line_subtotal'] + (float) $line()['line_subtotal_tax'];
	$gross_fee  = (float) WC()->cart->get_fee_total() + (float) WC()->cart->get_fee_tax();
	$check( '(i) prices incl. tax: gross line 2300, gross fee 200, total 2500', abs( $gross_line - 2300 ) < 0.011 && abs( $gross_fee - 200 ) < 0.011 && abs( (float) WC()->cart->get_total( 'edit' ) - 2500 ) < 0.011 );


	// (j) same product, different choices -> two lines; identical per_line options -> one fee per line.
	update_option( 'woocommerce_calc_taxes', 'no' );
	update_option( 'woocommerce_prices_include_tax', 'no' );
	WC()->cart->empty_cart();
	$post( $simple, array( $g_warranty => 'g2y', $g_extra => array( 'setup' ) ) );
	WC()->cart->add_to_cart( $simple, 1 );
	$post( $simple, array( $g_warranty => 'g2y', $g_extra => array( 'gift', 'setup' ) ) );
	WC()->cart->add_to_cart( $simple, 1 );
	WC()->cart->calculate_totals();
	$check( '(j) different choices -> two cart lines', 2 === count( WC()->cart->get_cart() ) );
	$fees = WC()->cart->get_fees();
	$check( '(j) two identical per_line options -> two fees, 400 in total', 2 === count( $fees ) && $near( WC()->cart->get_fee_total(), 400 ) );
	$check( '(j) subtotal 1100 + 1150', $near( WC()->cart->get_subtotal(), 2250 ) );
	WC()->cart->calculate_totals();
	$check( '(j) recalculated -> still two fees, 400', 2 === count( WC()->cart->get_fees() ) && $near( WC()->cart->get_fee_total(), 400 ) );

	// (k) session reload drops the stored base: a price change made between requests is picked up.
	WC()->cart->empty_cart();
	$post( $simple, array( $g_warranty => 'g2y', $g_extra => array( 'gift' ) ) );
	WC()->cart->add_to_cart( $simple, 2 );
	WC()->cart->calculate_totals();
	$sess = WC()->cart->get_cart_for_session();
	$check( '(k) session copy carries no request-only state', ! preg_grep( '/^_eru_aos_/', array_keys( reset( $sess ) ) ) );
	$sp = wc_get_product( $simple );
	$sp->set_regular_price( '2000' );
	$sp->save();
	WC()->cart->set_cart_contents( array() );
	WC()->session->set( 'cart', $sess );
	WC()->cart->get_cart_from_session();
	WC()->cart->calculate_totals();
	$check( '(k) after reload: 2 x (2000 + 200 + 50) = 4500', $near( $line()['line_subtotal'], 4500 ) && $near( Eru_AOS_Cart::base_for( $line() ), 2000 ) );
	$sp->set_regular_price( '1000' );
	$sp->save();

	// (l) other price plugins: priority 10 and 99, each "90 % of the first price it saw"; 3 recalculations.
	foreach ( array( 10, 99 ) as $prio ) {
		WC()->cart->empty_cart();
		$first = array();
		$cb    = function ( $cart ) use ( &$first ) {
			foreach ( $cart->get_cart() as $k => $it ) {
				if ( ! isset( $first[ $k ] ) ) {
					$first[ $k ] = (float) $it['data']->get_price( 'edit' );
				}
				$it['data']->set_price( $first[ $k ] * 0.9 );
			}
		};
		add_action( 'woocommerce_before_calculate_totals', $cb, $prio );
		$hooks[] = array( $cb, $prio );
		$post( $simple, array( $g_warranty => 'g2y', $g_extra => array( 'pline' ) ) );
		WC()->cart->add_to_cart( $simple, 1 );
		$runs = array();
		for ( $i = 0; $i < 3; $i++ ) {
			WC()->cart->calculate_totals();
			$l      = $line();
			$runs[] = array(
				round( (float) WC()->cart->get_total( 'edit' ), 2 ),
				round( (float) WC()->cart->get_fee_total(), 2 ),
				round( (float) $l['line_subtotal'], 2 ),
				wp_list_pluck( $l['eru_aos'], 'unit' ),
				Eru_AOS_Cart::base_for( $l ),
			);
		}
		$check( "(l) priority $prio plugin: 3 recalculations identical", $runs[0] == $runs[1] && $runs[1] == $runs[2] );
		if ( 10 === $prio ) {
			// Earlier plugin: its price (900) is the base. Garanti 90 per unit, servis 90 per line.
			$check( '(l) priority 10: base 900, line 990, fee 90', $near( $runs[2][4], 900 ) && $near( $runs[2][2], 990 ) && $near( $runs[2][1], 90 ) );
		} else {
			// Later plugin works on our price; our base, amounts and fee stay on 1000.
			$check( '(l) priority 99: base 1000, units 100/100, fee 100', $near( $runs[2][4], 1000 ) && array( 100.0, 100.0 ) == array_map( 'floatval', $runs[2][3] ) && $near( $runs[2][1], 100 ) );
		}
		remove_action( 'woocommerce_before_calculate_totals', $cb, $prio );
	}

	// (q) a plugin that replaces every item's product object with a clone on each calculation (priority 10 and 30).
	foreach ( array( 10, 30 ) as $prio ) {
		WC()->cart->empty_cart();
		$cb = function ( $cart ) {
			$contents = $cart->get_cart_contents();
			foreach ( $contents as $k => $it ) {
				$contents[ $k ]['data'] = clone $it['data'];
			}
			$cart->set_cart_contents( $contents );
		};
		add_action( 'woocommerce_before_calculate_totals', $cb, $prio );
		$hooks[] = array( $cb, $prio );
		$post( $simple, array( $g_warranty => 'g2y', $g_extra => array( 'pline' ) ) );
		WC()->cart->add_to_cart( $simple, 1 );
		$runs = array();
		for ( $i = 0; $i < 3; $i++ ) {
			WC()->cart->calculate_totals();
			$runs[] = array( round( (float) WC()->cart->get_total( 'edit' ), 2 ), round( (float) WC()->cart->get_fee_total(), 2 ), round( (float) $line()['line_subtotal'], 2 ) );
		}
		$check( "(q) priority $prio cloning plugin: 3 recalculations identical (1100 + fee 100)", $runs[0] == $runs[1] && $runs[1] == $runs[2] && $near( $runs[2][2], 1100 ) && $near( $runs[2][1], 100 ) );
		remove_action( 'woocommerce_before_calculate_totals', $cb, $prio );
	}

	// (o) calculate_fees() after calculate_totals() -> still exactly one fee per line + option.
	WC()->cart->empty_cart();
	$post( $simple, array( $g_warranty => 'g2y', $g_extra => array( 'setup' ) ) );
	WC()->cart->add_to_cart( $simple, 1 );
	WC()->cart->calculate_totals();
	WC()->cart->calculate_fees();
	$check( '(o) calculate_fees() after calculate_totals() -> 1 fee of 200', 1 === count( WC()->cart->get_fees() ) && $near( array_sum( wp_list_pluck( WC()->cart->get_fees(), 'amount' ) ), 200 ) );

	// (p) cart hash is stable across a session load and a recalculation (no fragment refresh churn).
	WC()->cart->calculate_totals();
	$h0 = WC()->cart->get_cart_hash();
	WC()->cart->get_cart_from_session();
	$h1 = WC()->cart->get_cart_hash();
	WC()->cart->calculate_totals();
	$h2 = WC()->cart->get_cart_hash();
	$check( '(p) cart hash: after load === after calculate_totals (=== before)', $h1 === $h2 && $h0 === $h2 );
	$check( '(p) price after load + calc is still 1100', $near( $line()['data']->get_price(), 1100 ) );

	// (r) a second cart sharing the product objects (recurring-cart clone, set_cart_contents copy) does not compound.
	$snap = function ( $c ) {
		return array( round( (float) $c->get_total( 'edit' ), 2 ), round( (float) $c->get_fee_total(), 2 ) );
	};
	WC()->cart->calculate_totals();
	$main0 = $snap( WC()->cart );
	$twin  = clone WC()->cart;
	$twin->calculate_totals();
	$copy = new WC_Cart();
	$copy->set_cart_contents( WC()->cart->get_cart_contents() );
	$copy->calculate_totals();
	$twin_total = $snap( $twin );
	$copy_total = $snap( $copy );
	$twin->empty_cart( false );
	WC()->cart->calculate_totals();
	$main1 = $snap( WC()->cart );
	WC()->cart->calculate_totals();
	$main2 = $snap( WC()->cart );
	$check( '(r) cloned and copied carts total like the main cart', $twin_total == $main0 && $copy_total == $main0 );
	$check( '(r) main cart unchanged after other carts were calculated and emptied', $main0 == $main1 && $main1 == $main2 && $near( $line()['data']->get_price(), 1100 ) );

	// (m) theme AJAX variation add: product_id = variation ID, validation runs with the parent ID.
	WC()->cart->empty_cart();
	wc_clear_notices();
	$_POST = $_REQUEST = array( 'product_id' => $vids[600], 'eru_aos' => array( $g_warranty => 'g2y' ) );
	$check( '(m) AJAX variation add: choices read for the parent', true === $validate( $var_id, 1 ) );
	WC()->cart->add_to_cart( $var_id, 1, $vids[600], array( 'attribute_boyut' => 'Büyük' ) );
	WC()->cart->calculate_totals();
	$check( '(m) AJAX variation add keeps the add-ons (unit 660)', $near( $line()['data']->get_price(), 660 ) && 1 === count( $line()['eru_aos'] ) );

	// (n) grouped product: children with a required group can still be added from the grouped form (without add-ons).
	$grp = new WC_Product_Grouped();
	$grp->set_name( 'AOS Demo Grup Ürün' );
	$grp->set_children( array( $simple, $other ) );
	$grp->set_status( 'publish' );
	$ids[] = $grp_id = $grp->save();
	WC()->cart->empty_cart();
	wc_clear_notices();
	$_POST = $_REQUEST = array( 'add-to-cart' => $grp_id, 'quantity' => array( $simple => 1 ) );
	$check( '(n) grouped child with a required group passes validation', true === $validate( $simple, 1 ) );
	WC()->cart->add_to_cart( $simple, 1 );
	WC()->cart->calculate_totals();
	$check( '(n) grouped child added without add-ons at 1000', 1 === count( WC()->cart->get_cart() ) && empty( $line()['eru_aos'] ) && $near( $line()['line_subtotal'], 1000 ) );
	$_POST = $_REQUEST = array();

	// Per-product kill switch.
	update_post_meta( $simple, '_eru_aos_disabled', 'yes' );
	$check( 'product "disable add-on services" -> no groups', array() === Eru_AOS_Groups::for_product( $simple ) );
	delete_post_meta( $simple, '_eru_aos_disabled' );
} catch ( RuntimeException $e ) {
	echo 'STOPPED at first failure: ' . $e->getMessage() . "\n";
} finally {
	foreach ( $hooks as $h ) {
		remove_action( 'woocommerce_before_calculate_totals', $h[0], $h[1] );
	}
	$_POST = $_REQUEST = array();
	WC()->cart->empty_cart();
	wc_clear_notices();
	foreach ( $order_ids as $oid ) {
		$o = wc_get_order( $oid );
		if ( $o ) {
			$o->delete( true );
		}
	}
	foreach ( array_reverse( $ids ) as $id ) {
		wp_delete_post( $id, true );
	}
	foreach ( $group_ids as $gid ) {
		if ( is_int( $gid ) ) {
			wp_delete_post( $gid, true );
		}
	}
	foreach ( $rate_ids as $rid ) {
		WC_Tax::_delete_tax_rate( $rid );
	}
	foreach ( $old_opts as $o => $v ) {
		null === $v ? delete_option( $o ) : update_option( $o, $v );
	}
}

echo $fail ? "FAILED: $fail\n" : "ALL PASS\n";
exit( $fail ? 1 : 0 );
