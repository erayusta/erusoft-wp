<?php
/** Topic queue stored in one non-autoloaded option. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CA_Queue {

	const OPTION       = 'eru_ca_queue';
	const MAX_ITEMS    = 200;
	const MAX_ATTEMPTS = 3;

	/** @return array<int,array<string,mixed>> */
	public static function all(): array {
		$q = get_option( self::OPTION, array() );
		return is_array( $q ) ? array_values( $q ) : array();
	}

	private static function save( array $items ): void {
		update_option( self::OPTION, array_values( $items ), false );
	}

	public static function count( string $status ): int {
		return count( array_filter( self::all(), static fn( $i ) => ( $i['status'] ?? '' ) === $status ) );
	}

	/** One topic per line; "topic | keyword, keyword". Returns the number of items added. */
	public static function add( string $text ): int {
		$items = self::all();
		$next  = $items ? max( array_column( $items, 'id' ) ) + 1 : 1;
		$added = 0;
		foreach ( preg_split( '/\R/u', $text ) as $line ) {
			if ( count( $items ) >= self::MAX_ITEMS ) {
				break;
			}
			$bits  = explode( '|', $line, 2 );
			$topic = sanitize_text_field( trim( $bits[0] ) );
			if ( '' === $topic ) {
				continue;
			}
			$items[] = array(
				'id'       => $next++,
				'topic'    => mb_substr( $topic, 0, 300 ),
				'keywords' => isset( $bits[1] ) ? mb_substr( sanitize_text_field( trim( $bits[1] ) ), 0, 300 ) : '',
				'status'   => 'pending',
				'attempts' => 0,
				'post_id'  => 0,
				'error'    => '',
			);
			++$added;
		}
		self::save( $items );
		return $added;
	}

	/** @return array<int,array<string,mixed>> Up to $limit pending items, oldest first. */
	public static function pending( int $limit ): array {
		$out = array();
		foreach ( self::all() as $item ) {
			if ( 'pending' === $item['status'] && (int) $item['attempts'] < self::MAX_ATTEMPTS ) {
				$out[] = $item;
				if ( count( $out ) >= $limit ) {
					break;
				}
			}
		}
		return $out;
	}

	public static function update( int $id, array $fields ): void {
		$items = self::all();
		foreach ( $items as $k => $item ) {
			if ( (int) $item['id'] === $id ) {
				$items[ $k ] = array_merge( $item, $fields );
			}
		}
		self::save( $items );
	}

	/** Remove finished (and optionally failed) entries. */
	public static function clear_finished( bool $failed_too = false ): void {
		$keep = array_filter(
			self::all(),
			static fn( $i ) => 'pending' === $i['status'] || ( 'failed' === $i['status'] && ! $failed_too )
		);
		self::save( $keep );
	}

	public static function remove( int $id ): void {
		self::save( array_filter( self::all(), static fn( $i ) => (int) $i['id'] !== $id ) );
	}
}
