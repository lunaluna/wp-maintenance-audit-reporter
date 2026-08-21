<?php
/**
 * Mirrors WPMAR_Settings::defaults() as a standalone array so tests don't need
 * to load the production class just to get a baseline settings envelope.
 *
 * @package WPMAR\Tests
 */

return array(
	'schedule'    => array(
		'day'    => 25,
		'hour'   => 2,
		'minute' => 0,
		'tz'     => 'Asia/Tokyo',
	),
	'domain'      => array(
		'allowed_host' => '',
	),
	'mail'        => array(
		'enabled'      => false,
		'client_to'    => array(),
		'admin_to'     => array(),
		'from_address' => '',
		'from_name'    => '',
	),
	'output'      => array(
		'md_enabled'  => true,
		'pdf_enabled' => true,
	),
	'checksums'   => array(
		'core_exclude_paths'   => array(),
		'plugin_exclude_rules' => array(),
	),
	'retention'   => array(
		'months' => 12,
	),
	'security'    => array(
		'ssl_check_enabled' => true,
		'admin_stale_days'  => 90,
	),
	'performance' => array(
		'db_size_enabled' => false,
	),
);
