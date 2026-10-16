<?php
/**
 * WordPress personal data exporter and eraser ("eru-stc"), looked up by the account e-mail.
 *
 * Agent: export = code, status, display name, dates, masked payout details, payout requests (amount,
 * dates, status, reference); commission rows are accounting records by agent id and are not exported.
 * Erase = payout details (holder, IBAN, TCKN) and every payout snapshot of them are wiped, the display
 * name is removed. While a payout request is open, its details and snapshot are kept (reported as
 * retained) so it can still be paid; run the erasure again once the request is paid or cancelled. Commission rows and payout requests stay as accounting
 * records, tied to the agent id only; the agent row itself stays (it holds the hierarchy) and is
 * suspended.
 * Customer: export = the agent the customer is linked to; erase = the link and the invitation marker are
 * removed and the customer id on commission rows is set to 0 (the order id stays for accounting).
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_STC_Privacy {

	const ID = 'eru-stc';

	/** Registers the exporter, the eraser and the privacy policy text. */
	public static function init() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
		add_action( 'admin_init', array( __CLASS__, 'policy' ) );
	}

	/** @param array $list Exporters. @return array */
	public static function register_exporter( $list ) {
		$list[ self::ID ] = array(
			'exporter_friendly_name' => __( 'Satış ekibi komisyonları', 'sales-team-commissions-for-woocommerce' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $list;
	}

	/** @param array $list Erasers. @return array */
	public static function register_eraser( $list ) {
		$list[ self::ID ] = array(
			'eraser_friendly_name' => __( 'Satış ekibi komisyonları', 'sales-team-commissions-for-woocommerce' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $list;
	}

	/** Suggested privacy policy text. */
	public static function policy() {
		if ( function_exists( 'wp_add_privacy_policy_content' ) ) {
			wp_add_privacy_policy_content(
				__( 'Sales Team Commissions for WooCommerce', 'sales-team-commissions-for-woocommerce' ),
				'<p>' . esc_html__( 'Satış temsilcilerinin ödeme bilgileri (hesap sahibi, IBAN, isteğe bağlı T.C. kimlik no) şifreli saklanır ve yalnızca ödeme için kullanılır. Müşterilerin hangi temsilciye bağlı olduğu ve siparişlerden doğan komisyon kayıtları muhasebe kaydı olarak tutulur. Temsilci davet linkiyle gelen ziyaretçilere, ayarlanan gün sayısı kadar geçerli "eru_stc_ref" çerezi yazılır.', 'sales-team-commissions-for-woocommerce' ) . '</p>'
			);
		}
	}

	/**
	 * @param string $email Address.
	 * @param int    $page  Page (single page).
	 * @return array{data:array,done:bool}
	 */
	public static function export( $email, $page = 1 ) {
		$user = get_user_by( 'email', (string) $email );
		$data = array();
		if ( $user ) {
			$agent = Eru_STC_Agents::get_by_user( $user->ID );
			if ( $agent ) {
				$settings = Eru_STC_Agents::settings( $agent );
				$m        = Eru_STC_Payout_Details::masked( $agent );
				$fields   = array(
					array(
						'name'  => __( 'Davet kodu', 'sales-team-commissions-for-woocommerce' ),
						'value' => $agent->referral_code,
					),
					array(
						'name'  => __( 'Durum', 'sales-team-commissions-for-woocommerce' ),
						'value' => Eru_STC_Labels::agent_status( $agent->status ),
					),
					array(
						'name'  => __( 'Kayıt tarihi', 'sales-team-commissions-for-woocommerce' ),
						'value' => get_date_from_gmt( $agent->created_at ),
					),
				);
				if ( ! empty( $settings['display_name'] ) ) {
					$fields[] = array(
						'name'  => __( 'Görünen ad', 'sales-team-commissions-for-woocommerce' ),
						'value' => (string) $settings['display_name'],
					);
				}
				foreach ( array(
					'holder' => __( 'Hesap sahibi (maskeli)', 'sales-team-commissions-for-woocommerce' ),
					'iban'   => __( 'IBAN (maskeli)', 'sales-team-commissions-for-woocommerce' ),
					'tckn'   => __( 'T.C. kimlik no (maskeli)', 'sales-team-commissions-for-woocommerce' ),
				) as $k => $label ) {
					if ( '' !== $m[ $k ] ) {
						$fields[] = array(
							'name'  => $label,
							'value' => $m[ $k ],
						);
					}
				}
				$data[] = array(
					'group_id'    => 'eru-stc-agent',
					'group_label' => __( 'Satış temsilcisi', 'sales-team-commissions-for-woocommerce' ),
					'item_id'     => 'eru-stc-agent-' . (int) $agent->id,
					'data'        => $fields,
				);
				foreach ( Eru_STC_Payouts::list(
					array(
						'agent_id' => (int) $agent->id,
						'limit'    => 500,
					)
				) as $p ) {
					$data[] = array(
						'group_id'    => 'eru-stc-payouts',
						'group_label' => __( 'Komisyon ödemeleri', 'sales-team-commissions-for-woocommerce' ),
						'item_id'     => 'eru-stc-payout-' . (int) $p->id,
						'data'        => array(
							array(
								'name'  => __( 'Tutar', 'sales-team-commissions-for-woocommerce' ),
								'value' => Eru_STC_Reports::dec( $p->amount ) . ' ' . $p->currency,
							),
							array(
								'name'  => __( 'Durum', 'sales-team-commissions-for-woocommerce' ),
								'value' => Eru_STC_Labels::payout_status( $p->status ),
							),
							array(
								'name'  => __( 'Talep tarihi', 'sales-team-commissions-for-woocommerce' ),
								'value' => get_date_from_gmt( $p->requested_at ),
							),
							array(
								'name'  => __( 'Referans', 'sales-team-commissions-for-woocommerce' ),
								'value' => $p->reference,
							),
						),
					);
				}
			}
			$link = Eru_STC_Links::get( $user->ID );
			if ( $link ) {
				$la     = Eru_STC_Agents::get( (int) $link->agent_id );
				$data[] = array(
					'group_id'    => 'eru-stc-customer',
					'group_label' => __( 'Satış temsilciniz', 'sales-team-commissions-for-woocommerce' ),
					'item_id'     => 'eru-stc-link-' . (int) $link->id,
					'data'        => array(
						array(
							'name'  => __( 'Temsilci', 'sales-team-commissions-for-woocommerce' ),
							'value' => $la ? Eru_STC_Agents::label( $la ) . ' (' . $la->referral_code . ')' : '#' . (int) $link->agent_id,
						),
						array(
							'name'  => __( 'Bağlantı', 'sales-team-commissions-for-woocommerce' ),
							'value' => Eru_STC_Labels::link_type( $link->link_type ),
						),
						array(
							'name'  => __( 'Tarih', 'sales-team-commissions-for-woocommerce' ),
							'value' => get_date_from_gmt( $link->created_at ),
						),
					),
				);
			}
		}
		return array(
			'data' => $data,
			'done' => true,
		);
	}

	/**
	 * @param string $email Address.
	 * @param int    $page  Page (single page).
	 * @return array{items_removed:bool,items_retained:bool,messages:string[],done:bool}
	 */
	public static function erase( $email, $page = 1 ) {
		global $wpdb;
		$user     = get_user_by( 'email', (string) $email );
		$removed  = false;
		$retained = false;
		$messages = array();
		if ( $user ) {
			$agent = Eru_STC_Agents::get_by_user( $user->ID );
			if ( $agent ) {
				$payouts_table = Eru_STC_Installer::table( 'payouts' );
				$open          = Eru_STC_Payouts::count(
					array(
						'agent_id' => (int) $agent->id,
						'status'   => 'requested',
					)
				);
				if ( $open ) {
					// The open request still has to be paid: keep the details and that request's copy for now.
					$retained   = true;
					$messages[] = __( 'Açık ödeme talebi sonuçlanınca tekrar çalıştırın: ödeme bilgileri ve açık talebin şifreli kopyası ödeme yapılabilsin diye şimdilik saklandı.', 'sales-team-commissions-for-woocommerce' );
				} elseif ( Eru_STC_Payout_Details::wipe( (int) $agent->id ) ) {
					$removed = true;
				}
				if ( $wpdb->query( $wpdb->prepare( "UPDATE {$payouts_table} SET details = NULL WHERE agent_id = %d AND details IS NOT NULL AND status <> 'requested'", $agent->id ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$removed = true;
				}
				$settings = Eru_STC_Agents::settings( $agent );
				if ( isset( $settings['display_name'] ) ) {
					unset( $settings['display_name'] );
					$removed = true;
				}
				$fields = array( 'settings' => $settings );
				if ( 'suspended' !== $agent->status ) {
					$fields['status'] = 'suspended';
				}
				Eru_STC_Agents::update( (int) $agent->id, $fields );
				$retained   = true;
				$messages[] = __( 'Komisyon ve ödeme kayıtları muhasebe kaydı olarak temsilci numarasıyla saklandı; temsilci hesabı askıya alındı.', 'sales-team-commissions-for-woocommerce' );
			}
			if ( Eru_STC_Links::get( $user->ID ) ) {
				Eru_STC_Links::unlink( $user->ID );
				$removed = true;
			}
			if ( delete_user_meta( $user->ID, Eru_STC_Invitations::CUSTOMER_META ) ) {
				$removed = true;
			}
			delete_user_meta( $user->ID, Eru_STC_Invitations::AGENT_META );
			delete_user_meta( $user->ID, Eru_STC_Invitations::ID_META );
			$ct = Eru_STC_Commissions::t();
			if ( $wpdb->query( $wpdb->prepare( "UPDATE {$ct} SET customer_id = 0 WHERE customer_id = %d", $user->ID ) ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$removed    = true;
				$retained   = true;
				$messages[] = __( 'Siparişlerinizden doğan komisyon kayıtları muhasebe kaydı olarak müşteri bilgisi olmadan saklandı.', 'sales-team-commissions-for-woocommerce' );
			}
		}
		return array(
			'items_removed'  => $removed,
			'items_retained' => $retained,
			'messages'       => $messages,
			'done'           => true,
		);
	}
}
