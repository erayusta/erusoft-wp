<?php
/**
 * Transaction paging: page size 100, at most 30 pages, de-duplicated by transaction id.
 *
 * @package bank-transfer-auto-approval-for-garanti-bbva
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure: the page fetcher is injected. The Account Transactions response carries no total or next marker,
 * so the end is an empty page: a short page is not the end (the bank may cap the page size). Any page
 * error, a full page that only repeats known items, or reaching the page cap without an empty page marks
 * the result incomplete; the caller then makes no approval at all.
 */
class Eru_GHA_Paginator {

	const PAGE_SIZE = 100;
	const MAX_PAGES = 30;

	/**
	 * @param callable $fetch_page fn(int $page): array{transactions?:array, error?:string}.
	 * @param int      $page_size  Page size.
	 * @param int      $max_pages  Page cap.
	 * @return array{transactions:array, pages:int, error:string, truncated:bool}
	 */
	public static function collect( callable $fetch_page, $page_size = self::PAGE_SIZE, $max_pages = self::MAX_PAGES ) {
		$page_size = max( 1, (int) $page_size );
		$max_pages = max( 1, (int) $max_pages );
		$all       = array();
		$seen      = array();
		$pages     = 0;
		$error     = '';
		$truncated = false;

		for ( $page = 1; $page <= $max_pages; $page++ ) {
			$result = call_user_func( $fetch_page, $page );
			++$pages;
			if ( ! is_array( $result ) ) {
				$error = 'invalid page result';
				break;
			}
			if ( ! empty( $result['error'] ) ) {
				$error = (string) $result['error'];
				break;
			}
			$items = isset( $result['transactions'] ) && is_array( $result['transactions'] ) ? $result['transactions'] : array();
			$new   = 0;
			foreach ( $items as $t ) {
				$key = self::key( $t );
				if ( isset( $seen[ $key ] ) ) {
					continue;
				}
				$seen[ $key ] = true;
				$all[]        = $t;
				++$new;
			}
			if ( ! $items ) {
				break; // Explicit end.
			}
			if ( 0 === $new ) {
				// The bank ignored the page index. A repeated short page holds everything; a repeated full
				// page hides the rest.
				$truncated = count( $items ) >= $page_size;
				break;
			}
			if ( $page === $max_pages ) {
				$truncated = true;
			}
		}

		return array(
			'transactions' => $all,
			'pages'        => $pages,
			'error'        => $error,
			'truncated'    => $truncated,
		);
	}

	/** @param mixed $t Transaction. @return string De-duplication key. */
	public static function key( $t ) {
		if ( is_array( $t ) && isset( $t['transactionInstanceId'] ) && '' !== (string) $t['transactionInstanceId'] ) {
			return 'id:' . $t['transactionInstanceId'];
		}
		return 'hash:' . md5( (string) wp_json_encode( $t ) );
	}
}
