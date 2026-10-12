<?php
/**
 * Store-based import: every product of the merchant's own Trendyol store.
 *
 * @package trendyol-reviews-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Walks the merchant's product list from the Trendyol Seller API (seller ID +
 * API key + secret) one page at a time and matches each Trendyol product to
 * a WooCommerce product (Eru_TYR_Matcher). In the background each page is one
 * Action Scheduler action that only lists and matches; every matched product
 * then gets its own `eru_tyr_import_product` action that imports its reviews.
 * Unmatched products go to a list for manual mapping in the admin page.
 */
class Eru_TYR_Store_Import {

	const HOOK_PAGE        = 'eru_tyr_store_page';
	const HOOK_PRODUCT     = 'eru_tyr_import_product';
	const HOOK_DAILY       = 'eru_tyr_daily';
	const GROUP            = 'eru-tyr';
	const RUN_OPTION       = 'eru_tyr_run';
	const UNMATCHED_OPTION = 'eru_tyr_unmatched';
	const MAX_PAGES        = 500;
	const MAX_UNMATCHED    = 2000;
	const STALE_AFTER      = 30 * MINUTE_IN_SECONDS;

	public static function init(): void {
		add_action( self::HOOK_PAGE, array( __CLASS__, 'handle_page' ), 10, 3 );
		add_action( self::HOOK_PRODUCT, array( __CLASS__, 'handle_product' ), 10, 3 );
		add_action( self::HOOK_DAILY, array( __CLASS__, 'daily' ) );
	}

	/**
	 * Import one page of the store synchronously (WP-CLI, tests).
	 *
	 * @param string $seller_id Trendyol seller ID, digits only.
	 * @param int    $page      0-based Seller API page.
	 * @return array{imported:int, skipped:int, unmatched:int, next_page:?int, products:int, errors:int, error?:string}
	 */
	public static function run( string $seller_id, int $page = 0 ): array {
		$out = array(
			'imported'  => 0,
			'skipped'   => 0,
			'unmatched' => 0,
			'next_page' => null,
			'products'  => 0,
			'errors'    => 0,
		);

		$listing = self::list_page( $seller_id, $page );
		if ( isset( $listing['error'] ) ) {
			$out['error'] = $listing['error'];
			return $out;
		}
		$out['unmatched'] = $listing['unmatched'];
		$out['products']  = $listing['products'];
		$out['next_page'] = $listing['next_page'];

		$importer = new Eru_TYR_Importer();
		$delay    = max( 0, (int) apply_filters( 'eru_tyr_request_delay', 250 ) );
		foreach ( $listing['matched'] as $pair ) {
			$result = $importer->import_reviews( $pair[0], $pair[1] );
			if ( is_wp_error( $result ) ) {
				$out['errors']++;
				Eru_TYR_Logger::log( sprintf( '#%d → %d: %s', $pair[1], $pair[0], $result->get_error_message() ), 'error' );
			} else {
				$out['imported'] += $result['imported'];
				$out['skipped']  += $result['skipped'];
			}
			if ( $delay ) {
				usleep( $delay * 1000 ); // Be polite to the public review endpoint.
			}
		}
		return $out;
	}

	/**
	 * List one Seller API page and match its products.
	 *
	 * @return array{matched: array<array{0:int,1:int}>, unmatched:int, products:int, next_page:?int}|array{error:string}
	 */
	public static function list_page( string $seller_id, int $page ): array {
		$seller_id = Eru_TYR_Settings::sanitize_seller_id( $seller_id );
		if ( '' === $seller_id || $page < 0 ) {
			return array( 'error' => __( 'Geçersiz Satıcı ID. Trendyol Satıcı ID yalnızca rakamlardan oluşur.', 'trendyol-reviews-for-woocommerce' ) );
		}

		$client  = new Eru_TYR_Client();
		$listing = $client->get_seller_products_page(
			$seller_id,
			(string) Eru_TYR_Settings::get( 'api_key' ),
			Eru_TYR_Settings::get_secret(),
			$page,
			max( 1, min( 200, (int) apply_filters( 'eru_tyr_store_page_size', 100 ) ) )
		);
		if ( is_wp_error( $listing ) ) {
			return array( 'error' => $listing->get_error_message() );
		}

		$matched = array();
		$added   = array();
		$removed = array();
		foreach ( $listing['products'] as $item ) {
			$content_id = (int) $item['content_id'];
			$product_id = Eru_TYR_Matcher::match(
				array(
					'content_id' => $content_id,
					'barcode'    => $item['barcode'],
					'sku'        => $item['sku'],
				)
			);
			if ( null === $product_id ) {
				$added[ $content_id ] = array(
					'title'   => sanitize_text_field( $item['title'] ),
					'url'     => $item['url'],
					'barcode' => sanitize_text_field( implode( ', ', array_slice( $item['barcode'], 0, 3 ) ) ),
					'sku'     => sanitize_text_field( implode( ', ', array_slice( $item['sku'], 0, 2 ) ) ),
				);
				continue;
			}
			$removed[] = $content_id;
			$matched[] = array( $product_id, $content_id );
		}
		self::merge_unmatched( $added, $removed );

		$next = $page + 1;
		return array(
			'matched'   => $matched,
			'unmatched' => count( $added ),
			'products'  => count( $listing['products'] ),
			'next_page' => ( $next < $listing['total_pages'] && $next < self::MAX_PAGES ) ? $next : null,
		);
	}

	/* ---------------------------------------------------------------------
	 * Background runs (Action Scheduler)
	 * ------------------------------------------------------------------- */

	/**
	 * Start (or restart) a full store import in the background.
	 *
	 * @return true|WP_Error
	 */
	public static function start( string $seller_id ) {
		$seller_id = Eru_TYR_Settings::sanitize_seller_id( $seller_id );
		if ( '' === $seller_id ) {
			return new WP_Error( 'eru_tyr_seller', __( 'Geçersiz Satıcı ID. Trendyol Satıcı ID yalnızca rakamlardan oluşur.', 'trendyol-reviews-for-woocommerce' ) );
		}
		if ( '' === (string) Eru_TYR_Settings::get( 'api_key' ) || '' === Eru_TYR_Settings::get_secret() ) {
			return new WP_Error( 'eru_tyr_credentials', __( 'Mağaza içe aktarımı için API Key ve API Secret gerekir (Trendyol Satıcı Paneli → Hesap Bilgileri → Entegrasyon Bilgileri).', 'trendyol-reviews-for-woocommerce' ) );
		}
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return new WP_Error( 'eru_tyr_as', __( 'Action Scheduler bulunamadı (WooCommerce ile gelir).', 'trendyol-reviews-for-woocommerce' ) );
		}

		as_unschedule_all_actions( self::HOOK_PAGE );
		update_option( self::UNMATCHED_OPTION, array(), false );
		$run_id = wp_generate_password( 12, false );
		update_option(
			self::RUN_OPTION,
			array(
				'run_id'    => $run_id,
				'seller_id' => $seller_id,
				'status'    => 'queued',
				'pages'     => 0,
				'products'  => 0,
				'queued'    => 0,
				'processed' => 0,
				'imported'  => 0,
				'skipped'   => 0,
				'unmatched' => 0,
				'errors'    => 0,
				'started'   => time(),
				'updated'   => time(),
				'finished'  => 0,
				'message'   => '',
			),
			false
		);
		as_enqueue_async_action( self::HOOK_PAGE, array( $seller_id, 0, $run_id ), self::GROUP );
		/* translators: %s: Trendyol seller ID */
		Eru_TYR_Logger::log( sprintf( __( 'Mağaza içe aktarımı kuyruğa alındı (Satıcı ID %s).', 'trendyol-reviews-for-woocommerce' ), $seller_id ) );
		return true;
	}

	/**
	 * Action Scheduler callback: list + match one page, queue one import per
	 * matched product, then queue the next page.
	 */
	public static function handle_page( $seller_id, $page, $run_id = '' ): void {
		$seller_id = (string) $seller_id;
		$page      = (int) $page;
		$state     = self::get_run();
		if ( ( $state['run_id'] ?? '' ) !== (string) $run_id || ( $state['seller_id'] ?? '' ) !== $seller_id || 'error' === ( $state['status'] ?? '' ) ) {
			return; // Replaced by a newer run, or stopped.
		}
		$state['status']  = 'running';
		$state['updated'] = time();
		update_option( self::RUN_OPTION, $state, false );

		$listing = self::list_page( $seller_id, $page );

		$queued = 0;
		if ( ! isset( $listing['error'] ) ) {
			foreach ( $listing['matched'] as $pair ) {
				if ( self::queue_product( $pair[0], $pair[1], (string) $run_id ) ) {
					$queued++;
				}
			}
		}

		$state            = self::get_run( false );
		$state['updated'] = time();
		$state['pages']   = $page + 1;
		if ( isset( $listing['error'] ) ) {
			$state['status']   = 'error';
			$state['message']  = $listing['error'];
			$state['finished'] = time();
			/* translators: %s: error message */
			Eru_TYR_Logger::log( sprintf( __( 'Mağaza içe aktarımı durdu: %s', 'trendyol-reviews-for-woocommerce' ), $listing['error'] ), 'error' );
		} else {
			$state['products']  = (int) ( $state['products'] ?? 0 ) + $listing['products'];
			$state['unmatched'] = (int) ( $state['unmatched'] ?? 0 ) + $listing['unmatched'];
			$state['queued']    = (int) ( $state['queued'] ?? 0 ) + $queued;
			if ( null !== $listing['next_page'] ) {
				as_enqueue_async_action( self::HOOK_PAGE, array( $seller_id, $listing['next_page'], (string) $run_id ), self::GROUP );
			} else {
				$state['status'] = 'importing';
				$state           = self::maybe_finish( $state );
			}
		}
		update_option( self::RUN_OPTION, $state, false );
	}

	/**
	 * Queue a single product import (store runs, inline mapping, CSV rows,
	 * daily sync). Returns false when the same import is already pending.
	 */
	public static function queue_product( int $product_id, int $content_id, string $run_id = '' ): bool {
		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return false;
		}
		$args = array( $product_id, $content_id, $run_id );
		if ( as_get_scheduled_actions( array( 'hook' => self::HOOK_PRODUCT, 'args' => $args, 'status' => 'pending', 'group' => self::GROUP, 'per_page' => 1 ), 'ids' ) ) {
			return false;
		}
		as_enqueue_async_action( self::HOOK_PRODUCT, $args, self::GROUP );
		return true;
	}

	/**
	 * Action Scheduler callback for a single product.
	 */
	public static function handle_product( $product_id, $content_id, $run_id = '' ): void {
		$importer = new Eru_TYR_Importer();
		$result   = $importer->import_reviews( (int) $product_id, (int) $content_id );
		if ( is_wp_error( $result ) ) {
			Eru_TYR_Logger::log( sprintf( '#%d → %d: %s', $content_id, $product_id, $result->get_error_message() ), 'error' );
		} else {
			/* translators: 1: Trendyol content ID, 2: product ID, 3: new reviews, 4: skipped reviews */
			Eru_TYR_Logger::log( sprintf( __( '#%1$d → %2$d: %3$d yeni yorum, %4$d atlandı.', 'trendyol-reviews-for-woocommerce' ), $content_id, $product_id, $result['imported'], $result['skipped'] ), 'success' );
		}

		$state = self::get_run( false );
		if ( '' === (string) $run_id || ( $state['run_id'] ?? '' ) !== (string) $run_id ) {
			return;
		}
		$state['processed'] = (int) ( $state['processed'] ?? 0 ) + 1;
		$state['updated']   = time();
		if ( is_wp_error( $result ) ) {
			$state['errors'] = (int) ( $state['errors'] ?? 0 ) + 1;
		} else {
			$state['imported'] = (int) ( $state['imported'] ?? 0 ) + $result['imported'];
			$state['skipped']  = (int) ( $state['skipped'] ?? 0 ) + $result['skipped'];
		}
		update_option( self::RUN_OPTION, self::maybe_finish( $state ), false );
	}

	/**
	 * Daily sync (opt-in): the whole store when credentials are saved,
	 * otherwise every manually mapped product.
	 */
	public static function daily(): void {
		$settings = Eru_TYR_Settings::all();
		if ( empty( $settings['sync_enabled'] ) ) {
			return;
		}
		if ( Eru_TYR_Settings::has_credentials() ) {
			self::start( $settings['seller_id'] );
			return;
		}
		foreach ( Eru_TYR_Matcher::get_map() as $content_id => $product_id ) {
			self::queue_product( (int) $product_id, (int) $content_id );
		}
	}

	/**
	 * (Re)schedule or clear the daily WP-Cron event to match the settings.
	 */
	public static function sync_schedule(): void {
		$enabled = (bool) Eru_TYR_Settings::get( 'sync_enabled' );
		$next    = wp_next_scheduled( self::HOOK_DAILY );
		if ( $enabled && ! $next ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::HOOK_DAILY );
		} elseif ( ! $enabled && $next ) {
			wp_clear_scheduled_hook( self::HOOK_DAILY );
		}
	}

	/**
	 * Current run state. An unfinished run without progress for 30 minutes is
	 * marked as failed so the admin sees it and can restart.
	 */
	public static function get_run( bool $check = true ): array {
		$state = (array) get_option( self::RUN_OPTION, array() );
		if ( ! $check || ! in_array( $state['status'] ?? '', array( 'queued', 'running', 'importing' ), true ) ) {
			return $state;
		}
		$before = $state;
		if ( 'importing' === $state['status'] ) {
			$state = self::maybe_finish( $state, true );
		}
		if ( 'done' !== $state['status'] && time() - (int) ( $state['updated'] ?? $state['started'] ?? 0 ) > self::STALE_AFTER ) {
			$state['status']   = 'error';
			$state['finished'] = time();
			$state['message']  = __( '30 dakikadır ilerleme yok; arka plan işleri (Action Scheduler / WP-Cron) çalışmıyor olabilir. İçe aktarımı yeniden başlatabilirsiniz.', 'trendyol-reviews-for-woocommerce' );
			Eru_TYR_Logger::log( $state['message'], 'error' );
		}
		if ( $state !== $before ) {
			update_option( self::RUN_OPTION, $state, false );
		}
		return $state;
	}

	/**
	 * @return array<int,array{title:string,url:string,barcode:string,sku:string}>
	 */
	public static function get_unmatched(): array {
		$list = get_option( self::UNMATCHED_OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	public static function forget_unmatched( int $content_id ): void {
		self::merge_unmatched( array(), array( $content_id ) );
	}

	/* ---------------------------------------------------------------------
	 * Internals
	 * ------------------------------------------------------------------- */

	/**
	 * Apply additions/removals to a freshly read list, so a product mapped
	 * meanwhile (inline, URL, CSV) is not written back as unmatched.
	 *
	 * @param array<int,array> $added
	 * @param int[]            $removed
	 */
	protected static function merge_unmatched( array $added, array $removed ): void {
		$list = self::get_unmatched();
		$map  = Eru_TYR_Matcher::get_map();
		foreach ( $added as $content_id => $entry ) {
			if ( ! isset( $map[ $content_id ] ) ) {
				$list[ $content_id ] = $entry;
			}
		}
		foreach ( $removed as $content_id ) {
			unset( $list[ $content_id ] );
		}
		update_option( self::UNMATCHED_OPTION, array_slice( $list, - self::MAX_UNMATCHED, null, true ), false );
	}

	/**
	 * Mark an importing run done when all its product actions ran.
	 */
	protected static function maybe_finish( array $state, bool $check_queue = false ): array {
		if ( 'importing' !== ( $state['status'] ?? '' ) ) {
			return $state;
		}
		$all_done = (int) ( $state['processed'] ?? 0 ) >= (int) ( $state['queued'] ?? 0 );
		if ( ! $all_done && $check_queue && function_exists( 'as_get_scheduled_actions' ) ) {
			// Counters can miss an update when actions run in parallel; trust the queue.
			$left     = as_get_scheduled_actions( array( 'hook' => self::HOOK_PRODUCT, 'status' => array( 'pending', 'in-progress' ), 'group' => self::GROUP, 'per_page' => 1 ), 'ids' );
			$all_done = ! $left;
		}
		if ( $all_done ) {
			$state['status']   = 'done';
			$state['finished'] = time();
			Eru_TYR_Logger::log(
				/* translators: 1: products, 2: new reviews, 3: unmatched products, 4: errors */
				sprintf( __( 'Mağaza içe aktarımı bitti: %1$d ürün, %2$d yeni yorum, %3$d eşleşmeyen, %4$d hata.', 'trendyol-reviews-for-woocommerce' ), $state['products'] ?? 0, $state['imported'] ?? 0, $state['unmatched'] ?? 0, $state['errors'] ?? 0 ),
				! empty( $state['errors'] ) ? 'warning' : 'success'
			);
		}
		return $state;
	}
}
