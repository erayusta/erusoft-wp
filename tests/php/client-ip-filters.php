<?php
/**
 * Every IP-based limit reads the visitor address through its `eru_<abbr>_client_ip` filter (proxy/CDN setups).
 *   wp eval-file tests/php/client-ip-filters.php
 * Needs the plugins below active: Wholesale Inquiry Box, Exit-Intent Coupons, Smart Product Search,
 * Order Blacklist, PDF Product Catalog, Abandoned Checkout Call List.
 */

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};

// Documentation addresses (RFC 5737), assembled to avoid literals.
$remote = implode( '.', array( '192', '0', '2', '10' ) );
$real   = implode( '.', array( '198', '51', '100', '20' ) );
$old    = $_SERVER['REMOTE_ADDR'] ?? null;
$_SERVER['REMOTE_ADDR'] = $remote;

$cases = array(
	'wib'  => array( 'Eru_WIB_Form', 'client_ip' ),
	'eic'  => array( 'Eru_EIC_Coupon', 'client_ip' ),
	'sps'  => array( 'Eru_SPS', 'client_ip' ),
	'ob'   => array( 'Eru_OB_Guard', 'client_ip' ),
	'ppc'  => array( 'Eru_PPC', 'client_ip' ),
	'accl' => array( 'Eru_ACCL_Capture', 'client_ip' ),
);
foreach ( $cases as $abbr => $cb ) {
	if ( ! class_exists( $cb[0] ) ) {
		$check( "$abbr: class {$cb[0]} loaded (plugin active?)", false );
		continue;
	}
	if ( ! method_exists( $cb[0], $cb[1] ) ) {
		$check( "$abbr: {$cb[0]}::client_ip() exists", false );
		continue;
	}
	$check( "$abbr: default is REMOTE_ADDR", $remote === call_user_func( $cb ) );
	$f = fn() => $real;
	add_filter( "eru_{$abbr}_client_ip", $f );
	$check( "$abbr: eru_{$abbr}_client_ip filter wins", $real === call_user_func( $cb ) );
	remove_filter( "eru_{$abbr}_client_ip", $f );
}

// No plugin reads REMOTE_ADDR outside its client_ip() helper.
$root = dirname( __DIR__, 2 );
$out  = array();
foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/plugins', FilesystemIterator::SKIP_DOTS ) ) as $file ) {
	if ( '.php' !== substr( (string) $file, -4 ) ) {
		continue;
	}
	$lines = file( (string) $file );
	foreach ( $lines as $n => $line ) {
		if ( false !== strpos( $line, "\$_SERVER['REMOTE_ADDR']" ) ) {
			// The helper is the only allowed reader: the next lines must apply an eru_*_client_ip filter.
			$window = implode( '', array_slice( $lines, $n, 8 ) );
			if ( ! preg_match( '/apply_filters\(\s*\'eru_[a-z0-9]+_client_ip\'/', $window ) ) {
				$out[] = str_replace( $root . '/', '', (string) $file ) . ':' . ( $n + 1 );
			}
		}
	}
}
$check( 'REMOTE_ADDR is only read inside a filtered client_ip() helper' . ( $out ? ' (' . implode( ', ', $out ) . ')' : '' ), ! $out );

if ( null === $old ) {
	unset( $_SERVER['REMOTE_ADDR'] );
} else {
	$_SERVER['REMOTE_ADDR'] = $old;
}
exit( $fail ? 1 : 0 );
