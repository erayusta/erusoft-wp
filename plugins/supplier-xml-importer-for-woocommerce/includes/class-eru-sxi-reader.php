<?php
/**
 * Safe, streaming XML reader.
 *
 * - XMLReader with LIBXML_NONET; entities are never substituted and documents with a DOCTYPE are refused.
 * - Items are expanded one at a time, so memory stays flat regardless of the feed size.
 * - Field values are read with relative XPath expressions evaluated on the single item.
 *
 * @package supplier-xml-importer-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SXI_Reader {

	/**
	 * Run a callable with PHP warnings from libxml/DOM silenced.
	 */
	private static function quiet( callable $fn ) {
		set_error_handler( fn() => true ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_set_error_handler
		try {
			return $fn();
		} finally {
			restore_error_handler();
		}
	}

	public static function valid_xpath( $expr ) {
		$doc = new DOMDocument();
		$doc->loadXML( '<i/>', LIBXML_NONET );
		$xp  = new DOMXPath( $doc );
		$res = self::quiet( fn() => $xp->evaluate( (string) $expr, $doc->documentElement ) );
		return false !== $res;
	}

	/**
	 * Evaluate an expression against an item and return the string values.
	 *
	 * @return string[]
	 */
	public static function values( DOMXPath $xp, DOMNode $ctx, $expr ) {
		if ( '' === $expr ) {
			return array();
		}
		$res = self::quiet( fn() => $xp->evaluate( $expr, $ctx ) );
		$out = array();
		if ( $res instanceof DOMNodeList ) {
			foreach ( $res as $n ) {
				$v = trim( (string) $n->textContent );
				if ( '' !== $v ) {
					$out[] = $v;
				}
			}
		} elseif ( is_scalar( $res ) && '' !== trim( (string) $res ) && false !== $res ) {
			$out[] = trim( (string) $res );
		}
		return $out;
	}

	/**
	 * Parse the feed and call $on_row( array $row ) for every item.
	 *
	 * @param string   $file     Absolute path.
	 * @param array    $feed     Feed definition (item_path, map, attrs).
	 * @param callable $on_row   Receives the mapped row.
	 * @param int      $limit    Stop after this many items (0 = all).
	 * @param bool     $tolerant Ignore a truncated tail (used for samples).
	 * @return int|WP_Error Items read.
	 */
	public static function parse( $file, array $feed, callable $on_row, $limit = 0, $tolerant = false ) {
		return self::walk(
			$file,
			$feed['item_path'],
			function ( DOMDocument $doc ) use ( $feed, $on_row ) {
				$xp  = new DOMXPath( $doc );
				$ctx = $doc->documentElement;
				$row = array();
				foreach ( array( 'sku', 'name', 'description', 'price', 'sale_price', 'stock', 'parent_sku' ) as $f ) {
					$v         = self::values( $xp, $ctx, $feed['map'][ $f ] ?? '' );
					$row[ $f ] = $v ? $v[0] : '';
				}
				$row['images']     = self::values( $xp, $ctx, $feed['map']['images'] ?? '' );
				$row['categories'] = self::values( $xp, $ctx, $feed['map']['categories'] ?? '' );
				$row['attrs']      = array();
				foreach ( preg_split( '/\R/', (string) ( $feed['attrs'] ?? '' ) ) as $line ) {
					$parts = explode( '=', $line, 2 );
					if ( 2 === count( $parts ) && '' !== trim( $parts[0] ) ) {
						$vals = self::values( $xp, $ctx, trim( $parts[1] ) );
						if ( $vals ) {
							$row['attrs'][ trim( $parts[0] ) ] = $vals;
						}
					}
				}
				$on_row( $row );
			},
			$limit,
			$tolerant
		);
	}

	/**
	 * First item as a flat list of leaf paths and sample values (for the mapping helper).
	 *
	 * @return array|WP_Error list of array( path, value ).
	 */
	public static function sample( $file, $item_path ) {
		$found = array();
		$res   = self::walk(
			$file,
			$item_path,
			function ( DOMDocument $doc ) use ( &$found ) {
				self::leaves( $doc->documentElement, '', $found );
			},
			1,
			true
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = array();
		foreach ( $found as $path => $value ) {
			$out[] = array(
				'path'  => $path,
				'value' => mb_substr( $value, 0, 80 ),
			);
		}
		return $out;
	}

	private static function leaves( DOMElement $el, $prefix, array &$found ) {
		foreach ( $el->attributes as $a ) {
			$found[ $prefix . '@' . $a->name ] = $found[ $prefix . '@' . $a->name ] ?? trim( $a->value );
		}
		$has_child = false;
		foreach ( $el->childNodes as $c ) {
			if ( $c instanceof DOMElement ) {
				$has_child = true;
				self::leaves( $c, $prefix . $c->localName . '/', $found );
			}
		}
		if ( ! $has_child && '' !== $prefix ) {
			$key           = rtrim( $prefix, '/' );
			$found[ $key ] = $found[ $key ] ?? trim( $el->textContent );
		}
	}

	private static function walk( $file, $item_path, callable $on_item, $limit, $tolerant ) {
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return new WP_Error( 'eru_sxi_nofile', __( 'XML dosyası okunamadı.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		if ( filesize( $file ) > Eru_SXI_Feeds::max_bytes() ) {
			return new WP_Error( 'eru_sxi_big', __( 'XML dosyası izin verilen boyuttan büyük.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		$item_path = trim( (string) $item_path, '/' );
		if ( '' === $item_path ) {
			return new WP_Error( 'eru_sxi_item', __( 'Ürün öğesi (item) yolu boş.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		$absolute = false !== strpos( $item_path, '/' );
		$prev     = libxml_use_internal_errors( true );
		libxml_clear_errors();
		$reader = new XMLReader();
		if ( ! $reader->open( $file, null, LIBXML_NONET | LIBXML_COMPACT ) ) {
			libxml_use_internal_errors( $prev );
			return new WP_Error( 'eru_sxi_open', __( 'XML dosyası açılamadı.', 'supplier-xml-importer-for-woocommerce' ) );
		}
		$stack = array();
		$count = 0;
		$err   = null;
		$ok = self::quiet( fn() => $reader->read() );
		while ( $ok ) {
			if ( XMLReader::DOC_TYPE === $reader->nodeType ) {
				$err = new WP_Error( 'eru_sxi_dtd', __( 'DOCTYPE içeren XML dosyaları güvenlik nedeniyle reddedilir.', 'supplier-xml-importer-for-woocommerce' ) );
				break;
			}
			if ( XMLReader::ELEMENT === $reader->nodeType ) {
				$stack   = array_slice( $stack, 0, $reader->depth );
				$stack[] = $reader->localName;
				$hit     = $absolute ? implode( '/', $stack ) === $item_path : $reader->localName === $item_path;
				if ( $hit ) {
					$node = self::quiet( fn() => $reader->expand() );
					if ( $node ) {
						$doc = new DOMDocument();
						$doc->appendChild( $doc->importNode( $node, true ) );
						$on_item( $doc );
						++$count;
					}
					if ( $limit && $count >= $limit ) {
						break;
					}
					$ok = self::quiet( fn() => $reader->next() ); // Skip the item subtree; lands on the next sibling.
					continue;
				}
			}
			$ok = self::quiet( fn() => $reader->read() );
		}
		$reader->close();
		if ( ! $err && ! ( $limit && $count >= $limit ) ) {
			foreach ( libxml_get_errors() as $e ) {
				if ( LIBXML_ERR_FATAL === $e->level && ! $tolerant ) {
					/* translators: %d: line number */
					$err = new WP_Error( 'eru_sxi_xml', sprintf( __( 'XML ayrıştırma hatası (satır %d).', 'supplier-xml-importer-for-woocommerce' ), (int) $e->line ) );
					break;
				}
			}
		}
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		return $err ? $err : $count;
	}
}
