<?php
/** Builds the prompt, calls the provider and stores the result as a draft. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_APG_Generator {

	/** Capability needed to generate (filterable; contributors cannot by default). */
	public static function capability(): string {
		return (string) apply_filters( 'eru_apg_capability', 'publish_posts' );
	}

	const LENGTHS = array( 'short' => 400, 'medium' => 800, 'long' => 1500 );

	/**
	 * @param array $input topic (required), keywords, length (short|medium|long), category (term id).
	 * @return int|WP_Error New post ID.
	 */
	public static function generate( array $input ) {
		if ( ! current_user_can( self::capability() ) ) {
			return new WP_Error( 'eru_apg_cap_denied', __( 'Bu işlem için yetkiniz yok.', 'ai-post-generator-for-wordpress' ) );
		}
		$topic = sanitize_text_field( (string) ( $input['topic'] ?? '' ) );
		if ( '' === $topic ) {
			return new WP_Error( 'eru_apg_topic', __( 'Lütfen bir konu girin.', 'ai-post-generator-for-wordpress' ) );
		}
		$s        = Eru_APG_Settings::get();
		$provider = $s['provider'];
		if ( '' === Eru_APG_Settings::key( $provider ) ) {
			return new WP_Error( 'eru_apg_no_key', __( 'Seçili sağlayıcı için API anahtarı girilmemiş; istek gönderilmedi.', 'ai-post-generator-for-wordpress' ) );
		}
		if ( ! Eru_APG_Settings::take_request() ) {
			return new WP_Error( 'eru_apg_cap', __( 'Günlük istek sınırına ulaşıldı.', 'ai-post-generator-for-wordpress' ) );
		}
		$keywords = sanitize_text_field( (string) ( $input['keywords'] ?? '' ) );
		$length   = isset( self::LENGTHS[ $input['length'] ?? '' ] ) ? self::LENGTHS[ $input['length'] ] : self::LENGTHS['medium'];

		$system = 'You are an experienced blog writer. Write an original, helpful, well structured article. Do not invent statistics, quotes or sources. '
			. 'Reply with ONE JSON object only, no markdown fences, with the keys "title" (plain text), "excerpt" (one or two sentences, plain text) and "content" (HTML using only h2, h3, p, ul, ol, li, strong, em, a; no h1, no scripts).';
		$user   = 'Topic: ' . $topic . "\n"
			. ( '' !== $keywords ? 'Keywords to cover naturally: ' . $keywords . "\n" : '' )
			. 'Language: ' . $s['language'] . "\n"
			. 'Target length: about ' . $length . ' words.';

		$res = Eru_APG_Client::complete( $provider, $system, $user, (int) $s['max_tokens'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$parsed = self::parse( $res['text'] );
		if ( '' === $parsed['content'] ) {
			return new WP_Error( 'eru_apg_parse', __( 'Yanıt yazıya dönüştürülemedi.', 'ai-post-generator-for-wordpress' ) );
		}
		$content = wp_kses_post( $parsed['content'] );
		if ( $res['citations'] ) {
			$content .= '<h2>' . esc_html__( 'Kaynaklar', 'ai-post-generator-for-wordpress' ) . '</h2><ul>';
			foreach ( $res['citations'] as $url ) {
				$content .= '<li><a href="' . esc_url( $url ) . '" rel="nofollow noopener">' . esc_html( $url ) . '</a></li>';
			}
			$content .= '</ul>';
		}
		$title = '' !== $parsed['title'] ? $parsed['title'] : $topic;
		$post  = array(
			'post_type'    => 'post',
			'post_status'  => in_array( $s['status'], array( 'draft', 'pending' ), true ) ? $s['status'] : 'draft',
			'post_title'   => sanitize_text_field( $title ),
			'post_content' => $content,
			'post_excerpt' => sanitize_textarea_field( $parsed['excerpt'] ),
			'post_author'  => get_current_user_id(),
		);
		$cat = isset( $input['category'] ) ? absint( $input['category'] ) : 0;
		if ( $cat && term_exists( $cat, 'category' ) ) {
			$post['post_category'] = array( $cat );
		}
		$id = wp_insert_post( wp_slash( $post ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_eru_apg_source', $provider . ':' . Eru_APG_Settings::model( $provider ) );

		if ( $s['image'] && 'openai' === $provider && Eru_APG_Settings::take_request() ) {
			self::attach_image( (int) $id, $post['post_title'] );
		}
		return (int) $id;
	}

	/** @return array{title:string,excerpt:string,content:string} */
	public static function parse( string $text ): array {
		$out  = array( 'title' => '', 'excerpt' => '', 'content' => '' );
		$body = trim( preg_replace( '/^```[a-z]*\s*|\s*```$/i', '', trim( $text ) ) );
		$a    = strpos( $body, '{' );
		$b    = strrpos( $body, '}' );
		if ( false !== $a && false !== $b && $b > $a ) {
			$j = json_decode( substr( $body, $a, $b - $a + 1 ), true );
			if ( is_array( $j ) && ! empty( $j['content'] ) && is_string( $j['content'] ) ) {
				$out['title']   = isset( $j['title'] ) ? wp_strip_all_tags( (string) $j['title'] ) : '';
				$out['excerpt'] = isset( $j['excerpt'] ) ? wp_strip_all_tags( (string) $j['excerpt'] ) : '';
				$out['content'] = $j['content'];
				return $out;
			}
		}
		// Fallback: the model answered with plain HTML or text.
		if ( preg_match( '#<h1[^>]*>(.*?)</h1>#is', $body, $m ) ) {
			$out['title'] = trim( wp_strip_all_tags( $m[1] ) );
			$body         = trim( str_replace( $m[0], '', $body ) );
		}
		$out['content'] = false === strpos( $body, '<' ) ? wpautop( $body ) : $body;
		return $out;
	}

	private static function attach_image( int $post_id, string $title ): void {
		$img = Eru_APG_Client::image( 'Editorial illustration for a blog post titled: ' . $title . '. No text, no logos.' );
		if ( is_wp_error( $img ) ) {
			return;
		}
		$ext    = array( 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp' )[ $img['mime'] ];
		$upload = wp_upload_bits( 'ai-post-' . $post_id . '.' . $ext, null, $img['data'] );
		if ( ! empty( $upload['error'] ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$att = wp_insert_attachment(
			array(
				'post_mime_type' => $img['mime'],
				'post_title'     => sanitize_text_field( $title ),
				'post_status'    => 'inherit',
			),
			$upload['file'],
			$post_id
		);
		if ( ! $att || is_wp_error( $att ) ) {
			return;
		}
		wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $upload['file'] ) );
		set_post_thumbnail( $post_id, $att );
	}
}
