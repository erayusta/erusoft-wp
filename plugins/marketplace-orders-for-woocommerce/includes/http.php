<?php
/**
 * Shared HTTP helper for the marketplace adapters: one wp_remote_request per call, never sleeps, never follows
 * redirects, and writes a redacted request log.
 *
 * @package marketplace-orders-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every adapter request goes through request(). Credentials travel only in headers: a URL that contains one of the
 * request's secrets is refused before anything is sent. The result says whether the failure is worth retrying
 * (transport error, 408, 429, 5xx) and whether the remote side may have processed the request although no clear
 * answer came back (transport error or 5xx: "ambiguous"), so writers can look the object up before sending again.
 *
 * Request log (table eru_mpo_log): failures always, writes (POST/PUT/PATCH/DELETE) always, reads only when the
 * eru_mpo_http_log_reads filter returns true. A row holds the method, the path with its query (never the host or a
 * header), the status, the duration and, for failures and writes, the bodies: JSON keys that look like credentials,
 * identity or tax numbers are replaced with "[gizli]", the request's secrets are removed wherever they appear, the
 * logger's own redaction runs on top (Basic/Bearer values, 11-digit runs, IBANs, e-mail addresses, phone numbers),
 * and each body is capped at 8 KB.
 *
 * Result: array{ ok: bool, code: int, data: array|null, raw: string, error: string, retryable: bool,
 * ambiguous: bool, retry_after: int, ms: int }.
 */
class Eru_MPO_Http {

	const TIMEOUT   = 30;
	const LOG_BYTES = 8192;
	/** JSON keys whose values never reach the log. */
	const SECRET_KEYS = '/(password|secret|token|api_?key|authorization|identity|tckn|tc_?kimlik|tax_?no|tax_?number|taxnumber|vkn|iban)/i';

	/** @var array<string,array> Last failed result per marketplace (read by the sync scheduler). */
	private static $last = array();

	/**
	 * @param string            $mp      Marketplace key (log column, last-failure slot).
	 * @param string            $method  GET|POST|PUT|PATCH|DELETE.
	 * @param string            $url     Full URL (credentials never in it).
	 * @param array             $headers Headers (Authorization, User-Agent …).
	 * @param array|string|null $body    JSON body (array) or raw string.
	 * @param array             $opts    secrets (string[]: redacted everywhere, refused in the URL), ref (log column),
	 *                                   timeout (seconds).
	 * @return array See class doc.
	 */
	public static function request( $mp, $method, $url, array $headers = array(), $body = null, array $opts = array() ) {
		$method  = strtoupper( (string) $method );
		$secrets = array_values( array_filter( array_map( 'strval', (array) ( $opts['secrets'] ?? array() ) ), fn( $s ) => strlen( $s ) >= 4 ) );
		$ref     = (string) ( $opts['ref'] ?? '' );
		$path    = self::path( $url );

		foreach ( $secrets as $s ) {
			if ( false !== strpos( (string) $url, $s ) || false !== strpos( (string) $url, rawurlencode( $s ) ) ) {
				$res = self::result( false, 0, null, '', __( 'İstek gönderilmedi: kimlik bilgisi adreste yer alamaz.', 'marketplace-orders-for-woocommerce' ), false, false );
				Eru_MPO_Logger::error( sprintf( '%s %s: %s', $method, self::redact_text( $path, $secrets ), $res['error'] ), $mp, $ref );
				return self::fail( $mp, $res );
			}
		}

		$payload = null;
		if ( is_array( $body ) ) {
			$payload                 = (string) wp_json_encode( $body );
			$headers['Content-Type'] = $headers['Content-Type'] ?? 'application/json';
		} elseif ( is_string( $body ) ) {
			$payload = $body;
		}
		$headers['Accept'] = $headers['Accept'] ?? 'application/json';

		$args = array(
			'method'      => $method,
			'timeout'     => (int) ( $opts['timeout'] ?? self::TIMEOUT ),
			'redirection' => 0,
			'headers'     => $headers,
		);
		if ( null !== $payload ) {
			$args['body'] = $payload;
		}

		$t0  = microtime( true );
		$raw = wp_remote_request( $url, $args );
		$ms  = (int) round( ( microtime( true ) - $t0 ) * 1000 );

		if ( is_wp_error( $raw ) ) {
			$res       = self::result( false, 0, null, '', self::redact_text( $raw->get_error_message(), $secrets ), true, true );
			$res['ms'] = $ms;
			/* translators: 1: method, 2: path, 3: duration in ms, 4: error */
			self::log( 'error', sprintf( __( '%1$s %2$s → bağlantı hatası (%3$d ms): %4$s', 'marketplace-orders-for-woocommerce' ), $method, $path, $ms, $res['error'] ), $mp, $ref, $payload, '', $secrets );
			return self::fail( $mp, $res );
		}

		$code  = (int) wp_remote_retrieve_response_code( $raw );
		$text  = (string) wp_remote_retrieve_body( $raw );
		$data  = '' !== trim( $text ) ? json_decode( $text, true ) : null;
		$data  = is_array( $data ) ? $data : null;
		$ok    = $code >= 200 && $code < 300;
		$retry = 0 === $code || 408 === $code || 429 === $code || $code >= 500;
		$error = '';
		if ( ! $ok ) {
			$msg = '';
			if ( is_array( $data ) ) {
				$msg = (string) ( $data['errors'][0]['message'] ?? $data['message'] ?? $data['error'] ?? $data['detail'] ?? '' );
			}
			if ( '' === $msg ) {
				$msg = wp_strip_all_tags( mb_substr( $text, 0, 200 ) );
			}
			/* translators: 1: HTTP status, 2: message */
			$error = self::redact_text( sprintf( __( 'HTTP %1$d: %2$s', 'marketplace-orders-for-woocommerce' ), $code, $msg ), $secrets );
		}
		$res                = self::result( $ok, $code, $data, $text, $error, ! $ok && $retry, ! $ok && ( $code >= 500 || 0 === $code ) );
		$res['retry_after'] = self::retry_after( $raw );
		$res['ms']          = $ms;

		$write = in_array( $method, array( 'POST', 'PUT', 'PATCH', 'DELETE' ), true );
		if ( ! $ok ) {
			/* translators: 1: method, 2: path, 3: HTTP status, 4: duration in ms */
			self::log( 'error', sprintf( __( '%1$s %2$s → %3$d (%4$d ms)', 'marketplace-orders-for-woocommerce' ), $method, $path, $code, $ms ), $mp, $ref, $payload, $text, $secrets );
			return self::fail( $mp, $res );
		}
		if ( $write ) {
			/* translators: 1: method, 2: path, 3: HTTP status, 4: duration in ms */
			self::log( 'info', sprintf( __( '%1$s %2$s → %3$d (%4$d ms)', 'marketplace-orders-for-woocommerce' ), $method, $path, $code, $ms ), $mp, $ref, $payload, $text, $secrets );
		} elseif ( apply_filters( 'eru_mpo_http_log_reads', false, $mp ) ) {
			/* translators: 1: method, 2: path, 3: HTTP status, 4: duration in ms */
			Eru_MPO_Logger::info( sprintf( __( '%1$s %2$s → %3$d (%4$d ms)', 'marketplace-orders-for-woocommerce' ), $method, self::redact_text( $path, $secrets ), $code, $ms ), $mp, $ref );
		}
		return $res;
	}

	/**
	 * A failed result as a WP_Error (data: status, retryable, ambiguous, retry_after).
	 *
	 * @param array  $res  Result.
	 * @param string $code Error code.
	 * @return WP_Error
	 */
	public static function to_error( array $res, $code = 'eru_mpo_http' ) {
		return new WP_Error(
			$code,
			'' !== $res['error'] ? $res['error'] : __( 'Pazaryeri isteği başarısız.', 'marketplace-orders-for-woocommerce' ),
			array(
				'status'      => (int) $res['code'],
				'retryable'   => (bool) $res['retryable'],
				'ambiguous'   => (bool) $res['ambiguous'],
				'retry_after' => (int) $res['retry_after'],
			)
		);
	}

	/** @param WP_Error $e Error. @return bool Worth retrying later (no data = not retryable). */
	public static function is_retryable( $e ) {
		$d = is_wp_error( $e ) ? $e->get_error_data() : null;
		return is_array( $d ) && ! empty( $d['retryable'] );
	}

	/** @param WP_Error $e Error. @return bool The remote side may have processed the request. */
	public static function is_ambiguous( $e ) {
		$d = is_wp_error( $e ) ? $e->get_error_data() : null;
		return is_array( $d ) && ! empty( $d['ambiguous'] );
	}

	/** @param WP_Error $e Error. @return int HTTP status (0 = none). */
	public static function status( $e ) {
		$d = is_wp_error( $e ) ? $e->get_error_data() : null;
		return is_array( $d ) ? (int) ( $d['status'] ?? 0 ) : 0;
	}

	/** @param string $mp Marketplace. @return array|null Last failed result of this request cycle. */
	public static function last_failure( $mp ) {
		return self::$last[ (string) $mp ] ?? null;
	}

	/** Forgets the last failure of a marketplace (the scheduler calls it before a pull). @param string $mp Key. */
	public static function reset( $mp ) {
		unset( self::$last[ (string) $mp ] );
	}

	/** @param string $user User. @param string $pass Password. @return string Basic authorization header value. */
	public static function basic( $user, $pass ) {
		return 'Basic ' . base64_encode( $user . ':' . $pass ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Redacts a request or response body for the log and caps it at 8 KB.
	 *
	 * @param string   $text    Body.
	 * @param string[] $secrets Values removed wherever they appear.
	 * @return string
	 */
	public static function redact_body( $text, array $secrets = array() ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return '';
		}
		$json = json_decode( $text );
		if ( is_array( $json ) || is_object( $json ) ) {
			$text = (string) wp_json_encode( self::redact_json( $json ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		} else {
			$text = (string) preg_replace( '/("?\b[a-z_]*(?:tax_?number|taxnumber|identity[a-z_]*|tckn|vkn)"?\s*[:=]\s*"?)[^&\s",}<]+/i', '$1[gizli]', $text );
		}
		$text = Eru_MPO_Logger::redact( self::redact_text( $text, $secrets ) );
		if ( strlen( $text ) > self::LOG_BYTES ) {
			$text = mb_strcut( $text, 0, self::LOG_BYTES, 'UTF-8' ) . ' …[' . __( 'kesildi', 'marketplace-orders-for-woocommerce' ) . ']';
		}
		return $text;
	}

	/** @param array|object $j Decoded JSON (objects kept as objects). @return array|object Copy with secret-looking keys hidden. */
	private static function redact_json( $j ) {
		$obj = is_object( $j );
		$out = array();
		foreach ( (array) $j as $k => $v ) {
			if ( is_string( $k ) && preg_match( self::SECRET_KEYS, $k ) && ( is_scalar( $v ) && '' !== (string) $v ) ) {
				$out[ $k ] = '[gizli]';
			} else {
				$out[ $k ] = ( is_array( $v ) || is_object( $v ) ) ? self::redact_json( $v ) : $v;
			}
		}
		return $obj ? (object) $out : $out;
	}

	/** @param string $text Text. @param string[] $secrets Secrets. @return string Text without the secrets (plain, URL-encoded and Basic-encoded forms). */
	private static function redact_text( $text, array $secrets ) {
		$text = (string) $text;
		foreach ( $secrets as $s ) {
			$text = str_replace( array( $s, rawurlencode( $s ) ), '[gizli]', $text );
		}
		return $text;
	}

	/** Writes one request log row (bodies redacted and capped). */
	private static function log( $level, $line, $mp, $ref, $request, $response, array $secrets ) {
		$msg = self::redact_text( $line, $secrets );
		$req = null === $request ? '' : self::redact_body( (string) $request, $secrets );
		$rsp = self::redact_body( (string) $response, $secrets );
		if ( '' !== $req ) {
			$msg .= "\n" . __( 'İstek:', 'marketplace-orders-for-woocommerce' ) . ' ' . $req;
		}
		if ( '' !== $rsp ) {
			$msg .= "\n" . __( 'Yanıt:', 'marketplace-orders-for-woocommerce' ) . ' ' . $rsp;
		}
		Eru_MPO_Logger::log( $level, $msg, $mp, $ref, 2 * self::LOG_BYTES + 1000 );
	}

	/** @param string $url URL. @return string Path and query (no scheme, host or credentials). */
	private static function path( $url ) {
		$p = wp_parse_url( (string) $url );
		$q = isset( $p['query'] ) ? '?' . $p['query'] : '';
		return ( $p['path'] ?? '/' ) . $q;
	}

	/** @param array $raw Response. @return int Seconds the server asked to wait (Retry-After / X-RateLimit-Reset), 0 when absent. */
	private static function retry_after( $raw ) {
		foreach ( array( 'retry-after', 'x-ratelimit-reset' ) as $h ) {
			$v = trim( (string) wp_remote_retrieve_header( $raw, $h ) );
			if ( '' !== $v && is_numeric( $v ) ) {
				return max( 0, min( DAY_IN_SECONDS, (int) $v ) );
			}
		}
		return 0;
	}

	/** @return array Result shape. */
	private static function result( $ok, $code, $data, $raw, $error, $retryable, $ambiguous ) {
		return array(
			'ok'          => (bool) $ok,
			'code'        => (int) $code,
			'data'        => $data,
			'raw'         => (string) $raw,
			'error'       => (string) $error,
			'retryable'   => (bool) $retryable,
			'ambiguous'   => (bool) $ambiguous,
			'retry_after' => 0,
			'ms'          => 0,
		);
	}

	/** Remembers the failure for the scheduler and returns it. */
	private static function fail( $mp, array $res ) {
		self::$last[ (string) $mp ] = $res;
		return $res;
	}
}
