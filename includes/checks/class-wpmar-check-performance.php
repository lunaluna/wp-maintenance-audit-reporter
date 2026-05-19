<?php
/**
 * Optional database size sampling via information_schema (off by default).
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Collects optional table-size telemetry when enabled in settings.
 */
class WPMAR_Check_Performance {

	/**
	 * Runs the DB size probe when `db_size_enabled` is true.
	 *
	 * @param array<string,mixed> $performance Normalised `wpmar_settings.performance` slice.
	 * @return array<string,mixed>
	 */
	public function collect( array $performance ) {
		if ( empty( $performance['db_size_enabled'] ) ) {
			return array();
		}

		return array(
			'timestamp_utc' => gmdate( 'c' ),
			'db_tables'     => $this->probe_table_sizes(),
		);
	}

	/**
	 * Turns filter extras into trailing Markdown snippets (pure helper for tests).
	 *
	 * @param array<int,mixed> $extras Output of {@see apply_filters( 'wpmar_report_sections' )} style lists.
	 * @return string
	 */
	public static function stringify_section_extras( array $extras ) {
		$chunks = array();
		foreach ( $extras as $item ) {
			if ( is_string( $item ) && '' !== trim( $item ) ) {
				$chunks[] = trim( $item );
				continue;
			}
			if ( is_array( $item ) && ! empty( $item['markdown'] ) && is_string( $item['markdown'] ) ) {
				$chunk = trim( $item['markdown'] );
				if ( '' !== $chunk ) {
					$chunks[] = $chunk;
				}
			}
		}

		return '' !== implode( "\n\n", $chunks )
			? implode( "\n\n", $chunks ) . "\n\n"
			: '';
	}

	/**
	 * Queries information_schema.TABLES sizes for DB_NAME sorted desc.
	 *
	 * @return array<string,mixed>
	 */
	protected function probe_table_sizes() {
		global $wpdb;

		if ( ! defined( 'DB_NAME' ) || '' === DB_NAME ) {
			return array(
				'ok'       => false,
				'error'    => 'db_name_unknown',
				'top'      => array(),
				'total_mb' => 0.0,
				'database' => '',
			);
		}

		if ( ! ( $wpdb instanceof wpdb ) ) {
			return array(
				'ok'       => false,
				'error'    => 'wpdb_missing',
				'top'      => array(),
				'total_mb' => 0.0,
				'database' => sanitize_text_field( DB_NAME ),
			);
		}

		$db_name = sanitize_text_field( (string) DB_NAME );

		if ( '' === $db_name ) {
			return array(
				'ok'       => false,
				'error'    => 'db_name_bad',
				'top'      => array(),
				'total_mb' => 0.0,
				'database' => '',
			);
		}

		$suppress_errors_prior = $wpdb->suppress_errors( true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- information_schema.TABLES telemetry (optional diagnostics).
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT TABLE_NAME AS `name`, ROUND( COALESCE( DATA_LENGTH, 0 ) + COALESCE( INDEX_LENGTH, 0 ) ) AS `bytes`
				FROM information_schema.TABLES
				WHERE TABLE_SCHEMA = %s
				ORDER BY `bytes` DESC
				LIMIT %d',
				$db_name,
				40
			),
			ARRAY_A
		);

		$sql_error = sanitize_text_field( (string) $wpdb->last_error );
		$wpdb->suppress_errors( $suppress_errors_prior );

		$failure_reason = 'unexpected_null';
		if ( '' !== $sql_error ) {
			$failure_reason = $sql_error;
		}

		if ( null === $rows ) {

			return array(
				'ok'       => false,
				'error'    => 'query_failed:' . substr( $failure_reason, 0, 200 ),
				'top'      => array(),
				'total_mb' => 0.0,
				'database' => $db_name,
			);
		}

		if ( '' !== $sql_error ) {
			return array(
				'ok'       => false,
				'error'    => 'query_failed:' . substr( $failure_reason, 0, 200 ),
				'top'      => array(),
				'total_mb' => 0.0,
				'database' => $db_name,
			);
		}

		$top         = array();
		$total_bytes = 0;

		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( empty( $row['name'] ) ) {
				continue;
			}
			$name = sanitize_text_field( (string) $row['name'] );
			if ( '' === $name ) {
				continue;
			}
			$b            = isset( $row['bytes'] ) ? (int) round( floatval( $row['bytes'] ), 0 ) : 0;
			$b            = max( $b, 0 );
			$top[]        = array(
				'table' => $name,
				'mb'    => round( $b / 1048576, 2 ),
			);
			$total_bytes += $b;
		}

		return array(
			'ok'          => true,
			'database'    => $db_name,
			'total_mb'    => round( $total_bytes / 1048576, 2 ),
			'top'         => $top,
			'table_count' => count( $top ),
		);
	}
}
