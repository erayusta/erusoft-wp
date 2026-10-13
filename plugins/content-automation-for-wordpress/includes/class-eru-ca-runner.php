<?php
/** WP-Cron pipeline: a bounded batch per tick, one run at a time. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_CA_Runner {

	const HOOK      = 'eru_ca_tick';
	const LOCK      = 'eru_ca_lock';
	const LOCK_TTL  = 600;

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'tick' ) );
		add_action( 'update_option_' . Eru_CA_Settings::OPTION, array( __CLASS__, 'sync_schedule' ) );
		add_action( 'add_option_' . Eru_CA_Settings::OPTION, array( __CLASS__, 'sync_schedule' ) );
		add_action( 'delete_option_' . Eru_CA_Settings::OPTION, array( __CLASS__, 'clear_schedule' ) );
	}

	/** Schedule only while the pipeline is enabled; otherwise remove the event. */
	public static function sync_schedule(): void {
		$s = Eru_CA_Settings::get();
		wp_clear_scheduled_hook( self::HOOK );
		if ( $s['enabled'] ) {
			wp_schedule_event( time() + 300, $s['interval'], self::HOOK );
		}
	}

	public static function clear_schedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Row value is "token|unix-time"; a plain number (older format) counts as a token-less lock. */
	private static function lock_time( string $value ): int {
		$pos = strrpos( $value, '|' );
		return (int) ( false === $pos ? $value : substr( $value, $pos + 1 ) );
	}

	/**
	 * Atomic lock on one options row: INSERT IGNORE to take it, compare-and-swap to take over a stale one.
	 *
	 * @return string Token to pass to release_lock(); '' when another run holds the lock.
	 */
	public static function acquire_lock(): string {
		global $wpdb;
		$token = wp_generate_uuid4();
		$value = $token . '|' . time();
		self::flush_lock_cache();
		$inserted = (int) $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')", self::LOCK, $value ) );
		if ( 1 === $inserted ) {
			return $token;
		}
		$held = (string) $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", self::LOCK ) );
		if ( '' === $held || time() - self::lock_time( $held ) <= self::LOCK_TTL ) {
			return '';
		}
		// Stale: only one of several racing runs can swap the exact old value.
		$swapped = (int) $wpdb->query( $wpdb->prepare( "UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s", $value, self::LOCK, $held ) );
		self::flush_lock_cache();
		return 1 === $swapped ? $token : '';
	}

	/** Release only a lock this run still owns (token must match the stored value). */
	public static function release_lock( string $token ): void {
		global $wpdb;
		if ( '' === $token ) {
			return;
		}
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value LIKE %s", self::LOCK, $wpdb->esc_like( $token . '|' ) . '%' ) );
		self::flush_lock_cache();
	}

	private static function flush_lock_cache(): void {
		wp_cache_delete( self::LOCK, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
		wp_cache_delete( 'alloptions', 'options' );
	}

	/** Process up to `per_tick` pending topics. Does nothing (and sends nothing) unless enabled and a key exists. */
	public static function tick(): int {
		$s = Eru_CA_Settings::get();
		if ( ! $s['enabled'] || '' === Eru_CA_Settings::key( $s['provider'] ) ) {
			return 0;
		}
		$items = Eru_CA_Queue::pending( (int) $s['per_tick'] );
		if ( ! $items ) {
			return 0;
		}
		$token = self::acquire_lock();
		if ( '' === $token ) {
			return 0;
		}
		$made = 0;
		try {
			foreach ( $items as $item ) {
				if ( ! Eru_CA_Settings::take_request() ) {
					break;
				}
				$res = self::process( $item, $s );
				if ( is_wp_error( $res ) ) {
					$attempts = (int) $item['attempts'] + 1;
					Eru_CA_Queue::update( (int) $item['id'], array( 'attempts' => $attempts, 'error' => $res->get_error_message(), 'status' => $attempts >= Eru_CA_Queue::MAX_ATTEMPTS ? 'failed' : 'pending' ) );
				} else {
					Eru_CA_Queue::update( (int) $item['id'], array( 'status' => 'done', 'post_id' => $res, 'error' => '' ) );
					++$made;
				}
			}
		} finally {
			self::release_lock( $token );
		}
		return $made;
	}

	/** @return int|WP_Error Post ID. */
	private static function process( array $item, array $s ) {
		$length = 800;
		$system = 'You are an experienced blog writer. Write an original, helpful, well structured article. Do not invent statistics, quotes or sources. '
			. 'Reply with ONE JSON object only, no markdown fences, with the keys "title" (plain text), "excerpt" (one or two sentences, plain text) and "content" (HTML using only h2, h3, p, ul, ol, li, strong, em, a; no h1, no scripts).';
		$user   = 'Topic: ' . $item['topic'] . "\n"
			. ( '' !== $item['keywords'] ? 'Keywords to cover naturally: ' . $item['keywords'] . "\n" : '' )
			. 'Language: ' . $s['language'] . "\n"
			. 'Target length: about ' . $length . ' words.';
		$res = Eru_CA_Client::complete( $s['provider'], $system, $user, (int) $s['max_tokens'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$parsed = self::parse( $res['text'] );
		if ( '' === $parsed['content'] ) {
			return new WP_Error( 'eru_ca_parse', __( 'Yanıt yazıya dönüştürülemedi.', 'content-automation-for-wordpress' ) );
		}
		$content = wp_kses_post( $parsed['content'] );
		if ( in_array( $s['publish_rule'], array( 'publish', 'schedule' ), true ) ) {
			// Posts that go live without review must not hot-link third-party images.
			$content = self::strip_external_images( $content );
		}
		if ( $res['citations'] ) {
			$content .= '<h2>' . esc_html__( 'Kaynaklar', 'content-automation-for-wordpress' ) . '</h2><ul>';
			foreach ( $res['citations'] as $url ) {
				$content .= '<li><a href="' . esc_url( $url ) . '" rel="nofollow noopener">' . esc_html( $url ) . '</a></li>';
			}
			$content .= '</ul>';
		}

		$post = array(
			'post_type'    => 'post',
			'post_title'   => sanitize_text_field( '' !== $parsed['title'] ? $parsed['title'] : $item['topic'] ),
			'post_content' => $content,
			'post_excerpt' => sanitize_textarea_field( $parsed['excerpt'] ),
			'post_author'  => self::author_id(),
		);
		switch ( $s['publish_rule'] ) {
			case 'publish':
				$post['post_status'] = 'publish';
				break;
			case 'schedule':
				$when                   = time() + (int) $s['schedule_delay_hours'] * HOUR_IN_SECONDS;
				$post['post_status']    = 'future';
				$post['post_date']      = wp_date( 'Y-m-d H:i:s', $when );
				$post['post_date_gmt']  = gmdate( 'Y-m-d H:i:s', $when );
				break;
			case 'pending':
				$post['post_status'] = 'pending';
				break;
			default:
				$post['post_status'] = 'draft';
		}
		if ( $s['category'] && term_exists( (int) $s['category'], 'category' ) ) {
			$post['post_category'] = array( (int) $s['category'] );
		}
		$id = wp_insert_post( wp_slash( $post ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		update_post_meta( $id, '_eru_ca_item', (int) $item['id'] );
		update_post_meta( $id, '_eru_ca_source', $s['provider'] . ':' . Eru_CA_Settings::model( $s['provider'] ) );

		if ( $s['image'] && 'openai' === $s['provider'] && Eru_CA_Settings::take_request() ) {
			self::attach_image( (int) $id, $post['post_title'] );
		}
		return (int) $id;
	}

	/** Remove <img> tags whose src is not on this site (drafts keep them for the reviewer to decide). */
	public static function strip_external_images( string $html ): string {
		$home = wp_parse_url( home_url(), PHP_URL_HOST );
		return (string) preg_replace_callback(
			'/<img\b[^>]*>/i',
			static function ( $m ) use ( $home ) {
				if ( ! preg_match( '/\ssrc\s*=\s*("|\')(.*?)\1/i', $m[0], $s ) ) {
					return '';
				}
				$host = wp_parse_url( html_entity_decode( $s[2] ), PHP_URL_HOST );
				return ( null === $host || strtolower( (string) $host ) === strtolower( (string) $home ) ) && 0 !== stripos( ltrim( $s[2] ), 'data:' ) ? $m[0] : '';
			},
			$html
		);
	}

	private static function author_id(): int {
		$uid = get_current_user_id();
		if ( $uid ) {
			return $uid;
		}
		$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID', 'orderby' => 'ID' ) );
		return $admins ? (int) $admins[0] : 0;
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
		if ( preg_match( '#<h1[^>]*>(.*?)</h1>#is', $body, $m ) ) {
			$out['title'] = trim( wp_strip_all_tags( $m[1] ) );
			$body         = trim( str_replace( $m[0], '', $body ) );
		}
		$out['content'] = false === strpos( $body, '<' ) ? wpautop( $body ) : $body;
		return $out;
	}

	private static function attach_image( int $post_id, string $title ): void {
		$img = Eru_CA_Client::image( 'Editorial illustration for a blog post titled: ' . $title . '. No text, no logos.' );
		if ( is_wp_error( $img ) ) {
			return;
		}
		$ext    = array( 'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp' )[ $img['mime'] ];
		$upload = wp_upload_bits( 'auto-post-' . $post_id . '.' . $ext, null, $img['data'] );
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
