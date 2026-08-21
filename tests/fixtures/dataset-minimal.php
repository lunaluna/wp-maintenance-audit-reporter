<?php
/**
 * Empty-inventory / zero-change counterpart to dataset-full.php.
 *
 * Same top-level key structure as WPMAR_Data_Collector::gather(), but every
 * collection is empty so tests can assert the "nothing to report" rendering
 * path (Markdown sections, mail summaries, dry_brevity counts, etc.).
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
		'inventory' => array(),
		'org'       => array(),
	),
	'plugins'          => array(
		'inventory' => array(),
		'org'       => array(),
	),
	'users'            => array(),
	'server'           => array(
		'php'          => '8.4.18',
		'mysql'        => '8.4.0',
		'wp_memory'    => '40M',
		'wp_debug'     => 'false',
		'script_debug' => 'false',
		'environment'  => 'production',
	),
	'plugins_outdated' => array(
		'tier_365' => array(),
		'tier_180' => array(),
	),
	'checksums'        => array(
		'core'    => array(
			'version'                  => '7.1',
			'locale'                   => 'ja',
			'ok'                       => true,
			'manifest_ok'              => true,
			'checked_files'            => 0,
			'mismatches'               => array(),
			'skipped_files'            => 0,
			'error'                    => '',
			'manifest_locale'          => 'ja',
			'manifest_locale_fallback' => false,
		),
		'plugins' => array(),
	),
	'security'         => array(
		'ssl'                  => array(
			'status' => 'ok',
			'notes'  => array(),
		),
		'php_eol'              => array(
			'branch'      => '8.4',
			'current'     => '8.4.18',
			'eol_date'    => '2028-12-31T00:00:00+00:00',
			'status'      => 'ok',
			'days_to_eol' => 863,
			'notes'       => array(),
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
			'users'                => array(),
			'stale_user_ids'       => array(),
		),
		'wp_config'            => array(
			'candidates' => array(),
		),
		'debug'                => array(
			'environment_type'      => 'production',
			'wp_debug'              => false,
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
	'performance'      => array(),
);
