<?php
/**
 * Campaign archive (`/kampanyalar/`) and detail pages (`/kampanya/<name>/`), slugs from the settings.
 * Only published, active campaigns marked "Kampanya sayfasında listelensin" appear; a detail page of
 * any other campaign is a 404. The countdown uses the campaign's real end time.
 *
 * Rewrite rules exist only while the module is on. They are flushed once after a slug or module change
 * (Rewrite), never on every request.
 *
 * Templates: templates/campaign-archive.php and templates/campaign-single.php; a theme overrides them
 * with campaign-engine-for-woocommerce/<file> in the theme folder.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Storefront;

use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Infrastructure\Campaign\PostType;
use Erusoft\CampaignEngine\Infrastructure\Clock;
use Erusoft\CampaignEngine\Infrastructure\Database\CampaignRepository;
use Erusoft\CampaignEngine\Infrastructure\Settings\StorefrontOptions;

final class CampaignPages {

	public const VAR_PAGE = 'eru_ce_page';
	public const VAR_SLUG = 'eru_ce_slug';

	/** @var array<string,mixed>|null data of the page being shown */
	private static ?array $current = null;

	public static function hooks(): void {
		if ( ! StorefrontOptions::on( 'pages' ) ) {
			return;
		}

		add_action( 'init', array( self::class, 'rules' ) );
		add_filter( 'query_vars', array( self::class, 'vars' ) );
		add_action( 'template_redirect', array( self::class, 'resolve' ), 5 );
		add_filter( 'template_include', array( self::class, 'template' ), 99 );
		add_filter( 'document_title_parts', array( self::class, 'title' ) );
	}

	public static function rules(): void {
		$archive = (string) StorefrontOptions::get( 'archive_slug' );
		$single  = (string) StorefrontOptions::get( 'single_slug' );

		add_rewrite_rule( '^' . preg_quote( $archive, '#' ) . '/?$', 'index.php?' . self::VAR_PAGE . '=archive', 'top' );
		add_rewrite_rule( '^' . preg_quote( $single, '#' ) . '/([^/]+)/?$', 'index.php?' . self::VAR_PAGE . '=single&' . self::VAR_SLUG . '=$matches[1]', 'top' );
	}

	/** @param string[] $vars */
	public static function vars( $vars ): array {
		$vars   = (array) $vars;
		$vars[] = self::VAR_PAGE;
		$vars[] = self::VAR_SLUG;

		return $vars;
	}

	public static function url( ?Campaign $campaign = null ): string {
		if ( null === $campaign ) {
			return home_url( user_trailingslashit( (string) StorefrontOptions::get( 'archive_slug' ) ) );
		}

		$post = get_post( $campaign->id );

		return home_url( user_trailingslashit( StorefrontOptions::get( 'single_slug' ) . '/' . ( $post ? $post->post_name : (string) $campaign->id ) ) );
	}

	/**
	 * Public campaigns running now, highest priority first.
	 *
	 * @return Campaign[]
	 */
	public static function listed(): array {
		$now = Clock::now();

		return array_values(
			array_filter(
				CampaignRepository::active(),
				static fn( Campaign $c ): bool => ! empty( $c->meta['public'] ) && $c->schedule->isActiveAt( $now )
			)
		);
	}

	public static function resolve(): void {
		$page = (string) get_query_var( self::VAR_PAGE );

		if ( '' === $page ) {
			return;
		}

		global $wp_query;

		if ( 'archive' === $page ) {
			self::$current = array(
				'type'      => 'archive',
				'campaigns' => self::listed(),
			);
		} else {
			$slug  = sanitize_title( (string) get_query_var( self::VAR_SLUG ) );
			$post  = '' === $slug ? null : get_page_by_path( $slug, OBJECT, PostType::NAME );
			$found = null;

			if ( $post && 'publish' === $post->post_status ) {
				foreach ( self::listed() as $campaign ) {
					if ( $campaign->id === (int) $post->ID ) {
						$found = $campaign;
						break;
					}
				}
			}

			if ( null === $found ) {
				$wp_query->set_404();
				status_header( 404 );
				nocache_headers();

				return;
			}

			self::$current = array(
				'type'     => 'single',
				'campaign' => $found,
			);
		}

		$wp_query->is_404  = false;
		$wp_query->is_home = false;
		status_header( 200 );
	}

	public static function template( $template ) {
		if ( null === self::$current ) {
			return $template;
		}

		$file   = 'archive' === self::$current['type'] ? 'campaign-archive.php' : 'campaign-single.php';
		$theme  = locate_template( array( 'campaign-engine-for-woocommerce/' . $file ) );

		return '' !== $theme ? $theme : ERU_CE_PATH . 'templates/' . $file;
	}

	/** @param array<string,string> $parts */
	public static function title( $parts ) {
		if ( null === self::$current ) {
			return $parts;
		}

		$parts          = (array) $parts;
		$parts['title'] = 'single' === self::$current['type'] ? self::$current['campaign']->label : __( 'Kampanyalar', 'campaign-engine-for-woocommerce' );

		return $parts;
	}

	/** @return array<string,mixed>|null */
	public static function current(): ?array {
		return self::$current;
	}

	/** Product listing of a campaign's targets: product ids, categories, or the whole shop. */
	public static function products( Campaign $campaign ): string {
		$targets = $campaign->targets->toArray();
		$ids     = array_merge( $targets['include']['product'], $targets['include']['variation'] );
		$terms   = $targets['include']['term'];

		if ( array() !== $ids ) {
			return do_shortcode( '[products ids="' . esc_attr( implode( ',', array_map( 'intval', $ids ) ) ) . '" limit="24" columns="4"]' );
		}

		$slugs = array();

		foreach ( $terms as $term_id ) {
			$term = get_term( (int) $term_id, 'product_cat' );

			if ( $term && ! is_wp_error( $term ) ) {
				$slugs[] = $term->slug;
			}
		}

		if ( array() !== $slugs ) {
			return do_shortcode( '[products category="' . esc_attr( implode( ',', $slugs ) ) . '" limit="24" columns="4"]' );
		}

		return '';
	}

	/** Page start for classic and block themes. */
	public static function header(): void {
		if ( ! wp_is_block_theme() ) {
			get_header();

			return;
		}

		// Block theme: render the header part first so its styles are enqueued before wp_head().
		$header = do_blocks( '<!-- wp:template-part {"slug":"header","tagName":"header"} /-->' );
		?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
		<?php wp_head(); ?>
</head>
<body <?php body_class( 'eru-ce-campaign-page' ); ?>>
		<?php wp_body_open(); ?>
<div class="wp-site-blocks">
		<?php
		echo $header; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks.
	}

	/** Page end for classic and block themes. */
	public static function footer(): void {
		if ( ! wp_is_block_theme() ) {
			get_footer();

			return;
		}

		echo do_blocks( '<!-- wp:template-part {"slug":"footer","tagName":"footer"} /-->' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rendered blocks.
		echo '</div>';
		wp_footer();
		echo '</body></html>';
	}
}
