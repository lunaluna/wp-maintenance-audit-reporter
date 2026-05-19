<?php
/**
 * Sample — register a Markdown chunk via `wpmar_backup_providers`.
 *
 * Copy to mu-plugins / small plugin and adjust. Output appears under the stakeholder
 * **バックアップ連携（拡張）** section (see Reports / mail).
 *
 * Replace the closure body with adapters that read Options API or uploads folders.
 *
 * @package WPMAR_Examples
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'wpmar_backup_providers',
	static function ( $providers, $settings ) {
		if ( ! is_array( $providers ) ) {
			$providers = array();
		}

		unset( $settings );

		$providers[] = array(
			'id'       => 'example_static_note',
			'label'    => __( 'Example backup adapter', 'wp-maintenance-audit-reporter' ),
			'markdown' => __( 'ここにカスタムのバックアップ状態をMarkdownで記述してください。', 'wp-maintenance-audit-reporter' ),
		);

		return $providers;
	},
	10,
	2
);
