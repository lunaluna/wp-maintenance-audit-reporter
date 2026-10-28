<?php
/**
 * WPMAR_Report_Repository::delete_row() のファイル削除のテスト.
 *
 * レポートを消すときに、管理者向け Markdown・クライアント向け PDF・
 * クライアント向け Markdown (1.8.0) の 3 つのファイルをすべて消すこと.
 * 保持期間による削除 (purge_older_than_months()) も delete_row() を通るので、同じ挙動になる.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

// 保存先を一時フォルダにする. 他のテストが先に定義していれば、その値をそのまま使う.
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-delete-row-test-content' );
}

if ( ! defined( 'WPMAR_PRIVATE_STORAGE_DIR' ) ) {
	define( 'WPMAR_PRIVATE_STORAGE_DIR', rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-delete-row-test-storage' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-private-storage.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-md-writer.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-report-repository.php';

/**
 * Covers WPMAR_Report_Repository::delete_row() artefact cleanup.
 */
final class ReportRepositoryDeleteRowTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->rrmdir( WPMAR_PRIVATE_STORAGE_DIR );
		mkdir( WPMAR_PRIVATE_STORAGE_DIR, 0755, true );

		$GLOBALS['_wpmar_test_options']         = array();
		$GLOBALS['_wpmar_test_is_multisite']    = false;
		$GLOBALS['_wpmar_test_current_blog_id'] = 1;
		$GLOBALS['wpdb']                        = new \WPMAR_Test_Fake_Wpdb();
	}

	protected function tearDown(): void {
		$this->rrmdir( WPMAR_PRIVATE_STORAGE_DIR );
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	/**
	 * 3 つのファイルがすべて消え、行も消えること.
	 *
	 * @return void
	 */
	public function test_deletes_admin_markdown_pdf_and_client_markdown_files(): void {
		$admin_md  = \WPMAR_MD_Writer::write_markdown_file( 'wpmar-report-example-com-admin-20261028-000000', 'admin' );
		$client_md = \WPMAR_MD_Writer::write_markdown_file( 'wpmar-report-example-com-client-20261028-5', 'client' );
		$pdf       = $this->write_fake_pdf();

		$this->seed_row(
			5,
			array(
				'md_file_path'        => $admin_md,
				'pdf_file_path'       => $pdf,
				'client_md_file_path' => $client_md,
			)
		);

		$abs = array_map( array( \WPMAR_MD_Writer::class, 'absolute_path_from_upload_relative' ), array( $admin_md, $pdf, $client_md ) );
		foreach ( $abs as $file ) {
			self::assertFileExists( $file );
		}

		self::assertTrue( ( new \WPMAR_Report_Repository() )->delete_row( 5 ) );

		foreach ( $abs as $file ) {
			self::assertFileDoesNotExist( $file );
		}
		self::assertArrayNotHasKey( '5', $GLOBALS['wpdb']->tables['wp_wpmar_reports'] );
	}

	/**
	 * 1.8.0 より前の行 (client_md_file_path の列が無い) でも、エラーにならずに消せること.
	 * マルチサイトでスキーマが未更新のブログの表が、この形になる.
	 *
	 * @return void
	 */
	public function test_deletes_a_row_from_a_table_without_the_client_md_column(): void {
		$admin_md = \WPMAR_MD_Writer::write_markdown_file( 'wpmar-report-example-com-admin-20261028-000001', 'admin' );

		$this->seed_row( 6, array( 'md_file_path' => $admin_md ) );

		self::assertTrue( ( new \WPMAR_Report_Repository() )->delete_row( 6 ) );
		self::assertFileDoesNotExist( \WPMAR_MD_Writer::absolute_path_from_upload_relative( $admin_md ) );
	}

	/**
	 * 偽の DB に行を入れる. 指定しない列は入れない (列が無い表を再現できるように).
	 *
	 * @param int                 $id      Report id.
	 * @param array<string,mixed> $columns File path columns.
	 * @return void
	 */
	private function seed_row( $id, array $columns ) {
		$GLOBALS['wpdb']->tables['wp_wpmar_reports'][ (string) $id ] = array_merge( array( 'id' => $id ), $columns );
	}

	/**
	 * PDF の代わりのファイルを pdf/ に置く. 削除の確認に PDF の中身は要らない.
	 *
	 * @return string `private:` 付きの相対パス.
	 */
	private function write_fake_pdf() {
		$dir  = \WPMAR_Private_Storage::pdf_dir();
		$file = $dir . 'wpmar-report-example-com-client-20261028-5-token.pdf';
		file_put_contents( $file, '%PDF-1.4 fake' );

		return \WPMAR_Private_Storage::relative_for_storage( $file );
	}

	/**
	 * @param string $dir Directory to remove recursively.
	 * @return void
	 */
	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		foreach ( scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			is_dir( $path ) ? $this->rrmdir( $path ) : unlink( $path );
		}
		rmdir( $dir );
	}
}
