<?php
/** WP-CLI: print what would be served. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_SM_CLI {

	/**
	 * Print llms.txt as it would be served (add --full for llms-full.txt).
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Flags.
	 */
	public function llms( $args, $assoc ) {
		if ( ! Eru_SM_Llms::enabled() ) {
			WP_CLI::warning( 'llms.txt modülü kapalı; aşağıdaki çıktı yalnızca önizlemedir.' );
		}
		WP_CLI::line( rtrim( Eru_SM_Llms::render( ! empty( $assoc['full'] ), true ) ) );
	}

	/** Print the LocalBusiness JSON-LD. */
	public function schema() {
		if ( empty( Eru_SM_Settings::get( 'schema' )['enabled'] ) ) {
			WP_CLI::warning( 'Yerel işletme şeması modülü kapalı; aşağıdaki çıktı yalnızca önizlemedir.' );
		}
		WP_CLI::line( wp_json_encode( Eru_SM_Schema::build(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) );
	}

	/** Print robots.txt as it would be served. */
	public function robots() {
		if ( empty( Eru_SM_Settings::get( 'robots' )['enabled'] ) ) {
			WP_CLI::warning( 'robots.txt modülü kapalı; aşağıdaki çıktı yalnızca önizlemedir.' );
		}
		$base = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		$base = (string) apply_filters( 'robots_txt', $base, get_option( 'blog_public' ) );
		WP_CLI::line( rtrim( $base ) );
	}
}
