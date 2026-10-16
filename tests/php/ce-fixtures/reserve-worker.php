<?php
/**
 * Campaign Engine — one side of the concurrent reservation test in ce-orders.php. Not a test on its own.
 *
 * Waits until CE_START (unix time, float), then tries to reserve the last use of campaign CE_CAMPAIGN
 * (limit 1) under token CE_TOKEN. A pause between the count and the insert widens the race window, so
 * without the lock both processes would succeed. Prints RESERVED or REFUSED.
 */

use Erusoft\CampaignEngine\Infrastructure\Database\UsageRepository;

$campaign = (int) getenv( 'CE_CAMPAIGN' );
$token    = (string) getenv( 'CE_TOKEN' );
$start    = (float) getenv( 'CE_START' );

add_action(
	'eru_ce_reservation_checked',
	function () {
		usleep( 600000 );
	}
);

while ( microtime( true ) < $start ) {
	usleep( 2000 );
}

$refused = UsageRepository::reserve( array( $campaign => array( 'total' => 1, 'per_customer' => null ) ), $token, 0, '', 0 );
echo ( array() === $refused ? 'RESERVED' : 'REFUSED' ) . "\n";
