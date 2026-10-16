<?php
/**
 * WP-CLI:
 *   wp eru-ce doctor                                      health checks (exit 1 on an error row)
 *   wp eru-ce campaign evaluate <id> --cart=<json>        run one campaign on a cart, nothing written
 *                         [--customer=<guest|returning>]  e.g. --cart='[{"product":12,"qty":3}]'
 *   wp eru-ce rewards expire [--batch=<n>]                mark available rewards past their date expired
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Cli;

use Erusoft\CampaignEngine\Infrastructure\Campaign\CampaignWriter;
use Erusoft\CampaignEngine\Infrastructure\Campaign\PostType;
use Erusoft\CampaignEngine\Infrastructure\Database\RewardRepository;
use Erusoft\CampaignEngine\Presentation\Admin\Doctor;
use Erusoft\CampaignEngine\Presentation\Admin\PreviewController;
use WP_CLI;

final class Commands {

	public static function register(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		WP_CLI::add_command( 'eru-ce doctor', array( self::class, 'doctor' ), array( 'shortdesc' => 'Campaign Engine health checks.' ) );
		WP_CLI::add_command(
			'eru-ce campaign evaluate',
			array( self::class, 'evaluate' ),
			array(
				'shortdesc' => 'Runs one campaign on a cart; nothing is written.',
				'synopsis'  => array(
					array(
						'type'        => 'positional',
						'name'        => 'id',
						'description' => 'Campaign (post) id.',
					),
					array(
						'type'        => 'assoc',
						'name'        => 'cart',
						'description' => 'JSON list of {"product":id,"qty":n}.',
					),
					array(
						'type'        => 'assoc',
						'name'        => 'customer',
						'optional'    => true,
						'default'     => 'guest',
						'options'     => array( 'guest', 'returning' ),
						'description' => 'Customer profile.',
					),
				),
			)
		);
		WP_CLI::add_command(
			'eru-ce rewards expire',
			array( self::class, 'expire' ),
			array(
				'shortdesc' => 'Marks available rewards past their coupon date as expired.',
				'synopsis'  => array(
					array(
						'type'     => 'assoc',
						'name'     => 'batch',
						'optional' => true,
						'default'  => '500',
					),
				),
			)
		);
	}

	public static function doctor(): void {
		$error = false;

		foreach ( Doctor::report() as $row ) {
			$error = $error || 'error' === $row['status'];
			WP_CLI::line( sprintf( '[%s] %s: %s', strtoupper( $row['status'] ), $row['label'], $row['detail'] ) );
		}

		if ( $error ) {
			WP_CLI::error( 'Campaign Engine: errors found.' );
		}

		WP_CLI::success( 'Campaign Engine: no errors.' );
	}

	/**
	 * @param string[]             $args
	 * @param array<string,string> $assoc
	 */
	public static function evaluate( array $args, array $assoc ): void {
		$id   = absint( $args[0] ?? 0 );
		$post = get_post( $id );

		if ( ! $post || PostType::NAME !== $post->post_type ) {
			WP_CLI::error( 'Campaign not found.' );
		}

		$def  = get_post_meta( $id, CampaignWriter::META, true );
		$cart = json_decode( (string) ( $assoc['cart'] ?? '' ), true );

		if ( ! is_array( $def ) ) {
			WP_CLI::error( 'Campaign has no stored definition.' );
		}

		if ( ! is_array( $cart ) || array() === $cart ) {
			WP_CLI::error( '--cart must be a JSON list such as [{"product":12,"qty":3}].' );
		}

		$lines = array();

		foreach ( $cart as $row ) {
			if ( is_array( $row ) && isset( $row['product'] ) ) {
				$lines[] = array( absint( $row['product'] ), max( 1, absint( $row['qty'] ?? 1 ) ) );
			}
		}

		$out = PreviewController::run(
			$def,
			$lines,
			array(
				'customer' => (string) ( $assoc['customer'] ?? 'guest' ),
				'id'       => $id,
				'label'    => $post->post_title,
			)
		);

		WP_CLI\Utils\format_items( 'table', $out['lines'], array( 'name', 'qty', 'unit', 'line', 'discount', 'net' ) );
		WP_CLI::line( sprintf( 'Subtotal: %s  Discount: %s  Total: %s', $out['subtotal'], $out['total_discount'], $out['total'] ) );
		WP_CLI::line( 'Free shipping: ' . ( $out['free_shipping'] ? 'yes' : 'no' ) . ( '' !== $out['reward'] ? '  Reward: ' . $out['reward'] : '' ) );

		foreach ( $out['trace'] as $line ) {
			WP_CLI::line( '- ' . $line );
		}

		WP_CLI::success( $out['applied'] ? 'Campaign applies.' : 'Campaign does not apply.' );
	}

	/**
	 * @param string[]             $args
	 * @param array<string,string> $assoc
	 */
	public static function expire( array $args, array $assoc ): void {
		$batch = max( 1, min( 5000, absint( $assoc['batch'] ?? 500 ) ) );
		$total = 0;

		for ( $round = 0; $round < 200; $round++ ) {
			$done   = RewardRepository::expire( $batch );
			$total += $done;

			if ( $done < $batch ) {
				break;
			}
		}

		WP_CLI::success( sprintf( '%d reward(s) expired.', $total ) );
	}
}
