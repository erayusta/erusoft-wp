<?php
/**
 * Customer → agent links. A customer has at most one agent (UNIQUE customer_id). A locked link
 * (first-touch) moves only with 'force' (an admin decision); an agent is never its own customer.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Links {

	const TYPES = array( 'referral', 'round_robin', 'manual', 'invite' );

	/** @return string Table name. */
	private static function t() {
		return Eru_STC_Installer::table( 'links' );
	}

	/**
	 * Links (or re-links) a customer to an agent.
	 *
	 * @param int    $customer_id Customer user id.
	 * @param int    $agent_id    Agent id.
	 * @param string $type        referral | round_robin | manual | invite (confirmed by the customer).
	 * @param array  $args        lock (bool: set locked_at), force (bool: move a locked link), created_by (user id).
	 * @return true|WP_Error
	 */
	public static function link( $customer_id, $agent_id, $type = 'manual', array $args = array() ) {
		global $wpdb;
		$customer_id = (int) $customer_id;
		$agent       = Eru_STC_Agents::get( $agent_id );
		if ( $customer_id <= 0 || ! get_userdata( $customer_id ) ) {
			return new WP_Error( 'eru_stc_link_customer', __( 'Müşteri bulunamadı.', 'sales-team-commissions-for-woocommerce' ) );
		}
		if ( ! $agent ) {
			return new WP_Error( 'eru_stc_link_agent', __( 'Temsilci bulunamadı.', 'sales-team-commissions-for-woocommerce' ) );
		}
		if ( (int) $agent->user_id === $customer_id ) {
			return new WP_Error( 'eru_stc_link_self', __( 'Temsilci kendi müşterisi olamaz.', 'sales-team-commissions-for-woocommerce' ) );
		}
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error( 'eru_stc_link_type', __( 'Geçersiz bağlantı türü.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$now      = current_time( 'mysql', true );
		$existing = self::get( $customer_id );
		$lock     = ! empty( $args['lock'] );
		if ( $existing ) {
			if ( (int) $existing->agent_id === (int) $agent->id ) {
				if ( $lock && null === $existing->locked_at ) {
					$wpdb->update( self::t(), array( 'locked_at' => $now, 'updated_at' => $now ), array( 'id' => (int) $existing->id ) );
				}
				return true;
			}
			if ( null !== $existing->locked_at && empty( $args['force'] ) ) {
				return new WP_Error( 'eru_stc_link_locked', __( 'Müşteri ilk temsilcisine kilitli.', 'sales-team-commissions-for-woocommerce' ) );
			}
			$data = array(
				'agent_id'   => (int) $agent->id,
				'link_type'  => $type,
				'locked_at'  => $lock ? $now : ( empty( $args['force'] ) ? null : $existing->locked_at ),
				'created_by' => (int) ( $args['created_by'] ?? get_current_user_id() ),
				'updated_at' => $now,
			);
			if ( false === $wpdb->update( self::t(), $data, array( 'id' => (int) $existing->id ) ) ) {
				return new WP_Error( 'eru_stc_link_db', __( 'Bağlantı kaydedilemedi.', 'sales-team-commissions-for-woocommerce' ) );
			}
		} else {
			// INSERT IGNORE: two concurrent first orders of the same customer keep the first link.
			$t = self::t();
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$t} (customer_id, agent_id, link_type, locked_at, created_by, created_at, updated_at) VALUES (%d, %d, %s, IF(%d = 1, %s, NULL), %d, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$customer_id,
					(int) $agent->id,
					$type,
					$lock ? 1 : 0,
					$now,
					(int) ( $args['created_by'] ?? get_current_user_id() ),
					$now,
					$now
				)
			);
			$row = self::get( $customer_id );
			if ( ! $row || (int) $row->agent_id !== (int) $agent->id ) {
				return new WP_Error( 'eru_stc_link_race', __( 'Müşteri bu arada başka bir temsilciye bağlandı.', 'sales-team-commissions-for-woocommerce' ) );
			}
		}
		/**
		 * Fires after a customer is linked to an agent.
		 *
		 * @param int    $customer_id Customer user id.
		 * @param int    $agent_id    Agent id.
		 * @param string $type        Link type.
		 * @param int    $previous    Previous agent id (0 = none).
		 */
		do_action( 'eru_stc_customer_linked', $customer_id, (int) $agent->id, $type, $existing ? (int) $existing->agent_id : 0 );
		return true;
	}

	/** @param int $customer_id Customer. @return object|null Link row. */
	public static function get( $customer_id ) {
		global $wpdb;
		$t = self::t();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE customer_id = %d", $customer_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @param int $customer_id Customer. @return int Linked agent id (any status), 0 when none. */
	public static function agent_for( $customer_id ) {
		$row = $customer_id > 0 ? self::get( $customer_id ) : null;
		return $row ? (int) $row->agent_id : 0;
	}

	/** @param int $customer_id Customer. @return bool Whether a link was removed. */
	public static function unlink( $customer_id ) {
		global $wpdb;
		$row = self::get( $customer_id );
		if ( ! $row ) {
			return false;
		}
		$wpdb->delete( self::t(), array( 'id' => (int) $row->id ) );
		do_action( 'eru_stc_customer_unlinked', (int) $customer_id, (int) $row->agent_id );
		return true;
	}

	/**
	 * Customers of an agent, newest link first.
	 *
	 * @param int $agent_id Agent.
	 * @param int $limit    Max rows (≤ 500).
	 * @param int $offset   Offset.
	 * @return object[] Link rows.
	 */
	public static function customers_of( $agent_id, $limit = 50, $offset = 0 ) {
		global $wpdb;
		$t = self::t();
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE agent_id = %d ORDER BY id DESC LIMIT %d OFFSET %d", $agent_id, max( 1, min( 500, (int) $limit ) ), max( 0, (int) $offset ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @param int $agent_id Agent. @return int Customer count. */
	public static function count_for( $agent_id ) {
		global $wpdb;
		$t = self::t();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE agent_id = %d", $agent_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
