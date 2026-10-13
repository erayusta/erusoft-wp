<?php
/** Lead table, settings, purge and the WordPress privacy exporter/eraser. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_ACCL_DB {

	const DB_VERSION = '1';
	const OPTION     = 'eru_accl_settings';

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'eru_accl_leads';
	}

	/** Statuses: key => label. */
	public static function statuses(): array {
		return array(
			'call'        => __( 'Aranacak', 'abandoned-checkout-call-list-for-woocommerce' ),
			'called'      => __( 'Arandı', 'abandoned-checkout-call-list-for-woocommerce' ),
			'unreachable' => __( 'Ulaşılamadı', 'abandoned-checkout-call-list-for-woocommerce' ),
			'ordered'     => __( 'Sipariş verdi', 'abandoned-checkout-call-list-for-woocommerce' ),
		);
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				email varchar(190) NOT NULL DEFAULT '',
				phone varchar(32) NOT NULL DEFAULT '',
				first_name varchar(100) NOT NULL DEFAULT '',
				cart longtext NULL,
				cart_total decimal(14,2) NOT NULL DEFAULT 0,
				status varchar(20) NOT NULL DEFAULT 'call',
				note text NULL,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_gmt datetime NOT NULL,
				updated_gmt datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY email (email),
				KEY phone (phone),
				KEY status (status),
				KEY updated_gmt (updated_gmt)
			) {$charset};"
		);
		update_option( 'eru_accl_db_version', self::DB_VERSION, false );
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'eru_accl_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	/* ---- settings (option autoload=no) ---- */

	public static function defaults(): array {
		return array(
			'enabled'          => 0,
			'consent_text'     => '',
			'require_checkbox' => 1,
			'retention_days'   => 30,
		);
	}

	public static function settings(): array {
		$s = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $s ) ? $s : array(), self::defaults() );
	}

	public static function now(): string {
		return gmdate( 'Y-m-d H:i:s' );
	}

	public static function normalize_phone( $phone ): string {
		$d = preg_replace( '/\D+/', '', (string) $phone );
		return strlen( $d ) > 10 ? substr( $d, -10 ) : $d;
	}

	/**
	 * Insert or refresh the open lead for this contact.
	 *
	 * @param array $cart List of [product_id, variation_id, name, qty].
	 * @return int Lead id, 0 when nothing was stored.
	 */
	public static function upsert( string $email, string $phone, string $first_name, array $cart, float $total ): int {
		global $wpdb;
		$email = strtolower( sanitize_email( $email ) );
		$phone = self::normalize_phone( $phone );
		if ( ! is_email( $email ) ) {
			$email = '';
		}
		if ( strlen( $phone ) < 10 ) {
			$phone = '';
		}
		if ( ( '' === $email && '' === $phone ) || ! $cart ) {
			return 0;
		}
		$t   = self::table();
		$id  = 0;
		if ( '' !== $email ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE email = %s AND status <> 'ordered' ORDER BY id DESC LIMIT 1", $email ) ); // phpcs:ignore WordPress.DB
		}
		if ( ! $id && '' !== $phone ) {
			$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE phone = %s AND status <> 'ordered' ORDER BY id DESC LIMIT 1", $phone ) ); // phpcs:ignore WordPress.DB
		}
		$data = array(
			'cart'       => wp_json_encode( $cart, JSON_UNESCAPED_UNICODE ),
			'cart_total' => $total,
			'updated_gmt' => self::now(),
		);
		if ( '' !== $email ) {
			$data['email'] = $email;
		}
		if ( '' !== $phone ) {
			$data['phone'] = $phone;
		}
		if ( '' !== $first_name ) {
			$data['first_name'] = mb_substr( sanitize_text_field( $first_name ), 0, 100 );
		}
		if ( $id ) {
			$wpdb->update( $t, $data, array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
			return $id;
		}
		$data['status']      = 'call';
		$data['created_gmt'] = self::now();
		$wpdb->insert( $t, $data ); // phpcs:ignore WordPress.DB
		return (int) $wpdb->insert_id;
	}

	public static function get( int $id ): ?array {
		global $wpdb;
		$t   = self::table();
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ?: null;
	}

	public static function update_follow_up( int $id, string $status, string $note ): bool {
		global $wpdb;
		if ( ! isset( self::statuses()[ $status ] ) ) {
			return false;
		}
		return false !== $wpdb->update( self::table(), array( 'status' => $status, 'note' => sanitize_textarea_field( $note ), 'updated_gmt' => self::now() ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
	}

	public static function delete( int $id ): void {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB
	}

	/** Mark open leads of this e-mail/phone as ordered. */
	public static function mark_ordered( string $email, string $phone, int $order_id ): int {
		global $wpdb;
		$t     = self::table();
		$email = strtolower( trim( $email ) );
		$phone = self::normalize_phone( $phone );
		$n     = 0;
		if ( '' !== $email ) {
			$n += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status='ordered', order_id=%d, updated_gmt=%s WHERE email=%s AND status<>'ordered'", $order_id, self::now(), $email ) ); // phpcs:ignore WordPress.DB
		}
		if ( strlen( $phone ) >= 10 ) {
			$n += (int) $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status='ordered', order_id=%d, updated_gmt=%s WHERE phone=%s AND status<>'ordered'", $order_id, self::now(), $phone ) ); // phpcs:ignore WordPress.DB
		}
		return $n;
	}

	/** Delete rows not updated for $days days (0 = everything up to now). @return int deleted */
	public static function purge( int $days ): int {
		global $wpdb;
		$cut = gmdate( 'Y-m-d H:i:s', time() - max( 0, $days ) * DAY_IN_SECONDS );
		$t   = self::table();
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$t} WHERE updated_gmt <= %s", $cut ) ); // phpcs:ignore WordPress.DB
	}

	/* ---- WordPress privacy tools (key: e-mail) ---- */

	public static function register_exporter( $exporters ) {
		$exporters['eru-accl'] = array(
			'exporter_friendly_name' => __( 'Abandoned Checkout Call List', 'abandoned-checkout-call-list-for-woocommerce' ),
			'callback'               => array( __CLASS__, 'export_personal' ),
		);
		return $exporters;
	}

	public static function register_eraser( $erasers ) {
		$erasers['eru-accl'] = array(
			'eraser_friendly_name' => __( 'Abandoned Checkout Call List', 'abandoned-checkout-call-list-for-woocommerce' ),
			'callback'             => array( __CLASS__, 'erase_personal' ),
		);
		return $erasers;
	}

	/** Phone numbers (last 10 digits) of orders placed with this e-mail; WP privacy tools only pass the e-mail. */
	private static function phones_for_email( string $email ): array {
		$phones = array();
		foreach ( wc_get_orders( array( 'billing_email' => $email, 'limit' => 50, 'return' => 'objects' ) ) as $o ) {
			$p = self::normalize_phone( $o->get_billing_phone() );
			if ( strlen( $p ) >= 10 ) {
				$phones[ $p ] = true;
			}
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			$p = self::normalize_phone( (string) get_user_meta( $user->ID, 'billing_phone', true ) );
			if ( strlen( $p ) >= 10 ) {
				$phones[ $p ] = true;
			}
		}
		return array_keys( $phones );
	}

	/** WHERE clause matching the e-mail or any known phone of that person. */
	private static function match_sql( string $email ): string {
		global $wpdb;
		$sql = $wpdb->prepare( 'email = %s', $email );
		$ph  = self::phones_for_email( $email );
		if ( $ph ) {
			$sql .= $wpdb->prepare( ' OR phone IN (' . implode( ',', array_fill( 0, count( $ph ), '%s' ) ) . ')', ...$ph ); // phpcs:ignore WordPress.DB
		}
		return $sql;
	}

	public static function export_personal( $email ): array {
		global $wpdb;
		$t    = self::table();
		$rows = $wpdb->get_results( "SELECT * FROM {$t} WHERE " . self::match_sql( strtolower( trim( (string) $email ) ) ), ARRAY_A ); // phpcs:ignore WordPress.DB
		$data = array();
		foreach ( (array) $rows as $r ) {
			$items = array();
			foreach ( (array) json_decode( (string) $r['cart'], true ) as $line ) {
				$items[] = ( $line['name'] ?? '' ) . ' × ' . (int) ( $line['qty'] ?? 0 );
			}
			$data[] = array(
				'group_id'    => 'eru-accl',
				'group_label' => __( 'Terk edilen ödeme kaydı', 'abandoned-checkout-call-list-for-woocommerce' ),
				'item_id'     => 'eru-accl-' . $r['id'],
				'data'        => array(
					array( 'name' => __( 'E-posta', 'abandoned-checkout-call-list-for-woocommerce' ), 'value' => $r['email'] ),
					array( 'name' => __( 'Telefon', 'abandoned-checkout-call-list-for-woocommerce' ), 'value' => $r['phone'] ),
					array( 'name' => __( 'Ad', 'abandoned-checkout-call-list-for-woocommerce' ), 'value' => $r['first_name'] ),
					array( 'name' => __( 'Sepet', 'abandoned-checkout-call-list-for-woocommerce' ), 'value' => implode( ', ', $items ) ),
					array( 'name' => __( 'Durum', 'abandoned-checkout-call-list-for-woocommerce' ), 'value' => self::statuses()[ $r['status'] ] ?? $r['status'] ),
					array( 'name' => __( 'Not', 'abandoned-checkout-call-list-for-woocommerce' ), 'value' => (string) $r['note'] ),
					array( 'name' => __( 'Kayıt tarihi (UTC)', 'abandoned-checkout-call-list-for-woocommerce' ), 'value' => $r['created_gmt'] ),
				),
			);
		}
		return array( 'data' => $data, 'done' => true );
	}

	public static function erase_personal( $email ): array {
		global $wpdb;
		$t = self::table();
		$n = (int) $wpdb->query( "DELETE FROM {$t} WHERE " . self::match_sql( strtolower( trim( (string) $email ) ) ) ); // phpcs:ignore WordPress.DB
		return array( 'items_removed' => $n > 0, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}
}
