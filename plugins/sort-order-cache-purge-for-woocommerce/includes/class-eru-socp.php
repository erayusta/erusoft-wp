<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Sıralama kaydı sonrası önbellek temizliği. */
class Eru_Socp {

	/** İstek başına bir kez "tümünü temizle" yeterli. */
	private static bool $purged_all = false;

	public static function init(): void {
		// Negatif öncelik: LiteSpeed, temizlik başlıklarını shutdown:0'da gönderir; sonra eklenen etiket kaybolur.
		add_action( 'shutdown', array( __CLASS__, 'purge_after_ajax' ), -10 );
		add_action( 'post_updated', array( __CLASS__, 'on_post_updated' ), 10, 3 );
	}

	/**
	 * Sıralama yazan AJAX action'ları ve kapsamları.
	 *
	 * 'term' => istekteki terim kimliği alanının adı (yalnızca o arşiv temizlenir).
	 * 'all'  => global sıra değiştiği için tüm önbellek temizlenir.
	 * Varsayılanlar "Rearrange WooCommerce Products" eklentisinin action'larıdır.
	 */
	public static function actions(): array {
		$actions = array(
			'save_all_order_by_category' => array( 'term' => 'term_id' ),
			'save_all_order'             => array( 'all' => true ),
			'smart_sort_products'        => array( 'all' => true ),
			'rwpp_apply_preset'          => array( 'all' => true ),
			'rwpp_import_file'           => array( 'all' => true ),
			'rwpp_run_remigration'       => array( 'all' => true ),
		);
		/**
		 * İzlenen sıralama AJAX action'larını değiştirir.
		 *
		 * @param array $actions action => array( 'term' => alan_adı ) | array( 'all' => true ).
		 */
		$filtered = apply_filters( 'eru_socp_actions', $actions );
		return is_array( $filtered ) ? $filtered : $actions;
	}

	/**
	 * Bir AJAX isteğinden sonra neyin temizleneceğini belirler (saf fonksiyon).
	 *
	 * @param array $request $_POST benzeri veri.
	 * @return array Boş (temizlik yok), array( 'term' => id ) veya array( 'all' => true ).
	 */
	public static function plan( array $request ): array {
		$action = isset( $request['action'] ) && is_scalar( $request['action'] ) ? (string) $request['action'] : '';
		$specs  = self::actions();
		if ( '' === $action || ! isset( $specs[ $action ] ) || ! is_array( $specs[ $action ] ) ) {
			return array();
		}

		// Büyük listeler parça parça gelir; yalnızca son parçadan sonra temizle.
		$is_chunk      = ! empty( $request['is_chunk'] ) && '0' !== (string) $request['is_chunk'];
		$is_last_chunk = ! empty( $request['is_last_chunk'] ) && '0' !== (string) $request['is_last_chunk'];
		if ( $is_chunk && ! $is_last_chunk ) {
			return array();
		}

		$spec = $specs[ $action ];
		if ( isset( $spec['term'] ) ) {
			$field   = (string) $spec['term'];
			$term_id = isset( $request[ $field ] ) && is_scalar( $request[ $field ] ) ? absint( $request[ $field ] ) : 0;
			return $term_id > 0 ? array( 'term' => $term_id ) : array();
		}
		return ! empty( $spec['all'] ) ? array( 'all' => true ) : array();
	}

	/**
	 * Yetki kapısı ŞART: karar $_POST['action'] değerine bakar, nonce doğrulaması ise sıralama
	 * eklentisinin kendi işleyicisindedir. Kapı olmazsa ziyaretçi admin-ajax'a bu action'ı
	 * POST'layıp önbelleği sürekli boşaltabilir.
	 */
	public static function can_sort(): bool {
		return is_user_logged_in() && current_user_can( 'manage_woocommerce' );
	}

	public static function purge_after_ajax(): void {
		if ( ! wp_doing_ajax() || ! self::can_sort() ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Yalnızca okuma; nonce ve yetkiyi sıralama eklentisinin işleyicisi doğrular.
		$plan = self::plan( wp_unslash( $_POST ) );
		if ( empty( $plan ) ) {
			return;
		}
		if ( ! empty( $plan['all'] ) ) {
			self::purge_all();
		} else {
			self::purge_term( (int) $plan['term'] );
		}
	}

	/** Ürünün menu_order değeri kaydedilirken değiştiyse tüm önbelleği temizler. */
	public static function on_post_updated( int $post_id, WP_Post $after, WP_Post $before ): void {
		if ( 'product' === $after->post_type && (int) $after->menu_order !== (int) $before->menu_order ) {
			self::purge_all();
		}
	}

	public static function purge_all(): void {
		if ( self::$purged_all ) {
			return;
		}
		self::$purged_all = true;
		/** Tüm sayfa önbelleğinin temizlenmesi gerektiğinde tetiklenir (kendi önbellek eklentinizi buraya bağlayın). */
		do_action( 'eru_socp_purge_all' );
		do_action( 'litespeed_purge_all' );
	}

	public static function purge_term( int $term_id ): void {
		if ( self::$purged_all || $term_id < 1 ) {
			return;
		}
		/**
		 * Bir terim arşivinin önbelleği temizlenmesi gerektiğinde tetiklenir.
		 *
		 * @param int $term_id Terim kimliği.
		 */
		do_action( 'eru_socp_purge_term', $term_id );
		// LiteSpeed terim arşivi etiketi.
		do_action( 'litespeed_purge', 'T.' . $term_id );
	}
}
