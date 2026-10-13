<?php
/** Batched regeneration for existing media (AJAX driven, cap manage_options). */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_WEBP_Bulk {

	const BATCH = 5;

	public static function init(): void {
		add_action( 'wp_ajax_eru_webp_bulk', array( __CLASS__, 'ajax' ) );
	}

	public static function total(): int {
		$q = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => array( 'image/jpeg', 'image/png' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);
		return (int) $q->found_posts;
	}

	/** Attachment IDs of one batch, after the given last ID (keyset paging keeps it stable). @return int[] */
	public static function next_ids( int $after, int $size = self::BATCH ): array {
		global $wpdb;
		return array_map(
			'intval',
			(array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png') AND ID > %d ORDER BY ID ASC LIMIT %d",
					$after,
					$size
				)
			)
		);
	}

	/**
	 * Convert every file of the given attachments.
	 *
	 * @param int[] $ids
	 * @return array{created:int,exists:int,skipped:int,error:int}
	 */
	public static function run_batch( array $ids, bool $force ): array {
		$out     = array( 'created' => 0, 'exists' => 0, 'skipped' => 0, 'error' => 0 );
		$basedir = wp_get_upload_dir()['basedir'];
		foreach ( $ids as $id ) {
			$meta = wp_get_attachment_metadata( (int) $id );
			if ( ! is_array( $meta ) ) {
				continue;
			}
			foreach ( Eru_WEBP_Converter::files_for_meta( $meta, $basedir ) as $path ) {
				$r = Eru_WEBP_Converter::convert( $path, $force );
				++$out[ $r ];
			}
		}
		return $out;
	}

	public static function ajax(): void {
		check_ajax_referer( 'eru_webp_bulk', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( null, 403 );
		}
		if ( '' === Eru_WEBP_Converter::engine() ) {
			wp_send_json_error( array( 'message' => __( 'Sunucuda WebP desteği yok.', 'local-webp-generator-for-wordpress' ) ), 400 );
		}
		$after = isset( $_POST['after'] ) ? absint( $_POST['after'] ) : 0;
		$force = ! empty( $_POST['force'] );
		$ids   = self::next_ids( $after );
		$stats = self::run_batch( $ids, $force );
		wp_send_json_success(
			array(
				'done'  => count( $ids ),
				'last'  => $ids ? max( $ids ) : $after,
				'end'   => count( $ids ) < self::BATCH,
				'stats' => $stats,
				'total' => self::total(),
			)
		);
	}
}
