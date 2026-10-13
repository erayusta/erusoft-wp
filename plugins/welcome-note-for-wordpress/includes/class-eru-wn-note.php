<?php
/** Renders the note (admin notice and front-end bar) and handles per-user dismissal. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_WN_Note {

	const META = 'eru_wn_dismissed';

	public static function init(): void {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'wp_footer', array( __CLASS__, 'front_bar' ) );
		add_action( 'wp_ajax_eru_wn_dismiss', array( __CLASS__, 'ajax_dismiss' ) );
	}

	/** Identifies the current note; changing the text or link shows it again. */
	public static function key(): string {
		$s = Eru_WN_Settings::get();
		return substr( md5( $s['text'] . '|' . $s['link_url'] . '|' . $s['link_label'] ), 0, 12 );
	}

	/** Text with {name} and {site} replaced; empty when no note is configured. */
	private static function message( ?WP_User $user ): string {
		$s = Eru_WN_Settings::get();
		if ( '' === trim( $s['text'] ) ) {
			return '';
		}
		$name = $user && $user->exists() ? $user->display_name : '';
		$out = str_replace( array( '{name}', '{site}' ), array( $name, get_bloginfo( 'name' ) ), $s['text'] );
		return trim( preg_replace( '/\s+([,.!?])/u', '$1', $out ) );
	}

	private static function dismissed( int $user_id ): bool {
		if ( ! $user_id ) {
			return false;
		}
		$done = get_user_meta( $user_id, self::META, true );
		return is_string( $done ) && '' !== $done && hash_equals( $done, self::key() );
	}

	public static function dismiss_for( int $user_id, string $key ): bool {
		if ( $user_id < 1 || ! hash_equals( self::key(), $key ) ) {
			return false;
		}
		return (bool) update_user_meta( $user_id, self::META, self::key() ) || self::dismissed( $user_id );
	}

	private static function allowed_in_admin( WP_User $user ): bool {
		$s = Eru_WN_Settings::get();
		return (bool) $s['admin_enabled'] && (bool) array_intersect( (array) $s['roles'], (array) $user->roles );
	}

	private static function markup( string $msg, string $class_extra, bool $dismissible ): string {
		$s    = Eru_WN_Settings::get();
		$link = '';
		if ( '' !== $s['link_url'] ) {
			$label = '' !== $s['link_label'] ? $s['link_label'] : __( 'Devamı', 'welcome-note-for-wordpress' );
			$link  = ' <a class="eru-wn__link" href="' . esc_url( $s['link_url'] ) . '">' . esc_html( $label ) . '</a>';
		}
		return '<div class="eru-wn eru-wn--' . esc_attr( $s['style'] ) . ' ' . esc_attr( $class_extra ) . '" role="note" data-eru-wn-key="' . esc_attr( self::key() ) . '">'
			. '<p class="eru-wn__text">' . esc_html( $msg ) . $link . '</p>'
			. ( $dismissible ? '<button type="button" class="eru-wn__close" aria-label="' . esc_attr__( 'Notu kapat', 'welcome-note-for-wordpress' ) . '">&times;</button>' : '' )
			. '</div>';
	}

	public static function admin_notice(): void {
		$user = wp_get_current_user();
		if ( ! $user->exists() || ! self::allowed_in_admin( $user ) || self::dismissed( $user->ID ) ) {
			return;
		}
		$msg = self::message( $user );
		if ( '' === $msg ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in markup().
		echo self::markup( $msg, 'eru-wn--admin notice', true );
	}

	public static function front_bar(): void {
		$s = Eru_WN_Settings::get();
		if ( ! $s['front_enabled'] || is_admin() ) {
			return;
		}
		$user = wp_get_current_user();
		if ( $user->exists() && self::dismissed( $user->ID ) ) {
			return;
		}
		$msg = self::message( $user );
		if ( '' === $msg ) {
			return;
		}
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in markup().
		echo self::markup( $msg, 'eru-wn--front eru-wn--' . esc_attr( $s['front_pos'] ), true );
	}

	public static function enqueue(): void {
		$s = Eru_WN_Settings::get();
		if ( '' === trim( $s['text'] ) ) {
			return;
		}
		if ( is_admin() ? ! $s['admin_enabled'] : ! $s['front_enabled'] ) {
			return;
		}
		wp_enqueue_style( 'eru-wn', ERU_WN_URL . 'assets/note.css', array(), ERU_WN_VERSION );
		wp_enqueue_script( 'eru-wn', ERU_WN_URL . 'assets/note.js', array(), ERU_WN_VERSION, true );
		wp_localize_script(
			'eru-wn',
			'eruWn',
			array(
				'ajax'   => admin_url( 'admin-ajax.php' ),
				'nonce'  => is_user_logged_in() ? wp_create_nonce( 'eru_wn_dismiss' ) : '',
				'key'    => self::key(),
				'logged' => is_user_logged_in(),
			)
		);
	}

	public static function ajax_dismiss(): void {
		check_ajax_referer( 'eru_wn_dismiss', 'nonce' );
		$key = isset( $_POST['key'] ) ? sanitize_text_field( wp_unslash( $_POST['key'] ) ) : '';
		if ( ! is_user_logged_in() || ! self::dismiss_for( get_current_user_id(), $key ) ) {
			wp_send_json_error( null, 400 );
		}
		wp_send_json_success();
	}
}
