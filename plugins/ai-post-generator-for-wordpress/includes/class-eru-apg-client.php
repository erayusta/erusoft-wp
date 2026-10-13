<?php
/** Provider adapters. Each key is only ever sent to its own provider host. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_APG_Client {

	/** @return array<string,array{label:string,host:string,url:string,model:string}> */
	public static function providers(): array {
		return array(
			'anthropic'  => array(
				'label' => 'Anthropic',
				'host'  => 'api.anthropic.com',
				'url'   => 'https://api.anthropic.com/v1/messages',
				'model' => 'claude-sonnet-5-5',
			),
			'openai'     => array(
				'label' => 'OpenAI',
				'host'  => 'api.openai.com',
				'url'   => 'https://api.openai.com/v1/chat/completions',
				'model' => 'gpt-4.1-mini',
			),
			'perplexity' => array(
				'label' => 'Perplexity',
				'host'  => 'api.perplexity.ai',
				'url'   => 'https://api.perplexity.ai/chat/completions',
				'model' => 'sonar',
			),
		);
	}

	/** @return array<string,string> */
	public static function default_models(): array {
		return wp_list_pluck( self::providers(), 'model' );
	}

	/** True or WP_Error: the URL must be https and exactly the provider's host. */
	public static function assert_host( string $provider, string $url ) {
		$p = self::providers()[ $provider ] ?? null;
		if ( ! $p ) {
			return new WP_Error( 'eru_apg_provider', __( 'Bilinmeyen sağlayıcı.', 'ai-post-generator-for-wordpress' ) );
		}
		$parts = wp_parse_url( $url );
		if ( ! $parts || 'https' !== ( $parts['scheme'] ?? '' ) || strtolower( (string) ( $parts['host'] ?? '' ) ) !== $p['host'] ) {
			return new WP_Error( 'eru_apg_host', __( 'API adresi sağlayıcının sunucusuyla eşleşmiyor; anahtar gönderilmedi.', 'ai-post-generator-for-wordpress' ) );
		}
		return true;
	}

	/**
	 * Send one prompt. Returns array{text:string,citations:string[]} or WP_Error.
	 * The caller is responsible for the key and cap checks that precede this call.
	 */
	public static function complete( string $provider, string $system, string $user, int $max_tokens ) {
		$p   = self::providers()[ $provider ] ?? null;
		$key = Eru_APG_Settings::key( $provider );
		if ( ! $p || '' === $key ) {
			return new WP_Error( 'eru_apg_no_key', __( 'Bu sağlayıcı için API anahtarı girilmemiş.', 'ai-post-generator-for-wordpress' ) );
		}
		$url = (string) apply_filters( 'eru_apg_endpoint', $p['url'], $provider );
		$ok  = self::assert_host( $provider, $url );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$model = Eru_APG_Settings::model( $provider );

		if ( 'anthropic' === $provider ) {
			$headers = array( 'x-api-key' => $key, 'anthropic-version' => '2023-06-01', 'content-type' => 'application/json' );
			$body    = array(
				'model'      => $model,
				'max_tokens' => $max_tokens,
				'system'     => $system,
				'messages'   => array( array( 'role' => 'user', 'content' => $user ) ),
			);
		} else {
			$headers = array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' );
			$body    = array(
				'model'    => $model,
				'messages' => array(
					array( 'role' => 'system', 'content' => $system ),
					array( 'role' => 'user', 'content' => $user ),
				),
			);
			// OpenAI's current models want max_completion_tokens; Perplexity still takes max_tokens.
			$body[ 'openai' === $provider ? 'max_completion_tokens' : 'max_tokens' ] = $max_tokens;
		}

		$res = wp_remote_post(
			$url,
			array(
				'headers'     => $headers,
				'body'        => wp_json_encode( $body ),
				'timeout'     => 90,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'eru_apg_http', $res->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			// Never echo provider error bodies: they can contain key fragments.
			if ( 401 === $code || 403 === $code ) {
				/* translators: %d: HTTP status */
				return new WP_Error( 'eru_apg_auth', sprintf( __( 'Sağlayıcı anahtarı kabul etmedi (HTTP %d). API anahtarını ve hesap yetkilerini kontrol edin.', 'ai-post-generator-for-wordpress' ), $code ) );
			}
			/* translators: %d: HTTP status */
			return new WP_Error( 'eru_apg_api', sprintf( __( 'Sağlayıcı hata döndürdü (HTTP %d).', 'ai-post-generator-for-wordpress' ), $code ) );
		}

		$text = '';
		if ( 'anthropic' === $provider ) {
			foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
				if ( is_array( $block ) && 'text' === ( $block['type'] ?? '' ) ) {
					$text .= (string) ( $block['text'] ?? '' );
				}
			}
		} else {
			$text = (string) ( $data['choices'][0]['message']['content'] ?? '' );
		}
		if ( '' === trim( $text ) ) {
			return new WP_Error( 'eru_apg_empty', __( 'Sağlayıcı boş yanıt döndürdü.', 'ai-post-generator-for-wordpress' ) );
		}
		$citations = array();
		foreach ( (array) ( $data['citations'] ?? array() ) as $c ) {
			if ( is_string( $c ) && preg_match( '#^https?://#i', $c ) ) {
				$citations[] = esc_url_raw( $c );
			}
		}
		return array( 'text' => $text, 'citations' => array_values( array_filter( $citations ) ) );
	}

	/** OpenAI image generation; returns binary image data or WP_Error. Only b64_json from the provider response is used. */
	public static function image( string $prompt ) {
		$key = Eru_APG_Settings::key( 'openai' );
		if ( '' === $key ) {
			return new WP_Error( 'eru_apg_no_key', __( 'OpenAI anahtarı yok.', 'ai-post-generator-for-wordpress' ) );
		}
		$url = 'https://api.openai.com/v1/images/generations';
		$ok  = self::assert_host( 'openai', $url );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$res = wp_remote_post(
			$url,
			array(
				'headers'     => array( 'Authorization' => 'Bearer ' . $key, 'Content-Type' => 'application/json' ),
				'body'        => wp_json_encode( array( 'model' => Eru_APG_Settings::get()['image_model'], 'prompt' => $prompt, 'size' => '1024x1024', 'n' => 1 ) ),
				'timeout'     => 120,
				'redirection' => 0,
			)
		);
		if ( is_wp_error( $res ) ) {
			return new WP_Error( 'eru_apg_http', $res->get_error_message() );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$b64  = is_array( $data ) ? (string) ( $data['data'][0]['b64_json'] ?? '' ) : '';
		$cap  = (int) apply_filters( 'eru_apg_max_image_bytes', 10 * MB_IN_BYTES );
		// Base64 is ~4/3 of the binary size: refuse oversized payloads before decoding.
		$bin  = '' !== $b64 && strlen( $b64 ) <= (int) ceil( $cap * 4 / 3 ) + 8 ? base64_decode( $b64, true ) : false;
		if ( is_string( $bin ) && strlen( $bin ) > $cap ) {
			$bin = false;
		}
		if ( ! $bin || ! ( $info = @getimagesizefromstring( $bin ) ) || ! in_array( $info['mime'], array( 'image/png', 'image/jpeg', 'image/webp' ), true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.CodeAnalysis.AssignmentInCondition.Found
			return new WP_Error( 'eru_apg_image', __( 'Sağlayıcıdan geçerli bir görsel alınamadı.', 'ai-post-generator-for-wordpress' ) );
		}
		return array( 'data' => $bin, 'mime' => $info['mime'] );
	}
}
