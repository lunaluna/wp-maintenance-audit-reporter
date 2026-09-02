<?php
/**
 * Renders saved WPMAR_Snapshot_Repository rows as read-only Markdown.
 *
 * Parsedown (the plugin's only Markdown-to-HTML converter) ships in the optional
 * `vendor-pdf.zip` on-demand install, not the distributed plugin ZIP — so a site
 * without the PDF add-on has no Markdown renderer available. This class therefore
 * never converts to HTML; it only builds a Markdown string, which the caller
 * escapes with esc_html() and prints inside a <pre> (see render_section() in a
 * later step). No external dependency, so behaviour never depends on whether the
 * PDF library happens to be installed.
 *
 * @package WPMAR
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pure Markdown formatter for snapshot rows — takes already-loaded data, touches
 * neither $wpdb nor the current blog, so it is reusable verbatim by both the
 * per-site system status screen and the network cross-site view.
 */
class WPMAR_Snapshot_Preview {

	/**
	 * Builds the preview Markdown from already-loaded rows.
	 *
	 * Each $by_type entry is rendered as its own `##` section, in the order given
	 * by the caller — this class does not decide which types exist or in what
	 * order; the caller (system status page / network view) does, via
	 * WPMAR_Snapshot_Repository::types().
	 *
	 * @param array<string,array<int,array{id:int,captured_at:string,payload:array<string,mixed>}>> $by_type Rows per snapshot_type, newest generation first.
	 * @param array{table?:string,site_label?:string}                                               $context Heading context; an empty/absent site_label hides the "site:" line.
	 * @return string
	 */
	public static function to_markdown( array $by_type, array $context = array() ) {
		$lines = array();

		$lines[] = __( '# スナップショット（差分比較の基準データ）', 'wp-maintenance-audit-reporter' );
		$lines[] = '';

		if ( ! empty( $context['table'] ) ) {
			$lines[] = '* ' . __( '保存先テーブル', 'wp-maintenance-audit-reporter' ) . ': `' . $context['table'] . '`';
		}
		if ( ! empty( $context['site_label'] ) ) {
			$lines[] = '* ' . __( 'サイト', 'wp-maintenance-audit-reporter' ) . ': ' . $context['site_label'];
		}
		$lines[] = '* ' . __( '時刻はすべて UTC', 'wp-maintenance-audit-reporter' );
		$lines[] = '';

		if ( self::all_empty( $by_type ) ) {
			$lines[] = __( '* スナップショットはまだ保存されていません。監査を実行し、手動実行の場合は『スナップショットを保存する（差分比較用）』にチェックしてください。', 'wp-maintenance-audit-reporter' );

			return implode( "\n", $lines );
		}

		foreach ( $by_type as $type => $rows ) {
			$headings = self::column_headings( $type );

			$lines[] = '## ' . $headings['heading'];
			$lines[] = '';
			$lines   = array_merge( $lines, self::render_type_section( $type, is_array( $rows ) ? $rows : array() ) );
		}

		return rtrim( implode( "\n", $lines ) ) . "\n";
	}

	/**
	 * Whether every type in $by_type has zero rows (or $by_type itself is empty) —
	 * the trigger for the single top-level "nothing saved yet" message instead of
	 * one empty per-type section each.
	 *
	 * @param array<string,array<int,mixed>> $by_type Rows per snapshot_type.
	 * @return bool
	 */
	protected static function all_empty( array $by_type ) {
		foreach ( $by_type as $rows ) {
			if ( ! empty( $rows ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Renders one type's "最新" / "前回" subsections (or the empty-state line).
	 *
	 * @param string                                                                  $type Snapshot type, used only for column_headings() lookup.
	 * @param array<int,array{id:int,captured_at:string,payload:array<string,mixed>}> $rows Newest generation first; index 0 is "最新", index 1 is "前回".
	 * @return array<int,string> Lines to append (no trailing newline joins here).
	 */
	protected static function render_type_section( $type, array $rows ) {
		if ( empty( $rows ) ) {
			return array(
				__( '* 記録がありません。', 'wp-maintenance-audit-reporter' ),
				'',
			);
		}

		$labels   = array(
			__( '最新', 'wp-maintenance-audit-reporter' ),
			__( '前回', 'wp-maintenance-audit-reporter' ),
		);
		$headings = self::column_headings( $type );
		$lines    = array();

		foreach ( array_values( $rows ) as $index => $row ) {
			// Only two generations are ever kept (prune_keep()'s default); a third
			// row would have no "最新"/"前回" label to render under.
			if ( ! isset( $labels[ $index ] ) ) {
				break;
			}

			$captured_at = isset( $row['captured_at'] ) ? (string) $row['captured_at'] : '';
			$id          = isset( $row['id'] ) ? (int) $row['id'] : 0;

			$lines[] = sprintf( '### %1$s — %2$s UTC（id %3$d）', $labels[ $index ], $captured_at, $id );
			$lines[] = '';

			$payload = isset( $row['payload'] ) && is_array( $row['payload'] ) ? $row['payload'] : array();

			if ( empty( $payload ) ) {
				$lines[] = __( '* 項目がありません。', 'wp-maintenance-audit-reporter' );
				$lines[] = '';
				continue;
			}

			$lines[] = '| ' . self::cell( $headings['col1'] ) . ' | ' . self::cell( $headings['col2'] ) . ' |';
			$lines[] = '| --- | --- |';
			foreach ( $payload as $key => $value ) {
				$lines[] = '| ' . self::cell( $key ) . ' | ' . self::cell( $value ) . ' |';
			}
			$lines[] = '';
		}

		if ( 1 === count( $rows ) ) {
			$lines[] = __( '* 前回のスナップショットはまだありません（監査の実行が 1 回のみ）。', 'wp-maintenance-audit-reporter' );
			$lines[] = '';
		}

		return $lines;
	}

	/**
	 * Column heading + labels for one snapshot_type.
	 *
	 * The four known types map to fixed Japanese headings; anything else (a future
	 * dimension WPMAR_Snapshot_Repository::types() picks up) falls back to a
	 * generic key/value heading using the raw type string, so new types show up
	 * without a code change here.
	 *
	 * @param string $type Snapshot type.
	 * @return array{heading:string,col1:string,col2:string}
	 */
	protected static function column_headings( $type ) {
		$known = array(
			'core'    => array(
				'heading' => __( 'WordPress コア（core）', 'wp-maintenance-audit-reporter' ),
				'col1'    => __( '項目', 'wp-maintenance-audit-reporter' ),
				'col2'    => __( '値', 'wp-maintenance-audit-reporter' ),
			),
			'themes'  => array(
				'heading' => __( 'テーマ（themes）', 'wp-maintenance-audit-reporter' ),
				'col1'    => __( 'スラッグ', 'wp-maintenance-audit-reporter' ),
				'col2'    => __( 'バージョン', 'wp-maintenance-audit-reporter' ),
			),
			'plugins' => array(
				'heading' => __( 'プラグイン（plugins）', 'wp-maintenance-audit-reporter' ),
				'col1'    => __( 'スラッグ', 'wp-maintenance-audit-reporter' ),
				'col2'    => __( 'バージョン', 'wp-maintenance-audit-reporter' ),
			),
			'users'   => array(
				'heading' => __( 'ユーザー（users）', 'wp-maintenance-audit-reporter' ),
				'col1'    => __( 'ユーザー ID', 'wp-maintenance-audit-reporter' ),
				'col2'    => __( 'シグネチャ（メールアドレス｜権限）', 'wp-maintenance-audit-reporter' ),
			),
		);

		if ( isset( $known[ $type ] ) ) {
			return $known[ $type ];
		}

		return array(
			'heading' => (string) $type,
			'col1'    => __( 'キー', 'wp-maintenance-audit-reporter' ),
			'col2'    => __( '値', 'wp-maintenance-audit-reporter' ),
		);
	}

	/**
	 * Escapes one table cell: collapses embedded newlines (which would otherwise
	 * break the row onto multiple lines) and escapes `|` (which would otherwise end
	 * the cell early) — same convention as WPMAR_Runner::render_users_markdown_table().
	 * Non-scalar values (a future type's payload need not be string→string) are
	 * JSON-encoded first rather than dropped.
	 *
	 * @param mixed $value Raw key or value.
	 * @return string
	 */
	protected static function cell( $value ) {
		if ( is_array( $value ) || is_object( $value ) ) {
			$value = wp_json_encode( $value );
		}

		$value = str_replace( array( "\r\n", "\r", "\n" ), ' ', (string) $value );

		return str_replace( '|', '\\|', $value );
	}
}
