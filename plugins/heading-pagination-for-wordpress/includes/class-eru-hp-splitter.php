<?php
/** Pure splitting logic: raw post content -> pages at heading boundaries. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_HP_Splitter {

	/**
	 * Split $content at headings of $level. Text before the first heading stays with the first section.
	 * Returns a single-element array (the untouched content) when there are fewer than $min_sections
	 * headings or fewer than $min_words words.
	 *
	 * @return string[] One entry per page.
	 */
	public static function split( string $content, int $level = 2, int $min_sections = 3, int $min_words = 600 ): array {
		$positions = self::positions( $content, $level );
		if ( count( $positions ) < max( 2, $min_sections ) ) {
			return array( $content );
		}
		if ( $min_words > 0 && self::word_count( $content ) < $min_words ) {
			return array( $content );
		}
		$pages = array();
		$count = count( $positions );
		foreach ( $positions as $i => $pos ) {
			$start   = 0 === $i ? 0 : $pos['start'];
			$end     = $i + 1 < $count ? $positions[ $i + 1 ]['start'] : strlen( $content );
			$pages[] = substr( $content, $start, $end - $start );
		}
		return $pages;
	}

	/** Heading titles, one per page produced by split(); empty when not split. @return string[] */
	public static function titles( string $content, int $level = 2, int $min_sections = 3, int $min_words = 600 ): array {
		if ( count( self::split( $content, $level, $min_sections, $min_words ) ) < 2 ) {
			return array();
		}
		return array_map( static fn( $p ) => $p['title'], self::positions( $content, $level ) );
	}

	/** @return array<int,array{start:int,title:string}> */
	private static function positions( string $content, int $level ): array {
		$level = max( 2, min( 4, $level ) );
		// A block-editor heading comment directly before the tag belongs to the heading.
		$attr = 2 === $level ? '(?:\s+\{[^}]*\})?' : '\s+\{[^}]*"level"\s*:\s*' . $level . '[^}]*\}';
		$re   = '#(?:<!--\s*wp:heading' . $attr . '\s*-->\s*)?<h' . $level . '\b[^>]*>(.*?)</h' . $level . '>#is';
		if ( ! preg_match_all( $re, $content, $m, PREG_OFFSET_CAPTURE ) ) {
			return array();
		}
		$out = array();
		foreach ( $m[0] as $i => $hit ) {
			$title = trim( wp_strip_all_tags( $m[1][ $i ][0] ) );
			// For level 2 the comment pattern could also match a differently levelled heading; check the tag only.
			$out[] = array(
				'start' => (int) $hit[1],
				'title' => '' !== $title ? $title : sprintf( /* translators: %d: section number */ __( 'Bölüm %d', 'heading-pagination-for-wordpress' ), $i + 1 ),
			);
		}
		return $out;
	}

	private static function word_count( string $content ): int {
		$text = wp_strip_all_tags( preg_replace( '/<!--.*?-->/s', '', $content ) );
		return count( preg_split( '/\s+/u', trim( $text ), -1, PREG_SPLIT_NO_EMPTY ) );
	}
}
