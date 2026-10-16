<?php
/**
 * The campaign list and editor on the `eru_ce_campaign` post type: the post type's admin UI, the
 * wizard save, field errors and warnings after a save, and the list columns.
 *
 * Saving: the wizard form posts `eru_ce[...]` with the nonce `eru_ce_wizard_<post id>`. The save runs
 * only for a user who may edit that campaign (the meta capability, held by every shop manager) and with
 * a valid nonce. The fields are read by WizardForm and validated with the engine's readers; an invalid
 * form writes nothing, keeps the post's previous status (a draft is never published by it) and brings
 * the merchant back to the form with the field errors and what they typed.
 *
 * @package Erusoft\CampaignEngine
 */

declare(strict_types=1);

namespace Erusoft\CampaignEngine\Presentation\Admin;

use Erusoft\CampaignEngine\Engine\Campaign\Campaign;
use Erusoft\CampaignEngine\Infrastructure\Campaign\CampaignWriter;
use Erusoft\CampaignEngine\Infrastructure\Campaign\PostType;
use Erusoft\CampaignEngine\Infrastructure\Clock;
use Erusoft\CampaignEngine\Infrastructure\ConfigErrors;
use Erusoft\CampaignEngine\Infrastructure\Database\CampaignRepository;
use Erusoft\CampaignEngine\Infrastructure\Database\UsageRepository;
use Erusoft\CampaignEngine\Presentation\Texts;
use WP_Post;

final class CampaignScreen {

	public const NONCE_FIELD = 'eru_ce_wizard_nonce';
	public const FORM_KEY    = 'eru_ce';

	/** @var array<string,array{definition:array,errors:array<string,string>,input:array}> parse result of the posted form */
	private static array $parsed = array();

	/** @var array{errors:array<string,string>,input:array<string,mixed>}|null errors of the last refused save */
	private static ?array $refused = null;

	public static function hooks(): void {
		add_filter( 'eru_ce_campaign_post_type_args', array( self::class, 'postTypeArgs' ) );
		add_filter( 'wp_insert_post_data', array( self::class, 'beforeSave' ), 10, 2 );
		add_action( 'save_post_' . PostType::NAME, array( self::class, 'save' ), 10, 2 );

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'add_meta_boxes_' . PostType::NAME, array( self::class, 'metaboxes' ) );
		add_action( 'load-post.php', array( self::class, 'load' ) );
		add_action( 'load-post-new.php', array( self::class, 'load' ) );
		add_action( 'admin_notices', array( self::class, 'notices' ) );
		add_filter( 'manage_' . PostType::NAME . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . PostType::NAME . '_posts_custom_column', array( self::class, 'column' ), 10, 2 );
		add_filter( 'post_updated_messages', array( self::class, 'messages' ) );
		add_filter( 'enter_title_here', array( self::class, 'titlePlaceholder' ), 10, 2 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ), 20 ); // After WooCommerce registers its admin assets.
	}

	/**
	 * Turns the post type's admin UI on, under its own top-level menu "Kampanyalar".
	 *
	 * @param array<string,mixed> $args
	 * @return array<string,mixed>
	 */
	public static function postTypeArgs( $args ) {
		$args                  = is_array( $args ) ? $args : array();
		$args['show_ui']       = true;
		$args['show_in_menu']  = true;
		$args['menu_position'] = 56;
		$args['menu_icon']     = 'dashicons-megaphone';
		$args['labels']        = array(
			'name'               => __( 'Kampanyalar', 'campaign-engine-for-woocommerce' ),
			'singular_name'      => __( 'Kampanya', 'campaign-engine-for-woocommerce' ),
			'menu_name'          => __( 'Kampanyalar', 'campaign-engine-for-woocommerce' ),
			'all_items'          => __( 'Tüm kampanyalar', 'campaign-engine-for-woocommerce' ),
			'add_new'            => __( 'Boş kampanya', 'campaign-engine-for-woocommerce' ),
			'add_new_item'       => __( 'Yeni kampanya', 'campaign-engine-for-woocommerce' ),
			'edit_item'          => __( 'Kampanyayı düzenle', 'campaign-engine-for-woocommerce' ),
			'new_item'           => __( 'Yeni kampanya', 'campaign-engine-for-woocommerce' ),
			'search_items'       => __( 'Kampanya ara', 'campaign-engine-for-woocommerce' ),
			'not_found'          => __( 'Kampanya bulunamadı.', 'campaign-engine-for-woocommerce' ),
			'not_found_in_trash' => __( 'Çöp kutusunda kampanya yok.', 'campaign-engine-for-woocommerce' ),
			'item_published'     => __( 'Kampanya yayınlandı.', 'campaign-engine-for-woocommerce' ),
			'item_updated'       => __( 'Kampanya güncellendi.', 'campaign-engine-for-woocommerce' ),
		);

		return $args;
	}

	/** Whether the current request may save the wizard form of this campaign. */
	public static function authorized( int $post_id ): bool {
		if ( ! isset( $_POST[ self::FORM_KEY ] ) || ! is_array( $_POST[ self::FORM_KEY ] ) ) {
			return false;
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) ) : '';

		return '' !== $nonce && false !== wp_verify_nonce( $nonce, 'eru_ce_wizard_' . $post_id ) && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Parses the posted form once per request.
	 *
	 * @return array{definition:array,errors:array<string,string>,input:array<string,mixed>}
	 */
	private static function parsed( int $post_id ): array {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Missing -- nonce checked by authorized(); fields read one by one in WizardForm.
		$input = wp_unslash( (array) $_POST[ self::FORM_KEY ] );
		$key   = $post_id . ':' . md5( (string) wp_json_encode( $input ) );

		if ( ! isset( self::$parsed[ $key ] ) ) {
			self::$parsed       = array();
			self::$parsed[ $key ] = WizardForm::parse( $input ) + array( 'input' => $input );
		}

		return self::$parsed[ $key ];
	}

	/**
	 * A form with errors never changes the post's status: a draft is not published, a published
	 * campaign keeps running on its previous (valid) definition.
	 *
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $postarr
	 * @return array<string,mixed>
	 */
	public static function beforeSave( $data, $postarr ) {
		$post_id = (int) ( $postarr['ID'] ?? 0 );

		if ( PostType::NAME !== ( $data['post_type'] ?? '' ) || $post_id <= 0 || ! self::authorized( $post_id ) ) {
			return $data;
		}

		if ( array() !== self::parsed( $post_id )['errors'] ) {
			$current             = (string) get_post_status( $post_id );
			$data['post_status'] = in_array( $current, array( '', 'auto-draft', 'new' ), true ) ? 'draft' : $current;
		}

		return $data;
	}

	/** save_post (before the compile at priority 20): stores the wizard definition. */
	public static function save( $post_id, $post ): void {
		$post_id = (int) $post_id;

		if ( ! $post instanceof WP_Post || wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) || ! isset( $_POST[ self::FORM_KEY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return;
		}

		$user = get_current_user_id();
		$key  = self::transientKey( $user, $post_id );

		if ( ! self::authorized( $post_id ) ) {
			return;
		}

		$parsed = self::parsed( $post_id );

		if ( array() !== $parsed['errors'] ) {
			set_transient(
				$key,
				array(
					'errors' => $parsed['errors'],
					'input'  => $parsed['input'],
				),
				10 * MINUTE_IN_SECONDS
			);
			add_filter( 'redirect_post_location', array( self::class, 'withoutSavedMessage' ) );

			return;
		}

		$stored = CampaignWriter::store( $post_id, $parsed['definition'] );

		if ( is_wp_error( $stored ) ) {
			$data = (array) $stored->get_error_data();
			add_filter( 'redirect_post_location', array( self::class, 'withoutSavedMessage' ) );
			set_transient(
				$key,
				array(
					'errors' => array( WizardForm::formField( (string) ( $data['field'] ?? '' ) ) => (string) ( $data['reason'] ?? 'invalid' ) ),
					'input'  => $parsed['input'],
				),
				10 * MINUTE_IN_SECONDS
			);

			return;
		}

		delete_transient( $key );
		$warnings = WizardForm::warnings( $parsed['definition'] );

		if ( array() !== $warnings ) {
			set_transient( $key . '_warn', $warnings, 10 * MINUTE_IN_SECONDS );
		}
	}

	/** A refused save must not be announced as "updated". */
	public static function withoutSavedMessage( $location ) {
		return remove_query_arg( 'message', (string) $location );
	}

	public static function transientKey( int $user_id, int $post_id ): string {
		return 'eru_ce_wizard_' . $user_id . '_' . $post_id;
	}

	/** load-post.php: picks up the errors of a refused save once. */
	public static function load(): void {
		$screen = get_current_screen();

		if ( ! $screen || PostType::NAME !== $screen->post_type ) {
			return;
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $post_id <= 0 ) {
			return;
		}

		$key     = self::transientKey( get_current_user_id(), $post_id );
		$refused = get_transient( $key );

		if ( is_array( $refused ) ) {
			self::$refused = array(
				'errors' => (array) ( $refused['errors'] ?? array() ),
				'input'  => (array) ( $refused['input'] ?? array() ),
			);
			delete_transient( $key );
		}
	}

	/** Errors of the refused save shown on this screen, or null. */
	public static function refused(): ?array {
		return self::$refused;
	}

	public static function metaboxes( WP_Post $post ): void {
		add_meta_box( 'eru-ce-wizard', __( 'Kampanya sihirbazı', 'campaign-engine-for-woocommerce' ), array( WizardView::class, 'render' ), PostType::NAME, 'normal', 'high' );
		add_meta_box( 'eru-ce-preview', __( 'Canlı önizleme', 'campaign-engine-for-woocommerce' ), array( WizardView::class, 'renderPreview' ), PostType::NAME, 'side', 'default' );
		remove_meta_box( 'slugdiv', PostType::NAME, 'normal' );
	}

	public static function notices(): void {
		$screen = get_current_screen();

		if ( ! $screen || PostType::NAME !== $screen->post_type || 'post' !== $screen->base ) {
			return;
		}

		if ( null !== self::$refused ) {
			echo '<div class="notice notice-error eru-ce-errors"><p><strong>' . esc_html__( 'Kampanya kaydedilmedi; aşağıdaki alanları düzeltin. Kampanyanın durumu değiştirilmedi.', 'campaign-engine-for-woocommerce' ) . '</strong></p><ul>';

			foreach ( self::$refused['errors'] as $field => $reason ) {
				echo '<li>' . esc_html( TraceText::error( (string) $field, (string) $reason ) ) . '</li>';
			}

			echo '</ul></div>';
		}

		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $post_id <= 0 ) {
			return;
		}

		$key      = self::transientKey( get_current_user_id(), $post_id ) . '_warn';
		$warnings = get_transient( $key );

		if ( is_array( $warnings ) && array() !== $warnings ) {
			delete_transient( $key );

			foreach ( $warnings as $warning ) {
				echo '<div class="notice notice-warning"><p>' . esc_html( (string) $warning ) . '</p></div>';
			}
		}

		foreach ( ConfigErrors::all() as $entry ) {
			if ( 'campaign' === $entry['type'] && $post_id === (int) $entry['id'] ) {
				echo '<div class="notice notice-error"><p>' . esc_html( sprintf( /* translators: %s: field error */ __( 'Bu kampanya çalıştırılamıyor: %s. Düzeltip yeniden kaydedin.', 'campaign-engine-for-woocommerce' ), TraceText::error( WizardForm::formField( (string) $entry['field'] ), (string) $entry['reason'] ) ) ) . '</p></div>';
			}
		}
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function columns( $columns ) {
		$out = array();

		foreach ( (array) $columns as $key => $label ) {
			if ( 'date' === $key ) {
				continue;
			}

			$out[ $key ] = $label;

			if ( 'title' === $key ) {
				$out['eru_ce_status']   = __( 'Durum', 'campaign-engine-for-woocommerce' );
				$out['eru_ce_type']     = __( 'Ne veriyor', 'campaign-engine-for-woocommerce' );
				$out['eru_ce_schedule'] = __( 'Zaman', 'campaign-engine-for-woocommerce' );
				$out['eru_ce_priority'] = __( 'Öncelik', 'campaign-engine-for-woocommerce' );
				$out['eru_ce_usage']    = __( 'Kullanım', 'campaign-engine-for-woocommerce' );
			}
		}

		return $out;
	}

	public static function column( $column, $post_id ): void {
		$post_id = (int) $post_id;
		$def     = get_post_meta( $post_id, CampaignWriter::META, true );
		$def     = is_array( $def ) ? $def : array();

		switch ( $column ) {
			case 'eru_ce_status':
				$state = self::state( $post_id, $def );
				echo '<span class="eru-ce-state eru-ce-state--' . esc_attr( $state[0] ) . '">' . esc_html( $state[1] ) . '</span>';
				if ( ! empty( $def['exclusive'] ) ) {
					echo '<br><small>' . esc_html__( 'Tek başına', 'campaign-engine-for-woocommerce' ) . '</small>';
				}
				break;
			case 'eru_ce_type':
				$texts = array();
				foreach ( (array) ( $def['actions'] ?? array() ) as $row ) {
					if ( is_array( $row ) ) {
						$texts[] = Texts::action( $row );
					}
				}
				echo esc_html( implode( ' + ', array_filter( $texts ) ) );
				break;
			case 'eru_ce_schedule':
				$schedule = is_array( $def['schedule'] ?? null ) ? $def['schedule'] : array();
				$parts    = array();
				if ( ! empty( $schedule['starts_ts'] ) ) {
					/* translators: %s: date */
					$parts[] = sprintf( __( 'Başlangıç: %s', 'campaign-engine-for-woocommerce' ), Texts::dateTime( (int) $schedule['starts_ts'] ) );
				}
				if ( ! empty( $schedule['ends_ts'] ) ) {
					/* translators: %s: date */
					$parts[] = sprintf( __( 'Bitiş: %s', 'campaign-engine-for-woocommerce' ), Texts::dateTime( (int) $schedule['ends_ts'] ) );
				}
				if ( ! empty( $schedule['recurrence'] ) ) {
					$parts[] = __( 'Tekrar eden saatlerde', 'campaign-engine-for-woocommerce' );
				}
				echo array() === $parts ? esc_html__( 'Süresiz', 'campaign-engine-for-woocommerce' ) : wp_kses( implode( '<br>', array_map( 'esc_html', $parts ) ), array( 'br' => array() ) );
				break;
			case 'eru_ce_priority':
				echo esc_html( (string) (int) ( $def['priority'] ?? 10 ) );
				break;
			case 'eru_ce_usage':
				$used  = UsageRepository::activeCount( $post_id );
				$limit = isset( $def['usage_limit'] ) ? (int) $def['usage_limit'] : 0;
				echo esc_html( $limit > 0 ? sprintf( '%d / %d', $used, $limit ) : (string) $used );
				break;
		}
	}

	/**
	 * Status of a campaign for the list: [css key, label].
	 *
	 * @param array<string,mixed> $def
	 * @return array{0:string,1:string}
	 */
	public static function state( int $post_id, array $def ): array {
		$status = get_post_status( $post_id );

		if ( 'publish' !== $status ) {
			return array( 'draft', __( 'Taslak', 'campaign-engine-for-woocommerce' ) );
		}

		foreach ( ConfigErrors::all() as $entry ) {
			if ( 'campaign' === $entry['type'] && $post_id === (int) $entry['id'] ) {
				return array( 'error', __( 'Hatalı', 'campaign-engine-for-woocommerce' ) );
			}
		}

		if ( ! empty( $def['paused'] ) ) {
			return array( 'paused', __( 'Duraklatıldı', 'campaign-engine-for-woocommerce' ) );
		}

		$row = CampaignRepository::row( $post_id );

		if ( null === $row || 'active' !== $row['status'] ) {
			return array( 'error', __( 'Çalışmıyor', 'campaign-engine-for-woocommerce' ) );
		}

		$schedule = is_array( $def['schedule'] ?? null ) ? $def['schedule'] : array();
		$campaign = Campaign::fromArray( array( 'id' => $post_id, 'actions' => array(), 'schedule' => $schedule ) );
		$now      = Clock::now();

		if ( $campaign->schedule->hasEnded( $now ) ) {
			return array( 'ended', __( 'Süresi doldu', 'campaign-engine-for-woocommerce' ) );
		}

		if ( ! $campaign->schedule->isActiveAt( $now ) ) {
			return array( 'waiting', __( 'Zamanı bekleniyor', 'campaign-engine-for-woocommerce' ) );
		}

		return array( 'active', __( 'Yayında', 'campaign-engine-for-woocommerce' ) );
	}

	/**
	 * @param array<string,array<int,string>> $messages
	 * @return array<string,array<int,string>>
	 */
	public static function messages( $messages ) {
		$messages                   = is_array( $messages ) ? $messages : array();
		$messages[ PostType::NAME ] = array(
			0  => '',
			1  => __( 'Kampanya güncellendi.', 'campaign-engine-for-woocommerce' ),
			4  => __( 'Kampanya güncellendi.', 'campaign-engine-for-woocommerce' ),
			6  => __( 'Kampanya yayınlandı.', 'campaign-engine-for-woocommerce' ),
			7  => __( 'Kampanya kaydedildi.', 'campaign-engine-for-woocommerce' ),
			8  => __( 'Kampanya gönderildi.', 'campaign-engine-for-woocommerce' ),
			9  => __( 'Kampanya zamanlandı.', 'campaign-engine-for-woocommerce' ),
			10 => __( 'Kampanya taslağı güncellendi.', 'campaign-engine-for-woocommerce' ),
		);

		return $messages;
	}

	public static function titlePlaceholder( $text, $post ) {
		return $post instanceof WP_Post && PostType::NAME === $post->post_type ? __( 'Kampanya adı (müşteriye de görünür)', 'campaign-engine-for-woocommerce' ) : $text;
	}

	/** Assets of the editor, the list and the plugin's own pages. */
	public static function assets( $hook ): void {
		$screen = get_current_screen();

		if ( ! $screen || ! Menu::isPluginScreen( $screen ) ) {
			return;
		}

		wp_enqueue_style( 'eru-ce-admin', plugins_url( 'assets/css/admin.css', ERU_CE_FILE ), array(), ERU_CE_VERSION );

		if ( PostType::NAME === $screen->post_type && 'post' === $screen->base ) {
			if ( wp_script_is( 'wc-enhanced-select', 'registered' ) ) {
				wp_enqueue_script( 'wc-enhanced-select' );
			}
			if ( wp_style_is( 'woocommerce_admin_styles', 'registered' ) ) {
				wp_enqueue_style( 'woocommerce_admin_styles' );
			}
			wp_enqueue_script( 'eru-ce-wizard', plugins_url( 'assets/js/admin-wizard.js', ERU_CE_FILE ), array(), ERU_CE_VERSION, true );
			wp_localize_script( 'eru-ce-wizard', 'eruCeWizard', WizardView::scriptData() );
		}
	}
}
