<?php
/**
 * SOAP 1.1 envelope builder (every value escaped) and a safe response parser (DOMDocument, no network,
 * no DTD, no entity expansion).
 *
 * @package yurtici-kargo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Element names come from code only and are validated; values are never interpolated unescaped.
 */
class Eru_YK_Xml {

	const NS_SOAP = 'http://schemas.xmlsoap.org/soap/envelope/';
	const NS_SHIP = 'http://yurticikargo.com.tr/ShippingOrderDispatcherServices';

	/**
	 * Escapes a value for element content or attributes and drops characters XML 1.0 cannot carry.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function esc( $value ) {
		if ( is_bool( $value ) ) {
			$value = $value ? 'true' : 'false';
		}
		$value = (string) $value;
		if ( function_exists( 'mb_check_encoding' ) && ! mb_check_encoding( $value, 'UTF-8' ) ) {
			$value = mb_convert_encoding( $value, 'UTF-8', 'UTF-8' );
		}
		$value = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $value );
		return htmlspecialchars( $value, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * One element. Arrays become children; a list (0..n keys) repeats the element.
	 *
	 * @param string $name  Element name (code-defined).
	 * @param mixed  $value Scalar, assoc array (children) or list (repeated element).
	 * @return string
	 * @throws InvalidArgumentException On an invalid element name.
	 */
	public static function element( $name, $value ) {
		if ( ! preg_match( '/^[A-Za-z_][A-Za-z0-9_]*(:[A-Za-z_][A-Za-z0-9_]*)?$/', (string) $name ) ) {
			throw new InvalidArgumentException( 'Invalid XML element name' );
		}
		if ( is_array( $value ) ) {
			if ( array_is_list( $value ) ) {
				$out = '';
				foreach ( $value as $v ) {
					$out .= self::element( $name, $v );
				}
				return $out;
			}
			$inner = '';
			foreach ( $value as $k => $v ) {
				$inner .= self::element( (string) $k, $v );
			}
			return '<' . $name . '>' . $inner . '</' . $name . '>';
		}
		if ( null === $value ) {
			return '<' . $name . '/>';
		}
		return '<' . $name . '>' . self::esc( $value ) . '</' . $name . '>';
	}

	/**
	 * Full SOAP 1.1 envelope for one operation.
	 *
	 * @param string $operation Operation (createShipment, queryShipment, cancelShipment).
	 * @param array  $fields    Ordered fields.
	 * @return string
	 */
	public static function envelope( $operation, array $fields ) {
		$body = '';
		foreach ( $fields as $k => $v ) {
			$body .= self::element( (string) $k, $v );
		}
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<soapenv:Envelope xmlns:soapenv="' . self::NS_SOAP . '" xmlns:ship="' . self::NS_SHIP . '">'
			. '<soapenv:Header/><soapenv:Body>'
			. '<ship:' . self::name( $operation ) . '>' . $body . '</ship:' . self::name( $operation ) . '>'
			. '</soapenv:Body></soapenv:Envelope>';
	}

	/** @param string $name Name. @return string Validated name. @throws InvalidArgumentException When invalid. */
	private static function name( $name ) {
		if ( ! preg_match( '/^[A-Za-z][A-Za-z0-9_]*$/', (string) $name ) ) {
			throw new InvalidArgumentException( 'Invalid XML element name' );
		}
		return (string) $name;
	}

	/**
	 * Parses a SOAP response.
	 *
	 * @param string $xml Body.
	 * @return array{fault:?array,body:array}|WP_Error body = first element under soap:Body as nested array.
	 */
	public static function parse( $xml ) {
		$xml = trim( (string) $xml );
		if ( '' === $xml ) {
			return new WP_Error( 'eru_yk_parse', __( 'Boş yanıt.', 'yurtici-kargo-integration-for-woocommerce' ) );
		}
		if ( false !== stripos( $xml, '<!DOCTYPE' ) || false !== stripos( $xml, '<!ENTITY' ) ) {
			return new WP_Error( 'eru_yk_parse', __( 'Yanıtta DTD/varlık tanımı var; işlenmedi.', 'yurtici-kargo-integration-for-woocommerce' ) );
		}
		$prev = libxml_use_internal_errors( true );
		$dom  = new DOMDocument();
		$ok   = $dom->loadXML( $xml, LIBXML_NONET | LIBXML_NOCDATA );
		libxml_clear_errors();
		libxml_use_internal_errors( $prev );
		if ( ! $ok || ! $dom->documentElement ) {
			return new WP_Error( 'eru_yk_parse', __( 'Yanıt XML olarak okunamadı.', 'yurtici-kargo-integration-for-woocommerce' ) );
		}
		$xp    = new DOMXPath( $dom );
		$bodyn = $xp->query( '//*[local-name()="Body"]' );
		$body  = $bodyn && $bodyn->length ? $bodyn->item( 0 ) : $dom->documentElement;
		$first = null;
		foreach ( $body->childNodes as $c ) {
			if ( XML_ELEMENT_NODE === $c->nodeType ) {
				$first = $c;
				break;
			}
		}
		if ( ! $first ) {
			return new WP_Error( 'eru_yk_parse', __( 'Yanıt gövdesi boş.', 'yurtici-kargo-integration-for-woocommerce' ) );
		}
		if ( 'Fault' === $first->localName ) {
			$f   = self::to_array( $first );
			$f   = is_array( $f ) ? $f : array();
			$exc = is_array( $f['detail'] ?? null ) && is_array( $f['detail']['YurticikargoWSException'] ?? null ) ? $f['detail']['YurticikargoWSException'] : array();
			return array(
				'fault' => array(
					'code'       => is_string( $f['faultcode'] ?? null ) ? $f['faultcode'] : '',
					'string'     => is_string( $f['faultstring'] ?? null ) ? $f['faultstring'] : '',
					'error_code' => is_string( $exc['errorCode'] ?? null ) ? trim( $exc['errorCode'] ) : '',
					'error_text' => is_string( $exc['errText'] ?? null ) ? trim( $exc['errText'] ) : '',
				),
				'body'  => array(),
			);
		}
		$arr = self::to_array( $first );
		return array(
			'fault' => null,
			'body'  => is_array( $arr ) ? $arr : array(),
		);
	}

	/**
	 * Element → nested array by local name; text-only element → string; repeated names → list.
	 *
	 * @param DOMNode $node Node.
	 * @return array|string
	 */
	public static function to_array( DOMNode $node ) {
		$children = array();
		foreach ( $node->childNodes as $c ) {
			if ( XML_ELEMENT_NODE === $c->nodeType ) {
				$children[] = $c;
			}
		}
		if ( ! $children ) {
			return trim( (string) $node->textContent );
		}
		$out      = array();
		$repeated = array();
		foreach ( $children as $c ) {
			$k = (string) $c->localName;
			$v = self::to_array( $c );
			if ( ! array_key_exists( $k, $out ) ) {
				$out[ $k ] = $v;
				continue;
			}
			if ( ! isset( $repeated[ $k ] ) ) {
				$out[ $k ]      = array( $out[ $k ] );
				$repeated[ $k ] = true;
			}
			$out[ $k ][] = $v;
		}
		return $out;
	}

	/**
	 * First item of a value that may be a single record or a list of records.
	 *
	 * @param mixed $v Value.
	 * @return array|null
	 */
	public static function first( $v ) {
		if ( ! is_array( $v ) ) {
			return null;
		}
		if ( array_is_list( $v ) ) {
			return isset( $v[0] ) && is_array( $v[0] ) ? $v[0] : null;
		}
		return $v;
	}
}
