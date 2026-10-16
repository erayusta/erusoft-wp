<?php
/**
 * Admin list tables: agents, commissions, payouts. Loaded only when a screen renders one.
 *
 * @package sales-team-commissions-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/** Shared helpers. */
abstract class Eru_STC_Table extends WP_List_Table {

	const PER_PAGE = 20;

	/** @param string $key Query arg. @return string Sanitised GET value. */
	protected function arg( $key ) {
		return isset( $_GET[ $key ] ) && is_string( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/** @param object $item Row. @return string Checkbox. */
	public function column_cb( $item ) {
		return '<input type="checkbox" name="ids[]" value="' . esc_attr( (string) $item->id ) . '">';
	}

	/** @param object $item Row. @param string $col Column. @return string */
	public function column_default( $item, $col ) {
		return isset( $item->$col ) ? esc_html( (string) $item->$col ) : '';
	}

	/**
	 * Status views (links).
	 *
	 * @param string $page    Page slug.
	 * @param array  $labels  status => label ('' = all).
	 * @param array  $counts  status => count.
	 * @param string $current Current status.
	 * @param array  $keep    Other args to keep.
	 * @return array
	 */
	protected function status_views( $page, array $labels, array $counts, $current, array $keep = array() ) {
		$out = array();
		foreach ( $labels as $status => $label ) {
			$url            = add_query_arg( array_merge( array( 'page' => $page ), $keep, '' === $status ? array() : array( 'status' => $status ) ), admin_url( 'admin.php' ) );
			$out[ $status ? $status : 'all' ] = sprintf( '<a href="%1$s"%2$s>%3$s <span class="count">(%4$d)</span></a>', esc_url( $url ), $status === $current ? ' class="current" aria-current="page"' : '', esc_html( $label ), (int) ( $counts[ $status ] ?? 0 ) );
		}
		return $out;
	}

	/** @param int $id Order id. @return string Order link. */
	public static function order_link( $id ) {
		return Eru_STC_Table_Links::order( $id );
	}

	/** @param int $id Agent id. @return string Agent link. */
	public static function agent_link( $id ) {
		return Eru_STC_Table_Links::agent( $id );
	}

	/** @param string $amount Decimal. @param string $currency Currency. @return string */
	public static function money( $amount, $currency = '' ) {
		return wp_kses_post( wc_price( (float) $amount, array( 'currency' => $currency ? $currency : get_woocommerce_currency() ) ) );
	}
}

/** Agents. */
class Eru_STC_Agents_Table extends Eru_STC_Table {

	/** @var array<int,int> Order counts of the page. */
	private $orders = array();

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'agent',
				'plural'   => 'agents',
				'ajax'     => false,
			)
		);
	}

	/** @return array */
	public function get_columns() {
		return array(
			'cb'        => '<input type="checkbox">',
			'name'      => __( 'Temsilci', 'sales-team-commissions-for-woocommerce' ),
			'code'      => __( 'Kod', 'sales-team-commissions-for-woocommerce' ),
			'parent'    => __( 'Üst temsilci', 'sales-team-commissions-for-woocommerce' ),
			'status'    => __( 'Durum', 'sales-team-commissions-for-woocommerce' ),
			'customers' => __( 'Müşteri', 'sales-team-commissions-for-woocommerce' ),
			'orders'    => __( 'Sipariş', 'sales-team-commissions-for-woocommerce' ),
			'available' => __( 'Ödenebilir', 'sales-team-commissions-for-woocommerce' ),
			'pending'   => __( 'Beklemede', 'sales-team-commissions-for-woocommerce' ),
		);
	}

	/** @return array */
	protected function get_bulk_actions() {
		return array(
			'approve'  => __( 'Başvuruyu onayla', 'sales-team-commissions-for-woocommerce' ),
			'activate' => __( 'Etkinleştir', 'sales-team-commissions-for-woocommerce' ),
			'suspend'  => __( 'Askıya al', 'sales-team-commissions-for-woocommerce' ),
		);
	}

	/** @return array */
	protected function get_views() {
		$counts = array( '' => Eru_STC_Agents::count() );
		foreach ( Eru_STC_Agents::STATUSES as $s ) {
			$counts[ $s ] = Eru_STC_Agents::count( array( 'status' => $s ) );
		}
		return $this->status_views(
			Eru_STC_Admin::PAGES['agents'],
			array(
				''          => __( 'Tümü', 'sales-team-commissions-for-woocommerce' ),
				'pending'   => __( 'Onay bekleyen', 'sales-team-commissions-for-woocommerce' ),
				'active'    => __( 'Etkin', 'sales-team-commissions-for-woocommerce' ),
				'suspended' => __( 'Askıda', 'sales-team-commissions-for-woocommerce' ),
			),
			$counts,
			$this->arg( 'status' )
		);
	}

	/** Loads the page. */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$args                  = array( 'status' => $this->arg( 'status' ) );
		$search                = $this->arg( 's' );
		if ( '' !== $search ) {
			$args['search']   = $search;
			$args['user_ids'] = get_users(
				array(
					'search'         => '*' . $search . '*',
					'search_columns' => array( 'user_login', 'user_email', 'display_name' ),
					'number'         => 200,
					'fields'         => 'ID',
				)
			);
			$args['user_ids'] = $args['user_ids'] ? $args['user_ids'] : array( 0 );
		}
		$total       = Eru_STC_Agents::count( $args );
		$this->items = Eru_STC_Agents::list(
			$args + array(
				'limit'  => self::PER_PAGE,
				'offset' => ( $this->get_pagenum() - 1 ) * self::PER_PAGE,
			)
		);
		$this->orders = Eru_STC_Reports::order_counts( wp_list_pluck( $this->items, 'id' ) );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/** @param object $item Row. @return string */
	public function column_name( $item ) {
		$user = get_userdata( (int) $item->user_id );
		$url  = add_query_arg( array( 'page' => Eru_STC_Admin::PAGES['agents'], 'agent' => (int) $item->id ), admin_url( 'admin.php' ) );
		return '<strong><a href="' . esc_url( $url ) . '">' . esc_html( Eru_STC_Agents::label( $item ) ) . '</a></strong>' . ( $user ? '<br><span class="description">' . esc_html( $user->user_email ) . '</span>' : '' );
	}

	/** @param object $item Row. @return string */
	public function column_code( $item ) {
		return '<code>' . esc_html( $item->referral_code ) . '</code>';
	}

	/** @param object $item Row. @return string */
	public function column_parent( $item ) {
		return $item->parent_agent_id ? self::agent_link( (int) $item->parent_agent_id ) : '—';
	}

	/** @param object $item Row. @return string */
	public function column_status( $item ) {
		return '<span class="eru-stc-badge eru-stc-badge--' . esc_attr( $item->status ) . '">' . esc_html( Eru_STC_Labels::agent_status( $item->status ) ) . '</span>';
	}

	/** @param object $item Row. @return string */
	public function column_customers( $item ) {
		return esc_html( (string) Eru_STC_Links::count_for( (int) $item->id ) );
	}

	/** @param object $item Row. @return string */
	public function column_orders( $item ) {
		return esc_html( (string) ( $this->orders[ (int) $item->id ] ?? 0 ) );
	}

	/** @param object $item Row. @return string */
	public function column_available( $item ) {
		return self::money( Eru_STC_Commissions::balance( (int) $item->id )['available'] );
	}

	/** @param object $item Row. @return string */
	public function column_pending( $item ) {
		return self::money( Eru_STC_Commissions::balance( (int) $item->id )['pending'] );
	}

	/** Empty text. */
	public function no_items() {
		esc_html_e( 'Temsilci yok.', 'sales-team-commissions-for-woocommerce' );
	}
}

/** Commission rows. */
class Eru_STC_Commissions_Table extends Eru_STC_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'commission',
				'plural'   => 'commissions',
				'ajax'     => false,
			)
		);
	}

	/** @return array Current filters. */
	public function filters() {
		$args   = array(
			'agent_id' => absint( $this->arg( 'agent_id' ) ),
			'order_id' => absint( $this->arg( 'order_id' ) ),
		);
		$status = $this->arg( 'status' );
		if ( in_array( $status, Eru_STC_Commissions::STATUSES, true ) ) {
			$args['status'] = $status;
		}
		$month = $this->arg( 'month' );
		if ( 1 === preg_match( '/^\d{4}-\d{2}$/', $month ) ) {
			list( $args['from'], $args['to'] ) = Eru_STC_Reports::month_range( $month );
		}
		return $args;
	}

	/** @return array */
	public function get_columns() {
		return array(
			'cb'     => '<input type="checkbox">',
			'id'     => __( 'No', 'sales-team-commissions-for-woocommerce' ),
			'order'  => __( 'Sipariş', 'sales-team-commissions-for-woocommerce' ),
			'agent'  => __( 'Temsilci', 'sales-team-commissions-for-woocommerce' ),
			'level'  => __( 'Seviye', 'sales-team-commissions-for-woocommerce' ),
			'rule'   => __( 'Kural', 'sales-team-commissions-for-woocommerce' ),
			'basis'  => __( 'Matrah', 'sales-team-commissions-for-woocommerce' ),
			'amount' => __( 'Komisyon', 'sales-team-commissions-for-woocommerce' ),
			'status' => __( 'Durum', 'sales-team-commissions-for-woocommerce' ),
			'earned' => __( 'Kazanıldı', 'sales-team-commissions-for-woocommerce' ),
			'payout' => __( 'Ödeme', 'sales-team-commissions-for-woocommerce' ),
		);
	}

	/** @return array */
	protected function get_bulk_actions() {
		return array(
			'approve' => __( 'Onayla (bekleme süresini atla)', 'sales-team-commissions-for-woocommerce' ),
			'void'    => __( 'İptal et', 'sales-team-commissions-for-woocommerce' ),
		);
	}

	/** @return array */
	protected function get_views() {
		$base   = $this->filters();
		$keep   = array_filter(
			array(
				'agent_id' => $base['agent_id'],
				'order_id' => $base['order_id'],
				'month'    => $this->arg( 'month' ),
			)
		);
		unset( $base['status'] );
		$counts = array( '' => Eru_STC_Commissions::count( $base ) );
		foreach ( Eru_STC_Commissions::STATUSES as $s ) {
			$counts[ $s ] = Eru_STC_Commissions::count( $base + array( 'status' => $s ) );
		}
		return $this->status_views(
			Eru_STC_Admin::PAGES['commissions'],
			array(
				''         => __( 'Tümü', 'sales-team-commissions-for-woocommerce' ),
				'pending'  => __( 'Beklemede', 'sales-team-commissions-for-woocommerce' ),
				'approved' => __( 'Onaylı', 'sales-team-commissions-for-woocommerce' ),
				'paid'     => __( 'Ödendi', 'sales-team-commissions-for-woocommerce' ),
				'void'     => __( 'İptal', 'sales-team-commissions-for-woocommerce' ),
			),
			$counts,
			$this->arg( 'status' ),
			$keep
		);
	}

	/** Loads the page. */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$args                  = $this->filters();
		$total                 = Eru_STC_Commissions::count( $args );
		$this->items           = Eru_STC_Commissions::list(
			$args + array(
				'limit'  => self::PER_PAGE,
				'offset' => ( $this->get_pagenum() - 1 ) * self::PER_PAGE,
			)
		);
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/** @param object $item Row. @return string Checkbox for unclaimed pending/approved rows only. */
	public function column_cb( $item ) {
		return in_array( $item->status, array( 'pending', 'approved' ), true ) && null === $item->payout_id ? parent::column_cb( $item ) : '';
	}

	/** @param object $item Row. @return string */
	public function column_order( $item ) {
		return self::order_link( (int) $item->order_id ) . ( '' !== $item->entry_key ? ' <span class="description">' . esc_html__( 'düzeltme', 'sales-team-commissions-for-woocommerce' ) . '</span>' : '' );
	}

	/** @param object $item Row. @return string */
	public function column_agent( $item ) {
		return self::agent_link( (int) $item->agent_id );
	}

	/** @param object $item Row. @return string */
	public function column_rule( $item ) {
		if ( '' !== $item->entry_key || ! $item->rule_id ) {
			return '—';
		}
		$rate = 'percent' === $item->rule_type ? rtrim( rtrim( (string) $item->rule_rate, '0' ), '.' ) . ' %' : Eru_STC_Labels::money( $item->rule_rate, $item->currency ) . ' ' . __( '/ adet', 'sales-team-commissions-for-woocommerce' );
		return '#' . (int) $item->rule_id . ' · ' . esc_html( $rate );
	}

	/** @param object $item Row. @return string */
	public function column_basis( $item ) {
		return '' === $item->entry_key ? self::money( $item->basis, $item->currency ) : '—';
	}

	/** @param object $item Row. @return string */
	public function column_amount( $item ) {
		$out = self::money( $item->amount, $item->currency );
		if ( '' === $item->entry_key && $item->amount !== $item->original_amount ) {
			$out .= '<br><span class="description">' . esc_html__( 'ilk:', 'sales-team-commissions-for-woocommerce' ) . ' ' . self::money( $item->original_amount, $item->currency ) . '</span>';
		}
		return $out;
	}

	/** @param object $item Row. @return string */
	public function column_status( $item ) {
		$out = '<span class="eru-stc-badge eru-stc-badge--' . esc_attr( $item->status ) . '">' . esc_html( Eru_STC_Labels::commission_status( $item->status ) ) . '</span>';
		if ( 'void' === $item->status && '' !== $item->void_reason ) {
			$out .= ' <span class="description">' . esc_html( Eru_STC_Labels::void_reason( $item->void_reason ) ) . '</span>';
		}
		return $out;
	}

	/** @param object $item Row. @return string */
	public function column_earned( $item ) {
		return esc_html( get_date_from_gmt( $item->earned_at, get_option( 'date_format' ) ) );
	}

	/** @param object $item Row. @return string */
	public function column_payout( $item ) {
		return $item->payout_id ? '#' . (int) $item->payout_id : '—';
	}

	/** Empty text. */
	public function no_items() {
		esc_html_e( 'Komisyon kaydı yok.', 'sales-team-commissions-for-woocommerce' );
	}
}

/** Payout requests. */
class Eru_STC_Payouts_Table extends Eru_STC_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'payout',
				'plural'   => 'payouts',
				'ajax'     => false,
			)
		);
	}

	/** @return array */
	public function get_columns() {
		return array(
			'id'      => __( 'No', 'sales-team-commissions-for-woocommerce' ),
			'agent'   => __( 'Temsilci', 'sales-team-commissions-for-woocommerce' ),
			'amount'  => __( 'Tutar', 'sales-team-commissions-for-woocommerce' ),
			'details' => __( 'Ödeme bilgisi', 'sales-team-commissions-for-woocommerce' ),
			'status'  => __( 'Durum', 'sales-team-commissions-for-woocommerce' ),
			'dates'   => __( 'Tarih', 'sales-team-commissions-for-woocommerce' ),
			'actions' => __( 'İşlem', 'sales-team-commissions-for-woocommerce' ),
		);
	}

	/** @return array */
	protected function get_views() {
		$counts = array( '' => Eru_STC_Payouts::count() );
		foreach ( Eru_STC_Payouts::STATUSES as $s ) {
			$counts[ $s ] = Eru_STC_Payouts::count( array( 'status' => $s ) );
		}
		return $this->status_views(
			Eru_STC_Admin::PAGES['payouts'],
			array(
				''          => __( 'Tümü', 'sales-team-commissions-for-woocommerce' ),
				'requested' => __( 'Talep edildi', 'sales-team-commissions-for-woocommerce' ),
				'paid'      => __( 'Ödendi', 'sales-team-commissions-for-woocommerce' ),
				'cancelled' => __( 'İptal edildi', 'sales-team-commissions-for-woocommerce' ),
			),
			$counts,
			$this->arg( 'status' )
		);
	}

	/** Loads the page. */
	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$args                  = array(
			'status'   => $this->arg( 'status' ),
			'agent_id' => absint( $this->arg( 'agent_id' ) ),
		);
		$total                 = Eru_STC_Payouts::count( $args );
		$this->items           = Eru_STC_Payouts::list(
			$args + array(
				'limit'  => self::PER_PAGE,
				'offset' => ( $this->get_pagenum() - 1 ) * self::PER_PAGE,
			)
		);
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}

	/** @param object $item Row. @return string */
	public function column_id( $item ) {
		return '#' . (int) $item->id;
	}

	/** @param object $item Row. @return string */
	public function column_agent( $item ) {
		return self::agent_link( (int) $item->agent_id );
	}

	/** @param object $item Row. @return string */
	public function column_amount( $item ) {
		$rows = Eru_STC_Payouts::rows_total( (int) $item->id );
		$out  = '<strong>' . self::money( $item->amount, $item->currency ) . '</strong>';
		if ( Eru_STC_Money::to_minor( $rows ) !== Eru_STC_Money::to_minor( $item->amount ) ) {
			$out .= '<br><span class="eru-stc-warn">' . esc_html__( 'Satır toplamı:', 'sales-team-commissions-for-woocommerce' ) . ' ' . self::money( $rows, $item->currency ) . '</span>';
		}
		return $out;
	}

	/** @param object $item Row. @return string Masked holder / IBAN from the request's snapshot. */
	public function column_details( $item ) {
		$d = Eru_STC_Payout_Details::from_snapshot( $item->details, false );
		if ( 'empty' === $d['state'] ) {
			return '<span class="description">' . esc_html__( 'kayıtlı bilgi yok', 'sales-team-commissions-for-woocommerce' ) . '</span>';
		}
		return esc_html( $d['holder'] ) . '<br><code>' . esc_html( $d['iban'] ) . '</code>';
	}

	/** @param object $item Row. @return string */
	public function column_status( $item ) {
		$out = '<span class="eru-stc-badge eru-stc-badge--' . esc_attr( $item->status ) . '">' . esc_html( Eru_STC_Labels::payout_status( $item->status ) ) . '</span>';
		if ( 'paid' === $item->status ) {
			$out .= '<br><span class="description">' . esc_html( Eru_STC_Labels::method( $item->method ) . ( '' !== $item->reference ? ' · ' . $item->reference : '' ) ) . '</span>';
		}
		return $out;
	}

	/** @param object $item Row. @return string */
	public function column_dates( $item ) {
		$out = esc_html( get_date_from_gmt( $item->requested_at, get_option( 'date_format' ) ) );
		if ( $item->paid_at ) {
			$out .= '<br><span class="description">' . esc_html__( 'ödendi:', 'sales-team-commissions-for-woocommerce' ) . ' ' . esc_html( get_date_from_gmt( $item->paid_at, get_option( 'date_format' ) ) ) . '</span>';
		}
		return $out;
	}

	/** @param object $item Row. @return string Pay / cancel forms for open requests. */
	public function column_actions( $item ) {
		if ( 'requested' !== $item->status ) {
			return '—';
		}
		$nonce   = wp_nonce_field( 'eru_stc_payout', '_wpnonce', true, false );
		$methods = '';
		foreach ( Eru_STC_Labels::methods() as $k => $label ) {
			$methods .= '<option value="' . esc_attr( $k ) . '">' . esc_html( $label ) . '</option>';
		}
		$action = esc_url( admin_url( 'admin-post.php' ) );
		return '<form method="post" action="' . $action . '" class="eru-stc-paid-form">' . $nonce
			. '<input type="hidden" name="action" value="eru_stc_payout_paid"><input type="hidden" name="payout_id" value="' . esc_attr( (string) $item->id ) . '">'
			. '<select name="method" aria-label="' . esc_attr__( 'Yöntem', 'sales-team-commissions-for-woocommerce' ) . '">' . $methods . '</select> '
			. '<input type="text" name="reference" placeholder="' . esc_attr__( 'Referans / dekont no', 'sales-team-commissions-for-woocommerce' ) . '" maxlength="190"> '
			. '<button type="submit" class="button button-primary">' . esc_html__( 'Ödendi', 'sales-team-commissions-for-woocommerce' ) . '</button></form>'
			. '<form method="post" action="' . $action . '" class="eru-stc-cancel-form">' . $nonce
			. '<input type="hidden" name="action" value="eru_stc_payout_cancel"><input type="hidden" name="payout_id" value="' . esc_attr( (string) $item->id ) . '">'
			. '<button type="submit" class="button-link eru-stc-danger">' . esc_html__( 'Talebi iptal et', 'sales-team-commissions-for-woocommerce' ) . '</button></form>';
	}

	/** Empty text. */
	public function no_items() {
		esc_html_e( 'Ödeme talebi yok.', 'sales-team-commissions-for-woocommerce' );
	}
}
