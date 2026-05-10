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
	 * Activation entry point.
	 *
	 * @return void
	 */
	public static function activate() {
		self::maybe_create_tables();
		self::maybe_seed_defaults();

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
				'md_enabled' => true,
			),
		);

		add_option(
			'wpmar_settings',
			$defaults,
			'',
			true
		);
	}
}
