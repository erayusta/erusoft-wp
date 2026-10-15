<?php
/**
 * WP-CLI: wp eru-ppt categories|attributes|brands|addresses|validate|push|poll.
 *
 * @package product-push-for-trendyol
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trendyol product push commands. Every command that talks to Trendyol needs the master switch on and
 * the credentials saved.
 */
class Eru_PPT_CLI {

	/**
	 * Lists Trendyol leaf categories from the cached tree.
	 *
	 * ## OPTIONS
	 *
	 * [--search=<text>]
	 * : Words that must all appear in the category path.
	 *
	 * [--refresh]
	 * : Fetch the tree again.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Options.
	 */
	public function categories( $args, $assoc ) {
		if ( ! empty( $assoc['refresh'] ) ) {
			Eru_PPT_Categories::flush();
		}
		$q = (string) ( $assoc['search'] ?? '' );
		if ( '' === $q ) {
			$tree = Eru_PPT_Categories::tree();
			if ( is_wp_error( $tree ) ) {
				WP_CLI::error( $tree->get_error_message() );
			}
			$leaves = count( array_filter( $tree, fn( $c ) => ! empty( $c['l'] ) ) );
			WP_CLI::success( sprintf( '%d kategori önbellekte (%d en alt seviye). Aramak için --search=<metin>.', count( $tree ), $leaves ) );
			return;
		}
		$rows = Eru_PPT_Categories::search( $q, 100 );
		if ( is_wp_error( $rows ) ) {
			WP_CLI::error( $rows->get_error_message() );
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'path' ) );
	}

	/**
	 * Shows the attributes of a Trendyol category.
	 *
	 * ## OPTIONS
	 *
	 * <category>
	 * : Trendyol category id.
	 *
	 * [--refresh]
	 * : Fetch again.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Options.
	 */
	public function attributes( $args, $assoc ) {
		$a = Eru_PPT_Categories::attributes( absint( $args[0] ?? 0 ), ! empty( $assoc['refresh'] ) );
		if ( is_wp_error( $a ) ) {
			WP_CLI::error( $a->get_error_message() );
		}
		$rows = array();
		foreach ( $a as $d ) {
			$vals   = array_map( fn( $v ) => $v['id'] . '=' . $v['name'], array_slice( $d['values'], 0, 8 ) );
			$rows[] = array(
				'id'        => $d['id'],
				'name'      => $d['name'],
				'required'  => $d['required'] ? 'evet' : '',
				'custom'    => $d['allowCustom'] ? 'evet' : '',
				'varianter' => $d['varianter'] ? 'evet' : '',
				'values'    => implode( ', ', $vals ) . ( count( $d['values'] ) > 8 ? ' …(' . count( $d['values'] ) . ')' : '' ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( 'id', 'name', 'required', 'custom', 'varianter', 'values' ) );
	}

	/**
	 * Searches Trendyol brands by name.
	 *
	 * ## OPTIONS
	 *
	 * --search=<name>
	 * : Brand name.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Options.
	 */
	public function brands( $args, $assoc ) {
		$r = Eru_PPT_Client::brands( (string) ( $assoc['search'] ?? '' ) );
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI\Utils\format_items( 'table', $r, array( 'id', 'name' ) );
	}

	/**
	 * Lists the seller's shipment / returning addresses.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Options.
	 */
	public function addresses( $args, $assoc ) {
		$r = Eru_PPT_Client::addresses();
		if ( is_wp_error( $r ) ) {
			WP_CLI::error( $r->get_error_message() );
		}
		WP_CLI\Utils\format_items( 'table', $r, array( 'id', 'type', 'address', 'default' ) );
	}

	/**
	 * Validates products and prints the items that would be sent (nothing is sent).
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Product ids.
	 *
	 * [--json]
	 * : Print the item JSON.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Options.
	 */
	public function validate( $args, $assoc ) {
		$bad = 0;
		foreach ( $args as $id ) {
			$p = wc_get_product( absint( $id ) );
			if ( ! $p ) {
				WP_CLI::warning( "#{$id}: ürün yok" );
				++$bad;
				continue;
			}
			$b = Eru_PPT_Builder::build( $p );
			if ( $b['errors'] ) {
				++$bad;
				WP_CLI::warning( "#{$id} {$p->get_name()}:" );
				foreach ( $b['errors'] as $e ) {
					WP_CLI::line( '  - ' . $e );
				}
			} else {
				WP_CLI::success( sprintf( '#%d %s: %d öğe hazır (productMainId %s).', $id, $p->get_name(), count( $b['items'] ), $b['main_id'] ) );
			}
			foreach ( $b['warnings'] as $w ) {
				WP_CLI::line( '  ! ' . $w );
			}
			if ( ! empty( $assoc['json'] ) ) {
				WP_CLI::line( Eru_PPT_Builder::encode( array( 'items' => $b['items'] ) ) );
			}
		}
		if ( $bad ) {
			WP_CLI::halt( 1 );
		}
	}

	/**
	 * Pushes products now (create / update by approval state).
	 *
	 * ## OPTIONS
	 *
	 * <id>...
	 * : Product ids.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Options.
	 */
	public function push( $args, $assoc ) {
		$r = Eru_PPT_Pusher::push( array_map( 'absint', $args ) );
		if ( '' !== $r['error'] ) {
			WP_CLI::error( $r['error'] );
		}
		foreach ( $r['errors'] as $pid => $errs ) {
			foreach ( (array) $errs as $e ) {
				WP_CLI::warning( "#{$pid}: {$e}" );
			}
		}
		foreach ( $r['skipped'] as $pid => $msgs ) {
			foreach ( (array) $msgs as $m ) {
				WP_CLI::line( "#{$pid} atlandı: {$m}" );
			}
		}
		WP_CLI::success( sprintf( 'Gönderilen öğe: oluşturma %d, içerik %d, varyant %d, onaysız %d. Batch: %s', $r['sent']['create'], $r['sent']['content'], $r['sent']['variant'], $r['sent']['unapproved'], implode( ', ', $r['batches'] ) ?: '-' ) );
	}

	/**
	 * Polls open batches and refreshes approval states now.
	 *
	 * @param array $args  Args.
	 * @param array $assoc Options.
	 */
	public function poll( $args, $assoc ) {
		if ( ! Eru_PPT_Settings::is_enabled() ) {
			WP_CLI::error( 'Entegrasyon kapalı veya API bilgileri eksik.' );
		}
		$before = count( Eru_PPT_Pusher::batches() );
		Eru_PPT_Poller::run();
		WP_CLI::success( sprintf( 'Açık batch: %d → %d.', $before, count( Eru_PPT_Pusher::batches() ) ) );
	}
}
