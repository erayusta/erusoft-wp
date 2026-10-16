<?php
/**
 * Agents and their hierarchy. An agent is a WordPress user with one row here; parent_agent_id points to
 * the upline. The repository refuses a parent that would close a cycle, and chain() still guards
 * against cycles written behind its back (imports, manual SQL).
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Agents {

	const STATUSES = array( 'pending', 'active', 'suspended' );

	/** @return string Table name. */
	private static function t() {
		return Eru_STC_Installer::table( 'agents' );
	}

	/**
	 * Creates an agent for a user.
	 *
	 * @param int   $user_id User id.
	 * @param array $args    parent_agent_id (int|null), status (default pending), referral_code (generated
	 *                       when empty), settings (array).
	 * @return int|WP_Error Agent id.
	 */
	public static function create( $user_id, array $args = array() ) {
		global $wpdb;
		$user_id = (int) $user_id;
		if ( $user_id <= 0 || ! get_userdata( $user_id ) ) {
			return new WP_Error( 'eru_stc_agent_user', __( 'Kullanıcı bulunamadı.', 'sales-team-commissions-for-woocommerce' ) );
		}
		if ( self::get_by_user( $user_id ) ) {
			return new WP_Error( 'eru_stc_agent_exists', __( 'Bu kullanıcı zaten temsilci.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$status = $args['status'] ?? 'pending';
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return new WP_Error( 'eru_stc_agent_status', __( 'Geçersiz temsilci durumu.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$parent = empty( $args['parent_agent_id'] ) ? null : (int) $args['parent_agent_id'];
		if ( null !== $parent && ! self::get( $parent ) ) {
			return new WP_Error( 'eru_stc_agent_parent', __( 'Üst temsilci bulunamadı.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$code = isset( $args['referral_code'] ) && '' !== $args['referral_code'] ? self::sanitize_code( $args['referral_code'] ) : self::generate_code();
		if ( '' === $code ) {
			return new WP_Error( 'eru_stc_agent_code', __( 'Geçersiz davet kodu.', 'sales-team-commissions-for-woocommerce' ) );
		}
		if ( self::get_by_code( $code ) ) {
			return new WP_Error( 'eru_stc_agent_code_taken', __( 'Bu davet kodu kullanımda.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$now = current_time( 'mysql', true );
		$ok  = $wpdb->insert(
			self::t(),
			array(
				'user_id'         => $user_id,
				'parent_agent_id' => $parent,
				'status'          => $status,
				'referral_code'   => $code,
				'settings'        => wp_json_encode( isset( $args['settings'] ) && is_array( $args['settings'] ) ? $args['settings'] : array() ),
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		if ( ! $ok ) {
			return new WP_Error( 'eru_stc_agent_db', __( 'Temsilci kaydedilemedi.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$id = (int) $wpdb->insert_id;
		/**
		 * Fires after an agent row is created.
		 *
		 * @param int $id      Agent id.
		 * @param int $user_id User id.
		 */
		do_action( 'eru_stc_agent_created', $id, $user_id );
		return $id;
	}

	/**
	 * Updates status, parent, referral code or settings.
	 *
	 * @param int   $id     Agent id.
	 * @param array $fields status, parent_agent_id (null/0 = none), referral_code, settings (array).
	 * @return true|WP_Error
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		$agent = self::get( $id );
		if ( ! $agent ) {
			return new WP_Error( 'eru_stc_agent_missing', __( 'Temsilci bulunamadı.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$data = array();
		if ( array_key_exists( 'status', $fields ) ) {
			if ( ! in_array( $fields['status'], self::STATUSES, true ) ) {
				return new WP_Error( 'eru_stc_agent_status', __( 'Geçersiz temsilci durumu.', 'sales-team-commissions-for-woocommerce' ) );
			}
			$data['status'] = $fields['status'];
		}
		if ( array_key_exists( 'parent_agent_id', $fields ) ) {
			$parent = empty( $fields['parent_agent_id'] ) ? null : (int) $fields['parent_agent_id'];
			if ( null !== $parent ) {
				if ( ! self::get( $parent ) ) {
					return new WP_Error( 'eru_stc_agent_parent', __( 'Üst temsilci bulunamadı.', 'sales-team-commissions-for-woocommerce' ) );
				}
				if ( self::would_cycle( (int) $agent->id, $parent ) ) {
					return new WP_Error( 'eru_stc_agent_cycle', __( 'Bu üst temsilci döngü oluşturur.', 'sales-team-commissions-for-woocommerce' ) );
				}
			}
			$data['parent_agent_id'] = $parent;
		}
		if ( array_key_exists( 'referral_code', $fields ) ) {
			$code = self::sanitize_code( $fields['referral_code'] );
			if ( '' === $code ) {
				return new WP_Error( 'eru_stc_agent_code', __( 'Geçersiz davet kodu.', 'sales-team-commissions-for-woocommerce' ) );
			}
			$other = self::get_by_code( $code );
			if ( $other && (int) $other->id !== (int) $agent->id ) {
				return new WP_Error( 'eru_stc_agent_code_taken', __( 'Bu davet kodu kullanımda.', 'sales-team-commissions-for-woocommerce' ) );
			}
			$data['referral_code'] = $code;
		}
		if ( array_key_exists( 'settings', $fields ) && is_array( $fields['settings'] ) ) {
			$data['settings'] = wp_json_encode( $fields['settings'] );
		}
		if ( ! $data ) {
			return true;
		}
		$data['updated_at'] = current_time( 'mysql', true );
		if ( false === $wpdb->update( self::t(), $data, array( 'id' => (int) $agent->id ) ) ) {
			return new WP_Error( 'eru_stc_agent_db', __( 'Temsilci kaydedilemedi.', 'sales-team-commissions-for-woocommerce' ) );
		}
		/**
		 * Fires after an agent row changes.
		 *
		 * @param int   $id     Agent id.
		 * @param array $data   Changed columns.
		 * @param object $before Row before the change.
		 */
		do_action( 'eru_stc_agent_updated', (int) $agent->id, $data, $agent );
		return true;
	}

	/**
	 * Deletes an agent that has no commission rows; its children move to its parent, its customers lose
	 * their link. Agents with history should be suspended instead.
	 *
	 * @param int $id Agent id.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		global $wpdb;
		$agent = self::get( $id );
		if ( ! $agent ) {
			return new WP_Error( 'eru_stc_agent_missing', __( 'Temsilci bulunamadı.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$ct = Eru_STC_Installer::table( 'commissions' );
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$ct} WHERE agent_id = %d", $agent->id ) ) > 0 ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			return new WP_Error( 'eru_stc_agent_has_history', __( 'Komisyon geçmişi olan temsilci silinemez; askıya alın.', 'sales-team-commissions-for-woocommerce' ) );
		}
		$t = self::t();
		if ( $agent->parent_agent_id ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET parent_agent_id = %d WHERE parent_agent_id = %d", $agent->parent_agent_id, $agent->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$wpdb->query( $wpdb->prepare( "UPDATE {$t} SET parent_agent_id = NULL WHERE parent_agent_id = %d", $agent->id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		$wpdb->delete( Eru_STC_Installer::table( 'links' ), array( 'agent_id' => (int) $agent->id ) );
		$wpdb->delete( $t, array( 'id' => (int) $agent->id ) );
		do_action( 'eru_stc_agent_deleted', (int) $agent->id, $agent );
		return true;
	}

	/** @param int $id Agent id. @return object|null Row. */
	public static function get( $id ) {
		global $wpdb;
		$t = self::t();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @param int $user_id User id. @return object|null Row. */
	public static function get_by_user( $user_id ) {
		global $wpdb;
		$t = self::t();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @param string $code Referral code (any case). @return object|null Row. */
	public static function get_by_code( $code ) {
		global $wpdb;
		$code = self::sanitize_code( $code );
		if ( '' === $code ) {
			return null;
		}
		$t = self::t();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE referral_code = %s", $code ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Name shown for an agent: the display name in its settings, else the user's display name, else
	 * "Temsilci #id".
	 *
	 * @param object|null $agent Row.
	 * @return string
	 */
	public static function label( $agent ) {
		if ( ! $agent ) {
			return '';
		}
		$settings = self::settings( $agent );
		if ( ! empty( $settings['display_name'] ) ) {
			return (string) $settings['display_name'];
		}
		$user = get_userdata( (int) $agent->user_id );
		/* translators: %d: agent id */
		return $user && '' !== $user->display_name ? $user->display_name : sprintf( __( 'Temsilci #%d', 'sales-team-commissions-for-woocommerce' ), (int) $agent->id );
	}

	/** @param object $agent Row. @return array Decoded settings JSON. */
	public static function settings( $agent ) {
		$s = $agent && isset( $agent->settings ) ? json_decode( (string) $agent->settings, true ) : null;
		return is_array( $s ) ? $s : array();
	}

	/**
	 * Downline (children, grandchildren, …) breadth first, bounded and cycle-safe.
	 *
	 * @param int $agent_id Agent.
	 * @param int $depth    Levels below the agent (1–5).
	 * @param int $limit    Max rows (≤ 500).
	 * @return array[] [ [ 'depth' => int, 'agent' => object ], … ].
	 */
	public static function downline( $agent_id, $depth, $limit = 200 ) {
		$out   = array();
		$seen  = array( (int) $agent_id => true );
		$level = array( (int) $agent_id );
		$limit = max( 1, min( 500, (int) $limit ) );
		for ( $d = 1; $d <= max( 1, min( 5, (int) $depth ) ) && $level; $d++ ) {
			$next = array();
			foreach ( $level as $parent ) {
				foreach ( self::list(
					array(
						'parent_agent_id' => $parent,
						'limit'           => $limit,
					)
				) as $child ) {
					if ( isset( $seen[ (int) $child->id ] ) ) {
						continue;
					}
					$seen[ (int) $child->id ] = true;
					$out[]                    = array(
						'depth' => $d,
						'agent' => $child,
					);
					$next[]                   = (int) $child->id;
					if ( count( $out ) >= $limit ) {
						return $out;
					}
				}
			}
			$level = $next;
		}
		return $out;
	}

	/** @param object|null $agent Row. @return bool Whether it is active. */
	public static function is_active( $agent ) {
		return $agent && 'active' === $agent->status;
	}

	/**
	 * Agents, newest first.
	 *
	 * @param array $args status, parent_agent_id, search (referral code prefix), user_ids (with search: also
	 *                    these users), limit (default 50), offset.
	 * @return object[]
	 */
	public static function list( array $args = array() ) {
		global $wpdb;
		list( $where, $params ) = self::where( $args );
		$limit                  = max( 1, min( 500, (int) ( $args['limit'] ?? 50 ) ) );
		$offset                 = max( 0, (int) ( $args['offset'] ?? 0 ) );
		$t                      = self::t();
		$params[]               = $limit;
		$params[]               = $offset;
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @param array $args Same filters as list(). @return int */
	public static function count( array $args = array() ) {
		global $wpdb;
		list( $where, $params ) = self::where( $args );
		$t                      = self::t();
		$sql                    = "SELECT COUNT(*) FROM {$t} WHERE {$where}";
		return (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $sql, $params ) ) : $wpdb->get_var( $sql ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @return array [ where SQL with placeholders, params ]. */
	private static function where( array $args ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( isset( $args['parent_agent_id'] ) ) {
			if ( (int) $args['parent_agent_id'] > 0 ) {
				$where[]  = 'parent_agent_id = %d';
				$params[] = (int) $args['parent_agent_id'];
			} else {
				$where[] = 'parent_agent_id IS NULL';
			}
		}
		if ( ! empty( $args['search'] ) && empty( $args['user_ids'] ) ) {
			$where[]  = 'referral_code LIKE %s';
			$params[] = $wpdb->esc_like( self::sanitize_code( $args['search'] ) ) . '%';
		} elseif ( ! empty( $args['search'] ) ) {
			// Code prefix, or one of the given users (an admin search by name / e-mail).
			$ids      = array_values( array_filter( array_map( 'absint', (array) $args['user_ids'] ) ) );
			$where[]  = '( referral_code LIKE %s' . ( $ids ? ' OR user_id IN (' . implode( ',', $ids ) . ')' : '' ) . ' )';
			$params[] = $wpdb->esc_like( self::sanitize_code( $args['search'] ) ) . '%';
		}
		return array( implode( ' AND ', $where ), $params );
	}

	/** @param int $id Agent id. @return int[] Direct children. */
	public static function children( $id ) {
		global $wpdb;
		$t = self::t();
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE parent_agent_id = %d ORDER BY id", $id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/** @return int[] Active agent ids, ascending. */
	public static function active_ids() {
		global $wpdb;
		$t = self::t();
		return array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$t} WHERE status = 'active' ORDER BY id" ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Whether making $parent_id the parent of $agent_id closes a cycle (the parent is the agent itself or
	 * one of its descendants). Walks up from the parent, bounded.
	 *
	 * @param int $agent_id  Agent.
	 * @param int $parent_id Proposed parent.
	 * @return bool
	 */
	public static function would_cycle( $agent_id, $parent_id ) {
		$seen    = array();
		$current = (int) $parent_id;
		for ( $i = 0; $current > 0 && $i < 1000; $i++ ) {
			if ( $current === (int) $agent_id || isset( $seen[ $current ] ) ) {
				return true;
			}
			$seen[ $current ] = true;
			$row              = self::get( $current );
			$current          = $row && $row->parent_agent_id ? (int) $row->parent_agent_id : 0;
		}
		return false;
	}

	/**
	 * The paying chain for a sale: the agent at level 1, its parent at level 2, … up to $max levels.
	 * Levels are distances, so an inactive ancestor earns nothing but still counts as a level. A cycle
	 * (an agent seen twice) ends the walk; it is logged once and reported through eru_stc_cycle_detected.
	 *
	 * @param int $agent_id Level-1 agent.
	 * @param int $max      Level cap (1–5).
	 * @return array[] [ [ 'level' => int, 'agent' => object ], … ] active agents only, by level.
	 */
	public static function chain( $agent_id, $max ) {
		$max     = max( 1, min( 5, (int) $max ) );
		$chain   = array();
		$visited = array();
		$current = (int) $agent_id;
		for ( $level = 1; $level <= $max && $current > 0; $level++ ) {
			if ( isset( $visited[ $current ] ) ) {
				Eru_STC_Log::warning( 'Agent hierarchy contains a cycle; commission walk stopped.', array( 'agent_id' => (int) $agent_id, 'repeated_agent_id' => $current ) );
				/**
				 * Fires when the upline walk meets an agent it has already visited.
				 *
				 * @param int $agent_id  Level-1 agent of the walk.
				 * @param int $repeated  The agent met twice.
				 */
				do_action( 'eru_stc_cycle_detected', (int) $agent_id, $current );
				break;
			}
			$visited[ $current ] = true;
			$row                 = self::get( $current );
			if ( ! $row ) {
				break;
			}
			if ( self::is_active( $row ) ) {
				$chain[] = array(
					'level' => $level,
					'agent' => $row,
				);
			}
			$current = $row->parent_agent_id ? (int) $row->parent_agent_id : 0;
		}
		return $chain;
	}

	/** @param string $code Raw code. @return string Upper-case A–Z, 0–9 and dashes, max 32. */
	public static function sanitize_code( $code ) {
		return substr( (string) preg_replace( '/[^A-Z0-9-]/', '', strtoupper( (string) $code ) ), 0, 32 );
	}

	/** @return string A fresh unused 8-character code. */
	public static function generate_code() {
		$alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		for ( $try = 0; $try < 20; $try++ ) {
			$code = '';
			for ( $i = 0; $i < 8; $i++ ) {
				$code .= $alphabet[ random_int( 0, strlen( $alphabet ) - 1 ) ];
			}
			if ( ! self::get_by_code( $code ) ) {
				return $code;
			}
		}
		return strtoupper( substr( wp_generate_uuid4(), 0, 8 ) . bin2hex( random_bytes( 4 ) ) );
	}
}
