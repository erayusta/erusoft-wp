<?php
/**
 * Bulk transfer: CSV import and export, and an importer for an append-only consent-log table.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Every imported record goes through Eru_CMI_Repository::set() (actor "import"), so the history, the İYS
 * rules and the queue apply as for any other change. Rules shared by every source:
 *
 * - The consent date is required and taken from the data; a missing or unreadable date is an error, never
 *   "now". Dates without a zone are Turkish time.
 * - An import never lifts a RET (RET → ONAY): such a line is counted as "kept RET".
 * - A line older than the stored record is skipped ("older"); a line equal to it is "unchanged" and writes
 *   nothing, so running the same import again adds nothing.
 * - Imported ONAYs are never re-stamped: one older than 3 business days is stored and recorded, and the queue
 *   marks it skipped (H467). An imported RET that old is sent dated now (a withdrawal must reach İYS). With
 *   "already at İYS" the records are marked as registered at İYS instead of being queued (for consents another
 *   integration already sent).
 * - Errors carry a line number (or index) and a short code; never the address.
 *
 * Table import: any table of this database that logs consent changes as rows (one row per change, an
 * increasing numeric column for the order). The admin maps its columns to the fields below; the latest row
 * of each recipient and channel is imported. Without a channel column, one channel is chosen for every row.
 *
 * Export writes the store as CSV with every recipient masked unless the full export is asked for; cells that
 * start with = + - @, a tab or a carriage return are prefixed with an apostrophe (formula injection).
 */
class Eru_CMI_Import {

	const REQUIRED = array( 'recipient', 'type', 'status', 'consent_date' );
	const OPTIONAL = array( 'source', 'recipient_type' );
	const JOB      = 'eru_cmi_table_job';
	const STEP     = 500;

	/** Table import: fields a column is mapped to (id = the increasing order column). */
	const MAP_REQUIRED = array( 'id', 'recipient', 'status', 'consent_date' );
	const MAP_OPTIONAL = array( 'type', 'source', 'recipient_type' );

	/**
	 * Checks one record.
	 *
	 * @param array $in            recipient, type, status, consent_date, source, recipient_type.
	 * @param bool  $strict_source An unknown İYS source is an error (CSV, API); otherwise it falls back to
	 *                             HS_WEB (a mapped table column may hold its own labels).
	 * @return array|WP_Error Normalised {recipient, type, status, ts, iys_source, recipient_type} or an error
	 *                        whose code is one of recipient, type, status, date_required, date, source,
	 *                        recipient_type.
	 */
	public static function validate( array $in, $strict_source = true ) {
		$type = Eru_CMI_Recipient::type( $in['type'] ?? '' );
		if ( '' === $type ) {
			return new WP_Error( 'type', __( 'Geçersiz kanal (EPOSTA, MESAJ, ARAMA).', 'consent-manager-for-iys' ) );
		}
		$recipient = Eru_CMI_Recipient::normalize( (string) ( $in['recipient'] ?? '' ), $type );
		if ( is_wp_error( $recipient ) ) {
			return new WP_Error( 'recipient', __( 'Geçersiz alıcı.', 'consent-manager-for-iys' ) );
		}
		$status = strtoupper( trim( (string) ( $in['status'] ?? '' ) ) );
		if ( ! in_array( $status, Eru_CMI_Recipient::STATUSES, true ) ) {
			return new WP_Error( 'status', __( 'Geçersiz durum (ONAY, RET).', 'consent-manager-for-iys' ) );
		}
		$date = trim( (string) ( $in['consent_date'] ?? '' ) );
		if ( '' === $date ) {
			return new WP_Error( 'date_required', __( 'İzin tarihi gerekli.', 'consent-manager-for-iys' ) );
		}
		$ts = preg_match( '/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?/', $date ) ? Eru_CMI_Rules::parse_date( $date ) : null;
		if ( null === $ts || $ts > time() + 5 * MINUTE_IN_SECONDS || $ts < strtotime( '2000-01-01' ) ) {
			return new WP_Error( 'date', __( 'İzin tarihi okunamadı (YYYY-AA-GG SS:DD:ss, Türkiye saati).', 'consent-manager-for-iys' ) );
		}
		$src = strtoupper( trim( (string) ( $in['source'] ?? '' ) ) );
		if ( '' !== $src && ! in_array( $src, Eru_CMI_Rules::IYS_SOURCES, true ) && $strict_source ) {
			return new WP_Error( 'source', __( 'Geçersiz İYS kaynağı (ör. HS_WEB).', 'consent-manager-for-iys' ) );
		}
		$rt = strtoupper( trim( (string) ( $in['recipient_type'] ?? '' ) ) );
		if ( '' !== $rt && ! in_array( $rt, Eru_CMI_Recipient::RECIPIENT_TYPES, true ) ) {
			return new WP_Error( 'recipient_type', __( 'Geçersiz alıcı tipi (BIREYSEL, TACIR).', 'consent-manager-for-iys' ) );
		}
		return array(
			'recipient'      => $recipient,
			'type'           => $type,
			'status'         => $status,
			'ts'             => min( (int) $ts, time() ),
			'iys_source'     => Eru_CMI_Rules::iys_source( $src ),
			'recipient_type' => $rt,
		);
	}

	/**
	 * Stores one validated record.
	 *
	 * @param array  $v       validate() result.
	 * @param array  $context actor, source (site label), at_iys (bool).
	 * @param bool   $dry     Report only.
	 * @return string imported | unchanged | older | kept_ret | busy | error
	 */
	public static function apply( array $v, array $context, $dry = false ) {
		$row = Eru_CMI_Repository::get( $v['recipient'], $v['type'] );
		if ( $row && 'RET' === $row->status && 'ONAY' === $v['status'] && 'iys' !== ( $context['actor'] ?? '' ) ) {
			return 'kept_ret';
		}
		if ( $row && (int) strtotime( $row->consent_date . ' UTC' ) > $v['ts'] ) {
			return 'older';
		}
		if ( $row && $row->status === $v['status'] && ( '' === $v['recipient_type'] || $v['recipient_type'] === $row->recipient_type ) ) {
			return 'unchanged';
		}
		if ( $dry ) {
			return 'imported';
		}
		$ctx = array(
			'actor'        => (string) ( $context['actor'] ?? 'import' ),
			'source'       => (string) ( $context['source'] ?? 'import' ),
			'actor_id'     => get_current_user_id(),
			'consent_date' => $v['ts'],
			'iys_source'   => $v['iys_source'],
		);
		if ( '' !== $v['recipient_type'] ) {
			$ctx['recipient_type'] = $v['recipient_type'];
		}
		if ( ! empty( $context['at_iys'] ) ) {
			$ctx['mark_accepted'] = true;
		}
		$r = Eru_CMI_Repository::set( $v['recipient'], $v['type'], $v['status'], $ctx );
		if ( is_wp_error( $r ) ) {
			$code = $r->get_error_code();
			return 'eru_cmi_reconsent_required' === $code ? 'kept_ret' : ( 'eru_cmi_busy' === $code ? 'busy' : 'error' );
		}
		return 'imported';
	}

	/** @return array Empty counters. */
	private static function counts() {
		return array(
			'lines'     => 0,
			'imported'  => 0,
			'unchanged' => 0,
			'older'     => 0,
			'kept_ret'  => 0,
			'errors'    => array(),
		);
	}

	/**
	 * Imports a CSV file (header row; comma, semicolon or tab separated; UTF-8 with or without BOM).
	 *
	 * @param string $path File.
	 * @param array  $opts at_iys (bool), max_lines (int, 0 = no limit), dry (bool).
	 * @return array{lines:int,imported:int,unchanged:int,older:int,kept_ret:int,errors:array} errors: list of
	 *               {line, code, message}; line 1 is the header.
	 */
	public static function csv( $path, array $opts = array() ) {
		$out = self::counts();
		$fh  = is_readable( $path ) ? fopen( $path, 'r' ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! $fh ) {
			$out['errors'][] = array( 'line' => 0, 'code' => 'file', 'message' => __( 'Dosya okunamadı.', 'consent-manager-for-iys' ) );
			return $out;
		}
		try {
			$first = (string) fgets( $fh );
			$first = preg_replace( '/^\xEF\xBB\xBF/', '', $first );
			$delim = self::delimiter( $first );
			$head  = array_map( fn( $h ) => strtolower( trim( (string) $h ) ), str_getcsv( trim( $first ), $delim, '"', '' ) );
			if ( array_diff( self::REQUIRED, $head ) ) {
				$out['errors'][] = array(
					'line'    => 1,
					'code'    => 'header',
					/* translators: %s: column names */
					'message' => sprintf( __( 'Başlık satırında şu sütunlar olmalı: %s', 'consent-manager-for-iys' ), implode( ', ', self::REQUIRED ) ),
				);
				return $out;
			}
			$max  = max( 0, (int) ( $opts['max_lines'] ?? 0 ) );
			$line = 1;
			while ( false !== ( $cells = fgetcsv( $fh, 0, $delim, '"', '' ) ) ) {
				++$line;
				if ( array( null ) === $cells || ( 1 === count( $cells ) && '' === trim( (string) $cells[0] ) ) ) {
					continue;
				}
				++$out['lines'];
				if ( $max && $out['lines'] > $max ) {
					$out['errors'][] = array(
						'line'    => $line,
						'code'    => 'limit',
						/* translators: %d: maximum lines */
						'message' => sprintf( __( 'En fazla %d satır işlenir; kalanlar için WP-CLI kullanın (wp eru-cmi import).', 'consent-manager-for-iys' ), $max ),
					);
					--$out['lines'];
					break;
				}
				$rec = array();
				foreach ( $head as $i => $h ) {
					$rec[ $h ] = isset( $cells[ $i ] ) ? (string) $cells[ $i ] : '';
				}
				self::one( $rec, $line, true, array( 'actor' => 'import', 'source' => 'import', 'at_iys' => ! empty( $opts['at_iys'] ) ), ! empty( $opts['dry'] ), $out );
			}
		} finally {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		return $out;
	}

	/**
	 * Validates and stores one record, counting the outcome.
	 *
	 * @param array $rec    Record.
	 * @param int   $where  Line number (or index).
	 * @param bool  $strict Strict İYS source.
	 * @param array $ctx    apply() context.
	 * @param bool  $dry    Report only.
	 * @param array $out    Counters.
	 */
	private static function one( array $rec, $where, $strict, array $ctx, $dry, array &$out ) {
		$v = self::validate( $rec, $strict );
		if ( is_wp_error( $v ) ) {
			$out['errors'][] = array( 'line' => (int) $where, 'code' => $v->get_error_code(), 'message' => $v->get_error_message() );
			return;
		}
		$r = self::apply( $v, $ctx, $dry );
		if ( isset( $out[ $r ] ) && is_int( $out[ $r ] ) ) {
			++$out[ $r ];
		} else {
			$out['errors'][] = array(
				'line'    => (int) $where,
				'code'    => $r,
				'message' => 'busy' === $r ? __( 'Alıcı için başka bir değişiklik sürüyordu; satırı yeniden içe aktarın.', 'consent-manager-for-iys' ) : __( 'Kayıt yazılamadı.', 'consent-manager-for-iys' ),
			);
		}
	}

	/** @param string $line Header line. @return string Separator. */
	private static function delimiter( $line ) {
		$best = ',';
		$max  = substr_count( $line, ',' );
		foreach ( array( ';', "\t" ) as $d ) {
			if ( substr_count( $line, $d ) > $max ) {
				$best = $d;
				$max  = substr_count( $line, $d );
			}
		}
		return $best;
	}

	/**
	 * Neutralises a spreadsheet formula: a cell starting with = + - @, a tab or a carriage return gets a
	 * leading apostrophe.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	public static function cell( $v ) {
		$v = (string) $v;
		return ( '' !== $v && false !== strpos( "=+-@\t\r", $v[0] ) ) ? "'" . $v : $v;
	}

	/**
	 * Writes the store as CSV (selected environment for the İYS column), 1000 rows per query.
	 *
	 * @param resource $fh   Stream.
	 * @param bool     $full Full addresses (otherwise masked).
	 * @return int Rows written.
	 */
	public static function export( $fh, $full = false ) {
		global $wpdb;
		$env = Eru_CMI_Settings::env();
		$c   = Eru_CMI_Installer::table( 'consents' );
		$q   = Eru_CMI_Installer::table( 'queue' );
		fputcsv( $fh, array( 'recipient', 'type', 'status', 'consent_date', 'source', 'recipient_type', 'iys_status' ), ',', '"', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$last = 0;
		$n    = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT c.*, ( SELECT q.status FROM {$q} q WHERE q.consent_id = c.id AND q.env = %s ORDER BY q.id DESC LIMIT 1 ) AS env_status FROM {$c} c WHERE c.id > %d ORDER BY c.id ASC LIMIT 1000", $env, $last ) ) ?: array(); // phpcs:ignore
			foreach ( $rows as $r ) {
				$last = (int) $r->id;
				if ( Eru_CMI_Repository::is_placeholder( $r ) ) {
					$who = __( '(anonimleştirildi)', 'consent-manager-for-iys' );
				} else {
					$who = $full ? $r->recipient : Eru_CMI_Recipient::mask( $r->recipient );
				}
				$iys = (string) $r->env_status;
				if ( '' === $iys && false !== strpos( (string) $r->iys_known, ',' . $env . ',' ) ) {
					$iys = 'accepted';
				}
				fputcsv( // phpcs:ignore WordPress.WP.AlternativeFunctions
					$fh,
					array_map( array( __CLASS__, 'cell' ), array( $who, $r->type, $r->status, Eru_CMI_Rules::iys_date( (int) strtotime( $r->consent_date . ' UTC' ) ), $r->source, $r->recipient_type, $iys ) ),
					',',
					'"',
					''
				);
				++$n;
			}
		} while ( count( $rows ) === 1000 );
		return $n;
	}

	/**
	 * Columns of a table that may be imported: a plain name in this database, not one of this plugin's tables.
	 *
	 * @param string $table Table name.
	 * @return string[]|WP_Error Column names.
	 */
	public static function table_columns( $table ) {
		global $wpdb;
		$table = (string) $table;
		if ( ! preg_match( '/^[A-Za-z0-9_]{1,64}$/', $table ) ) {
			return new WP_Error( 'eru_cmi_table', __( 'Tablo adı yalnızca harf, rakam ve alt çizgi içerebilir.', 'consent-manager-for-iys' ) );
		}
		if ( 0 === strpos( $table, $wpdb->prefix . 'eru_cmi_' ) ) {
			return new WP_Error( 'eru_cmi_table', __( 'Bu eklentinin kendi tabloları içe aktarılamaz.', 'consent-manager-for-iys' ) );
		}
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) { // phpcs:ignore
			return new WP_Error( 'eru_cmi_table', __( 'Tablo bulunamadı.', 'consent-manager-for-iys' ) );
		}
		$cols = array();
		foreach ( (array) $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`" ) as $c ) { // phpcs:ignore
			$cols[ (string) $c->Field ] = strtolower( (string) $c->Type ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}
		self::$types[ $table ] = $cols;
		return array_keys( $cols );
	}

	/** @var array<string,array<string,string>> Column types per table (filled by table_columns()). */
	private static $types = array();

	/**
	 * Checks a column mapping against the table.
	 *
	 * @param string $table Table.
	 * @param array  $map   Field => column for id, recipient, status, consent_date (required), type, source,
	 *                      recipient_type (optional); fixed_type = EPOSTA | MESAJ | ARAMA when no column holds
	 *                      the channel.
	 * @return array|WP_Error The normalised mapping (only mapped fields, plus fixed_type when used).
	 */
	public static function table_check( $table, array $map ) {
		$cols = self::table_columns( $table );
		if ( is_wp_error( $cols ) ) {
			return $cols;
		}
		$by_lower = array();
		foreach ( $cols as $c ) {
			$by_lower[ strtolower( $c ) ] = $c;
		}
		$out = array();
		foreach ( array_merge( self::MAP_REQUIRED, self::MAP_OPTIONAL ) as $field ) {
			$col = trim( (string) ( $map[ $field ] ?? '' ) );
			if ( '' === $col ) {
				if ( in_array( $field, self::MAP_REQUIRED, true ) ) {
					/* translators: %s: field name */
					return new WP_Error( 'eru_cmi_map', sprintf( __( 'Şu alan için bir sütun seçin: %s', 'consent-manager-for-iys' ), $field ) );
				}
				continue;
			}
			if ( ! isset( $by_lower[ strtolower( $col ) ] ) ) {
				/* translators: %s: column name */
				return new WP_Error( 'eru_cmi_map', sprintf( __( 'Tabloda böyle bir sütun yok: %s', 'consent-manager-for-iys' ), sanitize_text_field( $col ) ) );
			}
			$out[ $field ] = $by_lower[ strtolower( $col ) ];
		}
		if ( ! preg_match( '/^(tiny|small|medium|big)?int\b/', self::$types[ $table ][ $out['id'] ] ?? '' ) ) {
			return new WP_Error( 'eru_cmi_map', __( 'Sıra sütunu tam sayı olmalı (ör. otomatik artan birincil anahtar).', 'consent-manager-for-iys' ) );
		}
		if ( ! isset( $out['type'] ) ) {
			$fixed = Eru_CMI_Recipient::type( (string) ( $map['fixed_type'] ?? '' ) );
			if ( '' === $fixed ) {
				return new WP_Error( 'eru_cmi_map', __( 'Kanal için bir sütun seçin ya da tüm satırlar için bir kanal belirleyin.', 'consent-manager-for-iys' ) );
			}
			$out['fixed_type'] = $fixed;
		}
		return $out;
	}

	/**
	 * @param string $table Table.
	 * @param array  $map   Mapping (table_check() result).
	 * @param bool   $dry   Report only.
	 * @return array New job.
	 */
	public static function table_job( $table, array $map, $dry ) {
		return array(
			'table'     => (string) $table,
			'map'       => $map,
			'dry'       => (bool) $dry,
			'cursor'    => 0,
			'done'      => false,
			'started'   => time(),
			'finished'  => 0,
			'read'      => 0,
			'imported'  => 0,
			'unchanged' => 0,
			'older'     => 0,
			'kept_ret'  => 0,
			'invalid'   => 0,
			'failed'    => 0,
		);
	}

	/**
	 * One step of a table import: the latest row (highest order column) of each recipient and channel after
	 * the cursor.
	 *
	 * @param array $job   Job (table_job()).
	 * @param int   $limit Rows.
	 * @return array Updated job.
	 */
	public static function table_step( array $job, $limit = self::STEP ) {
		global $wpdb;
		$map = empty( $job['done'] ) ? self::table_check( (string) $job['table'], (array) ( $job['map'] ?? array() ) ) : null;
		if ( ! is_array( $map ) ) {
			$job['done'] = true;
			return $job;
		}
		$t   = $job['table'];
		$q   = fn( $f ) => '`' . str_replace( '`', '``', $map[ $f ] ) . '`'; // Names come from SHOW COLUMNS (checked above); a backtick inside is doubled.
		$sel = array( 't.' . $q( 'id' ) . ' AS id', 't.' . $q( 'recipient' ) . ' AS recipient', 't.' . $q( 'status' ) . ' AS status', 't.' . $q( 'consent_date' ) . ' AS consent_date' );
		foreach ( self::MAP_OPTIONAL as $f ) {
			if ( isset( $map[ $f ] ) ) {
				$sel[] = 't.' . $q( $f ) . ' AS ' . $f;
			}
		}
		$group = 'LOWER(TRIM(' . $q( 'recipient' ) . '))' . ( isset( $map['type'] ) ? ', UPPER(TRIM(' . $q( 'type' ) . '))' : '' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT ' . implode( ', ', $sel ) . " FROM `{$t}` t JOIN ( SELECT MAX(" . $q( 'id' ) . ") AS mid FROM `{$t}` GROUP BY {$group} ) m ON m.mid = t." . $q( 'id' ) . ' WHERE t.' . $q( 'id' ) . ' > %d ORDER BY t.' . $q( 'id' ) . ' ASC LIMIT %d', (int) $job['cursor'], max( 1, (int) $limit ) ), ARRAY_A ) ?: array(); // phpcs:ignore
		$ctx   = array( 'actor' => 'import', 'source' => 'table' );
		foreach ( $rows as $r ) {
			$job['cursor'] = (int) $r['id'];
			++$job['read'];
			if ( isset( $map['fixed_type'] ) ) {
				$r['type'] = $map['fixed_type'];
			}
			$v = self::validate( $r, false );
			if ( is_wp_error( $v ) ) {
				++$job['invalid'];
				continue;
			}
			$res = self::apply( $v, $ctx, ! empty( $job['dry'] ) );
			++$job[ isset( $job[ $res ] ) && 'done' !== $res ? $res : 'failed' ];
		}
		if ( count( $rows ) < max( 1, (int) $limit ) ) {
			$job['done']     = true;
			$job['finished'] = time();
		}
		return $job;
	}

	/**
	 * Full table import in this process (WP-CLI, tests).
	 *
	 * @param string $table Table.
	 * @param array  $map   Column mapping (see table_check()).
	 * @param bool   $dry   Report only (writes nothing).
	 * @return array|WP_Error Final counts.
	 */
	public static function table_import( $table, array $map, $dry = false ) {
		$map = self::table_check( $table, $map );
		if ( is_wp_error( $map ) ) {
			return $map;
		}
		$job = self::table_job( $table, $map, $dry );
		for ( $i = 0; $i < 100000 && empty( $job['done'] ); $i++ ) {
			$job = self::table_step( $job );
		}
		return $job;
	}

	/**
	 * Background step of the job started in Araçlar (option JOB, one at a time).
	 *
	 * @return array|null The job after this step.
	 */
	public static function table_job_step() {
		$owner = Eru_CMI_Lock::acquire( 'table', 10 * MINUTE_IN_SECONDS );
		if ( false === $owner ) {
			return null;
		}
		try {
			$job = get_option( self::JOB, null );
			if ( ! is_array( $job ) || ! empty( $job['done'] ) ) {
				return is_array( $job ) ? $job : null;
			}
			$job = self::table_step( $job );
			update_option( self::JOB, $job, false );
			return $job;
		} finally {
			Eru_CMI_Lock::release( 'table', $owner );
		}
	}
}
