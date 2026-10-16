<?php
/**
 * The `eru_ce_campaign` post type that holds campaign definitions, and the plugin's meta capabilities.
 *
 * The post is the editable source; its definition lives in the `_eru_ce_definition` meta and is compiled
 * into the campaigns table by CampaignWriter. Not public, no front-end URL. The admin screens hook
 * `eru_ce_campaign_post_type_args` to show it.
 *
 * Capabilities `manage_eru_ce_campaigns` and `manage_eru_ce_settings` are granted at run time to every
 * user who can `manage_woocommerce`; no role is ever written, so resetting roles cannot lose them.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Infrastructure\Campaign;

final class PostType {

	public const NAME = 'eru_ce_campaign';
	public const CAP  = 'manage_eru_ce_campaigns';

	public static function hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_filter( 'user_has_cap', array( self::class, 'grant' ), 10, 1 );
	}

	public static function register(): void {
		if ( post_type_exists( self::NAME ) ) {
			return;
		}

		$caps = array(
			'edit_post'              => self::CAP,
			'read_post'              => self::CAP,
			'delete_post'            => self::CAP,
			'edit_posts'             => self::CAP,
			'edit_others_posts'      => self::CAP,
			'edit_published_posts'   => self::CAP,
			'edit_private_posts'     => self::CAP,
			'publish_posts'          => self::CAP,
			'read_private_posts'     => self::CAP,
			'delete_posts'           => self::CAP,
			'delete_others_posts'    => self::CAP,
			'delete_published_posts' => self::CAP,
			'delete_private_posts'   => self::CAP,
			'create_posts'           => self::CAP,
		);

		$args = array(
			'labels'          => array(
				'name'          => __( 'Kampanyalar', 'campaign-engine-for-woocommerce' ),
				'singular_name' => __( 'Kampanya', 'campaign-engine-for-woocommerce' ),
			),
			'public'          => false,
			'show_ui'         => false,
			'show_in_rest'    => false,
			'rewrite'         => false,
			'query_var'       => false,
			'has_archive'     => false,
			'supports'        => array( 'title' ),
			'capabilities'    => $caps,
			'map_meta_cap'    => false,
			'capability_type' => 'post',
		);

		/**
		 * Filters the campaign post type arguments (the admin screens turn the UI on here).
		 *
		 * @param array $args register_post_type() arguments.
		 */
		register_post_type( self::NAME, (array) apply_filters( 'eru_ce_campaign_post_type_args', $args ) );
	}

	/**
	 * @param array<string,bool> $allcaps
	 * @return array<string,bool>
	 */
	public static function grant( $allcaps ) {
		if ( is_array( $allcaps ) && ! empty( $allcaps['manage_woocommerce'] ) ) {
			$allcaps[ self::CAP ]              = true;
			$allcaps['manage_eru_ce_settings'] = true;
		}

		return $allcaps;
	}
}
