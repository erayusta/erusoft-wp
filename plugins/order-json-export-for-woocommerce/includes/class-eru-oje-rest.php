<?php
/** GET /wp-json/eru-oje/v1/orders?after=&before=&status=&per_page=&page= */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_OJE_Rest {

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		register_rest_route(
			'eru-oje/v1',
			'/orders',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => static fn() => current_user_can( 'manage_woocommerce' ),
				'args'                => array(
					'after'    => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'before'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'status'   => array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ),
					'per_page' => array( 'type' => 'integer', 'default' => 50, 'minimum' => 1, 'maximum' => 100 ),
					'page'     => array( 'type' => 'integer', 'default' => 1, 'minimum' => 1 ),
				),
			)
		);
	}

	public static function handle( WP_REST_Request $request ) {
		$statuses = array();
		if ( $request['status'] ) {
			foreach ( explode( ',', (string) $request['status'] ) as $s ) {
				$s = trim( $s );
				if ( '' !== $s ) {
					$statuses[] = 0 === strpos( $s, 'wc-' ) ? $s : 'wc-' . $s;
				}
			}
		}
		$res  = Eru_OJE_Exporter::query(
			array(
				'after'    => $request['after'],
				'before'   => $request['before'],
				'statuses' => $statuses,
				'limit'    => (int) $request['per_page'],
				'page'     => (int) $request['page'],
			)
		);
		$resp = new WP_REST_Response( Eru_OJE_Exporter::to_document( $res['orders'] ) );
		$resp->header( 'X-WP-Total', (string) $res['total'] );
		$resp->header( 'X-WP-TotalPages', (string) $res['pages'] );
		return $resp;
	}
}
