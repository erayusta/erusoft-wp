<?php
/**
 * `GET /wp-json/eru-ce/v1/clock?campaign=<id>`: the server time and the real end of a running campaign,
 * for countdowns that stay right behind a page cache. Public and read-only; it answers only for active
 * campaigns (a draft or ended campaign gets `ends: null`) and is registered only while a module that
 * shows a countdown is on. No write route exists under `eru-ce/v1`.
 *
 * Rate limit: 120 requests per minute per client address (filters `eru_ce_clock_rate_limit` and
 * `eru_ce_clock_client`), counted in a two-minute transient per client (an option row on sites without
 * a persistent object cache).
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Rest;

use Erusoft\CampaignEngine\Infrastructure\Clock;
use Erusoft\CampaignEngine\Infrastructure\Database\CampaignRepository;
use Erusoft\CampaignEngine\Infrastructure\Settings\StorefrontOptions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class ClockController {

	public const NAMESPACE = 'eru-ce/v1';

	public static function hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register' ) );
	}

	public static function register(): void {
		if ( ! StorefrontOptions::on( 'gain_box' ) && ! StorefrontOptions::on( 'pages' ) ) {
			return;
		}

		register_rest_route(
			self::NAMESPACE,
			'/clock',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'clock' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'campaign' => array(
						'type'              => 'integer',
						'minimum'           => 0,
						'default'           => 0,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/** @return WP_REST_Response|WP_Error */
	public static function clock( WP_REST_Request $request ) {
		if ( ! self::allowed() ) {
			return new WP_Error( 'eru_ce_rate_limited', __( 'Çok fazla istek; biraz sonra yeniden deneyin.', 'campaign-engine-for-woocommerce' ), array( 'status' => 429 ) );
		}

		$id   = (int) $request->get_param( 'campaign' );
		$now  = Clock::now();
		$ends = null;

		if ( $id > 0 ) {
			foreach ( CampaignRepository::active() as $campaign ) {
				if ( $campaign->id === $id ) {
					$end  = $campaign->schedule->currentEnd( $now );
					$ends = null === $end ? null : $end->getTimestamp();
					break;
				}
			}
		}

		$response = new WP_REST_Response(
			array(
				'now'      => $now->getTimestamp(),
				'campaign' => $id,
				'ends'     => $ends,
			)
		);
		$response->header( 'Cache-Control', 'no-store, max-age=0' );

		return $response;
	}

	/** A fixed one-minute window per client address, in a short-lived transient. */
	private static function allowed(): bool {
		/**
		 * Filters how many clock requests one address may make per minute.
		 *
		 * @param int $limit Default 120.
		 */
		$limit = (int) apply_filters( 'eru_ce_clock_rate_limit', 120 );

		if ( $limit <= 0 ) {
			return true;
		}

		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		/**
		 * Filters the client key of the rate limit. Behind a reverse proxy or CDN that does not restore
		 * the visitor's address, REMOTE_ADDR is the proxy's and every visitor shares one bucket; return
		 * the real client address here (from a header your proxy sets and you trust).
		 *
		 * @param string $client Default REMOTE_ADDR.
		 */
		$client = (string) apply_filters( 'eru_ce_clock_client', $ip );
		$key    = 'eru_ce_clock_' . substr( md5( $client . '|' . (int) floor( time() / 60 ) ), 0, 20 );
		$hits = (int) get_transient( $key );

		if ( $hits >= $limit ) {
			return false;
		}

		set_transient( $key, $hits + 1, 2 * MINUTE_IN_SECONDS );

		return true;
	}
}
