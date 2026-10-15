<?php
/**
 * Back-in-stock alerts for simple products and for single variations, with double opt-in.
 *
 * @package growth-automations-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * An alert is active only after the e-mail owner confirms; it is notified once.
 */
class Eru_GRA_Alerts {

	const CPT      = 'eru_gra_alert';
	const NONCE    = 'eru_gra_alert';
	const RATE_MAX = 10;

	/** Hooks. */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_cpt' ) );
		add_filter( 'woocommerce_get_stock_html', array( __CLASS__, 'simple_form' ), 20, 2 );
		add_filter( 'woocommerce_available_variation', array( __CLASS__, 'variation_form' ), 20, 3 );
		add_action( 'wc_ajax_eru_gra_alert', array( __CLASS__, 'ajax' ) );
		add_action( 'woocommerce_product_set_stock_status', array( __CLASS__, 'on_stock_status' ), 10, 3 );
		add_action( 'woocommerce_variation_set_stock_status', array( __CLASS__, 'on_stock_status' ), 10, 3 );
		add_action( 'eru_gra_notify', array( __CLASS__, 'notify' ) );
		add_action( 'template_redirect', array( __CLASS__, 'confirm_request' ), 1 );
		add_filter( 'manage_' . self::CPT . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . self::CPT . '_posts_custom_column', array( __CLASS__, 'column' ), 10, 2 );
	}

	/** Alerts are listed under WooCommerce, read-only. */
	public static function register_cpt() {
		$cap = 'manage_woocommerce';
		register_post_type(
			self::CPT,
			array(
				'labels'              => array(
					'name'          => __( 'Stok bildirimleri', 'growth-automations-for-woocommerce' ),
					'singular_name' => __( 'Stok bildirimi', 'growth-automations-for-woocommerce' ),
					'search_items'  => __( 'Bildirim ara', 'growth-automations-for-woocommerce' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => 'woocommerce',
				'supports'            => array( 'title' ),
				'capabilities'        => array(
					'edit_post'           => $cap,
					'read_post'           => $cap,
					'delete_post'         => $cap,
					'edit_posts'          => $cap,
					'edit_others_posts'   => $cap,
					'delete_posts'        => $cap,
					'delete_others_posts' => $cap,
					'publish_posts'       => $cap,
					'read_private_posts'  => $cap,
					'create_posts'        => 'do_not_allow',
				),
				'map_meta_cap'        => false,
				'exclude_from_search' => true,
			)
		);
	}

	/** @param array $cols Columns. @return array */
	public static function columns( $cols ) {
		return array(
			'cb'    => $cols['cb'] ?? '',
			'title' => __( 'E-posta', 'growth-automations-for-woocommerce' ),
			'prod'  => __( 'Ürün', 'growth-automations-for-woocommerce' ),
			'state' => __( 'Durum', 'growth-automations-for-woocommerce' ),
			'date'  => $cols['date'] ?? '',
		);
	}

	/** @param string $col Column. @param int $id Post ID. */
	public static function column( $col, $id ) {
		if ( 'prod' === $col ) {
			$p = wc_get_product( (int) get_post_meta( $id, '_eru_gra_target', true ) );
			echo $p ? esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) : '&mdash;';
		} elseif ( 'state' === $col ) {
			echo esc_html( (string) get_post_meta( $id, '_eru_gra_state', true ) );
		}
	}

	/** @return string Per-IP rate transient key. */
	public static function rate_key() {
		return 'eru_gra_arl_' . md5( Eru_GRA_Cart::client_ip() );
	}

	/** @return string Global hourly counter key. */
	public static function global_key() {
		return 'eru_gra_agl_' . gmdate( 'YmdH' );
	}

	/**
	 * Find the newest alert of an address for a product or variation.
	 *
	 * @param string $email E-mail. @param int $target Product or variation ID. @return int Post ID or 0.
	 */
	public static function find( $email, $target ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_eru_gra_target' WHERE p.post_type = %s AND p.post_title = %s AND m.meta_value = %d ORDER BY p.ID DESC LIMIT 1", self::CPT, Eru_GRA_Consent::norm( $email ), (int) $target ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/** @param int $target Product or variation ID. @return bool An active alert exists. */
	private static function has_active( $target ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_eru_gra_target' INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_eru_gra_state' WHERE p.post_type = %s AND m.meta_value = %d AND s.meta_value = 'active' LIMIT 1", self::CPT, (int) $target ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}

	/**
	 * Form markup. It is not a <form>: variation availability HTML is inserted inside WooCommerce's variations form, and
	 * a nested form would be dropped by the browser. Inputs have no name, so nothing is posted with add-to-cart.
	 *
	 * @param WC_Product $product Simple product or variation. @return string
	 */
	private static function form_html( WC_Product $product ) {
		$parent = $product->is_type( 'variation' ) ? $product->get_parent_id() : $product->get_id();
		$vid    = $product->is_type( 'variation' ) ? $product->get_id() : 0;
		$uid    = 'eru-gra-' . $product->get_id();
		ob_start();
		?>
		<div class="eru-gra-alert" data-product="<?php echo esc_attr( $parent ); ?>" data-variation="<?php echo esc_attr( $vid ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>">
			<p class="eru-gra-alert__title"><?php esc_html_e( 'Stoğa girince haber verin', 'growth-automations-for-woocommerce' ); ?></p>
			<div class="eru-gra-alert__row">
				<label class="screen-reader-text" for="<?php echo esc_attr( $uid ); ?>"><?php esc_html_e( 'E-posta', 'growth-automations-for-woocommerce' ); ?></label>
				<input type="email" id="<?php echo esc_attr( $uid ); ?>" class="eru-gra-alert__email" placeholder="<?php esc_attr_e( 'E-posta adresiniz', 'growth-automations-for-woocommerce' ); ?>" autocomplete="email">
				<input type="text" class="eru-gra-alert__hp" tabindex="-1" autocomplete="off" aria-hidden="true">
				<button type="button" class="button eru-gra-alert__btn"><?php esc_html_e( 'Haber ver', 'growth-automations-for-woocommerce' ); ?></button>
			</div>
			<p class="eru-gra-alert__note"><?php esc_html_e( 'Önce bir onay e-postası göndeririz; onayladığınızda ürün stoğa girince tek bir e-posta alırsınız.', 'growth-automations-for-woocommerce' ); ?></p>
			<p class="eru-gra-alert__msg" role="status" aria-live="polite"></p>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/** @param string $html Stock HTML. @param WC_Product $product Product. @return string */
	public static function simple_form( $html, $product ) {
		if ( ! Eru_GRA_Settings::is_on( 'alerts' ) || ! $product instanceof WC_Product || $product->is_in_stock() ) {
			return $html;
		}
		if ( $product->is_type( 'variable' ) || $product->is_type( 'variation' ) || $product->is_type( 'grouped' ) || $product->is_type( 'external' ) ) {
			return $html;
		}
		return $html . self::form_html( $product );
	}

	/** @param array $data Variation data. @param WC_Product $parent Parent. @param WC_Product_Variation $variation Variation. @return array */
	public static function variation_form( $data, $parent, $variation ) {
		if ( Eru_GRA_Settings::is_on( 'alerts' ) && $variation instanceof WC_Product && ! $variation->is_in_stock() ) {
			$data['availability_html'] = (string) ( $data['availability_html'] ?? '' ) . self::form_html( $variation );
		}
		return $data;
	}

	/** @return array|null Raw settings of alerts. */
	private static function cfg( $key ) {
		return Eru_GRA_Settings::get( 'alerts', $key );
	}

	/** @param int $id Product or variation. @return string Link for the notification. */
	private static function link( $id ) {
		$p = wc_get_product( $id );
		return $p ? $p->get_permalink() : home_url( '/' );
	}

	/**
	 * Start a double opt-in alert request. Nothing is notified until the e-mail owner confirms.
	 *
	 * @param array $a email, product_id, variation_id, hp (honeypot).
	 * @return array{ok:bool,code:string,message:string,id:int}
	 */
	public static function subscribe( array $a ) {
		$res = fn( $ok, $code, $msg, $id = 0 ) => array( 'ok' => $ok, 'code' => $code, 'message' => $msg, 'id' => (int) $id );
		if ( ! Eru_GRA_Settings::is_on( 'alerts' ) ) {
			return $res( false, 'off', __( 'Stok bildirimi şu anda kapalı.', 'growth-automations-for-woocommerce' ) );
		}
		if ( ! empty( $a['hp'] ) ) {
			return $res( false, 'spam', __( 'İstek reddedildi.', 'growth-automations-for-woocommerce' ) );
		}
		$rl = self::rate_key();
		$n  = (int) get_transient( $rl );
		if ( $n >= self::RATE_MAX ) {
			return $res( false, 'rate', __( 'Çok fazla deneme yaptınız, lütfen daha sonra tekrar deneyin.', 'growth-automations-for-woocommerce' ) );
		}
		set_transient( $rl, $n + 1, HOUR_IN_SECONDS );
		$email = Eru_GRA_Consent::norm( (string) ( $a['email'] ?? '' ) );
		if ( ! is_email( $email ) ) {
			return $res( false, 'email', __( 'Geçerli bir e-posta adresi girin.', 'growth-automations-for-woocommerce' ) );
		}
		$vid     = (int) ( $a['variation_id'] ?? 0 );
		$pid     = (int) ( $a['product_id'] ?? 0 );
		$product = wc_get_product( $vid ? $vid : $pid );
		if ( ! $product || ( $vid && (int) $product->get_parent_id() !== $pid ) || 'publish' !== get_post_status( $pid ) ) {
			return $res( false, 'product', __( 'Ürün bulunamadı.', 'growth-automations-for-woocommerce' ) );
		}
		if ( $product->is_in_stock() ) {
			return $res( false, 'in-stock', __( 'Bu ürün şu anda stokta.', 'growth-automations-for-woocommerce' ) );
		}
		$target = $product->get_id();

		$lock  = 'a' . md5( $email . '|' . $target );
		$owner = Eru_GRA_Lock::acquire( $lock, MINUTE_IN_SECONDS );
		if ( false === $owner ) {
			return $res( false, 'exists', __( 'İsteğiniz zaten alındı.', 'growth-automations-for-woocommerce' ) );
		}
		try {
			$old = self::find( $email, $target );
			if ( $old ) {
				$state   = get_post_meta( $old, '_eru_gra_state', true );
				$expires = (int) get_post_meta( $old, '_eru_gra_token_expires', true );
				if ( 'active' === $state ) {
					return $res( false, 'exists', __( 'Bu ürün için zaten bildirim bekliyorsunuz.', 'growth-automations-for-woocommerce' ) );
				}
				if ( 'pending' === $state && $expires > time() ) {
					return $res( false, 'pending', __( 'Onay e-postası zaten gönderildi, lütfen gelen kutunuzu kontrol edin.', 'growth-automations-for-woocommerce' ) );
				}
				wp_delete_post( $old, true );
			}
			$gk = self::global_key();
			$g  = (int) get_transient( $gk );
			if ( $g >= (int) self::cfg( 'hourly_cap' ) ) {
				return $res( false, 'busy', __( 'Şu anda çok fazla istek var, lütfen daha sonra tekrar deneyin.', 'growth-automations-for-woocommerce' ) );
			}
			set_transient( $gk, $g + 1, HOUR_IN_SECONDS );
			$id = wp_insert_post( array( 'post_type' => self::CPT, 'post_status' => 'publish', 'post_title' => $email ) );
			if ( ! $id || is_wp_error( $id ) ) {
				return $res( false, 'store', __( 'Kayıt oluşturulamadı.', 'growth-automations-for-woocommerce' ) );
			}
			$token = wp_generate_password( 32, false, false );
			update_post_meta( $id, '_eru_gra_state', 'pending' );
			update_post_meta( $id, '_eru_gra_product', $pid );
			update_post_meta( $id, '_eru_gra_variation', $vid );
			update_post_meta( $id, '_eru_gra_target', $target );
			update_post_meta( $id, '_eru_gra_token', hash( 'sha256', $token ) );
			update_post_meta( $id, '_eru_gra_token_expires', time() + (int) self::cfg( 'confirm_hours' ) * HOUR_IN_SECONDS );
			Eru_GRA_DB::flag_data();
		} finally {
			Eru_GRA_Lock::release( $lock, $owner );
		}

		$ok = Eru_GRA_Mail::send(
			$email,
			array(
				'subject' => self::cfg( 'confirm_subject' ),
				'heading' => __( 'Bildirimi onaylayın', 'growth-automations-for-woocommerce' ),
				'body'    => self::cfg( 'confirm_body' ),
			),
			array(
				'product_name' => wp_strip_all_tags( $product->get_name() ),
				'product_link' => $product->get_permalink(),
				'confirm_link' => add_query_arg( 'eru_gra_confirm', $id . '.' . $token, home_url( '/' ) ),
			)
		);
		Eru_GRA_DB::log( 'alerts', $email, $ok ? 'confirm-sent' : 'confirm-failed' );
		return $res( true, 'pending', __( 'Teşekkürler! Onay için e-postanıza bir bağlantı gönderdik.', 'growth-automations-for-woocommerce' ), $id );
	}

	/** wc-ajax endpoint. */
	public static function ajax() {
		check_ajax_referer( self::NONCE, 'nonce' );
		// phpcs:disable WordPress.Security.NonceVerification -- verified above.
		$r = self::subscribe(
			array(
				'email'        => isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '',
				'product_id'   => isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0,
				'variation_id' => isset( $_POST['variation_id'] ) ? absint( $_POST['variation_id'] ) : 0,
				'hp'           => isset( $_POST['hp'] ) ? sanitize_text_field( wp_unslash( $_POST['hp'] ) ) : '',
			)
		);
		// phpcs:enable
		wp_send_json( array( 'ok' => $r['ok'], 'code' => $r['code'], 'message' => $r['message'] ), 'rate' === $r['code'] ? 429 : 200 );
	}

	/**
	 * Confirm a pending alert with the emailed token (single use, expiring).
	 *
	 * @param int $id Alert ID. @param string $token Raw token. @return bool
	 */
	public static function confirm( $id, $token ) {
		$id = (int) $id;
		if ( ! $id || self::CPT !== get_post_type( $id ) || 'pending' !== get_post_meta( $id, '_eru_gra_state', true ) ) {
			return false;
		}
		$hash = (string) get_post_meta( $id, '_eru_gra_token', true );
		if ( '' === $hash || ! hash_equals( $hash, hash( 'sha256', (string) $token ) ) || (int) get_post_meta( $id, '_eru_gra_token_expires', true ) < time() ) {
			return false;
		}
		update_post_meta( $id, '_eru_gra_state', 'active' );
		update_post_meta( $id, '_eru_gra_confirmed_at', gmdate( 'Y-m-d H:i:s' ) );
		delete_post_meta( $id, '_eru_gra_token' );
		delete_post_meta( $id, '_eru_gra_token_expires' );
		return true;
	}

	/**
	 * Link handling, free of output. GET only renders a button; a same-site POST with the token confirms.
	 *
	 * @param string $method HTTP method. @param string $raw "<id>.<token>".
	 * @return array{status:int,html:string}
	 */
	public static function handle_confirm( $method, $raw ) {
		$title = esc_html__( 'Stok bildirimi', 'growth-automations-for-woocommerce' );
		$page  = fn( $status, $body ) => array( 'status' => $status, 'html' => '<h1>' . $title . '</h1>' . $body );
		if ( 'POST' !== strtoupper( (string) $method ) ) {
			return $page(
				200,
				'<p>' . esc_html__( 'Bildirim isteğinizi tamamlamak için aşağıdaki düğmeye tıklayın.', 'growth-automations-for-woocommerce' ) . '</p>'
				. '<form method="post" action="' . esc_url( home_url( '/' ) ) . '"><input type="hidden" name="eru_gra_confirm" value="' . esc_attr( $raw ) . '">'
				. '<button type="submit" class="button">' . esc_html__( 'Bildirimi onayla', 'growth-automations-for-woocommerce' ) . '</button></form>'
			);
		}
		if ( ! Eru_GRA_Consent::not_cross_origin() ) {
			return $page( 403, '<p>' . esc_html__( 'İstek reddedildi.', 'growth-automations-for-woocommerce' ) . '</p>' );
		}
		$p = explode( '.', (string) $raw, 2 );
		if ( ! self::confirm( (int) $p[0], $p[1] ?? '' ) ) {
			return $page( 400, '<p>' . esc_html__( 'Onay bağlantısı geçersiz, süresi dolmuş veya daha önce kullanılmış.', 'growth-automations-for-woocommerce' ) . '</p>' );
		}
		return $page( 200, '<p>' . esc_html__( 'Teşekkürler! Ürün stoğa girdiğinde size bir e-posta göndereceğiz.', 'growth-automations-for-woocommerce' ) . '</p>' );
	}

	/** Front-end entry for the confirmation link. */
	public static function confirm_request() {
		// phpcs:disable WordPress.Security.NonceVerification -- the single-use token is the credential.
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		$src    = 'POST' === strtoupper( $method ) ? $_POST : $_GET;
		if ( empty( $src['eru_gra_confirm'] ) || ! is_string( $src['eru_gra_confirm'] ) ) {
			return;
		}
		$raw = sanitize_text_field( wp_unslash( $src['eru_gra_confirm'] ) );
		// phpcs:enable
		nocache_headers();
		$r = self::handle_confirm( $method, $raw );
		wp_die( Eru_GRA_Consent::kses( $r['html'] ), esc_html__( 'Stok bildirimi', 'growth-automations-for-woocommerce' ), array( 'response' => $r['status'], 'back_link' => false ) );
	}

	/** @param int $id Product or variation ID. @param string $status Stock status. @param WC_Product $product Product. */
	public static function on_stock_status( $id, $status = '', $product = null ) {
		unset( $product );
		if ( 'instock' !== $status || ! Eru_GRA_Settings::is_on( 'alerts' ) || ! self::has_active( (int) $id ) ) {
			return;
		}
		Eru_GRA_Runner::enqueue_unique( 'eru_gra_notify', array( (int) $id ) );
	}

	/**
	 * Notify one batch of active alerts for a product or variation; re-arms itself while more remain.
	 * An alert is marked sent before its mail goes out, and the selection only returns active ones, so nobody is mailed twice.
	 *
	 * @param int $target Product or variation ID.
	 */
	public static function notify( $target ) {
		$target = (int) $target;
		$p      = wc_get_product( $target );
		if ( ! $p || ! $p->is_in_stock() || ! Eru_GRA_Settings::is_on( 'alerts' ) ) {
			return;
		}
		$lock  = 'n' . $target;
		$owner = Eru_GRA_Lock::acquire( $lock, 10 * MINUTE_IN_SECONDS );
		if ( false === $owner ) {
			return;
		}
		try {
			$size = max( 1, (int) self::cfg( 'batch_size' ) );
			$ids  = self::active_ids( $target, $size );
			foreach ( $ids as $id ) {
				$email = get_post_field( 'post_title', $id );
				update_post_meta( $id, '_eru_gra_state', 'sent' );
				update_post_meta( $id, '_eru_gra_notified_at', gmdate( 'Y-m-d H:i:s' ) );
				$ok = Eru_GRA_Mail::send(
					$email,
					array(
						'subject' => self::cfg( 'subject' ),
						'heading' => self::cfg( 'heading' ),
						'body'    => self::cfg( 'body' ),
					),
					array(
						'product_name' => wp_strip_all_tags( $p->get_name() ),
						'product_link' => $p->get_permalink(),
					)
				);
				Eru_GRA_DB::log( 'alerts', $email, $ok ? 'sent' : 'failed' );
			}
			Eru_GRA_DB::trim_log();
			if ( count( $ids ) >= $size && self::active_ids( $target, 1 ) ) {
				Eru_GRA_Runner::enqueue_unique( 'eru_gra_notify', array( $target ) );
			}
		} finally {
			Eru_GRA_Lock::release( $lock, $owner );
		}
	}

	/** @param int $target Target. @param int $limit Limit. @return int[] Active alert IDs (done flag in the query). */
	private static function active_ids( $target, $limit ) {
		global $wpdb;
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_eru_gra_target' INNER JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_eru_gra_state' WHERE p.post_type = %s AND m.meta_value = %d AND s.meta_value = 'active' ORDER BY p.ID ASC LIMIT %d", self::CPT, (int) $target, (int) $limit ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL
	}
}
