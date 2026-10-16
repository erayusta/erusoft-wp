<?php
/**
 * WP-CLI: wp eru-vnv sync|instruction|cancel.
 *
 * @package vakifbank-vinov-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * VakıfBank Vinov talimat komutları.
 */
class Eru_VNV_CLI {

	/**
	 * Runs one status sync now (same run lock as the scheduled job).
	 *
	 * ## OPTIONS
	 *
	 * [--order=<id>]
	 * : Only this order's open instruction.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function sync( $args, $assoc ) {
		$s = Eru_VNV_Sync::run( isset( $assoc['order'] ) ? array( 'order' => absint( $assoc['order'] ) ) : array() );
		WP_CLI::log( sprintf( 'status=%s checked=%d paid=%d cancelled=%d gave_up=%d adopted=%d errors=%d', $s['status'], count( $s['touched'] ), $s['paid'], $s['cancelled'], $s['gave_up'], $s['adopted'], $s['errors'] ) );
		if ( 'locked' === $s['status'] ) {
			WP_CLI::error( 'Başka bir senkron çalışıyor.' );
		}
		WP_CLI::success( 'OK' );
	}

	/**
	 * Shows an instruction from the bank (status and installment detail; no names or identity numbers).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Instruction id.
	 *
	 * [--format=<format>]
	 * : table, csv or json.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function instruction( $args, $assoc ) {
		$id  = absint( $args[0] ?? 0 );
		$row = $id ? Eru_VNV_Instructions::by_instruction( $id ) : null;
		$env = $row && in_array( $row->env, Eru_VNV_Settings::ENVS, true ) ? $row->env : Eru_VNV_Settings::env();
		if ( ! $id || ! Eru_VNV_Settings::ready( $env ) ) {
			WP_CLI::error( $id ? 'Vinov ayarları eksik.' : 'Talimat no gerekli.' );
		}
		$c    = new Eru_VNV_API_Client( $env );
		$list = $c->list_instructions( array( 'id' => $id ) );
		if ( ! $list['success'] ) {
			WP_CLI::error( $list['error'] );
		}
		$item = null;
		foreach ( (array) ( $list['data']['WorkPlaceInstruction'] ?? array() ) as $it ) {
			if ( is_array( $it ) && (int) ( $it['Id'] ?? 0 ) === $id ) {
				$item = Eru_VNV_Admin::clean_item( $it, array( 'Id', 'InvoiceNumber', 'InstructionAmount', 'InstructionStatusCode', 'NumberofInstallments' ) );
			}
		}
		if ( ! $item ) {
			WP_CLI::warning( 'Talimat bankanın listesinde yok.' );
		} else {
			WP_CLI::log( sprintf( 'talimat=%s fatura=%s tutar=%s durum=%s (%s)%s', $item['Id'] ?? $id, $item['InvoiceNumber'] ?? '', $item['InstructionAmount'] ?? '', $item['InstructionStatusCode'] ?? '', Eru_VNV_Instructions::label( (int) ( $item['InstructionStatusCode'] ?? 0 ) ), $row ? ' sipariş=' . $row->order_id : ' (bu mağazada kayıtlı değil)' ) );
		}
		$d    = $c->instruction_detail( $id );
		$rows = array();
		foreach ( $d['success'] ? (array) ( $d['data']['DebtInformations'] ?? array() ) : array() as $debt ) {
			if ( is_array( $debt ) ) {
				$rows[] = array(
					'amount'   => wc_format_decimal( (float) ( $debt['DebtAmount'] ?? 0 ), 2 ),
					'maturity' => substr( (string) ( $debt['MaturityDate'] ?? '' ), 0, 10 ),
					'status'   => (string) ( $debt['DebtStatusCode'] ?? '' ),
				);
			}
		}
		if ( $rows ) {
			WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'amount', 'maturity', 'status' ) );
		}
	}

	/**
	 * Cancels a waiting instruction of this shop at the bank (by instruction id) and cancels its order.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Instruction id.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Named.
	 */
	public function cancel( $args, $assoc ) {
		$id  = absint( $args[0] ?? 0 );
		$row = $id ? Eru_VNV_Instructions::by_instruction( $id ) : null;
		$o   = $row ? wc_get_order( (int) $row->order_id ) : null;
		if ( ! $o ) {
			WP_CLI::error( 'Bu talimat bu mağazada kayıtlı değil.' );
		}
		WP_CLI::confirm( sprintf( 'Talimat %d bankada iptal edilsin ve sipariş #%s iptal durumuna alınsın mı?', $id, $o->get_order_number() ), $assoc );
		$r = Eru_VNV_Sync::cancel( $o, 'WP-CLI' );
		if ( ! $r['ok'] ) {
			WP_CLI::error( $r['code'] . ': ' . $r['message'] );
		}
		WP_CLI::success( $r['message'] );
	}
}
