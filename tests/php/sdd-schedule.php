<?php
/**
 * Same-Day Delivery — cut-off 14:00: 10:00 → "Bugün kargoda", 15:00 → "Yarın kargoda"; weekend/holiday; method.
 *
 *   wp eval-file tests/php/sdd-schedule.php
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

if ( ! class_exists( 'Eru_SDD_Schedule' ) ) {
	echo "FAIL: Eru_SDD_Schedule missing (plugin not active?)\n";
	exit( 1 );
}

$old = get_option( Eru_SDD_Schedule::OPTION, null );
$at  = function ( string $when ) {
	$ts = new DateTimeImmutable( $when, wp_timezone() );
	add_filter( 'eru_sdd_now', $GLOBALS['eru_sdd_f'] = fn() => $ts );
};
$unset = function () {
	remove_filter( 'eru_sdd_now', $GLOBALS['eru_sdd_f'] );
};

try {
	update_option( Eru_SDD_Schedule::OPTION, array_merge( Eru_SDD_Schedule::defaults(), array( 'cutoff' => '14:00', 'show_messages' => 1, 'holidays' => "2026-10-14\n12-25", 'locations' => "Örnek Şehir\nAnkara/Çankaya" ) ) );

	// 2026-10-12 is a Monday.
	$at( '2026-10-12 10:00:00' );
	$check( 'Mon 10:00 → "Bugün kargoda"', Eru_SDD_Schedule::status()['text'] === 'Bugün kargoda' );
	$check( 'Mon 10:00 ships today (method offered)', Eru_SDD_Schedule::ships_today() );
	$check( 'frontend message HTML contains it', str_contains( Eru_SDD_Frontend::message_html(), 'Bugün kargoda' ) );
	$unset();

	$at( '2026-10-12 15:00:00' );
	$st = Eru_SDD_Schedule::status();
	$check( 'Mon 15:00 → "Yarın kargoda"', $st['text'] === 'Yarın kargoda' && 'tomorrow' === $st['type'] );
	$check( 'Mon 15:00 method not offered', ! Eru_SDD_Schedule::ships_today() );
	$unset();

	$at( '2026-10-12 13:59:59' );
	$check( '13:59:59 still today', Eru_SDD_Schedule::status()['type'] === 'today' );
	$unset();
	$at( '2026-10-12 14:00:00' );
	$check( '14:00:00 is past the cut-off', Eru_SDD_Schedule::status()['type'] === 'tomorrow' );
	$unset();

	// Tuesday 2026-10-13 15:00 → next day Wed 14th is a holiday → Thursday ("later").
	$at( '2026-10-13 15:00:00' );
	$st = Eru_SDD_Schedule::status();
	$check( 'holiday skipped: ships Thursday 15th', 'later' === $st['type'] && '2026-10-15' === $st['date']->format( 'Y-m-d' ) );
	$unset();

	// Friday 2026-10-16 15:00 → Monday 19th.
	$at( '2026-10-16 15:00:00' );
	$st = Eru_SDD_Schedule::status();
	$check( 'Fri after cut-off → Monday', '2026-10-19' === $st['date']->format( 'Y-m-d' ) && 'later' === $st['type'] && str_contains( $st['text'], 'kargoda' ) );
	$unset();
	// Saturday morning.
	$at( '2026-10-17 09:00:00' );
	$check( 'Sat 09:00 → Monday', '2026-10-19' === Eru_SDD_Schedule::status()['date']->format( 'Y-m-d' ) );
	$unset();
	// Recurring holiday 12-25 (Fri 2026-12-25) at 10:00 → not today.
	$at( '2026-12-25 10:00:00' );
	$check( 'recurring MM-DD holiday blocks same-day', ! Eru_SDD_Schedule::ships_today() );
	$unset();

	// Destination eligibility.
	$check( 'city match (Turkish-insensitive)', Eru_SDD_Schedule::destination_eligible( array( 'country' => 'TR', 'city' => 'ORNEK SEHIR' ) ) );
	$pk = Eru_SDD_Frontend::package_slot( array( array( 'contents' => array() ) ) );
	$check( 'no same-day method in any zone: shipping packages untouched (no cache-key slot)', ! isset( $pk[0]['eru_sdd_slot'] ) || Eru_SDD_Frontend::method_configured() );
	$check( 'district rule needs both parts', ! Eru_SDD_Schedule::destination_eligible( array( 'country' => 'TR', 'city' => 'Ankara' ) ) );
	$check( 'city/district match via address_2', Eru_SDD_Schedule::destination_eligible( array( 'country' => 'TR', 'city' => 'Ankara', 'address_2' => 'Çankaya' ) ) );
	$check( 'other city not eligible', ! Eru_SDD_Schedule::destination_eligible( array( 'country' => 'TR', 'city' => 'Başka' ) ) );
	update_option( Eru_SDD_Schedule::OPTION, array_merge( Eru_SDD_Schedule::defaults(), array( 'locations' => '' ) ) );
	$check( 'empty locations → nowhere eligible', ! Eru_SDD_Schedule::destination_eligible( array( 'country' => 'TR', 'city' => 'Örnek Şehir' ) ) );

	// Shipping method availability + own fee.
	update_option( Eru_SDD_Schedule::OPTION, array_merge( Eru_SDD_Schedule::defaults(), array( 'locations' => 'Örnek Şehir' ) ) );
	require_once ERU_SDD_PATH . 'includes/class-eru-sdd-shipping-method.php';
	$m   = new Eru_SDD_Shipping_Method( 0 );
	$m->settings = array( 'title' => 'Aynı gün', 'cost' => '25', 'tax_status' => 'none' );
	$pkg = array( 'contents' => array(), 'contents_cost' => 0, 'applied_coupons' => array(), 'destination' => array( 'country' => 'TR', 'city' => 'Örnek Şehir' ) );
	$at( '2026-10-12 10:00:00' );
	$m->calculate_shipping( $pkg );
	$check( 'method offers a 25 TL rate before cut-off', 1 === count( $m->rates ) && abs( (float) reset( $m->rates )->get_cost() - 25 ) < 0.001 );
	$unset();
	$m->rates = array();
	$at( '2026-10-12 15:00:00' );
	$m->calculate_shipping( $pkg );
	$check( 'method hidden after cut-off', 0 === count( $m->rates ) );
	$unset();
	$check( 'method registered in WooCommerce', isset( WC()->shipping()->get_shipping_method_class_names()['eru_sdd'] ) );

	// Default state: messages off → no output.
	update_option( Eru_SDD_Schedule::OPTION, Eru_SDD_Schedule::defaults() );
	$check( 'default: shortcode prints nothing', '' === do_shortcode( '[eru_sdd_message]' ) );
} finally {
	null === $old ? delete_option( Eru_SDD_Schedule::OPTION ) : update_option( Eru_SDD_Schedule::OPTION, $old );
}
exit( $fail ? 1 : 0 );
