<?php
/**
 * Unit tests for WPMAR_Jobs_Repository pure helpers.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Covers sanitize_id() and sanitize_scope(), which gate the REST route alphabet
 * and the runner-selection scope. CRUD paths are exercised against the live DB.
 */
final class JobsRepositoryTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
		}

		require_once __DIR__ . '/wp-stubs.php';
		require_once dirname( __DIR__ ) . '/includes/storage/class-wpmar-jobs-repository.php';
	}

	// -------------------------------------------------------------------------
	// sanitize_id
	// -------------------------------------------------------------------------

	public function test_sanitize_id_lowercases_and_strips_invalid_chars(): void {
		self::assertSame( 'badx.9', \WPMAR_Jobs_Repository::sanitize_id( 'BAD/$X!.9' ) );
	}

	public function test_sanitize_id_preserves_dots_and_alphanumerics(): void {
		self::assertSame( 'wpmar64f.1a2b', \WPMAR_Jobs_Repository::sanitize_id( 'wpmar64f.1a2b' ) );
	}

	public function test_sanitize_id_caps_length_at_40(): void {
		$result = \WPMAR_Jobs_Repository::sanitize_id( str_repeat( 'a', 60 ) );

		self::assertSame( 40, strlen( $result ) );
	}

	public function test_sanitize_id_returns_empty_for_all_invalid(): void {
		self::assertSame( '', \WPMAR_Jobs_Repository::sanitize_id( '!!!///###' ) );
	}

	public function test_sanitize_id_matches_rest_route_alphabet(): void {
		// REST route is (?P<id>[a-z0-9.]+) — the sanitised id must satisfy it.
		$id = \WPMAR_Jobs_Repository::sanitize_id( 'wpmar' . uniqid( '', true ) );

		self::assertMatchesRegularExpression( '/^[a-z0-9.]+$/', $id );
	}

	// -------------------------------------------------------------------------
	// sanitize_scope
	// -------------------------------------------------------------------------

	public function test_sanitize_scope_accepts_network(): void {
		self::assertSame( 'network', \WPMAR_Jobs_Repository::sanitize_scope( 'network' ) );
	}

	public function test_sanitize_scope_accepts_single(): void {
		self::assertSame( 'single', \WPMAR_Jobs_Repository::sanitize_scope( 'single' ) );
	}

	public function test_sanitize_scope_defaults_unknown_to_single(): void {
		self::assertSame( 'single', \WPMAR_Jobs_Repository::sanitize_scope( 'whatever' ) );
		self::assertSame( 'single', \WPMAR_Jobs_Repository::sanitize_scope( '' ) );
	}
}
