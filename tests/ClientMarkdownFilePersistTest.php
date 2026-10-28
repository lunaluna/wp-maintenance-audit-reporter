<?php
/**
 * WPMAR_Runner::persist_client_markdown_file() (1.8.0) のテスト.
 *
 * クライアント向け Markdown をファイルに書き出し、そのパスを INSERT の後の UPDATE で
 * レポートの行に保存する処理. 単一サイトとネットワークの両方から呼ばれる.
 * UPDATE に失敗したとき (マルチサイトでメインサイトのテーブルに列がまだ無いときなど) に、
 * 書き出したファイルを消すことも確かめる.
 *
 * @package WPMAR\Tests
 *
 * @runTestsInSeparateProcesses
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

// 保存先を一時フォルダにする. PdfGenerationTest と同じやり方.
if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-client-md-persist-test-content' );
}

if ( ! defined( 'WPMAR_PRIVATE_STORAGE_DIR' ) ) {
	define( 'WPMAR_PRIVATE_STORAGE_DIR', rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-client-md-persist-test-storage' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-private-storage.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-md-writer.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-report-repository.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-logger.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-runner.php';

/**
 * UPDATE の結果を差し替えられるリポジトリ. DB には触らない.
 */
final class ScriptedClientMdReportRepository extends \WPMAR_Report_Repository {

	/**
	 * update_client_md_file_path() が返す値.
	 *
	 * @var bool
	 */
	public $update_result = true;

	/**
	 * update_client_md_file_path() に渡された引数.
	 *
	 * @var array<int,array{0:int,1:string}>
	 */
	public $update_calls = array();

	/**
	 * DB を使わないので、親のコンストラクタ ($wpdb の参照) は呼ばない.
	 */
	public function __construct() {}

	/**
	 * {@inheritDoc}
	 *
	 * @param int    $id            Report primary key.
	 * @param string $relative_path Stored path.
	 * @return bool
	 */
	public function update_client_md_file_path( $id, $relative_path ) {
		$this->update_calls[] = array( (int) $id, (string) $relative_path );
		return $this->update_result;
	}
}

/**
 * Covers WPMAR_Runner::persist_client_markdown_file().
 */
final class ClientMarkdownFilePersistTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->rrmdir( WPMAR_PRIVATE_STORAGE_DIR );
		mkdir( WPMAR_PRIVATE_STORAGE_DIR, 0755, true );

		$GLOBALS['_wpmar_test_options']         = array();
		$GLOBALS['_wpmar_test_is_multisite']    = false;
		$GLOBALS['_wpmar_test_current_blog_id'] = 1;
	}

	protected function tearDown(): void {
		$this->rrmdir( WPMAR_PRIVATE_STORAGE_DIR );
		parent::tearDown();
	}

	/**
	 * 書き出しと UPDATE が成功したら、ファイルが残り、そのパスが行に保存されること.
	 *
	 * @return void
	 */
	public function test_writes_the_client_body_and_stores_its_path_on_the_report_row(): void {
		$repo = new ScriptedClientMdReportRepository();

		$stored = \WPMAR_Runner::persist_client_markdown_file( $repo, 12, "# クライアント向け\n\n本文", 'wpmar-report-example-com-client-20261028-12' );

		self::assertNotSame( '', $stored );
		self::assertStringStartsWith( \WPMAR_Private_Storage::PREFIX, $stored );
		self::assertSame( array( array( 12, $stored ) ), $repo->update_calls );

		$abs = \WPMAR_MD_Writer::absolute_path_from_upload_relative( $stored );
		self::assertFileExists( $abs );
		self::assertSame( "# クライアント向け\n\n本文", file_get_contents( $abs ) );

		// 管理者向け Markdown と同じ reports/ に入ること.
		self::assertStringContainsString( '/reports/', wp_normalize_path( $abs ) );
		self::assertStringContainsString( 'wpmar-report-example-com-client-20261028-12-', basename( $abs ) );
	}

	/**
	 * UPDATE に失敗したら (列が無いときなど)、書き出したファイルを消して空文字を返すこと.
	 * どの行からも参照されないファイルを残さないため.
	 *
	 * @return void
	 */
	public function test_removes_the_written_file_when_the_path_update_fails(): void {
		$repo                = new ScriptedClientMdReportRepository();
		$repo->update_result = false;

		$stored = \WPMAR_Runner::persist_client_markdown_file( $repo, 34, 'body', 'wpmar-network-report-example-com-client-20261028-34' );

		self::assertSame( '', $stored );
		self::assertCount( 1, $repo->update_calls );

		// UPDATE には書き出したパスが渡っていた (= 一度はファイルを書いた) が、今は reports/ に何も残っていないこと.
		self::assertStringStartsWith( \WPMAR_Private_Storage::PREFIX, $repo->update_calls[0][1] );
		self::assertSame( array(), $this->files_under( WPMAR_PRIVATE_STORAGE_DIR . '/reports' ) );
	}

	/**
	 * @param string $dir Directory to list.
	 * @return array<int,string> Regular files directly under $dir (protection files excluded).
	 */
	private function files_under( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return array();
		}
		$files = array();
		foreach ( scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item || 0 === strpos( $item, '.' ) || 'index.php' === $item || 'web.config' === $item ) {
				continue;
			}
			if ( is_file( $dir . '/' . $item ) ) {
				$files[] = $item;
			}
		}
		return $files;
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
