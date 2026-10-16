<?php
/**
 * Campaign Engine — shared helpers for the pure engine tests: assertions, the engine autoloader and
 * small factories for invented carts, customers and campaigns.
 *
 * The engine needs no WordPress, so the plugin does not have to be active; the files are loaded
 * straight from the repository. Included by tests/php/ce-engine-*.php; not a test on its own.
 */

use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Engine\Context\CartInfo;
use Erusoft\CampaignEngine\Engine\Context\Context;
use Erusoft\CampaignEngine\Engine\Context\Customer;
use Erusoft\CampaignEngine\Engine\Context\Item;
use Erusoft\CampaignEngine\Engine\EvaluationResult;
use Erusoft\CampaignEngine\Engine\Evaluator;
use Erusoft\CampaignEngine\Engine\Settings;
use Erusoft\CampaignEngine\Engine\UsageLimits;

if ( defined( 'CE_TEST_HELPERS' ) ) {
	return;
}
define( 'CE_TEST_HELPERS', 1 );
define( 'CE_PLUGIN_DIR', dirname( __DIR__, 2 ) . '/plugins/campaign-engine-for-woocommerce' );

/** Prints PASS/FAIL and exits on the first failure. */
function ce_check( $label, $ok ) {
	echo ( $ok ? 'PASS' : 'FAIL' ) . ': ' . $label . "\n";
	if ( ! $ok ) {
		exit( 1 );
	}
}

/** Loads the engine through the plugin's own autoloader; exits when a public class is missing. */
function ce_require_engine() {
	$loader = CE_PLUGIN_DIR . '/src/Autoloader.php';
	if ( ! is_file( $loader ) ) {
		echo "FAIL: src/Autoloader.php missing\n";
		exit( 1 );
	}
	require_once $loader;
	\Erusoft\CampaignEngine\Autoloader::register( CE_PLUGIN_DIR . '/src' );
	$classes = array( 'Money', 'Evaluator', 'Resolver', 'Settings', 'UsageLimits', 'Trace', 'Candidate', 'EvaluationResult', 'Matcher', 'Args', 'Context\\Item', 'Context\\Customer', 'Context\\CartInfo', 'Context\\Context', 'Context\\Scope', 'Condition\\RuleTree', 'Condition\\ConditionRegistry', 'Condition\\Comparator', 'Action\\ActionRegistry', 'Action\\SetRewardAction', 'Action\\TieredAction', 'Adjustment\\Adjustment', 'Adjustment\\AdjustmentSet', 'Adjustment\\UnitAllocation', 'Campaign\\Campaign', 'Campaign\\Targets', 'Campaign\\Schedule', 'Campaign\\Recurrence', 'Offer\\Offer', 'Offer\\OfferFinder' );
	foreach ( $classes as $c ) {
		if ( ! class_exists( 'Erusoft\\CampaignEngine\\Engine\\' . $c ) ) {
			echo "FAIL: engine class {$c} missing\n";
			exit( 1 );
		}
	}
}

/** A fixed instant in the given zone (default: a Wednesday noon in Istanbul). */
function ce_now( $when = '2026-10-14 12:00:00', $tz = 'Europe/Istanbul' ) {
	return new DateTimeImmutable( $when, new DateTimeZone( $tz ) );
}

/** An invented cart line. @param array $extra Extra Item fields. */
function ce_item( $key, $product_id, $qty, $unit_minor, array $extra = array() ) {
	return Item::fromArray(
		array_merge(
			array(
				'key'        => $key,
				'product_id' => $product_id,
				'qty'        => $qty,
				'unit_minor' => $unit_minor,
			),
			$extra
		)
	);
}

/** A context over the lines; the cart subtotal is the sum of the lines unless given. */
function ce_ctx( array $items, $now = null, array $cart = array(), array $customer = array() ) {
	$subtotal = 0;
	foreach ( $items as $item ) {
		$subtotal += $item->lineSubtotalMinor();
	}
	return new Context(
		$items,
		Customer::fromArray( $customer ),
		CartInfo::fromArray( array_merge( array( 'subtotal_minor' => $subtotal ), $cart ) ),
		$now ?? ce_now()
	);
}

/** An invented campaign. @param array $actions Action rows. @param array $extra Campaign fields. */
function ce_campaign( $id, array $actions, array $extra = array() ) {
	return Campaign::fromArray(
		array_merge(
			array(
				'id'      => $id,
				'label'   => 'Demo Kampanya ' . $id,
				'actions' => $actions,
			),
			$extra
		)
	);
}

/** Runs the default engine. @return EvaluationResult */
function ce_eval( Context $ctx, array $campaigns, array $settings = array(), ?UsageLimits $usage = null ) {
	return Evaluator::withDefaults( Settings::fromArray( $settings ) )->evaluate( $ctx, $campaigns, $usage );
}

/** Line discount per item key, from a result. @return array<string,int> */
function ce_lines( EvaluationResult $r ) {
	$out = array();
	foreach ( $r->adjustments->forLevel( 'item' ) as $a ) {
		$out[ $a->item_key ] = ( $out[ $a->item_key ] ?? 0 ) + $a->discount_minor;
	}
	ksort( $out );
	return $out;
}

/** Every PHP file under src/Engine (or another src sub-directory). @return string[] */
function ce_src_files( $sub = 'Engine' ) {
	$dir = CE_PLUGIN_DIR . '/src' . ( '' === $sub ? '' : '/' . $sub );
	$out = array();
	if ( ! is_dir( $dir ) ) {
		return $out;
	}
	$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $f ) {
		if ( $f->isFile() && 'php' === $f->getExtension() ) {
			$out[] = $f->getPathname();
		}
	}
	sort( $out );
	return $out;
}
