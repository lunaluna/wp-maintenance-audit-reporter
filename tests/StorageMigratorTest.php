<?php
/**
 * Unit tests for WPMAR_Storage_Migrator (S-1: legacy file migration + rollback).
 *
 * Covers idempotency, cursor-based resume across batches, rollback of a file move
 * when the paired DB update fails, cleanup limited to known legacy patterns, and
 * that --dry-run never mutates anything.
 *
 * Runs with process isolation because WP_CONTENT_DIR / WPMAR_PRIVATE_STORAGE_DIR are
 * PHP constants; both are defined once, at file scope, to directories that persist
 * for the whole class (see PrivateStorageResolveTest.php for why: PHPUnit's isolation
 * replays constants from an earlier test into every later isolated process here).
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

require_once __DIR__ . '/wp-stubs.php';

if ( ! defined( 'WP_CONTENT_DIR' ) ) {
	define( 'WP_CONTENT_DIR', rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-migrator-test-content' );
}

if ( ! defined( 'WPMAR_PRIVATE_STORAGE_DIR' ) ) {
	define( 'WPMAR_PRIVATE_STORAGE_DIR', rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-migrator-test-configured' );
}

require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-md-writer.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-private-storage.php';
require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-storage-migrator.php';

/**
 * Minimal in-memory wpdb double supporting exactly the query shapes the migrator issues.
 */
class WPMAR_Test_Fake_Wpdb_Storage {

	/** @var string */
	public $prefix = 'wp_';

	/** @var array<int,array<string,mixed>> */
	public $reports = array();

	/** @var array<int,array<string,mixed>> */
	public $jobs = array();

	/** @var array<int,array{0:string,1:array<string,mixed>,2:array<string,mixed>}> */
	public $update_calls = array();

	/** @var array<int,string> Keys like 'reports:5' or 'jobs:abc' that should fail update(). */
	public $fail_update_for = array();

	/**
	 * @param string $query Query text (with %d/%s placeholders — never substituted here).
	 * @param mixed  ...$args Bound args.
	 * @return array{0:string,1:array<int,mixed>}
	 */
	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		return array( $query, $args );
	}

	/**
	 * @param array{0:string,1:array<int,mixed>}|string $prepared Prepared tuple.
	 * @param mixed                                      $output   Ignored.
	 * @return array<int,array<string,mixed>>
	 */
	public function get_results( $prepared, $output = null ) {
		unset( $output );
		$query = is_array( $prepared ) ? $prepared[0] : $prepared;
		$args  = is_array( $prepared ) && isset( $prepared[1] ) ? $prepared[1] : array();

		if ( false !== strpos( $query, 'wpmar_reports' ) ) {
			$cursor = isset( $args[0] ) ? (int) $args[0] : 0;
			$limit  = isset( $args[1] ) ? (int) $args[1] : 20;

			$rows = array_values(
				array_filter(
					$this->reports,
					static function ( $row ) use ( $cursor ) {
						return (int) $row['id'] > $cursor;
					}
				)
			);
			usort(
				$rows,
				static function ( $a, $b ) {
					return $a['id'] <=> $b['id'];
				}
			);

			return array_slice( $rows, 0, $limit );
		}

		if ( false !== strpos( $query, 'wpmar_jobs' ) ) {
			$cursor = isset( $args[0] ) ? (string) $args[0] : '';
			$limit  = isset( $args[1] ) ? (int) $args[1] : 20;

			$rows = array_values(
				array_filter(
					$this->jobs,
					static function ( $row ) use ( $cursor ) {
						return (string) $row['id'] > $cursor;
					}
				)
			);
			usort(
				$rows,
				static function ( $a, $b ) {
					return strcmp( (string) $a['id'], (string) $b['id'] );
				}
			);

			return array_slice( $rows, 0, $limit );
		}

		return array();
	}

	/**
	 * @param string $query Query text.
	 * @return int
	 */
	public function get_var( $query ) {
		if ( false !== strpos( $query, 'wpmar_reports' ) ) {
			return count( $this->reports );
		}
		if ( false !== strpos( $query, 'wpmar_jobs' ) ) {
			return count( $this->jobs );
		}

		return 0;
	}

	/**
	 * @param string                $table        Table name (contains 'wpmar_reports' or 'wpmar_jobs').
	 * @param array<string,mixed>   $data         Columns to set.
	 * @param array<string,mixed>   $where        Must contain 'id'.
	 * @param array<int,string>|null $data_format  Ignored.
	 * @param array<int,string>|null $where_format Ignored.
	 * @return bool|int
	 */
	public function update( $table, $data, $where, $data_format = null, $where_format = null ) {
		unset( $data_format, $where_format );

		$target = false !== strpos( $table, 'wpmar_reports' ) ? 'reports' : 'jobs';
		$id     = isset( $where['id'] ) ? $where['id'] : null;
		$key    = $target . ':' . $id;

		if ( in_array( $key, $this->fail_update_for, true ) ) {
			return false;
		}

		foreach ( $this->{$target} as &$row ) {
			if ( (string) $row['id'] === (string) $id ) {
				foreach ( $data as $col => $value ) {
					$row[ $col ] = $value;
				}
				$this->update_calls[] = array( $table, $data, $where );

				return 1;
			}
		}

		return false;
	}
}

class StorageMigratorTest extends TestCase {

	/** @var WPMAR_Test_Fake_Wpdb_Storage */
	private $db;

	protected function setUp(): void {
		parent::setUp();

		$this->rrmdir( WPMAR_PRIVATE_STORAGE_DIR );
		mkdir( WPMAR_PRIVATE_STORAGE_DIR, 0755, true );

		$uploads_base = rtrim( sys_get_temp_dir(), '/\\' ) . '/wpmar-migrator-test-uploads';
		$this->rrmdir( $uploads_base );
		mkdir( $uploads_base, 0777, true );

		$GLOBALS['_wpmar_test_upload_basedir']  = $uploads_base;
		$GLOBALS['_wpmar_test_options']         = array();
		$GLOBALS['_wpmar_test_is_multisite']    = false;
		$GLOBALS['_wpmar_test_current_blog_id'] = 1;

		$this->db           = new WPMAR_Test_Fake_Wpdb_Storage();
		$GLOBALS['wpdb']    = $this->db;
	}

	protected function tearDown(): void {
		chmod( WPMAR_PRIVATE_STORAGE_DIR, 0755 );
		$this->rrmdir( WPMAR_PRIVATE_STORAGE_DIR );
		if ( isset( $GLOBALS['_wpmar_test_upload_basedir'] ) ) {
			$this->rrmdir( $GLOBALS['_wpmar_test_upload_basedir'] );
		}
		unset(
			$GLOBALS['_wpmar_test_upload_basedir'],
			$GLOBALS['_wpmar_test_options'],
			$GLOBALS['_wpmar_test_is_multisite'],
			$GLOBALS['_wpmar_test_current_blog_id'],
			$GLOBALS['wpdb']
		);
		parent::tearDown();
	}

	private function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- test cleanup only.
		@chmod( $dir, 0755 );
		foreach ( scandir( $dir ) as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) ) {
				$this->rrmdir( $path );
			} else {
				unlink( $path );
			}
		}
		rmdir( $dir );
	}

	/**
	 * Creates a legacy (pre-1.3.1) uploads-relative report file + row.
	 *
	 * @param int    $id       Report id.
	 * @param string $basename Filename without extension.
	 * @return string Uploads-relative stored value (e.g. 'wpmar/wpmar-report-x.md').
	 */
	private function seed_legacy_md_file( $id, $basename ) {
		$uploads = $GLOBALS['_wpmar_test_upload_basedir'];
		$dir     = $uploads . '/wpmar';
		if ( ! is_dir( $dir ) ) {
			mkdir( $dir, 0777, true );
		}
		$file = $dir . '/' . $basename . '.md';
		file_put_contents( $file, 'legacy markdown body ' . $id );

		return 'wpmar/' . $basename . '.md';
	}

	public function test_migrate_moves_eligible_file_and_updates_db() {
		$rel = $this->seed_legacy_md_file( 1, 'wpmar-report-example-1' );
		$this->db->reports[] = array(
			'id'            => 1,
			'md_file_path'  => $rel,
			'pdf_file_path' => '',
		);

		$state = \WPMAR_Storage_Migrator::run_all( 'migrate', 20 );

		$this->assertSame( 'done', $state['state'] );
		$this->assertStringStartsWith( 'private:reports/', $this->db->reports[0]['md_file_path'] );

		$new_abs = \WPMAR_Private_Storage::resolve( $this->db->reports[0]['md_file_path'] );
		$this->assertFileExists( $new_abs );
		$this->assertStringContainsString( 'wpmar-report-example-1-', basename( $new_abs ) );

		// resolve() returns a path candidate regardless of whether a file exists there
		// (callers check is_file() themselves) — so the move is verified via is_file(),
		// not by expecting resolve() to start failing once the source is gone.
		$old_abs = \WPMAR_Private_Storage::resolve( $rel );
		$this->assertFalse( is_file( $old_abs ), 'the legacy file should have been moved out of its old location' );
	}

	public function test_migrate_is_idempotent_once_done() {
		$rel = $this->seed_legacy_md_file( 1, 'wpmar-report-example-1' );
		$this->db->reports[] = array(
			'id'            => 1,
			'md_file_path'  => $rel,
			'pdf_file_path' => '',
		);

		$first  = \WPMAR_Storage_Migrator::run_all( 'migrate', 20 );
		$second = \WPMAR_Storage_Migrator::run_batch( 'migrate', 20 );

		$this->assertSame( 'done', $first['state'] );
		$this->assertSame( $first, $second, 'a second call once done must be a pure no-op' );
	}

	public function test_migrate_resumes_from_cursor_across_batches() {
		for ( $i = 1; $i <= 3; $i++ ) {
			$rel                    = $this->seed_legacy_md_file( $i, 'wpmar-report-example-' . $i );
			$this->db->reports[]    = array(
				'id'            => $i,
				'md_file_path'  => $rel,
				'pdf_file_path' => '',
			);
		}

		// Batch size 1: the first call only advances the reports phase by one row.
		$state = \WPMAR_Storage_Migrator::run_batch( 'migrate', 1 );

		$this->assertSame( 'running', $state['state'] );
		$this->assertSame( 1, $state['cursor'] );
		$this->assertStringStartsWith( 'private:reports/', $this->db->reports[0]['md_file_path'] );
		$this->assertStringStartsWith( 'wpmar/', $this->db->reports[1]['md_file_path'] );
		$this->assertStringStartsWith( 'wpmar/', $this->db->reports[2]['md_file_path'] );

		// Resuming (not restarting) picks up from cursor=1, not from the top.
		$final = \WPMAR_Storage_Migrator::run_all( 'migrate', 1 );

		$this->assertSame( 'done', $final['state'] );
		foreach ( $this->db->reports as $row ) {
			$this->assertStringStartsWith( 'private:reports/', $row['md_file_path'] );
		}
	}

	public function test_migrate_rolls_back_file_move_when_db_update_fails() {
		$rel = $this->seed_legacy_md_file( 1, 'wpmar-report-example-1' );
		$this->db->reports[]        = array(
			'id'            => 1,
			'md_file_path'  => $rel,
			'pdf_file_path' => '',
		);
		$this->db->fail_update_for[] = 'reports:1';

		$original_abs = \WPMAR_Private_Storage::resolve( $rel );
		$this->assertNotSame( '', $original_abs );

		$state = \WPMAR_Storage_Migrator::run_batch( 'migrate', 20 );

		$this->assertSame( 1, $state['failed'] );
		$this->assertNotEmpty( $state['notes'] );
		$this->assertSame( $rel, $this->db->reports[0]['md_file_path'], 'DB value must be unchanged after a rolled-back update' );
		$this->assertFileExists( $original_abs, 'the file must be moved back to its original location on rollback' );
	}

	public function test_dry_run_does_not_modify_anything() {
		$rel = $this->seed_legacy_md_file( 1, 'wpmar-report-example-1' );
		$this->db->reports[] = array(
			'id'            => 1,
			'md_file_path'  => $rel,
			'pdf_file_path' => '',
		);

		$original_abs = \WPMAR_Private_Storage::resolve( $rel );

		$summary = \WPMAR_Storage_Migrator::dry_run_summary( 'migrate' );

		$this->assertSame( 1, $summary['reports_total'] );
		$this->assertSame( 1, $summary['reports_eligible'] );
		$this->assertNotEmpty( $summary['samples'] );

		// Nothing moved, nothing written to the DB row, and no migration state created.
		$this->assertFileExists( $original_abs );
		$this->assertSame( $rel, $this->db->reports[0]['md_file_path'] );
		$this->assertSame( 'pending', \WPMAR_Storage_Migrator::get_state()['state'] );
	}

	public function test_cleanup_only_removes_known_legacy_patterns() {
		$rel = $this->seed_legacy_md_file( 1, 'wpmar-report-example-1' );
		$this->db->reports[] = array(
			'id'            => 1,
			'md_file_path'  => $rel,
			'pdf_file_path' => '',
		);

		// A stray file that must survive cleanup — not one of the known legacy patterns.
		$legacy_dir = $GLOBALS['_wpmar_test_upload_basedir'] . '/wpmar';
		$stray      = $legacy_dir . '/keep-me.txt';
		file_put_contents( $stray, 'not ours' );

		$state = \WPMAR_Storage_Migrator::run_all( 'migrate', 20 );

		$this->assertSame( 'done', $state['state'] );
		$this->assertFileExists( $stray, 'a non-pattern-matching file must never be deleted by cleanup' );
		$this->assertDirectoryExists( $legacy_dir, 'the legacy directory must survive rmdir() while it still has content' );
	}

	public function test_revert_moves_file_back_and_keeps_the_same_filename() {
		$rel = $this->seed_legacy_md_file( 1, 'wpmar-report-example-1' );
		$this->db->reports[] = array(
			'id'            => 1,
			'md_file_path'  => $rel,
			'pdf_file_path' => '',
		);

		\WPMAR_Storage_Migrator::run_all( 'migrate', 20 );
		$migrated_stored   = $this->db->reports[0]['md_file_path'];
		$migrated_filename = basename( \WPMAR_Private_Storage::resolve( $migrated_stored ) );

		$reverted_state = \WPMAR_Storage_Migrator::run_all( 'revert', 20 );

		$this->assertSame( 'reverted', $reverted_state['state'] );

		$reverted_stored = $this->db->reports[0]['md_file_path'];
		$this->assertStringStartsWith( 'wpmar/', $reverted_stored );
		$this->assertStringNotContainsString( 'private:', $reverted_stored );
		$this->assertSame( $migrated_filename, basename( $reverted_stored ), 'the filename/token must be unchanged by --revert' );

		$legacy_abs = $GLOBALS['_wpmar_test_upload_basedir'] . '/' . $reverted_stored;
		$this->assertFileExists( $legacy_abs );

		$legacy_dir = dirname( $legacy_abs );
		$this->assertFileExists( $legacy_dir . '/.htaccess', 'v1.3.0 never seeded this — --revert must add it back' );
		$this->assertFileExists( $legacy_dir . '/index.php' );
	}
}
