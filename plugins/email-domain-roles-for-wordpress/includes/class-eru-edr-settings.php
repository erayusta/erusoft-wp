<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Ayarlar → E-posta Alan Adı Rolleri */
class Eru_Edr_Settings {

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_filter(
			'plugin_action_links_' . plugin_basename( ERU_EDR_FILE ),
			function ( $links ) {
				array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=eru-edr' ) ) . '">' . esc_html__( 'Ayarlar', 'email-domain-roles-for-wordpress' ) . '</a>' );
				return $links;
			}
		);
	}

	public static function menu(): void {
		add_options_page(
			__( 'E-posta Alan Adı Rolleri', 'email-domain-roles-for-wordpress' ),
			__( 'E-posta Alan Adı Rolleri', 'email-domain-roles-for-wordpress' ),
			'manage_options',
			'eru-edr',
			array( __CLASS__, 'render' )
		);
	}

	public static function register(): void {
		register_setting(
			'eru_edr',
			Eru_Edr::OPTION_MAPPINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_mappings' ),
				'default'           => array(),
			)
		);
		register_setting(
			'eru_edr',
			Eru_Edr::OPTION_FALLBACK,
			array(
				'type'              => 'string',
				'sanitize_callback' => array( __CLASS__, 'sanitize_fallback' ),
				'default'           => '',
			)
		);
	}

	public static function sanitize_mappings( $input ): array {
		$out     = array();
		$allowed = Eru_Edr::allowed_roles();
		$seen    = array();
		foreach ( (array) $input as $row ) {
			$domain = Eru_Edr::normalize_domain( sanitize_text_field( (string) ( $row['domain'] ?? '' ) ) );
			$role   = sanitize_key( (string) ( $row['role'] ?? '' ) );
			if ( '' === $domain || ! Eru_Edr::is_valid_domain( $domain ) || ! isset( $allowed[ $role ] ) || isset( $seen[ $domain ] ) ) {
				continue;
			}
			$seen[ $domain ] = true;
			$out[]           = array( 'domain' => $domain, 'role' => $role );
		}
		return $out;
	}

	public static function sanitize_fallback( $input ): string {
		$role = sanitize_key( (string) $input );
		return isset( Eru_Edr::allowed_roles()[ $role ] ) ? $role : '';
	}

	public static function enqueue( $hook ): void {
		if ( 'settings_page_eru-edr' !== $hook ) {
			return;
		}
		wp_enqueue_script( 'eru-edr-admin', ERU_EDR_URL . 'assets/admin.js', array(), ERU_EDR_VERSION, true );
	}

	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$mappings = Eru_Edr::mappings();
		$roles    = Eru_Edr::allowed_roles();
		$fallback = (string) get_option( Eru_Edr::OPTION_FALLBACK, '' );
		$name     = Eru_Edr::OPTION_MAPPINGS;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'E-posta Alan Adı Rolleri', 'email-domain-roles-for-wordpress' ); ?></h1>
			<p><?php esc_html_e( 'Yeni kayıt olan kullanıcının e-posta alan adı aşağıdaki tabloda varsa, kullanıcıya ilgili rol atanır. Tablo boşsa hiçbir şey değişmez.', 'email-domain-roles-for-wordpress' ); ?></p>
			<form action="options.php" method="post">
				<?php settings_fields( 'eru_edr' ); ?>
				<table id="eru-edr-table" class="widefat striped" style="max-width:640px" data-name="<?php echo esc_attr( $name ); ?>" data-next="<?php echo (int) count( $mappings ); ?>">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Alan adı', 'email-domain-roles-for-wordpress' ); ?></th>
							<th><?php esc_html_e( 'Rol', 'email-domain-roles-for-wordpress' ); ?></th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $mappings as $i => $map ) : ?>
							<tr>
								<td><input type="text" class="regular-text" placeholder="example.org" name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][domain]" value="<?php echo esc_attr( $map['domain'] ); ?>"></td>
								<td>
									<select name="<?php echo esc_attr( $name ); ?>[<?php echo (int) $i; ?>][role]">
										<?php foreach ( $roles as $key => $label ) : ?>
											<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $map['role'], $key ); ?>><?php echo esc_html( $label ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
								<td><button type="button" class="button eru-edr-remove"><?php esc_html_e( 'Kaldır', 'email-domain-roles-for-wordpress' ); ?></button></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<template id="eru-edr-row">
					<tr>
						<td><input type="text" class="regular-text" placeholder="example.org" name="" data-field="domain"></td>
						<td>
							<select name="" data-field="role">
								<?php foreach ( $roles as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td><button type="button" class="button eru-edr-remove"><?php esc_html_e( 'Kaldır', 'email-domain-roles-for-wordpress' ); ?></button></td>
					</tr>
				</template>
				<p><button type="button" class="button" id="eru-edr-add"><?php esc_html_e( 'Eşleme ekle', 'email-domain-roles-for-wordpress' ); ?></button></p>
				<p class="description"><?php esc_html_e( 'Tam alan adı (example.org) veya alt alan adları için "*.example.org" yazın. Yönetim ve yetki yükseltme yeteneği olan roller güvenlik nedeniyle listelenmez.', 'email-domain-roles-for-wordpress' ); ?></p>

				<h2><?php esc_html_e( 'Eşleşme yoksa', 'email-domain-roles-for-wordpress' ); ?></h2>
				<select name="<?php echo esc_attr( Eru_Edr::OPTION_FALLBACK ); ?>">
					<option value=""><?php esc_html_e( 'Rolü değiştirme (WordPress varsayılanı)', 'email-domain-roles-for-wordpress' ); ?></option>
					<?php foreach ( $roles as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $fallback, $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Alan adı hiçbir satırla eşleşmeyen yeni kullanıcılara bu rol atanır.', 'email-domain-roles-for-wordpress' ); ?></p>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}
