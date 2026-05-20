<?php
/**
 * Plugin activation: tables and defaults.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Runs on activate.
 */
class WPMAR_Activator {

	/**
	 * Activation entry point (single site or one blog in a network).
	 *
	 * @return void
	 */
	public static function activate() {
		self::maybe_create_tables();
		self::maybe_seed_defaults();
		self::ensure_site_defaults_and_schedule();

		update_option(
			'wpmar_db_version',
			WPMAR_VERSION,
			true
		);
	}

	/**
	 * Network-wide activation: tables on every blog + network defaults + main-site cron.
	 *
	 * @return void
	 */
	public static function activate_network() {
		if ( ! function_exists( 'get_sites' ) ) {
			self::activate();
			return;
		}

		$sites = get_sites(
			array(
				'number' => 0,
			)
		);

		foreach ( $sites as $site ) {
			if ( ! is_object( $site ) || ! isset( $site->blog_id ) ) {
				continue;
			}
			switch_to_blog( (int) $site->blog_id );
			self::maybe_create_tables();
			self::maybe_seed_defaults();
			self::ensure_site_defaults_and_schedule();
			update_option(
				'wpmar_db_version',
				WPMAR_VERSION,
				true
			);
			restore_current_blog();
		}

		WPMAR_Network_Settings::maybe_seed_defaults();

		WPMAR_Network::on_main_site(
			static function () {
				WPMAR_Scheduler::reschedule();
			}
		);
	}

	/**
	 * Provisions schema when a new blog is created on a multisite network.
	 *
	 * Hooked to `wp_initialize_site` (WordPress 5.1+).
	 *
	 * @param WP_Site $new_site Newly created site object.
	 * @return void
	 */
	public static function activate_new_site( WP_Site $new_site ) {
		if ( ! function_exists( 'switch_to_blog' ) ) {
			return;
		}

		switch_to_blog( absint( $new_site->blog_id ) );
		self::maybe_create_tables();
		self::maybe_seed_defaults();
		update_option(
			'wpmar_db_version',
			WPMAR_VERSION,
			true
		);
		restore_current_blog();
	}

	/**
	 * Runs dbDelta when {@see WPMAR_VERSION} advances so schema changes apply without re-activation.
	 *
	 * @return void
	 */
	public static function upgrade_database_if_needed() {
		$stored = get_option( 'wpmar_db_version', '0' );
		if ( version_compare( (string) $stored, WPMAR_VERSION, '>=' ) ) {
			return;
		}

		self::maybe_create_tables();
		update_option(
			'wpmar_db_version',
			WPMAR_VERSION,
			true
		);
	}

	/**
	 * Ensures schema via dbDelta.
	 *
	 * @return void
	 */
	protected static function maybe_create_tables() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$reports_table   = $wpdb->prefix . 'wpmar_reports';
		$snapshots_table = $wpdb->prefix . 'wpmar_snapshots';

		$sql_reports = "CREATE TABLE {$reports_table} (
	id bigint unsigned NOT NULL auto_increment,
	created_at datetime NOT NULL,
	status varchar(20) NOT NULL default 'success',
	triggered_by varchar(20) NOT NULL default 'cron',
	domain_matched tinyint(1) NOT NULL default 1,
	mail_sent tinyint(1) NOT NULL default 0,
	change_count int unsigned NOT NULL default 0,
	duration_sec int unsigned NOT NULL default 0,
	summary_json longtext NULL,
	body_md longtext NULL,
	body_client_md longtext NULL,
	md_file_path varchar(255) NULL,
	pdf_file_path varchar(255) NULL,
	PRIMARY KEY (id),
	KEY idx_created_at (created_at),
	KEY idx_status (status)
) {$charset_collate};";

		$sql_snapshots = "CREATE TABLE {$snapshots_table} (
	id bigint unsigned NOT NULL auto_increment,
	captured_at datetime NOT NULL,
	snapshot_type varchar(20) NOT NULL,
	snapshot_json longtext NOT NULL,
	PRIMARY KEY (id),
	KEY idx_type_captured (snapshot_type, captured_at)
) {$charset_collate};";

		dbDelta( $sql_reports );
		dbDelta( $sql_snapshots );
	}

	/**
	 * Default settings once.
	 *
	 * @return void
	 */
	protected static function maybe_seed_defaults() {
		if ( false !== get_option( 'wpmar_settings', false ) ) {
			return;
		}

		$defaults = array(
			'schedule' => array(
				'day'    => 25,
				'hour'   => 2,
				'minute' => 0,
				'tz'     => 'Asia/Tokyo',
			),
			'domain'   => array(
				'allowed_host' => '',
			),
			'mail'     => array(
				'enabled'      => false,
				'client_to'    => array(),
				'admin_to'     => array(),
				'from_address' => '',
				'from_name'    => '',
			),
			'output'   => array(
				'md_enabled'  => true,
				'pdf_enabled' => true,
			),
		);

		add_option(
			'wpmar_settings',
			$defaults,
			'',
			true
		);
	}

	/**
	 * Ensures timezone + hostname defaults once tables exist (requires Settings / Scheduler stubs).
	 *
	 * @return void
	 */
	protected static function ensure_site_defaults_and_schedule() {
		if ( ! defined( 'WPMAR_PLUGIN_DIR' ) ) {
			return;
		}

		$settings = WPMAR_Settings::get_all();

		if ( isset( $settings['schedule']['tz'] ) && '' === trim( (string) $settings['schedule']['tz'] ) ) {
			$settings['schedule']['tz'] = 'Asia/Tokyo';
		}

		$current_host = WPMAR_Domain_Gate::current_host();
		if (
			'' !== $current_host
			&& isset( $settings['domain']['allowed_host'] )
			&& '' === trim( (string) $settings['domain']['allowed_host'] )
		) {
			$settings['domain']['allowed_host'] = sanitize_text_field( $current_host );
		}

		WPMAR_Settings::update_all( $settings );

		WPMAR_Scheduler::reschedule();
	}
}
