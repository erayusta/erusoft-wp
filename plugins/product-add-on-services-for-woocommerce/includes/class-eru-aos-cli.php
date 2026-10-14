<?php
/**
 * wp eru-aos export|import
 *
 * @package product-add-on-services-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export and import add-on service groups as JSON.
 */
class Eru_AOS_CLI {

	/**
	 * Prints every service group (published and draft) as JSON.
	 *
	 * ## OPTIONS
	 *
	 * [--file=<path>]
	 * : Write to this file instead of standard output.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eru-aos export --file=groups.json
	 */
	public function export( $args, $assoc ) {
		$posts = get_posts(
			array(
				'post_type'   => Eru_AOS_Groups::CPT,
				'post_status' => array( 'publish', 'draft' ),
				'numberposts' => -1,
				'orderby'     => array(
					'menu_order' => 'ASC',
					'ID'         => 'ASC',
				),
			)
		);
		$out   = array();
		foreach ( $posts as $p ) {
			$g = Eru_AOS_Groups::get( $p );
			unset( $g['id'] );
			$out[] = $g;
		}
		$json = wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( ! empty( $assoc['file'] ) ) {
			if ( false === file_put_contents( $assoc['file'], $json . "\n" ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- CLI.
				WP_CLI::error( 'Cannot write ' . $assoc['file'] );
			}
			WP_CLI::success( count( $out ) . ' group(s) written to ' . $assoc['file'] );
			return;
		}
		WP_CLI::line( $json );
	}

	/**
	 * Creates groups from a JSON file made by `wp eru-aos export`.
	 *
	 * Category, tag and product IDs in the scope are site-specific; check them after importing on another site.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : JSON file.
	 *
	 * [--replace]
	 * : Delete every existing group first.
	 *
	 * ## EXAMPLES
	 *
	 *     wp eru-aos import groups.json
	 */
	public function import( $args, $assoc ) {
		if ( ! is_readable( $args[0] ) ) {
			WP_CLI::error( 'Cannot read ' . $args[0] );
		}
		$data = json_decode( (string) file_get_contents( $args[0] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file.
		if ( ! is_array( $data ) ) {
			WP_CLI::error( 'Invalid JSON.' );
		}
		if ( ! empty( $assoc['replace'] ) ) {
			foreach ( get_posts( array( 'post_type' => Eru_AOS_Groups::CPT, 'post_status' => 'any', 'numberposts' => -1, 'fields' => 'ids' ) ) as $id ) {
				wp_delete_post( $id, true );
			}
		}
		$n = 0;
		foreach ( $data as $g ) {
			if ( ! is_array( $g ) || empty( $g['title'] ) ) {
				WP_CLI::warning( 'Skipped an entry without a title.' );
				continue;
			}
			$id = Eru_AOS_Groups::create( $g );
			if ( is_wp_error( $id ) ) {
				WP_CLI::warning( $id->get_error_message() );
				continue;
			}
			++$n;
		}
		WP_CLI::success( $n . ' group(s) imported.' );
	}
}
