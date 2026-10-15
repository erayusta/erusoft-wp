<?php
/**
 * Tools: connection test, chained background jobs (import, push all, SKU audit, relink by SKU) and the
 * duplicate finder. Long jobs run in Action Scheduler steps under a lock and re-queue themselves until done.
 *
 * @package dopigo-integration-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Background tools never run inside an admin page request; the buttons only queue the first step.
 */
class Eru_DPG_Tools {

	const JOBS  = array( 'import', 'push_all', 'audit', 'relink', 'pull_now' );
	const INDEX = 'eru_dpg_index';
	const AUDIT = 'eru_dpg_audit';
	const JOB   = 'eru_dpg_job_';

	/** @return array<string,string> Job labels. */
	public static function labels() {
		return array(
			'import'   => __( 'Ürün içe aktarma', 'dopigo-integration-for-woocommerce' ),
			'push_all' => __( 'Tüm stok/fiyatı Dopigo\'ya gönder', 'dopigo-integration-for-woocommerce' ),
			'audit'    => __( 'SKU eşleştirme denetimi', 'dopigo-integration-for-woocommerce' ),
			'relink'   => __( 'SKU\'ya göre yeniden bağla', 'dopigo-integration-for-woocommerce' ),
			'pull_now' => __( 'Dopigo\'dan şimdi çek', 'dopigo-integration-for-woocommerce' ),
		);
	}

	/** @param string $job Job. @return bool Queued. */
	public static function queue( $job ) {
		if ( ! in_array( $job, self::JOBS, true ) ) {
			return false;
		}
		if ( in_array( $job, array( 'audit', 'relink', 'push_all' ), true ) ) {
			delete_option( self::JOB . $job );
		}
		update_option( self::JOB . $job . '_queued', time(), false );
		return Eru_DPG_Cron::enqueue( 'eru_dpg_tool', array( $job ) );
	}

	/** @param string $job Job. @return array Progress state. */
	public static function job_state( $job ) {
		$s = get_option( self::JOB . $job, array() );
		return is_array( $s ) ? $s : array();
	}

	/** @param string $job Job. @return bool A step is pending. */
	public static function is_queued( $job ) {
		return Eru_DPG_Cron::pending( 'eru_dpg_tool', array( $job ) ) > 0;
	}

	/** @return array Dopigo index built by the audit (may be empty). */
	public static function index() {
		$ix = get_option( self::INDEX, array() );
		return is_array( $ix ) ? $ix : array();
	}

	/**
	 * Action Scheduler callback for one step of a job.
	 *
	 * @param string $job Job.
	 */
	public static function run( $job ) {
		if ( ! in_array( $job, self::JOBS, true ) || ! Eru_DPG_Settings::is_enabled() ) {
			return;
		}
		if ( ! Eru_DPG_Lock::acquire( 'tool_' . $job, 15 * MINUTE_IN_SECONDS ) ) {
			return;
		}
		try {
			$more = false;
			$err  = null;
			switch ( $job ) {
				case 'import':
					$r = Eru_DPG_Importer::instance()->run_batch( (int) Eru_DPG_Settings::get( 'import_time_budget' ), (int) Eru_DPG_Settings::get( 'import_page_size' ), (int) Eru_DPG_Settings::get( 'import_image_budget' ) );
					if ( ! empty( $r['retryable'] ) ) {
						$err = new WP_Error( 'eru_dpg_retry', (string) $r['error'] );
					}
					$more = empty( $r['error'] ) && ( empty( $r['pass_done'] ) || Eru_DPG_Importer::pending_images() > 0 );
					break;
				case 'pull_now':
					Eru_DPG_Cron::run_pull();
					break;
				case 'push_all':
					list( $more, $err ) = self::step_push_all();
					break;
				case 'audit':
					list( $more, $err ) = self::step_audit();
					break;
				case 'relink':
					$more = self::step_relink();
					break;
			}
			if ( $err ) {
				Eru_DPG_Cron::retry( 'eru_dpg_tool', array( $job ), $err );
			} elseif ( $more ) {
				Eru_DPG_Cron::reset_attempts( 'eru_dpg_tool', array( $job ) );
				Eru_DPG_Cron::enqueue( 'eru_dpg_tool', array( $job ), 10 );
			} else {
				Eru_DPG_Cron::reset_attempts( 'eru_dpg_tool', array( $job ) );
			}
		} finally {
			Eru_DPG_Lock::release( 'tool_' . $job );
		}
	}

	/** @return float Deadline for one step. */
	private static function deadline() {
		return microtime( true ) + max( 5, (int) Eru_DPG_Settings::get( 'time_budget' ) );
	}

	/**
	 * Parent products from a cursor, oldest first.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Limit.
	 * @return WC_Product[]
	 */
	private static function parents( $offset, $limit ) {
		return wc_get_products(
			array(
				'status'  => array( 'publish', 'private', 'draft', 'pending' ),
				'type'    => array( 'simple', 'variable' ),
				'limit'   => $limit,
				'offset'  => $offset,
				'orderby' => 'ID',
				'order'   => 'ASC',
				'return'  => 'objects',
			)
		);
	}

	/** @param WC_Product $p Parent. @return WC_Product[] Sellable units. */
	private static function units( WC_Product $p ) {
		if ( ! $p->is_type( 'variable' ) ) {
			return array( $p );
		}
		return array_values( array_filter( array_map( 'wc_get_product', $p->get_children() ) ) );
	}

	/** @return array [more, WP_Error|null] */
	private static function step_push_all() {
		$s   = array_merge( array( 'offset' => 0, 'updated' => 0, 'failed' => 0, 'noop' => 0, 'started' => time() ), self::job_state( 'push_all' ) );
		$end = self::deadline();
		$err = null;
		while ( microtime( true ) < $end ) {
			$batch = self::parents( (int) $s['offset'], 20 );
			if ( ! $batch ) {
				$s['done'] = time();
				Eru_DPG_Logger::log( 'tools', 'success', __( 'Tüm stok/fiyat gönderimi tamamlandı.', 'dopigo-integration-for-woocommerce' ), $s, 'wc_to_dp' );
				update_option( self::JOB . 'push_all', $s, false );
				return array( false, null );
			}
			foreach ( $batch as $parent ) {
				foreach ( self::units( $parent ) as $u ) {
					$r = Eru_DPG_Products::instance()->push_unit( $u );
					if ( Eru_DPG_API::is_retryable( $r ) ) {
						update_option( self::JOB . 'push_all', $s, false );
						return array( true, $r ); // Same parent again after the backoff.
					}
					is_wp_error( $r ) ? ++$s['failed'] : ( 'updated' === $r ? ++$s['updated'] : ++$s['noop'] );
				}
				++$s['offset'];
			}
		}
		update_option( self::JOB . 'push_all', $s, false );
		return array( true, $err );
	}

	/**
	 * Audit in two phases: read the Dopigo catalogue into an index (pages), then check every WC unit against it.
	 *
	 * @return array [more, WP_Error|null]
	 */
	private static function step_audit() {
		$s   = array_merge( array( 'phase' => 'dopigo', 'offset' => 0, 'wc_offset' => 0, 'matched' => 0, 'missing' => 0, 'no_sku' => 0, 'total' => 0, 'samples' => array(), 'index' => array(), 'started' => time() ), self::job_state( 'audit' ) );
		$end = self::deadline();
		$api = new Eru_DPG_API();
		$per = max( 10, (int) Eru_DPG_Settings::get( 'page_size' ) );
		while ( 'dopigo' === $s['phase'] && microtime( true ) < $end ) {
			$batch = $api->get_products( $per, (int) $s['offset'] );
			if ( is_wp_error( $batch ) ) {
				update_option( self::JOB . 'audit', $s, false );
				if ( Eru_DPG_API::is_retryable( $batch ) ) {
					return array( true, $batch );
				}
				Eru_DPG_Logger::log( 'tools', 'failed', __( 'Denetim: Dopigo kataloğu okunamadı: ', 'dopigo-integration-for-woocommerce' ) . $batch->get_error_message() );
				delete_option( self::JOB . 'audit' );
				return array( false, null );
			}
			$s['index']   = Eru_DPG_API::index_from_batch( $batch, $s['index'] );
			$s['offset'] += count( $batch );
			if ( count( $batch ) < $per ) {
				update_option( self::INDEX, $s['index'] + array( 'built_at' => time() ), false );
				$s['index'] = array();
				$s['phase'] = 'wc';
			}
		}
		$ix = self::index();
		while ( 'wc' === $s['phase'] && microtime( true ) < $end ) {
			$batch = self::parents( (int) $s['wc_offset'], 50 );
			if ( ! $batch ) {
				$result = array(
					'generated_at'    => time(),
					'dopigo_variants' => count( $ix['by_variant_id'] ?? array() ),
					'shared_foreign'  => count( array_filter( (array) ( $ix['foreign_sku_counts'] ?? array() ), fn( $c ) => $c > 1 ) ),
					'wc_units'        => (int) $s['total'],
					'matched'         => (int) $s['matched'],
					'missing'         => (int) $s['missing'],
					'no_sku'          => (int) $s['no_sku'],
					'coverage'        => $s['total'] > 0 ? round( $s['matched'] / $s['total'] * 100, 1 ) : 0,
					'samples'         => $s['samples'],
				);
				update_option( self::AUDIT, $result, false );
				delete_option( self::JOB . 'audit' );
				Eru_DPG_Logger::log( 'tools', 'success', __( 'SKU eşleştirme denetimi tamamlandı.', 'dopigo-integration-for-woocommerce' ), array_diff_key( $result, array( 'samples' => 1 ) ) );
				return array( false, null );
			}
			foreach ( $batch as $parent ) {
				foreach ( self::units( $parent ) as $u ) {
					$sku = (string) $u->get_sku();
					if ( '' === $sku ) {
						++$s['no_sku'];
						continue;
					}
					++$s['total'];
					if ( self::match( $u, $ix ) ) {
						++$s['matched'];
					} else {
						++$s['missing'];
						if ( count( $s['samples'] ) < 25 ) {
							$s['samples'][] = array( 'id' => $u->get_id(), 'sku' => $sku, 'name' => $u->get_name() );
						}
					}
				}
				++$s['wc_offset'];
			}
		}
		update_option( self::JOB . 'audit', $s, false );
		return array( true, null );
	}

	/**
	 * @param WC_Product $u  Unit.
	 * @param array      $ix Index.
	 * @return array|null Dopigo variant (slim).
	 */
	public static function match( WC_Product $u, array $ix ) {
		$vid = (string) $u->get_meta( Eru_DPG_Products::META_VARIANT );
		if ( '' !== $vid && isset( $ix['by_variant_id'][ $vid ] ) ) {
			return $ix['by_variant_id'][ $vid ];
		}
		$sku = (string) $u->get_sku();
		if ( '' === $sku ) {
			return null;
		}
		return $ix['by_foreign_sku'][ $sku ] ?? ( $ix['by_sku'][ $sku ] ?? null );
	}

	/** Relink: store Dopigo variant/meta ids on units whose SKU matches the index. @return bool More to do. */
	private static function step_relink() {
		$ix = self::index();
		if ( empty( $ix['by_variant_id'] ) ) {
			Eru_DPG_Logger::log( 'tools', 'warning', __( 'Yeniden bağlama için önce SKU eşleştirme denetimini çalıştırın.', 'dopigo-integration-for-woocommerce' ) );
			return false;
		}
		$s   = array_merge( array( 'offset' => 0, 'linked' => 0, 'unchanged' => 0, 'shared' => 0 ), self::job_state( 'relink' ) );
		$end = self::deadline();
		while ( microtime( true ) < $end ) {
			$batch = self::parents( (int) $s['offset'], 50 );
			if ( ! $batch ) {
				delete_option( self::JOB . 'relink' );
				Eru_DPG_Logger::log( 'tools', 'success', __( 'SKU\'ya göre yeniden bağlama tamamlandı.', 'dopigo-integration-for-woocommerce' ), $s );
				return false;
			}
			foreach ( $batch as $parent ) {
				foreach ( self::units( $parent ) as $u ) {
					$sku = (string) $u->get_sku();
					$v   = '' !== $sku ? ( $ix['by_foreign_sku'][ $sku ] ?? ( $ix['by_sku'][ $sku ] ?? null ) ) : null;
					if ( ! $v || empty( $v['id'] ) ) {
						continue;
					}
					if ( isset( $ix['by_foreign_sku'][ $sku ] ) && ( $ix['foreign_sku_counts'][ $sku ] ?? 1 ) > 1 && ! isset( $ix['by_sku'][ $sku ] ) ) {
						++$s['shared']; // Ambiguous: several Dopigo variants share this foreign_sku.
						continue;
					}
					if ( (string) $u->get_meta( Eru_DPG_Products::META_VARIANT ) === (string) $v['id'] ) {
						++$s['unchanged'];
						continue;
					}
					if ( ! Eru_DPG_Settings::dry_run() ) {
						$u->update_meta_data( Eru_DPG_Products::META_VARIANT, (string) $v['id'] );
						$u->update_meta_data( Eru_DPG_Products::META_PRODUCT, (string) ( $v['_meta_id'] ?? '' ) );
						Eru_DPG_Products::without_echo( fn() => $u->save_meta_data() );
					}
					++$s['linked'];
				}
				++$s['offset'];
			}
		}
		update_option( self::JOB . 'relink', $s, false );
		return true;
	}

	/**
	 * Duplicate finder (database only, no HTTP).
	 *
	 * @return array wc_sku: duplicated WC SKUs; links: Dopigo variant ids linked to several units; shared: shared foreign SKUs (from the index).
	 */
	public static function duplicates() {
		global $wpdb;
		$lookup = $wpdb->prefix . 'wc_product_meta_lookup';
		$wc     = $wpdb->get_results( "SELECT sku, COUNT(*) AS c, GROUP_CONCAT(product_id ORDER BY product_id) AS ids FROM {$lookup} WHERE sku <> '' GROUP BY sku HAVING c > 1 ORDER BY c DESC LIMIT 50", ARRAY_A ) ?: array(); // phpcs:ignore
		$links  = $wpdb->get_results( $wpdb->prepare( "SELECT meta_value AS variant, COUNT(*) AS c, GROUP_CONCAT(post_id ORDER BY post_id) AS ids FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value <> '' GROUP BY meta_value HAVING c > 1 ORDER BY c DESC LIMIT 50", Eru_DPG_Products::META_VARIANT ), ARRAY_A ) ?: array(); // phpcs:ignore
		$shared = array_filter( (array) ( self::index()['foreign_sku_counts'] ?? array() ), fn( $c ) => $c > 1 );
		arsort( $shared );
		return array(
			'wc_sku' => $wc,
			'links'  => $links,
			'shared' => array_slice( $shared, 0, 50, true ),
		);
	}

	/** Connection test: one small GET. Stores and returns the result. @return array ok, message, time. */
	public static function test_connection() {
		if ( ! Eru_DPG_Settings::has_credentials() ) {
			$r = array( 'ok' => false, 'message' => __( 'Kimlik bilgisi girilmemiş.', 'dopigo-integration-for-woocommerce' ) );
		} else {
			$api = new Eru_DPG_API();
			$tok = $api->token( true );
			$res = is_wp_error( $tok ) ? $tok : $api->get_products( 1, 0 );
			$r   = is_wp_error( $res )
				? array( 'ok' => false, 'message' => Eru_DPG_Logger::redact( $res->get_error_message() ) )
				: array( 'ok' => true, 'message' => 'token' === Eru_DPG_Settings::get( 'auth_mode' ) ? __( 'Bağlantı başarılı (API anahtarı).', 'dopigo-integration-for-woocommerce' ) : __( 'Bağlantı başarılı (kullanıcı adı/şifre).', 'dopigo-integration-for-woocommerce' ) );
		}
		$r['time'] = time();
		update_option( 'eru_dpg_last_test', $r, false );
		Eru_DPG_Logger::log( 'auth', $r['ok'] ? 'success' : 'failed', $r['message'] );
		return $r;
	}
}
