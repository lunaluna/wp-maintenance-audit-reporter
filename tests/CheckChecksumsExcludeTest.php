<?php
/**
 * Unit tests for WPMAR_Check_Checksums exclude-path matching (exact, dir prefix, glob).
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

// The exposing subclass below needs the production class at declaration time,
// so these loads cannot wait for setUpBeforeClass().
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/checks/class-wpmar-check-checksums.php';

/**
 * Exposes the protected exclude-matching internals for direct assertions.
 */
final class ExposedCheckChecksums extends \WPMAR_Check_Checksums {

	/**
	 * @param string[] $paths Relative paths.
	 * @return array{exact: array<string,bool>, dirs: list<string>, globs: list<string>}
	 */
	public function callBuildExcludeSet( array $paths ): array {
		return $this->build_exclude_set( $paths );
	}

	/**
	 * @param string                                                                    $key Normalised relative path.
	 * @param array{exact: array<string,bool>, dirs: list<string>, globs: list<string>} $set Output of build_exclude_set().
	 * @return bool
	 */
	public function callIsExcluded( string $key, array $set ): bool {
		return $this->is_excluded( $key, $set );
	}
}

/**
 * Covers build_exclude_set() / is_excluded(), including the fnmatch()-based
 * glob support added for patterns like a leading '*' then '/.htaccess'.
 */
final class CheckChecksumsExcludeTest extends TestCase {

	private ExposedCheckChecksums $subject;

	protected function setUp(): void {
		parent::setUp();
		$this->subject = new ExposedCheckChecksums();
	}

	private function isExcluded( string $key, array $rawPaths ): bool {
		$set = $this->subject->callBuildExcludeSet( $rawPaths );

		return $this->subject->callIsExcluded( $key, $set );
	}

	// -------------------------------------------------------------------------
	// Glob patterns (new behaviour)
	// -------------------------------------------------------------------------

	public function test_star_slash_pattern_matches_nested_filename_at_any_depth(): void {
		$rules = array( '*/.htaccess' );

		self::assertTrue( $this->isExcluded( 'lib/.htaccess', $rules ) );
		self::assertTrue( $this->isExcluded( 'modules/login-security/classes/.htaccess', $rules ) );
	}

	public function test_star_dot_extension_pattern_matches_root_and_nested(): void {
		$rules = array( '*.htaccess' );

		self::assertTrue( $this->isExcluded( '.htaccess', $rules ) );
		self::assertTrue( $this->isExcluded( 'lib/.htaccess', $rules ) );
	}

	public function test_star_slash_pattern_does_not_match_unrelated_file(): void {
		$rules = array( '*/.htaccess' );

		self::assertFalse( $this->isExcluded( 'foo/bar.php', $rules ) );
	}

	public function test_glob_pattern_is_case_insensitive_via_normalisation(): void {
		$rules = array( '*/.HTACCESS' );

		self::assertTrue( $this->isExcluded( 'lib/.htaccess', $rules ) );
	}

	// -------------------------------------------------------------------------
	// Backward compatibility: exact match and directory prefix
	// -------------------------------------------------------------------------

	public function test_exact_match_without_wildcards_still_works(): void {
		$rules = array( 'lib/.htaccess' );

		self::assertTrue( $this->isExcluded( 'lib/.htaccess', $rules ) );
		self::assertFalse( $this->isExcluded( 'models/.htaccess', $rules ) );
	}

	public function test_directory_suffix_slash_still_excludes_whole_subtree(): void {
		$rules = array( 'waf/' );

		self::assertTrue( $this->isExcluded( 'waf/.htaccess', $rules ) );
		self::assertTrue( $this->isExcluded( 'waf/rules/custom.php', $rules ) );
		self::assertFalse( $this->isExcluded( 'lib/.htaccess', $rules ) );
	}

	public function test_directory_suffix_slash_star_still_excludes_whole_subtree(): void {
		$rules = array( 'waf/*' );

		self::assertTrue( $this->isExcluded( 'waf/.htaccess', $rules ) );
	}
}
