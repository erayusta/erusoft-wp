<?php
/** FAQ item post type and group taxonomy. */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Eru_FAQ_Post_Type {

	const TYPE = 'eru_faq';
	const TAX  = 'eru_faq_group';

	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register(): void {
		register_post_type(
			self::TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'SSS', 'faq-accordion-for-wordpress' ),
					'singular_name' => __( 'Soru', 'faq-accordion-for-wordpress' ),
					'add_new'       => __( 'Yeni soru', 'faq-accordion-for-wordpress' ),
					'add_new_item'  => __( 'Yeni soru ekle', 'faq-accordion-for-wordpress' ),
					'edit_item'     => __( 'Soruyu düzenle', 'faq-accordion-for-wordpress' ),
					'all_items'     => __( 'Tüm sorular', 'faq-accordion-for-wordpress' ),
					'search_items'  => __( 'Soru ara', 'faq-accordion-for-wordpress' ),
					'not_found'     => __( 'Soru bulunamadı.', 'faq-accordion-for-wordpress' ),
					'menu_name'     => __( 'SSS', 'faq-accordion-for-wordpress' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_rest'        => true,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'menu_icon'           => 'dashicons-editor-help',
				'menu_position'       => 58,
				'supports'            => array( 'title', 'editor', 'page-attributes' ),
			)
		);
		register_taxonomy(
			self::TAX,
			self::TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'SSS grupları', 'faq-accordion-for-wordpress' ),
					'singular_name' => __( 'SSS grubu', 'faq-accordion-for-wordpress' ),
					'add_new_item'  => __( 'Yeni grup ekle', 'faq-accordion-for-wordpress' ),
					'menu_name'     => __( 'Gruplar', 'faq-accordion-for-wordpress' ),
				),
				'public'            => false,
				'show_ui'           => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'hierarchical'      => false,
				'rewrite'           => false,
			)
		);
	}
}
