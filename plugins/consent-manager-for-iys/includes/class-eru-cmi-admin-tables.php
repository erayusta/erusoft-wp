<?php
/**
 * List tables of the İYS İzinleri screen: consents, audit trail and queue. Recipients are always masked.
 *
 * @package consent-manager-for-iys
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Shared helpers.
 */
trait Eru_CMI_Admin_Table_Helpers {

	/** @param string $utc MySQL UTC time. @return string Site time. */
	protected function when( $utc ) {
		return $utc ? wp_date( 'Y-m-d H:i', (int) strtotime( $utc . ' UTC' ) ) : '—';
	}

	/** @param string $key GET key. @param string[] $allowed Allowed values. @return string */
	protected function req( $key, array $allowed = array() ) {
		$v = isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		return ( ! $allowed || in_array( $v, $allowed, true ) ) ? $v : '';
	}

	/** @param object $row Consent-like row with recipient. @return string Masked recipient. */
	protected function who( $row ) {
		return ( '' === (string) $row->recipient || 0 === strpos( (string) $row->recipient, '#' ) ) ? __( '(anonimleştirildi)', 'consent-manager-for-iys' ) : Eru_CMI_Recipient::mask( $row->recipient );
	}

	/**
	 * @param string $name   Select name.
	 * @param array  $opts   value => label.
	 * @param string $chosen Current.
	 * @param string $all    Label of the empty option.
	 */
	protected function select( $name, array $opts, $chosen, $all ) {
		echo '<select name="' . esc_attr( $name ) . '"><option value="">' . esc_html( $all ) . '</option>';
		foreach ( $opts as $v => $l ) {
			echo '<option value="' . esc_attr( $v ) . '"' . selected( $chosen, (string) $v, false ) . '>' . esc_html( $l ) . '</option>';
		}
		echo '</select> ';
	}
}

/**
 * İzinler: search by exact address (looked up by hash), filter by channel, status and İYS state in the selected
 * environment.
 */
class Eru_CMI_Consents_Table extends WP_List_Table {

	use Eru_CMI_Admin_Table_Helpers;

	/** İYS filter values of the selected environment. */
	const IYS = array( 'known', 'none', 'queued', 'sent', 'accepted', 'rejected', 'skipped', 'failed', 'superseded' );

	/** Constructor. */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'eru-cmi-consent',
				'plural'   => 'eru-cmi-consents',
				'ajax'     => false,
			)
		);
	}

	/** @return array Columns. */
	public function get_columns() {
		return array(
			'recipient'      => __( 'Alıcı', 'consent-manager-for-iys' ),
			'type'           => __( 'Kanal', 'consent-manager-for-iys' ),
			'status'         => __( 'Durum', 'consent-manager-for-iys' ),
			'recipient_type' => __( 'Alıcı tipi', 'consent-manager-for-iys' ),
			'source'         => __( 'Kaynak', 'consent-manager-for-iys' ),
			'consent_date'   => __( 'İzin tarihi', 'consent-manager-for-iys' ),
			'iys'            => __( 'İYS', 'consent-manager-for-iys' ),
			'updated_at'     => __( 'Güncellendi', 'consent-manager-for-iys' ),
		);
	}

	/** Loads the page. */
	public function prepare_items() {
		global $wpdb;
		$env   = Eru_CMI_Settings::env();
		$c     = Eru_CMI_Installer::table( 'consents' );
		$q     = Eru_CMI_Installer::table( 'queue' );
		$sub   = $wpdb->prepare( "( SELECT q.status FROM {$q} q WHERE q.consent_id = c.id AND q.env = %s ORDER BY q.id DESC LIMIT 1 )", $env ); // phpcs:ignore
		$where = array( '1=1' );
		$args  = array();
		$s     = isset( $_GET['s'] ) ? trim( sanitize_text_field( wp_unslash( $_GET['s'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		if ( '' !== $s ) {
			$hashes = array( '' );
			foreach ( Eru_CMI_Recipient::TYPES as $t ) {
				$n = Eru_CMI_Recipient::normalize( $s, $t );
				if ( ! is_wp_error( $n ) ) {
					$hashes[] = Eru_CMI_Recipient::hash( $n );
				}
			}
			$where[] = 'c.recipient_hash IN (' . implode( ',', array_fill( 0, count( $hashes ), '%s' ) ) . ')';
			$args    = array_merge( $args, $hashes );
		}
		$type = $this->req( 'ctype', Eru_CMI_Recipient::TYPES );
		if ( '' !== $type ) {
			$where[] = 'c.type = %s';
			$args[]  = $type;
		}
		$status = $this->req( 'cstatus', Eru_CMI_Recipient::STATUSES );
		if ( '' !== $status ) {
			$where[] = 'c.status = %s';
			$args[]  = $status;
		}
		$iys  = $this->req( 'iys', self::IYS );
		$like = '%' . $wpdb->esc_like( ',' . $env . ',' ) . '%';
		if ( 'known' === $iys ) {
			$where[] = 'c.iys_known LIKE %s';
			$args[]  = $like;
		} elseif ( 'none' === $iys ) {
			$where[] = "c.iys_known NOT LIKE %s AND {$sub} IS NULL";
			$args[]  = $like;
		} elseif ( '' !== $iys ) {
			$where[] = "{$sub} = %s";
			$args[]  = $iys;
		}
		$per   = 20;
		$page  = $this->get_pagenum();
		$sql_w = implode( ' AND ', $where );
		$total = (int) $wpdb->get_var( $args ? $wpdb->prepare( "SELECT COUNT(*) FROM {$c} c WHERE {$sql_w}", ...$args ) : "SELECT COUNT(*) FROM {$c} c WHERE {$sql_w}" ); // phpcs:ignore
		$query = "SELECT c.*, {$sub} AS env_status FROM {$c} c WHERE {$sql_w} ORDER BY c.updated_at DESC, c.id DESC LIMIT %d OFFSET %d";
		$this->items = $wpdb->get_results( $wpdb->prepare( $query, ...array_merge( $args, array( $per, ( $page - 1 ) * $per ) ) ) ) ?: array(); // phpcs:ignore
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per,
			)
		);
	}

	/** @param string $which top|bottom. Filters. */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		echo '<div class="alignleft actions">';
		$this->select( 'ctype', array_combine( Eru_CMI_Recipient::TYPES, array_map( array( 'Eru_CMI_Links', 'channel_label' ), Eru_CMI_Recipient::TYPES ) ), $this->req( 'ctype' ), __( 'Tüm kanallar', 'consent-manager-for-iys' ) );
		$this->select( 'cstatus', array( 'ONAY' => 'ONAY', 'RET' => 'RET' ), $this->req( 'cstatus' ), __( 'Tüm durumlar', 'consent-manager-for-iys' ) );
		$this->select( 'iys', Eru_CMI_Admin::iys_labels(), $this->req( 'iys' ), __( 'İYS: tümü', 'consent-manager-for-iys' ) );
		submit_button( __( 'Süz', 'consent-manager-for-iys' ), '', 'filter_action', false );
		echo '</div>';
	}

	/** @param object $item Row. @param string $col Column. @return string */
	protected function column_default( $item, $col ) {
		switch ( $col ) {
			case 'recipient':
				$url = add_query_arg( array( 'page' => Eru_CMI_Admin::SLUG, 'tab' => 'consents', 'consent' => (int) $item->id ), admin_url( 'admin.php' ) );
				return '<strong><a href="' . esc_url( $url ) . '">' . esc_html( $this->who( $item ) ) . '</a></strong>'
					. $this->row_actions( array( 'view' => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Ayrıntı ve geçmiş', 'consent-manager-for-iys' ) . '</a>' ) );
			case 'type':
				return esc_html( Eru_CMI_Links::channel_label( $item->type ) );
			case 'status':
				return '<span class="eru-cmi-badge is-' . esc_attr( strtolower( $item->status ) ) . '">' . esc_html( $item->status ) . '</span>';
			case 'consent_date':
			case 'updated_at':
				return esc_html( $this->when( $item->$col ) );
			case 'iys':
				return esc_html( Eru_CMI_Admin::iys_state( $item ) );
			default:
				return esc_html( (string) ( $item->$col ?? '' ) );
		}
	}

	/** Empty list text. */
	public function no_items() {
		esc_html_e( 'Kayıt yok. Arama için alıcının tam e-posta adresini ya da telefon numarasını yazın.', 'consent-manager-for-iys' );
	}
}

/**
 * Denetim kaydı: every status change, newest first, filter by actor.
 */
class Eru_CMI_Audit_Table extends WP_List_Table {

	use Eru_CMI_Admin_Table_Helpers;

	/** Constructor. */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'eru-cmi-event',
				'plural'   => 'eru-cmi-events',
				'ajax'     => false,
			)
		);
	}

	/** @return array Columns. */
	public function get_columns() {
		return array(
			'created_at' => __( 'Zaman', 'consent-manager-for-iys' ),
			'recipient'  => __( 'Alıcı', 'consent-manager-for-iys' ),
			'type'       => __( 'Kanal', 'consent-manager-for-iys' ),
			'change'     => __( 'Değişiklik', 'consent-manager-for-iys' ),
			'actor'      => __( 'Kim', 'consent-manager-for-iys' ),
			'source'     => __( 'Kaynak', 'consent-manager-for-iys' ),
			'evidence'   => __( 'Kanıt', 'consent-manager-for-iys' ),
			'note'       => __( 'Not', 'consent-manager-for-iys' ),
		);
	}

	/** Loads the page. */
	public function prepare_items() {
		global $wpdb;
		$h     = Eru_CMI_Installer::table( 'history' );
		$c     = Eru_CMI_Installer::table( 'consents' );
		$actor = $this->req( 'actor', Eru_CMI_History::ACTORS );
		$where = '' !== $actor ? $wpdb->prepare( 'WHERE h.actor = %s', $actor ) : '';
		$per   = 30;
		$page  = $this->get_pagenum();
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$h} h {$where}" ); // phpcs:ignore
		$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT h.*, c.recipient, c.type, c.id AS cid FROM {$h} h LEFT JOIN {$c} c ON c.id = h.consent_id {$where} ORDER BY h.id DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ) ) ?: array(); // phpcs:ignore
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per,
			)
		);
	}

	/** @param string $which top|bottom. Filters. */
	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		echo '<div class="alignleft actions">';
		$this->select( 'actor', Eru_CMI_Admin::actor_labels(), $this->req( 'actor' ), __( 'Herkes', 'consent-manager-for-iys' ) );
		submit_button( __( 'Süz', 'consent-manager-for-iys' ), '', 'filter_action', false );
		echo '</div>';
	}

	/** @param object $item Row. @param string $col Column. @return string */
	protected function column_default( $item, $col ) {
		switch ( $col ) {
			case 'created_at':
				return esc_html( $this->when( $item->created_at ) );
			case 'recipient':
				$url = add_query_arg( array( 'page' => Eru_CMI_Admin::SLUG, 'tab' => 'consents', 'consent' => (int) $item->cid ), admin_url( 'admin.php' ) );
				return '<a href="' . esc_url( $url ) . '">' . esc_html( $this->who( $item ) ) . '</a>';
			case 'type':
				return esc_html( Eru_CMI_Links::channel_label( (string) $item->type ) );
			case 'change':
				return esc_html( ( '' === $item->previous_status ? '—' : $item->previous_status ) . ' → ' . $item->new_status );
			case 'actor':
				return esc_html( Eru_CMI_Admin::actor_labels()[ $item->actor ] ?? $item->actor );
			case 'evidence':
				return esc_html( Eru_CMI_Admin::evidence( $item ) );
			default:
				return esc_html( (string) ( $item->$col ?? '' ) );
		}
	}

	/** Empty list text. */
	public function no_items() {
		esc_html_e( 'Henüz kayıt yok.', 'consent-manager-for-iys' );
	}
}

/**
 * Kuyruk: rows of one environment by status; bulk retry/skip only for the selected environment.
 */
class Eru_CMI_Queue_Table extends WP_List_Table {

	use Eru_CMI_Admin_Table_Helpers;

	/** @var string Environment shown. */
	public $env;

	/** @param string $env Environment shown. */
	public function __construct( $env ) {
		$this->env = $env;
		parent::__construct(
			array(
				'singular' => 'eru-cmi-row',
				'plural'   => 'eru-cmi-queue',
				'ajax'     => false,
			)
		);
	}

	/** @return array Columns. */
	public function get_columns() {
		$cols = array(
			'cb'          => '<input type="checkbox">',
			'id'          => '#',
			'recipient'   => __( 'Alıcı', 'consent-manager-for-iys' ),
			'record'      => __( 'Kayıt', 'consent-manager-for-iys' ),
			'status'      => __( 'Kuyruk durumu', 'consent-manager-for-iys' ),
			'attempts'    => __( 'Deneme', 'consent-manager-for-iys' ),
			'next_try_at' => __( 'Sonraki deneme', 'consent-manager-for-iys' ),
			'last_error'  => __( 'Son hata / not', 'consent-manager-for-iys' ),
			'updated_at'  => __( 'Güncellendi', 'consent-manager-for-iys' ),
		);
		if ( Eru_CMI_Settings::env() !== $this->env ) {
			unset( $cols['cb'] );
		}
		return $cols;
	}

	/** @return array Bulk actions (selected environment only). */
	protected function get_bulk_actions() {
		if ( Eru_CMI_Settings::env() !== $this->env ) {
			return array();
		}
		return array(
			'retry' => __( 'Yeniden dene', 'consent-manager-for-iys' ),
			'skip'  => __( 'Atla (gönderme)', 'consent-manager-for-iys' ),
		);
	}

	/** @return array Views per status. */
	protected function get_views() {
		$counts = Eru_CMI_Queue::counts( $this->env );
		$cur    = $this->req( 'qstatus', Eru_CMI_Queue::STATUSES );
		$base   = array( 'page' => Eru_CMI_Admin::SLUG, 'tab' => 'queue', 'env' => $this->env );
		$views  = array( 'all' => '<a href="' . esc_url( add_query_arg( $base, admin_url( 'admin.php' ) ) ) . '"' . ( '' === $cur ? ' class="current"' : '' ) . '>' . esc_html__( 'Tümü', 'consent-manager-for-iys' ) . ' <span class="count">(' . (int) array_sum( $counts ) . ')</span></a>' );
		foreach ( $counts as $st => $n ) {
			$views[ $st ] = '<a href="' . esc_url( add_query_arg( $base + array( 'qstatus' => $st ), admin_url( 'admin.php' ) ) ) . '"' . ( $cur === $st ? ' class="current"' : '' ) . '>' . esc_html( Eru_CMI_Admin::iys_labels()[ $st ] ?? $st ) . ' <span class="count">(' . (int) $n . ')</span></a>';
		}
		return $views;
	}

	/** Loads the page. */
	public function prepare_items() {
		global $wpdb;
		$q      = Eru_CMI_Installer::table( 'queue' );
		$status = $this->req( 'qstatus', Eru_CMI_Queue::STATUSES );
		$where  = $wpdb->prepare( 'WHERE env = %s', $this->env );
		if ( '' !== $status ) {
			$where .= $wpdb->prepare( ' AND status = %s', $status );
		}
		$per   = 30;
		$page  = $this->get_pagenum();
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$q} {$where}" ); // phpcs:ignore
		$this->items = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$q} {$where} ORDER BY id DESC LIMIT %d OFFSET %d", $per, ( $page - 1 ) * $per ) ) ?: array(); // phpcs:ignore
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per,
			)
		);
	}

	/** @param object $item Row. @return string */
	protected function column_cb( $item ) {
		return '<input type="checkbox" name="ids[]" value="' . (int) $item->id . '">';
	}

	/** @param object $item Row. @param string $col Column. @return string */
	protected function column_default( $item, $col ) {
		$p = json_decode( (string) $item->payload, true );
		$p = is_array( $p ) ? $p : array();
		switch ( $col ) {
			case 'id':
				return (string) (int) $item->id;
			case 'recipient':
				return esc_html( $this->who( (object) array( 'recipient' => (string) ( $p['recipient'] ?? '' ) ) ) );
			case 'record':
				return esc_html( Eru_CMI_Links::channel_label( (string) ( $p['type'] ?? '' ) ) . ' · ' . ( $p['status'] ?? '' ) . ' · ' . ( $p['recipientType'] ?? '' ) . ' · ' . ( $p['consentDate'] ?? '' ) );
			case 'status':
				return '<span class="eru-cmi-badge is-' . esc_attr( $item->status ) . '">' . esc_html( Eru_CMI_Admin::iys_labels()[ $item->status ] ?? $item->status ) . '</span>';
			case 'next_try_at':
			case 'updated_at':
				return esc_html( $this->when( $item->$col ) );
			case 'last_error':
				return esc_html( Eru_CMI_Logger::redact( mb_substr( (string) $item->last_error, 0, 200 ) ) );
			default:
				return esc_html( (string) ( $item->$col ?? '' ) );
		}
	}

	/** Empty list text. */
	public function no_items() {
		esc_html_e( 'Bu ortamda kuyruk kaydı yok.', 'consent-manager-for-iys' );
	}
}
