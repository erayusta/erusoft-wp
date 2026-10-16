<?php
/**
 * Read-only REST API, namespace eru-stc/v1 (registered while the module is on). There are no write
 * routes: payouts, approvals and settings change only through the admin screens and My Account forms.
 *
 * Agent routes (logged-in agent; only the caller's own data, any agent_id parameter is ignored):
 *   GET /me                profile, referral link, balance
 *   GET /me/commissions    ?status= &page= &per_page=
 *   GET /me/payouts        ?page= &per_page=
 *   GET /me/team           downline to the level cap
 * Admin routes (manage_eru_stc):
 *   GET /agents            ?status= &page= &per_page=
 *   GET /agents/<id>       agent with balance and masked payout details
 *   GET /commissions       ?agent_id= &order_id= &status= &page= &per_page=
 *   GET /payouts           ?agent_id= &status= &page= &per_page=
 * Payout details only ever appear masked.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Rest {

	const NS = 'eru-stc/v1';

	/** Hooks the routes (registered only while the module is on). */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/** Registers the GET routes. */
	public static function routes() {
		if ( ! Eru_STC_Settings::enabled() ) {
			return;
		}
		$paging = array(
			'page'     => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page' => array(
				'type'              => 'integer',
				'default'           => 20,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
		);
		$status = array(
			'status' => array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => 'sanitize_key',
			),
		);
		$me     = array( __CLASS__, 'can_agent' );
		$admin  = array( __CLASS__, 'can_admin' );
		register_rest_route( self::NS, '/me', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'me' ), 'permission_callback' => $me ) );
		register_rest_route( self::NS, '/me/commissions', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'me_commissions' ), 'permission_callback' => $me, 'args' => $paging + $status ) );
		register_rest_route( self::NS, '/me/payouts', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'me_payouts' ), 'permission_callback' => $me, 'args' => $paging ) );
		register_rest_route( self::NS, '/me/team', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'me_team' ), 'permission_callback' => $me ) );
		register_rest_route( self::NS, '/agents', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'agents' ), 'permission_callback' => $admin, 'args' => $paging + $status ) );
		register_rest_route( self::NS, '/agents/(?P<id>\d+)', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'agent' ), 'permission_callback' => $admin ) );
		$filters = array(
			'agent_id' => array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
			'order_id' => array(
				'type'              => 'integer',
				'default'           => 0,
				'sanitize_callback' => 'absint',
			),
		);
		register_rest_route( self::NS, '/commissions', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'commissions' ), 'permission_callback' => $admin, 'args' => $paging + $status + $filters ) );
		register_rest_route( self::NS, '/payouts', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( __CLASS__, 'payouts' ), 'permission_callback' => $admin, 'args' => $paging + $status + array( 'agent_id' => $filters['agent_id'] ) ) );
	}

	/** @return true|WP_Error A logged-in user with an agent row. */
	public static function can_agent() {
		if ( ! is_user_logged_in() ) {
			return new WP_Error( 'rest_not_logged_in', __( 'Giriş yapmanız gerekiyor.', 'sales-team-commissions-for-woocommerce' ), array( 'status' => 401 ) );
		}
		if ( ! Eru_STC_Agents::get_by_user( get_current_user_id() ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Bu kaynak yalnızca temsilciler içindir.', 'sales-team-commissions-for-woocommerce' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/** @return true|WP_Error The plugin's capability. */
	public static function can_admin() {
		if ( ! current_user_can( 'manage_eru_stc' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'Bu işlem için yetkiniz yok.', 'sales-team-commissions-for-woocommerce' ), array( 'status' => is_user_logged_in() ? 403 : 401 ) );
		}
		return true;
	}

	/** @return object The current user's agent row. */
	private static function current_agent() {
		return Eru_STC_Agents::get_by_user( get_current_user_id() );
	}

	/** @param WP_REST_Request $req Request. @return WP_REST_Response */
	public static function me( $req ) {
		$agent = self::current_agent();
		$data  = self::agent_data( $agent, false );
		return rest_ensure_response( $data );
	}

	/** @param WP_REST_Request $req Request. @return WP_REST_Response */
	public static function me_commissions( $req ) {
		$agent = self::current_agent();
		return self::commission_page( array( 'agent_id' => (int) $agent->id ), $req );
	}

	/** @param WP_REST_Request $req Request. @return WP_REST_Response */
	public static function me_payouts( $req ) {
		$agent = self::current_agent();
		return self::payout_page( array( 'agent_id' => (int) $agent->id ), $req );
	}

	/** @param WP_REST_Request $req Request. @return WP_REST_Response */
	public static function me_team( $req ) {
		$agent = self::current_agent();
		$out   = array();
		foreach ( Eru_STC_Agents::downline( (int) $agent->id, (int) Eru_STC_Settings::get( 'max_levels' ) ) as $d ) {
			$out[] = array(
				'id'        => (int) $d['agent']->id,
				'name'      => Eru_STC_Agents::label( $d['agent'] ),
				'depth'     => (int) $d['depth'],
				'status'    => $d['agent']->status,
				'customers' => Eru_STC_Links::count_for( (int) $d['agent']->id ),
			);
		}
		return rest_ensure_response( $out );
	}

	/** @param WP_REST_Request $req Request. @return WP_REST_Response */
	public static function agents( $req ) {
		$args  = array( 'status' => (string) $req['status'] );
		$total = Eru_STC_Agents::count( $args );
		$rows  = Eru_STC_Agents::list(
			$args + array(
				'limit'  => (int) $req['per_page'],
				'offset' => ( (int) $req['page'] - 1 ) * (int) $req['per_page'],
			)
		);
		$resp  = rest_ensure_response( array_map( fn( $a ) => self::agent_data( $a, false ), $rows ) );
		return self::paged( $resp, $total, (int) $req['per_page'] );
	}

	/** @param WP_REST_Request $req Request. @return WP_REST_Response|WP_Error */
	public static function agent( $req ) {
		$agent = Eru_STC_Agents::get( (int) $req['id'] );
		if ( ! $agent ) {
			return new WP_Error( 'rest_not_found', __( 'Temsilci bulunamadı.', 'sales-team-commissions-for-woocommerce' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( self::agent_data( $agent, true ) );
	}

	/** @param WP_REST_Request $req Request. @return WP_REST_Response */
	public static function commissions( $req ) {
		return self::commission_page(
			array(
				'agent_id' => (int) $req['agent_id'],
				'order_id' => (int) $req['order_id'],
			),
			$req
		);
	}

	/** @param WP_REST_Request $req Request. @return WP_REST_Response */
	public static function payouts( $req ) {
		return self::payout_page( array( 'agent_id' => (int) $req['agent_id'] ), $req );
	}

	/** Commission rows page. */
	private static function commission_page( array $args, $req ) {
		$status = (string) $req['status'];
		if ( in_array( $status, Eru_STC_Commissions::STATUSES, true ) ) {
			$args['status'] = $status;
		}
		$per   = (int) $req['per_page'];
		$total = Eru_STC_Commissions::count( $args );
		$rows  = Eru_STC_Commissions::list(
			$args + array(
				'limit'  => $per,
				'offset' => ( (int) $req['page'] - 1 ) * $per,
			)
		);
		$out   = array();
		foreach ( $rows as $r ) {
			$out[] = self::commission_data( $r );
		}
		return self::paged( rest_ensure_response( $out ), $total, $per );
	}

	/** Payout rows page. */
	private static function payout_page( array $args, $req ) {
		$status = isset( $req['status'] ) ? (string) $req['status'] : '';
		if ( in_array( $status, Eru_STC_Payouts::STATUSES, true ) ) {
			$args['status'] = $status;
		}
		$per   = (int) $req['per_page'];
		$total = Eru_STC_Payouts::count( $args );
		$rows  = Eru_STC_Payouts::list(
			$args + array(
				'limit'  => $per,
				'offset' => ( (int) $req['page'] - 1 ) * $per,
			)
		);
		$out   = array();
		foreach ( $rows as $p ) {
			$out[] = array(
				'id'           => (int) $p->id,
				'agent_id'     => (int) $p->agent_id,
				'amount'       => Eru_STC_Reports::dec( $p->amount ),
				'currency'     => $p->currency,
				'status'       => $p->status,
				'method'       => $p->method,
				'reference'    => $p->reference,
				'requested_at' => mysql_to_rfc3339( $p->requested_at ),
				'paid_at'      => $p->paid_at ? mysql_to_rfc3339( $p->paid_at ) : null,
			);
		}
		return self::paged( rest_ensure_response( $out ), $total, $per );
	}

	/** @param object $r Commission row. @return array */
	public static function commission_data( $r ) {
		return array(
			'id'          => (int) $r->id,
			'order_id'    => (int) $r->order_id,
			'agent_id'    => (int) $r->agent_id,
			'level'       => (int) $r->level,
			'type'        => '' === $r->entry_key ? 'commission' : 'adjustment',
			'basis'       => Eru_STC_Reports::dec( $r->basis ),
			'amount'      => Eru_STC_Reports::dec( $r->amount ),
			'currency'    => $r->currency,
			'status'      => $r->status,
			'void_reason' => $r->void_reason,
			'earned_at'   => mysql_to_rfc3339( $r->earned_at ),
			'approved_at' => $r->approved_at ? mysql_to_rfc3339( $r->approved_at ) : null,
			'payout_id'   => $r->payout_id ? (int) $r->payout_id : null,
		);
	}

	/**
	 * @param object $agent  Row.
	 * @param bool   $detail Add balance and masked payout details (admin detail route).
	 * @return array
	 */
	private static function agent_data( $agent, $detail ) {
		$out = array(
			'id'           => (int) $agent->id,
			'name'         => Eru_STC_Agents::label( $agent ),
			'status'       => $agent->status,
			'code'         => $agent->referral_code,
			'parent_id'    => $agent->parent_agent_id ? (int) $agent->parent_agent_id : null,
			'referral_url' => add_query_arg( Eru_STC_Referral::param(), $agent->referral_code, home_url( '/' ) ),
			'customers'    => Eru_STC_Links::count_for( (int) $agent->id ),
			'balance'      => Eru_STC_Commissions::balance( (int) $agent->id ),
			'created_at'   => mysql_to_rfc3339( $agent->created_at ),
		);
		if ( $detail ) {
			$m                      = Eru_STC_Payout_Details::masked( $agent );
			$out['user_id']         = (int) $agent->user_id;
			$out['payout_details']  = array(
				'state'  => $m['state'],
				'holder' => $m['holder'],
				'iban'   => $m['iban'],
				'tckn'   => $m['tckn'],
			);
		}
		return $out;
	}

	/** Adds X-WP-Total / X-WP-TotalPages. */
	private static function paged( $resp, $total, $per ) {
		$resp->header( 'X-WP-Total', (string) (int) $total );
		$resp->header( 'X-WP-TotalPages', (string) max( 1, (int) ceil( $total / max( 1, $per ) ) ) );
		return $resp;
	}
}
