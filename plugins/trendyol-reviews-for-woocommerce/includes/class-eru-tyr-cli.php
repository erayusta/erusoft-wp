<?php
/**
 * WP-CLI commands.
 *
 * @package trendyol-reviews-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import Trendyol store reviews from the command line.
 */
class Eru_TYR_CLI {

	/**
	 * Import a store's reviews synchronously, page by page. Uses the API key
	 * and secret saved on the admin page.
	 *
	 * ## OPTIONS
	 *
	 * <seller_id>
	 * : Trendyol seller ID (Satıcı ID).
	 *
	 * [--page=<page>]
	 * : Only this 0-based listing page.
	 *
	 * ## EXAMPLES
	 *
	 *     wp trendyol-reviews import 000000
	 *     wp trendyol-reviews import 000000 --page=0
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function import( $args, $assoc_args ) {
		$seller = (string) $args[0];
		$page   = isset( $assoc_args['page'] ) ? (int) $assoc_args['page'] : 0;
		$single = isset( $assoc_args['page'] );
		$totals = array( 'imported' => 0, 'skipped' => 0, 'unmatched' => 0, 'errors' => 0 );

		do {
			$result = Eru_TYR_Store_Import::run( $seller, $page );
			if ( ! empty( $result['error'] ) ) {
				WP_CLI::error( $result['error'] );
			}
			foreach ( $totals as $key => $value ) {
				$totals[ $key ] = $value + (int) ( $result[ $key ] ?? 0 );
			}
			/* translators: 1: page number, 2: new reviews, 3: skipped, 4: unmatched products */
			WP_CLI::log( sprintf( __( 'Sayfa %1$d: %2$d yeni, %3$d atlandı, %4$d eşleşmeyen.', 'trendyol-reviews-for-woocommerce' ), $page, $result['imported'], $result['skipped'], $result['unmatched'] ) );
			$page = $result['next_page'];
		} while ( ! $single && null !== $page );

		/* translators: 1: new reviews, 2: skipped, 3: unmatched products, 4: errors */
		WP_CLI::success( sprintf( __( '%1$d yeni yorum, %2$d atlandı, %3$d eşleşmeyen ürün, %4$d hata.', 'trendyol-reviews-for-woocommerce' ), $totals['imported'], $totals['skipped'], $totals['unmatched'], $totals['errors'] ) );
	}

	/**
	 * Show the last background run and counts.
	 */
	public function status() {
		$run = Eru_TYR_Store_Import::get_run();
		WP_CLI::log( __( 'Son çalışma: ', 'trendyol-reviews-for-woocommerce' ) . ( $run ? wp_json_encode( $run ) : '-' ) );
		WP_CLI::log( __( 'Eşleşmeyen ürün: ', 'trendyol-reviews-for-woocommerce' ) . count( Eru_TYR_Store_Import::get_unmatched() ) );
		WP_CLI::log( __( 'Eşleştirme tablosu: ', 'trendyol-reviews-for-woocommerce' ) . count( Eru_TYR_Matcher::get_map() ) );
	}

	/**
	 * Delete every imported review.
	 *
	 * [--yes]
	 * : Skip confirmation.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Flags.
	 */
	public function reset( $args, $assoc_args ) {
		WP_CLI::confirm( __( 'İçe aktarılan tüm Trendyol yorumları silinsin mi?', 'trendyol-reviews-for-woocommerce' ), $assoc_args );
		/* translators: %d: deleted review count */
		WP_CLI::success( sprintf( __( '%d yorum silindi.', 'trendyol-reviews-for-woocommerce' ), Eru_TYR_Importer::delete_all_imported() ) );
	}
}
