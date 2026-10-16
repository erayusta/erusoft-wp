<?php
/**
 * Consent Manager for İYS — admin handlers (capability, nonce, note), CSV import/export, consent-log table import,
 * uninstall, and the Task 2 carry-overs: erasure voids earlier confirmation links, confirmation-mail limits
 * and release, no confirmation mail from a capture retry after an opt-out.
 *
 *   wp eval-file tests/php/cmi-admin.php
 */

require __DIR__ . '/cmi-helpers.php';
cmi_require_plugin();
foreach ( array( 'Eru_CMI_Import', 'Eru_CMI_Links', 'Eru_CMI_Privacy', 'Eru_CMI_Capture_Checkout', 'Eru_CMI_Account' ) as $cmi_c ) {
	if ( ! class_exists( $cmi_c ) ) {
		echo "FAIL: {$cmi_c} missing\n";
		exit( 1 );
	}
}
require_once ERU_CMI_PATH . 'includes/class-eru-cmi-admin.php';
$fake = cmi_fake_iys();

global $wpdb;
$old_settings = get_option( Eru_CMI_Settings::OPTION, null );
$failed       = false;
$mails        = cmi_mail_trap();
$tmp          = array();
$logtable       = $wpdb->prefix . 'cmi_log_demo';
$emails       = array( 'adm-er@example.com', 'adm-retry@example.com', 'adm-acc@example.com', 'adm-sub@example.com' );
$ut_prefix    = $wpdb->base_prefix . 'cmiu_';
$wpdb->query( "DROP TABLE IF EXISTS {$logtable}" ); // phpcs:ignore -- leftovers of an aborted run.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cmi_tick_demo" ); // phpcs:ignore
foreach ( array( 'consents', 'history', 'queue', 'log' ) as $cmi_t ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$ut_prefix}eru_cmi_{$cmi_t}" ); // phpcs:ignore
}

$status = function ( $r, $t ) {
	$row = Eru_CMI_Repository::get( $r, $t );
	return $row ? $row->status : null;
};

/** Writes a temporary CSV. */
$csv = function ( array $lines ) use ( &$tmp ) {
	$f     = wp_tempnam( 'cmi-import' );
	$tmp[] = $f;
	file_put_contents( $f, implode( "\n", $lines ) . "\n" );
	return $f;
};

$tr  = new DateTimeImmutable( 'now', new DateTimeZone( 'Europe/Istanbul' ) );
$now = $tr->format( 'Y-m-d H:i:s' );

try {
	cmi_reset();
	cmi_cleanup_people( $emails );
	cmi_settings();
	cmi_fingerprint( 'admin' );
	$admin = (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 );
	$sub   = cmi_user( 'adm-sub@example.com', 'Demo Abone' );

	// (c) CSV import: 3 good lines + 1 bad → 3 rows and an error with its line number.
	$f = $csv(
		array(
			'recipient,type,status,consent_date,source,recipient_type',
			"csv1@example.com,EPOSTA,ONAY,{$now},HS_WEB,BIREYSEL",
			cmi_phone() . ",MESAJ,ONAY,{$now},HS_FIZIKSEL_ORTAM,",
			"csv2@example.com,eposta,ret,{$now},,",
			"csv-bad,EPOSTA,ONAY,{$now},HS_WEB,BIREYSEL",
		)
	);
	$r = Eru_CMI_Import::csv( $f );
	cmi_check( '(c) CSV: 3 good lines imported, 1 error', 3 === $r['imported'] && 1 === count( $r['errors'] ) && 3 === cmi_count( 'consents' ) );
	cmi_check( '(c) CSV: the error names line 5 and carries no address', 5 === (int) $r['errors'][0]['line'] && 'recipient' === $r['errors'][0]['code'] && false === strpos( wp_json_encode( $r['errors'] ), 'csv-bad' ) );
	$h = Eru_CMI_History::for_consent( (int) Eru_CMI_Repository::get( 'csv1@example.com', 'EPOSTA' )->id );
	cmi_check( '(c) CSV rows: actor import, the date from the file', 'import' === end( $h )->actor && $tr->getTimestamp() === strtotime( Eru_CMI_Repository::get( 'csv1@example.com', 'EPOSTA' )->consent_date . ' UTC' ) );
	$f = $csv(
		array(
			'recipient;type;status;consent_date',
			'csv3@example.com;EPOSTA;ONAY;',
			'csv4@example.com;EPOSTA;ONAY;dün',
		)
	);
	$r = Eru_CMI_Import::csv( $f );
	cmi_check( '(c) CSV: a missing or unreadable consent date is refused (no date is made up)', 0 === $r['imported'] && array( 'date_required', 'date' ) === wp_list_pluck( $r['errors'], 'code' ) && null === $status( 'csv3@example.com', 'EPOSTA' ) );
	$f = $csv( array( 'recipient,status,consent_date', "csv5@example.com,ONAY,{$now}" ) );
	$r = Eru_CMI_Import::csv( $f );
	cmi_check( '(c) CSV: a header without the required columns is refused as a whole', 0 === $r['imported'] && 'header' === ( $r['errors'][0]['code'] ?? '' ) );
	Eru_CMI_Repository::set( 'csv2@example.com', 'EPOSTA', 'RET', array( 'source' => 'test' ) );
	$f = $csv( array( 'recipient,type,status,consent_date', 'csv2@example.com,EPOSTA,ONAY,' . $tr->modify( '+1 second' )->format( 'Y-m-d H:i:s' ) ) );
	$r = Eru_CMI_Import::csv( $f );
	cmi_check( '(c) CSV: an import never lifts a RET', 'RET' === $status( 'csv2@example.com', 'EPOSTA' ) && 1 === $r['kept_ret'] && 0 === $r['imported'] );

	// (c) Export: masked by default, full only on request; cells starting with = + - @ are neutralised.
	Eru_CMI_Repository::set( '=1+1@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	$fh = fopen( 'php://temp', 'w+' );
	Eru_CMI_Import::export( $fh );
	rewind( $fh );
	$masked = stream_get_contents( $fh );
	fclose( $fh );
	cmi_check( '(c) export (default): masked recipients, no full address or phone', false !== strpos( $masked, 'cs***@example.com' ) && false === strpos( $masked, 'csv1@example.com' ) && false === strpos( $masked, '5550000000' ) );
	cmi_check( '(c) export: header row', 0 === strpos( $masked, 'recipient,type,status,consent_date,source,recipient_type,iys_status' ) );
	$fh = fopen( 'php://temp', 'w+' );
	Eru_CMI_Import::export( $fh, true );
	rewind( $fh );
	$full = stream_get_contents( $fh );
	fclose( $fh );
	cmi_check( '(c) export (full): addresses in full', false !== strpos( $full, 'csv1@example.com' ) );
	cmi_check( '(c) export: a cell starting with = is escaped', false !== strpos( $full, "'=1+1@example.com" ) && false === strpos( $full, ',=1+1@' ) && "'=1" === Eru_CMI_Import::cell( '=1' ) && "'+90" === Eru_CMI_Import::cell( '+90' ) && "'@x" === Eru_CMI_Import::cell( '@x' ) && "'-1" === Eru_CMI_Import::cell( '-1' ) && 'ok' === Eru_CMI_Import::cell( 'ok' ) );
	cmi_check( '(c) export: a phone in full export is escaped too (starts with +)', false !== strpos( $full, "'" . cmi_phone_e164() ) );

	// (d) Append-only consent-log table with its own column names: the admin maps the columns; the last row per
	// (recipient, channel) wins.
	cmi_reset();
	cmi_settings();
	$charset = $wpdb->get_charset_collate();
	$wpdb->query( "CREATE TABLE {$logtable} ( row_no bigint(20) unsigned NOT NULL AUTO_INCREMENT, contact varchar(190) NOT NULL, channel varchar(10) NOT NULL, state varchar(4) NOT NULL, origin varchar(40) NOT NULL DEFAULT '', kind varchar(10) NOT NULL DEFAULT 'BIREYSEL', given_at datetime NOT NULL, note varchar(20) NOT NULL DEFAULT '', PRIMARY KEY (row_no) ) {$charset}" ); // phpcs:ignore
	$d1 = $tr->modify( '-2 hours' )->format( 'Y-m-d H:i:s' );
	$d2 = $tr->modify( '-1 hour' )->format( 'Y-m-d H:i:s' );
	foreach ( array(
		array( 'leg-a@example.com', 'EPOSTA', 'ONAY', 'web', 'BIREYSEL', $d1 ),
		array( 'leg-b@example.com', 'EPOSTA', 'ONAY', 'web', 'BIREYSEL', $d1 ),
		array( 'LEG-A@example.com', 'EPOSTA', 'RET', 'form', 'BIREYSEL', $d2 ),
		array( cmi_phone(), 'MESAJ', 'ONAY', 'web', 'BIREYSEL', $d2 ),
		array( 'leg-ret@example.com', 'EPOSTA', 'ONAY', 'web', 'BIREYSEL', $d2 ),
		array( 'not-valid', 'EPOSTA', 'ONAY', 'web', 'BIREYSEL', $d2 ),
	) as $row ) {
		$wpdb->insert( $logtable, array_combine( array( 'contact', 'channel', 'state', 'origin', 'kind', 'given_at' ), $row ) ); // phpcs:ignore
	}
	Eru_CMI_Repository::set( 'leg-ret@example.com', 'EPOSTA', 'RET', array( 'source' => 'test' ) );
	$map = array( 'id' => 'row_no', 'recipient' => 'contact', 'type' => 'channel', 'status' => 'state', 'consent_date' => 'given_at', 'source' => 'origin', 'recipient_type' => 'kind' );
	$cols = Eru_CMI_Import::table_columns( $logtable );
	cmi_check( '(d) the table\'s columns are listed for the mapping step', is_array( $cols ) && in_array( 'contact', $cols, true ) && in_array( 'given_at', $cols, true ) && in_array( 'note', $cols, true ) );
	cmi_check( '(d) refused: own tables, bad names, missing tables', is_wp_error( Eru_CMI_Import::table_columns( $wpdb->prefix . 'eru_cmi_log' ) ) && is_wp_error( Eru_CMI_Import::table_columns( 'no such; table' ) ) && is_wp_error( Eru_CMI_Import::table_columns( $wpdb->prefix . 'cmi_no_such_table' ) ) );
	cmi_check( '(d) refused mappings: a required field unmapped, a column the table lacks, a non-numeric order column', is_wp_error( Eru_CMI_Import::table_check( $logtable, array_diff_key( $map, array( 'status' => 1 ) ) ) ) && is_wp_error( Eru_CMI_Import::table_check( $logtable, array( 'recipient' => 'nope' ) + $map ) ) && is_wp_error( Eru_CMI_Import::table_check( $logtable, array( 'id' => 'contact' ) + $map ) ) );
	cmi_check( '(d) channel: a column or one fixed channel for every row, not neither', is_wp_error( Eru_CMI_Import::table_check( $logtable, array_diff_key( $map, array( 'type' => 1 ) ) ) ) && is_array( Eru_CMI_Import::table_check( $logtable, array_diff_key( $map, array( 'type' => 1 ) ) + array( 'fixed_type' => 'EPOSTA' ) ) ) && is_array( Eru_CMI_Import::table_check( $logtable, $map ) ) );
	$counts = array( cmi_count( 'consents' ), cmi_count( 'history' ), cmi_count( 'queue' ) );
	$dry    = Eru_CMI_Import::table_import( $logtable, $map, true );
	cmi_check( '(d) --dry-run writes nothing and reports what it would do', $counts === array( cmi_count( 'consents' ), cmi_count( 'history' ), cmi_count( 'queue' ) ) && 3 === $dry['imported'] && 1 === $dry['kept_ret'] && 1 === $dry['invalid'] );
	$res = Eru_CMI_Import::table_import( $logtable, $map );
	cmi_check( '(d) last row per recipient and channel wins: leg-a RET, leg-b ONAY, phone ONAY', 'RET' === $status( 'leg-a@example.com', 'EPOSTA' ) && 'ONAY' === $status( 'leg-b@example.com', 'EPOSTA' ) && 'ONAY' === $status( cmi_phone(), 'MESAJ' ) && 3 === $res['imported'] );
	$ha = Eru_CMI_History::for_consent( (int) Eru_CMI_Repository::get( 'leg-a@example.com', 'EPOSTA' )->id );
	cmi_check( '(d) one history row, actor import, source "table"', 1 === count( $ha ) && 'import' === $ha[0]->actor && 'table' === $ha[0]->source );
	cmi_check( '(d) the importer never lifts a RET (leg-ret stays RET)', 'RET' === $status( 'leg-ret@example.com', 'EPOSTA' ) && 1 === $res['kept_ret'] );
	$counts = array( cmi_count( 'consents' ), cmi_count( 'history' ), cmi_count( 'queue' ) );
	$again  = Eru_CMI_Import::table_import( $logtable, $map );
	cmi_check( '(d) a second run imports nothing new', 0 === $again['imported'] && $counts === array( cmi_count( 'consents' ), cmi_count( 'history' ), cmi_count( 'queue' ) ) );
	// A table holding e-mail addresses only: no channel column, the admin picks EPOSTA for every row.
	cmi_reset();
	cmi_settings();
	$fixed = array( 'id' => 'row_no', 'recipient' => 'contact', 'status' => 'state', 'consent_date' => 'given_at', 'fixed_type' => 'EPOSTA' );
	$wpdb->query( "DELETE FROM {$logtable} WHERE channel <> 'EPOSTA'" ); // phpcs:ignore
	$res = Eru_CMI_Import::table_import( $logtable, $fixed );
	cmi_check( '(d) fixed channel: the e-mail rows are imported on EPOSTA, source falls back to HS_WEB', 3 === $res['imported'] && 'RET' === $status( 'leg-a@example.com', 'EPOSTA' ) && 'ONAY' === $status( 'leg-ret@example.com', 'EPOSTA' ) && false !== strpos( (string) cmi_queue_rows()[0]->payload, 'HS_WEB' ) );
	// A column name with a backtick is quoted safely.
	$tick = $wpdb->prefix . 'cmi_tick_demo';
	$wpdb->query( "DROP TABLE IF EXISTS {$tick}" ); // phpcs:ignore
	$wpdb->query( "CREATE TABLE {$tick} ( n bigint(20) unsigned NOT NULL AUTO_INCREMENT, `e``mail` varchar(190) NOT NULL, st varchar(4) NOT NULL, d datetime NOT NULL, PRIMARY KEY (n) ) {$charset}" ); // phpcs:ignore
	$wpdb->query( $wpdb->prepare( "INSERT INTO {$tick} ( `e``mail`, st, d ) VALUES ( %s, 'ONAY', %s )", 'tick@example.com', $d2 ) ); // phpcs:ignore
	$tres = Eru_CMI_Import::table_import( $tick, array( 'id' => 'n', 'recipient' => 'e`mail', 'status' => 'st', 'consent_date' => 'd', 'fixed_type' => 'EPOSTA' ) );
	$wpdb->query( "DROP TABLE IF EXISTS {$tick}" ); // phpcs:ignore
	cmi_check( '(d) a column name containing a backtick is escaped, not broken out of', is_array( $tres ) && 1 === $tres['imported'] && 'ONAY' === $status( 'tick@example.com', 'EPOSTA' ) );
	// The admin form: the mapping comes from the request; the job keeps it.
	wp_set_current_user( (int) ( get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) )[0] ?? 0 ) );
	delete_option( 'eru_cmi_table_job' );
	$job = Eru_CMI_Admin::table_request( array( 'table' => $logtable, 'map' => $map, 'dry' => '1', '_wpnonce' => wp_create_nonce( 'eru_cmi_table' ) ) );
	cmi_check( '(d) admin: the import job is queued with the mapping (dry run)', is_array( $job ) && ( function ( $a, $b ) { ksort( $a ); ksort( $b ); return $a === $b; } )( $map, $job['map'] ) && true === $job['dry'] && 1 === count( cmi_pending( 'eru_cmi_table_import' ) ) );
	$bad = Eru_CMI_Admin::table_request( array( 'table' => $logtable, 'map' => array( 'recipient' => 'nope' ) + $map, '_wpnonce' => wp_create_nonce( 'eru_cmi_table' ) ) );
	cmi_check( '(d) admin: a bad mapping is refused with 400', is_wp_error( $bad ) && 400 === ( $bad->get_error_data()['status'] ?? 0 ) );
	wp_set_current_user( 0 );
	delete_option( 'eru_cmi_table_job' );
	Eru_CMI_Scheduler::unschedule_all();

	// (f) Admin handlers: refused without the capability; bulk retry with a nonce re-queues rows.
	cmi_reset();
	cmi_settings();
	Eru_CMI_Repository::set( 'q1@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	Eru_CMI_Repository::set( 'q2@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
	$wpdb->query( 'UPDATE ' . $wpdb->prefix . "eru_cmi_queue SET status = 'failed', attempts = 5, next_try_at = NULL" ); // phpcs:ignore
	$ids = array_map( 'intval', wp_list_pluck( cmi_queue_rows(), 'id' ) );
	wp_set_current_user( $sub );
	$bulk = Eru_CMI_Admin::queue_bulk( array( 'bulk' => 'retry', 'ids' => $ids, '_wpnonce' => wp_create_nonce( Eru_CMI_Admin::BULK_NONCE ) ) );
	$cid  = (int) Eru_CMI_Repository::get( 'q1@example.com', 'EPOSTA' )->id;
	$chg  = Eru_CMI_Admin::change_consent( array( 'consent' => $cid, 'status' => 'RET', 'note' => 'Demo not', '_wpnonce' => wp_create_nonce( 'eru_cmi_change_' . $cid ) ) );
	$imp  = Eru_CMI_Admin::import_request( array( '_wpnonce' => wp_create_nonce( 'eru_cmi_import' ) ), array() );
	$tbl  = Eru_CMI_Admin::table_request( array( 'table' => $logtable, 'map' => array(), '_wpnonce' => wp_create_nonce( 'eru_cmi_table' ) ) );
	$key  = Eru_CMI_Admin::keys_request( array( '_wpnonce' => wp_create_nonce( 'eru_cmi_keys' ) ) );
	$exp  = Eru_CMI_Admin::export_request( array( '_wpnonce' => wp_create_nonce( 'eru_cmi_export' ) ) );
	$act  = Eru_CMI_Admin::action_request( array( 'do' => 'pull', '_wpnonce' => wp_create_nonce( 'eru_cmi_action' ) ) );
	cmi_check( '(f) without manage_woocommerce every handler is refused (403)', is_wp_error( $bulk ) && is_wp_error( $chg ) && is_wp_error( $imp ) && is_wp_error( $tbl ) && is_wp_error( $key ) && is_wp_error( $exp ) && is_wp_error( $act ) && 403 === ( $bulk->get_error_data()['status'] ?? 0 ) );
	cmi_check( '(f) ... and nothing changed', 2 === cmi_count( 'queue', "status = 'failed'" ) && 'ONAY' === $status( 'q1@example.com', 'EPOSTA' ) && '' === Eru_CMI_Settings::get( 'ext_key_hash' ) && false === get_option( 'eru_cmi_table_job' ) );
	wp_set_current_user( $admin );
	$bad = Eru_CMI_Admin::queue_bulk( array( 'bulk' => 'retry', 'ids' => $ids, '_wpnonce' => 'nope' ) );
	cmi_check( '(f) admin with a bad nonce → refused, nothing re-queued', is_wp_error( $bad ) && 2 === cmi_count( 'queue', "status = 'failed'" ) );
	$n = Eru_CMI_Admin::queue_bulk( array( 'bulk' => 'retry', 'ids' => $ids, '_wpnonce' => wp_create_nonce( Eru_CMI_Admin::BULK_NONCE ) ) );
	cmi_check( '(f) admin bulk retry with a nonce → both rows queued again with 0 attempts', 2 === $n && 2 === cmi_count( 'queue', "status = 'queued' AND attempts = 0" ) );
	$n = Eru_CMI_Admin::queue_bulk( array( 'bulk' => 'skip', 'ids' => array( $ids[0] ), '_wpnonce' => wp_create_nonce( Eru_CMI_Admin::BULK_NONCE ) ) );
	cmi_check( '(f) bulk skip closes a row without sending it', 1 === $n && 'skipped' === cmi_queue_rows( 'id = ' . $ids[0] )[0]->status );
	$chg = Eru_CMI_Admin::change_consent( array( 'consent' => $cid, 'status' => 'RET', 'note' => '', '_wpnonce' => wp_create_nonce( 'eru_cmi_change_' . $cid ) ) );
	cmi_check( '(f) an admin change without a note is refused', is_wp_error( $chg ) && 'ONAY' === $status( 'q1@example.com', 'EPOSTA' ) );
	$chg = Eru_CMI_Admin::change_consent( array( 'consent' => $cid, 'status' => 'RET', 'note' => 'Demo: müşteri telefonla istedi', 'iys_source' => 'HS_CAGRI_MERKEZI', '_wpnonce' => wp_create_nonce( 'eru_cmi_change_' . $cid ) ) );
	$h   = Eru_CMI_History::for_consent( $cid );
	cmi_check( '(f) admin change with a note: RET, actor admin, note in the history', ! is_wp_error( $chg ) && 'RET' === $status( 'q1@example.com', 'EPOSTA' ) && 'admin' === end( $h )->actor && 'Demo: müşteri telefonla istedi' === end( $h )->note );
	$chg = Eru_CMI_Admin::change_consent( array( 'consent' => $cid, 'status' => 'ONAY', 'note' => 'Demo: ıslak imzalı form', '_wpnonce' => wp_create_nonce( 'eru_cmi_change_' . $cid ) ) );
	cmi_check( '(f) RET → ONAY by an admin with a note is allowed and recorded', ! is_wp_error( $chg ) && 'ONAY' === $status( 'q1@example.com', 'EPOSTA' ) && 'Demo: ıslak imzalı form' === current( array_slice( Eru_CMI_History::for_consent( $cid ), -1 ) )->note );
	$counts = Eru_CMI_Admin::queue_counts( 'live' );
	cmi_check( '(f) queue lists are per environment and know superseded', isset( $counts['superseded'] ) && 0 === array_sum( $counts ) && array_sum( Eru_CMI_Admin::queue_counts( 'sandbox' ) ) > 0 );
	$key = Eru_CMI_Admin::keys_request( array( '_wpnonce' => wp_create_nonce( 'eru_cmi_keys' ) ) );
	cmi_check( '(f) key generation by an admin: shown once, stored hashed, secret kept for signing', is_array( $key ) && 0 === strpos( $key['key'], 'cmi_' ) && hash( 'sha256', $key['key'] ) === Eru_CMI_Settings::get( 'ext_key_hash' ) && $key['secret'] === Eru_CMI_Settings::get( 'ext_secret' ) );

	// Carry-over: erasure voids confirmation links mailed earlier and never restores the address.
	cmi_reset();
	cmi_settings();
	cmi_fingerprint( 'erase' );
	Eru_CMI_Repository::set( 'adm-er@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'actor' => 'user' ) );
	Eru_CMI_Repository::set( 'adm-er@example.com', 'EPOSTA', 'RET', array( 'source' => 'test', 'actor' => 'user' ) );
	$mails->exchangeArray( array() );
	cmi_check( '[confirm] request_confirm → sent', 'sent' === Eru_CMI_Links::request_confirm( 'adm-er@example.com' ) && 1 === count( $mails ) );
	preg_match( '/eru_cmi_confirm=([A-Za-z0-9_\-\.]+)/', (string) $mails[0]['message'], $m );
	$optout = Eru_CMI_Tokens::mint( 'adm-er@example.com', 'EPOSTA' );
	Eru_CMI_Privacy::erase( 'adm-er@example.com' );
	$row = Eru_CMI_Repository::get( 'adm-er@example.com', 'EPOSTA' );
	cmi_check( '[erase] the row is anonymised', Eru_CMI_Repository::is_placeholder( $row ) );
	$page = Eru_CMI_Links::handle( 'POST', array( 'eru_cmi_confirm' => $m[1] ?? '' ) );
	$row  = Eru_CMI_Repository::get( 'adm-er@example.com', 'EPOSTA' );
	cmi_check( '[erase] a confirmation link mailed before the erasure fails and restores nothing', 400 === $page['status'] && 'RET' === $row->status && Eru_CMI_Repository::is_placeholder( $row ) );
	$page = Eru_CMI_Links::handle( 'POST', array( 'eru_cmi_optout' => $optout ) );
	$row  = Eru_CMI_Repository::get( 'adm-er@example.com', 'EPOSTA' );
	cmi_check( '[erase] an earlier opt-out link never restores the erased address', Eru_CMI_Repository::is_placeholder( $row ) && 'RET' === $row->status && false === strpos( $page['html'], 'ad***' ) );

	// Carry-over: the per-client mail limit is checked before the daily marker is taken; a failed mail releases it.
	cmi_reset();
	cmi_settings();
	Eru_CMI_Repository::set( 'adm-acc@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test', 'actor' => 'user' ) );
	Eru_CMI_Repository::set( 'adm-acc@example.com', 'EPOSTA', 'RET', array( 'source' => 'test', 'actor' => 'user' ) );
	cmi_fingerprint( 'limit' );
	for ( $i = 0; $i < Eru_CMI_Rate_Limit::MAIL_LIMIT; $i++ ) {
		Eru_CMI_Rate_Limit::hit( 'mail' );
	}
	$mails->exchangeArray( array() );
	cmi_check( '[confirm] client over the mail limit → limited, no mail', 'limited' === Eru_CMI_Links::request_confirm( 'adm-acc@example.com' ) && 0 === count( $mails ) );
	cmi_fingerprint( 'limit2' );
	$fail_mail = fn() => false;
	add_filter( 'pre_wp_mail', $fail_mail, 20 ); // After the trap: the mail is refused.
	$r1 = Eru_CMI_Links::request_confirm( 'adm-acc@example.com' );
	remove_filter( 'pre_wp_mail', $fail_mail, 20 );
	cmi_check( '[confirm] the limited attempt did not take the daily slot; a failing mail → failed', 'failed' === $r1 );
	$mails->exchangeArray( array() ); // The trap also saw the refused mail.
	$r2 = Eru_CMI_Links::request_confirm( 'adm-acc@example.com' );
	$r3 = Eru_CMI_Links::request_confirm( 'adm-acc@example.com' );
	cmi_check( '[confirm] the failed mail released the daily slot: next → sent, then pending', 'sent' === $r2 && 'pending' === $r3 && 1 === count( $mails ) );
	$u = cmi_user( 'adm-acc@example.com', 'Demo Hesap' );
	wp_set_current_user( $u );
	cmi_collect( array( 'channels' => 'EPOSTA' ) );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'eru\\_cmi\\_lock\\_rc\\_%'" ); // phpcs:ignore
	cmi_fingerprint( 'limit3' );
	add_filter( 'pre_wp_mail', $fail_mail, 20 ); // After the trap: the mail is refused.
	$res = Eru_CMI_Account::process( $u, array( '_eru_cmi_nonce' => wp_create_nonce( Eru_CMI_Account::NONCE ), 'eru_cmi_channels' => array( 'EPOSTA' ) ) );
	remove_filter( 'pre_wp_mail', $fail_mail, 20 );
	cmi_check( '[confirm] Hesabım does not say "pending" when nothing was sent', 'confirm_failed' === ( $res['EPOSTA'] ?? '' ) );
	wp_set_current_user( 0 );

	// Carry-over: a capture retry after the buyer opted out sends no confirmation mail.
	cmi_reset();
	cmi_settings();
	cmi_collect( array( 'channels' => 'EPOSTA' ) );
	cmi_fingerprint( 'retry' );
	$o = wc_create_order();
	$o->set_billing_email( 'adm-retry@example.com' );
	$o->set_billing_first_name( 'Demo' );
	$o->save();
	$_POST['eru_cmi_optin'] = '1';
	do_action( 'woocommerce_checkout_create_order', $o, array() );
	$o->save();
	unset( $_POST['eru_cmi_optin'] );
	$fail = function ( $q ) {
		return ( 0 === stripos( ltrim( $q ), 'INSERT' ) && false !== strpos( $q, 'eru_cmi_consents' ) ) ? 'SELECT * FROM eru_cmi_no_such_table' : $q;
	};
	$prev = $wpdb->suppress_errors( true );
	add_filter( 'query', $fail );
	do_action( 'woocommerce_checkout_order_processed', $o->get_id(), array(), $o );
	remove_filter( 'query', $fail );
	$wpdb->suppress_errors( $prev );
	cmi_check( '[retry] first capture failed: retry scheduled', 1 === count( cmi_pending( 'eru_cmi_capture_order' ) ) && null === $status( 'adm-retry@example.com', 'EPOSTA' ) );
	Eru_CMI_Repository::set( 'adm-retry@example.com', 'EPOSTA', 'RET', array( 'source' => 'link', 'actor' => 'user' ) );
	$mails->exchangeArray( array() );
	do_action( 'eru_cmi_capture_order', $o->get_id() );
	cmi_check( '[retry] opted out before the retry: no confirmation mail, still RET', 0 === count( $mails ) && 'RET' === $status( 'adm-retry@example.com', 'EPOSTA' ) );

	// (g) Uninstall under a test table prefix: without the constant the consent store and history stay.
	$keep_opts = array();
	foreach ( array( 'eru_cmi_settings', 'eru_cmi_hash_salt', 'eru_cmi_token_secret', 'eru_cmi_texts', 'eru_cmi_db_version', 'eru_cmi_active', 'eru_cmi_front' ) as $opt ) {
		$keep_opts[ $opt ] = get_option( $opt, null );
	}
	$real_prefix   = $wpdb->prefix;
	$wpdb->prefix  = $ut_prefix;
	delete_option( 'eru_cmi_db_version' );
	Eru_CMI_Installer::maybe_install();
	$tables = function () use ( $wpdb, $ut_prefix ) {
		$out = array();
		foreach ( array( 'consents', 'history', 'queue', 'log' ) as $t ) {
			$out[ $t ] = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $ut_prefix . 'eru_cmi_' . $t ) ) );
		}
		return $out;
	};
	try {
		if ( 0 !== strpos( $wpdb->prefix, $wpdb->base_prefix . 'cmiu_' ) ) {
			throw new RuntimeException( 'prefix guard' );
		}
		cmi_check( '(g) test tables created under the test prefix', array( 'consents' => true, 'history' => true, 'queue' => true, 'log' => true ) === $tables() );
		Eru_CMI_Repository::set( 'unin@example.com', 'EPOSTA', 'ONAY', array( 'source' => 'test' ) );
		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'consent-manager-for-iys/consent-manager-for-iys.php' );
		}
		include ERU_CMI_PATH . 'uninstall.php';
		cmi_check( '(g) uninstall without ERU_CMI_REMOVE_DATA: queue and log dropped, consents and history kept', array( 'consents' => true, 'history' => true, 'queue' => false, 'log' => false ) === $tables() && 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$ut_prefix}eru_cmi_consents" ) ); // phpcs:ignore
		cmi_check( '(g) ... settings removed, hash salt kept', false === get_option( 'eru_cmi_settings' ) && false !== get_option( 'eru_cmi_hash_salt' ) );
		define( 'ERU_CMI_REMOVE_DATA', true );
		include ERU_CMI_PATH . 'uninstall.php';
		cmi_check( '(g) uninstall with ERU_CMI_REMOVE_DATA: every table dropped, salt and secret removed', array( 'consents' => false, 'history' => false, 'queue' => false, 'log' => false ) === $tables() && false === get_option( 'eru_cmi_hash_salt' ) && false === get_option( 'eru_cmi_token_secret' ) );
	} finally {
		foreach ( array( 'consents', 'history', 'queue', 'log' ) as $t ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$ut_prefix}eru_cmi_{$t}" ); // phpcs:ignore
		}
		$wpdb->prefix = $real_prefix;
		foreach ( $keep_opts as $opt => $val ) {
			if ( null === $val ) {
				delete_option( $opt );
			} else {
				update_option( $opt, $val, in_array( $opt, array( 'eru_cmi_db_version', 'eru_cmi_active', 'eru_cmi_front' ), true ) );
			}
		}
		wp_cache_flush();
	}
} catch ( Throwable $e ) {
	$failed = true;
	if ( ! $e instanceof RuntimeException ) {
		echo 'FAIL: ' . get_class( $e ) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n";
	}
} finally {
	wp_set_current_user( 0 );
	unset( $_POST['eru_cmi_optin'] );
	foreach ( $tmp as $f ) {
		@unlink( $f ); // phpcs:ignore
	}
	$wpdb->query( "DROP TABLE IF EXISTS {$logtable}" ); // phpcs:ignore
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cmi_tick_demo" ); // phpcs:ignore
	cmi_cleanup_people( $emails );
	if ( class_exists( 'Eru_CMI_Scheduler' ) ) {
		cmi_reset();
	}
	delete_option( 'eru_cmi_texts' );
	delete_option( 'eru_cmi_table_job' );
	if ( null === $old_settings ) {
		delete_option( 'eru_cmi_settings' );
	} else {
		update_option( 'eru_cmi_settings', $old_settings, false );
	}
	if ( class_exists( 'Eru_CMI_Settings' ) ) {
		Eru_CMI_Settings::sync_flag();
	}
	Eru_CMI_Rate_Limit::reset();
}
remove_filter( 'pre_http_request', array( $fake, 'route' ), 10 );
if ( $failed ) {
	exit( 1 );
}
echo "ALL PASS: cmi-admin\n";
