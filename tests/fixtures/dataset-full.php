<?php
/**
 * A shrunk, anonymised stand-in for WPMAR_Data_Collector::gather()'s real return shape.
 *
 * Captured from an actual `wp maintenance-audit run --dry` dry_preview payload, then
 * reduced to a handful of representative rows per collection and stripped of any
 * real site name / URL / user identity. Keep the key structure exactly aligned with
 * WPMAR_Data_Collector::gather() — this fixture exists so Step 1+ tests never have
 * to guess at that shape.
 *
 * @package WPMAR\Tests
 */

return array(
	'meta'             => array(
		'blogname' => 'テスト保守サイト',
		'home_url' => 'https://example.test',
		'site_url' => 'https://example.test',
		'utc'      => '2026-08-20T15:51:14+00:00',
	),
	'core'             => array(
		'version'           => '7.1',
		'locale'            => 'ja',
		'available_updates' => array(),
	),
	'themes'           => array(
		'inventory' => array(
			array(
				'slug'    => 'sample-theme',
				'name'    => 'Sample Theme',
				'version' => '30.0.4',
				'active'  => true,
			),
			array(
				'slug'    => 'twentytwentyfive',
				'name'    => 'Twenty Twenty-Five',
				'version' => '1.5',
				'active'  => false,
			),
			array(
				'slug'    => 'twentytwentyfour',
				'name'    => 'Twenty Twenty-Four',
				'version' => '1.6',
				'active'  => false,
			),
		),
		'org'       => array(
			'sample-theme'     => array(
				'last_updated' => '2026-06-10 3:04pm GMT',
				'version'      => '30.0.4',
			),
			'twentytwentyfive' => array(
				'last_updated' => '2026-08-13 5:39pm GMT',
				'version'      => '1.5',
			),
		),
	),
	'plugins'          => array(
		'inventory' => array(
			array(
				'slug'     => 'sample-active-plugin',
				'basename' => 'sample-active-plugin/sample-active-plugin.php',
				'title'    => 'Sample Active Plugin',
				'version'  => '4.4.1',
				'active'   => true,
			),
			array(
				'slug'     => 'sample-inactive-plugin',
				'basename' => 'sample-inactive-plugin/plugin.php',
				'title'    => 'Sample Inactive Plugin',
				'version'  => '5.7.2',
				'active'   => false,
			),
			array(
				'slug'     => 'sample-mismatch-plugin',
				'basename' => 'sample-mismatch-plugin/plugin.php',
				'title'    => 'Sample Mismatch Plugin',
				'version'  => '1.6',
				'active'   => true,
			),
			array(
				'slug'     => 'sample-no-checksums-plugin',
				'basename' => 'sample-no-checksums-plugin/index.php',
				'title'    => 'Sample No-Checksums Plugin',
				'version'  => '1.0.1',
				'active'   => true,
			),
			array(
				'slug'     => 'sample-stale-plugin',
				'basename' => 'sample-stale-plugin/hello.php',
				'title'    => 'Sample Stale Plugin',
				'version'  => '1.7.2',
				'active'   => false,
			),
			array(
				'slug'     => 'wp-maintenance-audit-reporter',
				'basename' => 'wp-maintenance-audit-reporter/wp-maintenance-audit-reporter.php',
				'title'    => 'WP Maintenance Audit Reporter',
				'version'  => '1.5.0',
				'active'   => true,
			),
		),
		'org'       => array(
			'sample-active-plugin' => array(
				'last_updated' => '2026-06-10 3:04pm GMT',
				'version'      => '4.4.1',
			),
			'sample-mismatch-plugin' => array(
				'last_updated' => '2021-07-15 10:24am GMT',
				'version'      => '1.6',
			),
			'sample-stale-plugin'   => array(
				'last_updated' => '2025-10-24 4:13am GMT',
				'version'      => '1.7.2',
			),
		),
	),
	'users'            => array(
		array(
			'id'           => '2',
			'login'        => 'sample-admin',
			'display_name' => 'サンプル管理者',
			'email'        => 'sample-admin@example.test',
			'registered'   => '2019-02-27 10:19:08',
			'roles'        => 'administrator',
		),
	),
	'server'           => array(
		'php'          => '8.4.18',
		'mysql'        => '8.4.0',
		'wp_memory'    => '40M',
		'wp_debug'     => 'true',
		'script_debug' => 'false',
		'environment'  => 'local',
	),
	'plugins_outdated' => array(
		'tier_365' => array(
			array(
				'slug'  => 'sample-mismatch-plugin',
				'title' => 'Sample Mismatch Plugin',
				'days'  => 1862,
			),
		),
		'tier_180' => array(
			array(
				'slug'  => 'sample-stale-plugin',
				'title' => 'Sample Stale Plugin',
				'days'  => 300,
			),
		),
	),
	'checksums'        => array(
		'core'    => array(
			'version'                  => '7.1',
			'locale'                   => 'ja',
			'ok'                       => true,
			'manifest_ok'              => true,
			'checked_files'            => 3338,
			'mismatches'               => array(),
			'skipped_files'            => 671,
			'error'                    => '',
			'manifest_locale'          => 'ja',
			'manifest_locale_fallback' => false,
		),
		'plugins' => array(
			'sample-active-plugin'       => array(
				'version'        => '4.4.1',
				'status'         => 'ok',
				'checked_files'  => 37,
				'mismatches'     => array(),
				'skipped_files'  => 0,
				'error'          => '',
				'manifest_found' => true,
			),
			'sample-inactive-plugin'     => array(
				'version'        => '5.7.2',
				'status'         => 'ok',
				'checked_files'  => 47,
				'mismatches'     => array(),
				'skipped_files'  => 0,
				'error'          => '',
				'manifest_found' => true,
			),
			'sample-mismatch-plugin'     => array(
				'version'        => '1.6',
				'status'         => 'mismatch',
				'checked_files'  => 5,
				'mismatches'     => array(
					array(
						'file'   => 'src/embed.php',
						'reason' => 'hash_mismatch',
					),
				),
				'skipped_files'  => 0,
				'error'          => '',
				'manifest_found' => true,
			),
			'sample-no-checksums-plugin' => array(
				'version'        => '1.0.1',
				'status'         => 'no_checksums',
				'checked_files'  => 0,
				'mismatches'     => array(),
				'skipped_files'  => 0,
				'error'          => '',
				'manifest_found' => false,
			),
			'sample-stale-plugin'        => array(
				'version'        => '1.7.2',
				'status'         => 'ok',
				'checked_files'  => 1,
				'mismatches'     => array(),
				'skipped_files'  => 0,
				'error'          => '',
				'manifest_found' => true,
			),
			'wp-maintenance-audit-reporter' => array(
				'version'        => '1.5.0',
				'status'         => 'no_checksums',
				'checked_files'  => 0,
				'mismatches'     => array(),
				'skipped_files'  => 0,
				'error'          => '',
				'manifest_found' => false,
			),
		),
	),
	'security'         => array(
		'ssl'                  => array(
			'status' => 'not_applicable',
			'notes'  => array(
				'サイト URL が https でないため、証明書期限の接続検査は行っていません。',
			),
		),
		'php_eol'              => array(
			'branch'       => '8.4',
			'current'      => '8.4.18',
			'eol_date'     => '2028-12-31T00:00:00+00:00',
			'status'       => 'ok',
			'days_to_eol'  => 863,
			'notes'        => array(),
		),
		'recommended_versions' => array(
			'wordpress' => array(
				'version'          => '7.1',
				'update_available' => false,
				'notes'            => array(),
			),
			'php'       => array(
				'version'   => '8.4.18',
				'below_8_1' => false,
				'notes'     => array(),
			),
			'mysql'     => array(
				'version' => '8.4.0',
				'legacy'  => false,
				'notes'   => array(),
			),
		),
		'admin_activity'       => array(
			'stale_threshold_days' => 90,
			'users'                => array(
				array(
					'user_id'    => 2,
					'user_login' => 'sample-admin',
					'last_seen'  => '2026-08-20T14:12:40+00:00',
				),
			),
			'stale_user_ids'       => array(),
		),
		'wp_config'            => array(
			'candidates' => array(
				array(
					'path'          => '/var/www/example.test/wp-config.php',
					'perm_octal'    => '0644',
					'warn_relaxed'  => false,
					'status'        => 'ok',
				),
				array(
					'path'   => '/var/www/wp-config.php',
					'status' => 'missing_or_unreadable',
				),
			),
		),
		'debug'                => array(
			'environment_type'      => 'local',
			'wp_debug'              => true,
			'script_debug'          => false,
			'production_debug_warn' => false,
			'notes'                 => array(),
		),
		'warning_count'        => 0,
		'summary_codes'        => array(),
	),
	'backup'           => array(
		'providers' => array(),
	),
	'performance'      => array(
		'timestamp_utc' => '2026-08-20T15:51:14+00:00',
		'db_tables'     => array(
			'ok'       => true,
			'error'    => '',
			'top'      => array(
				array(
					'name'  => 'wp_options',
					'bytes' => 5242880,
				),
				array(
					'name'  => 'wp_posts',
					'bytes' => 3145728,
				),
			),
			'total_mb' => 8.0,
			'database' => 'local',
		),
	),
);
