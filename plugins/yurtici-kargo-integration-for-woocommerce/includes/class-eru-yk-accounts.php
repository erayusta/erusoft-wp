<?php
/**
 * Sender accounts and the first-match routing rules that pick one for an order.
 *
 * @package yurtici-kargo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An account is {id, label, username, password, prefix, customer_code}. The id is a stable random key so
 * that renaming an account does not break orders or rules that point at it.
 */
class Eru_YK_Accounts {

	/** @return array<string,array> All saved accounts keyed by id. */
	public static function all() {
		$out = array();
		foreach ( (array) Eru_YK_Settings::get( 'accounts' ) as $a ) {
			if ( is_array( $a ) && ! empty( $a['id'] ) ) {
				$out[ (string) $a['id'] ] = array_merge(
					array(
						'label'         => '',
						'username'      => '',
						'password'      => '',
						'prefix'        => '',
						'customer_code' => '',
					),
					$a
				);
			}
		}
		return $out;
	}

	/** @return array<string,array> Accounts with a user name and a password. */
	public static function usable() {
		return array_filter( self::all(), fn( $a ) => '' !== trim( (string) $a['username'] ) && '' !== (string) $a['password'] );
	}

	/** @param string $id Account id. @return array|null */
	public static function get( $id ) {
		$all = self::all();
		return $all[ (string) $id ] ?? null;
	}

	/** @param string $id Account id. @return string Label (or the id when the account was removed). */
	public static function label( $id ) {
		$a = self::get( $id );
		return $a ? (string) $a['label'] : (string) $id;
	}

	/** @return string[] Every stored password (for redaction). */
	public static function secrets() {
		$out = array();
		foreach ( self::all() as $a ) {
			if ( '' !== (string) $a['password'] ) {
				$out[] = (string) $a['password'];
			}
		}
		return $out;
	}

	/**
	 * @param WC_Order $order Order.
	 * @return int[] Shipping method instance ids of the order.
	 */
	public static function order_instances( WC_Order $order ) {
		$ids = array();
		foreach ( $order->get_shipping_methods() as $item ) {
			$ids[] = (int) $item->get_instance_id();
		}
		return $ids;
	}

	/**
	 * First matching rule wins; otherwise the default account, otherwise the first usable one.
	 *
	 * @param WC_Order $order Order.
	 * @return string Account id ('' when there is no usable account).
	 */
	public static function route( WC_Order $order ) {
		$usable    = self::usable();
		$total     = (float) $order->get_total();
		$instances = self::order_instances( $order );
		foreach ( (array) Eru_YK_Settings::get( 'rules' ) as $r ) {
			$acc = (string) ( $r['account'] ?? '' );
			if ( ! isset( $usable[ $acc ] ) ) {
				continue;
			}
			if ( '' !== (string) ( $r['min'] ?? '' ) && $total < (float) $r['min'] ) {
				continue;
			}
			if ( '' !== (string) ( $r['max'] ?? '' ) && $total >= (float) $r['max'] ) {
				continue;
			}
			$methods = array_map( 'intval', (array) ( $r['methods'] ?? array() ) );
			if ( $methods && ! array_intersect( $methods, $instances ) ) {
				continue;
			}
			return (string) apply_filters( 'eru_yk_route_account', $acc, $order );
		}
		$def = (string) Eru_YK_Settings::get( 'default_account' );
		$acc = isset( $usable[ $def ] ) ? $def : (string) ( array_key_first( $usable ) ?? '' );
		return (string) apply_filters( 'eru_yk_route_account', $acc, $order );
	}
}
