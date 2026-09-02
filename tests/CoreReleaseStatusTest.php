<?php
/**
 * PHPUnit coverage for WPMAR_Data_Collector::core_release_status().
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Asserts the stable-check-map classification behind the 1.5.2 security-patch-status feature.
 *
 * @coversNothing
 */
final class CoreReleaseStatusTest extends TestCase {

	/**
	 * Bootstraps the data collector class without WordPress.
	 *
	 * core_release_status() is a pure function (no WP function calls), so no wp-stubs.php
	 * is needed here — unlike most other test files in this suite.
	 *
	 * @return void
	 */
	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
		}
		require_once dirname( __DIR__ ) . '/includes/class-wpmar-data-collector.php';
	}

	/**
	 * A representative slice of a real stable-check response: several branches, each with
	 * exactly one `outdated`/`latest` key (the branch tip) and several `insecure` keys, plus
	 * a decoy `7.01` key to guard against a prefix-match regression on branch `7.0`.
	 *
	 * @return array<string,string>
	 */
	private function stable_map(): array {
		return array(
			'6.9.1'           => 'insecure',
			'6.9.2'           => 'insecure',
			'6.9.7'           => 'outdated',
			'7.0.1'           => 'insecure',
			'7.0.4'           => 'outdated',
			'7.01'            => 'insecure',
			'7.1'             => 'latest',
			'7.2-alpha-99999' => 'insecure',
		);
	}

	public function test_insecure_version_reports_branch_and_branch_tip(): void {
		$result = \WPMAR_Data_Collector::core_release_status( '7.0.1', $this->stable_map() );

		self::assertSame(
			array(
				'status'     => 'insecure',
				'branch'     => '7.0',
				'branch_tip' => '7.0.4',
				'latest'     => '7.1',
			),
			$result
		);
	}

	public function test_branch_tip_version_reports_branch_tip_status(): void {
		$result = \WPMAR_Data_Collector::core_release_status( '7.0.4', $this->stable_map() );

		self::assertSame( 'branch_tip', $result['status'] );
		self::assertSame( '7.0.4', $result['branch_tip'] );
	}

	public function test_latest_version_reports_latest_status(): void {
		$result = \WPMAR_Data_Collector::core_release_status( '7.1', $this->stable_map() );

		self::assertSame( 'latest', $result['status'] );
	}

	public function test_another_branch_computes_its_own_branch_tip(): void {
		$result = \WPMAR_Data_Collector::core_release_status( '6.9.2', $this->stable_map() );

		self::assertSame( 'insecure', $result['status'] );
		self::assertSame( '6.9', $result['branch'] );
		self::assertSame( '6.9.7', $result['branch_tip'] );
	}

	public function test_branch_tip_lookup_uses_strict_major_minor_match_not_prefix(): void {
		// Regression: a naive `strpos($key, '7.0') === 0` prefix match would also catch the
		// decoy `7.01` key in stable_map(), which is a distinct version, not part of branch 7.0.
		$result = \WPMAR_Data_Collector::core_release_status( '7.0.1', $this->stable_map() );

		self::assertSame( '7.0.4', $result['branch_tip'] );
	}

	public function test_unknown_when_map_is_missing_or_not_an_array(): void {
		$expected = array(
			'status'     => 'unknown',
			'branch'     => '',
			'branch_tip' => '',
			'latest'     => '',
		);

		self::assertSame( $expected, \WPMAR_Data_Collector::core_release_status( '7.0.1', null ) );
		self::assertSame( $expected, \WPMAR_Data_Collector::core_release_status( '7.0.1', array() ) );
	}

	public function test_unknown_when_current_version_key_is_absent_from_map(): void {
		self::assertSame( 'unknown', \WPMAR_Data_Collector::core_release_status( '9.9.9', $this->stable_map() )['status'] );
	}

	public function test_unknown_for_a_development_build_version_string(): void {
		self::assertSame( 'unknown', \WPMAR_Data_Collector::core_release_status( '7.2-alpha', $this->stable_map() )['status'] );
	}
}
