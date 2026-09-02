<?php
/**
 * Unit tests for the 1.5.2 core-release-status wording in
 * WPMAR_Runner::render_operator_markup() / render_client_markup().
 *
 * Cases already covered by tests/OperatorMarkupTest.php (section presence,
 * domain gate, changelog wording, etc.) are not repeated here — this file
 * only exercises the `core.release_status` branching added for the
 * insecure / branch_tip / fallback wording.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
}

require_once __DIR__ . '/wp-stubs.php';
require_once dirname( __DIR__ ) . '/includes/checks/class-wpmar-check-performance.php';
require_once dirname( __DIR__ ) . '/includes/class-wpmar-runner.php';

/**
 * Covers the insecure/branch_tip/fallback wording in both audiences' core sections.
 */
final class CoreUpdateMarkupTest extends TestCase {

	/** @var array<string,mixed> */
	private $dataset_full;

	protected function setUp(): void {
		parent::setUp();

		$this->dataset_full = require __DIR__ . '/fixtures/dataset-full.php';

		$GLOBALS['_wpmar_test_site_transients']         = array();
		$GLOBALS['_wpmar_test_filters']                 = array();
		$GLOBALS['_wpmar_test_apply_filters_functional'] = false;
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_wpmar_test_site_transients'],
			$GLOBALS['_wpmar_test_filters'],
			$GLOBALS['_wpmar_test_apply_filters_functional']
		);
		parent::tearDown();
	}

	/**
	 * @param array<string,mixed> $core_overrides Merged over the fixture's `core` key.
	 * @return array<string,mixed>
	 */
	private function dataset_with_core( array $core_overrides ): array {
		$dataset         = $this->dataset_full;
		$dataset['core'] = array_merge( $dataset['core'], $core_overrides );

		return $dataset;
	}

	public function test_insecure_status_shows_urgent_wording_for_both_audiences(): void {
		$dataset = $this->dataset_with_core(
			array(
				'available_updates' => array( '7.1' ),
				'release_status'    => array(
					'status'     => 'insecure',
					'branch'     => '7.0',
					'branch_tip' => '7.0.4',
					'latest'     => '7.1',
				),
			)
		);

		// render_operator_markup() args: (facts, changelog, gate, changelog_size, duration_sec).
		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );
		// render_client_markup() args: (facts, changelog, changelog_size, gate).
		$client = \WPMAR_Runner::render_client_markup( $dataset, '', 0, true );

		self::assertStringContainsString(
			'7.0 系の最新バージョン (7.0.4) がリリース済みで、現在のバージョンはセキュリティパッチがあたっていない危険な状態です。至急セキュリティパッチのあたった最新バージョンへアップデートしてください。メジャーアップデートもリリース済みですので、可能な限りアップデートしてください。',
			$operator
		);
		self::assertStringContainsString(
			'* 現在のバージョンにはセキュリティ上の修正が適用されていません。至急のアップデートをおすすめします。',
			$client
		);
	}

	public function test_branch_tip_status_shows_major_only_wording_for_both_audiences(): void {
		$dataset = $this->dataset_with_core(
			array(
				'available_updates' => array( '7.1' ),
				'release_status'    => array(
					'status'     => 'branch_tip',
					'branch'     => '7.0',
					'branch_tip' => '7.0.4',
					'latest'     => '7.1',
				),
			)
		);

		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );
		$client   = \WPMAR_Runner::render_client_markup( $dataset, '', 0, true );

		self::assertStringContainsString(
			'7.0 系の最新バージョン (7.0.4) をご利用中ですが、メジャーアップデートがリリース済みです。可能な限りアップデートしてください。',
			$operator
		);
		self::assertStringContainsString(
			'* セキュリティ上の修正は適用済みですが、新しいメジャーバージョンがリリースされています。',
			$client
		);
	}

	public function test_unknown_status_falls_back_to_the_pre_1_5_2_generic_wording(): void {
		$dataset = $this->dataset_with_core(
			array(
				'available_updates' => array( '7.1' ),
				'release_status'    => array(
					'status'     => 'unknown',
					'branch'     => '',
					'branch_tip' => '',
					'latest'     => '',
				),
			)
		);

		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );
		$client   = \WPMAR_Runner::render_client_markup( $dataset, '', 0, true );

		self::assertStringContainsString(
			'コアファイルに最新バージョンがリリースされています。可能な限り早くアップデートしてください。',
			$operator
		);
		self::assertStringNotContainsString( 'セキュリティ上の修正', $client );
		self::assertStringNotContainsString( 'セキュリティパッチ', $operator );
	}

	/**
	 * Backward compatibility: a dataset built before 1.5.2 has no `core.release_status` key
	 * at all. Rendering must not error and must fall back to the pre-1.5.2 generic wording.
	 */
	public function test_missing_release_status_key_falls_back_without_error(): void {
		$dataset = $this->dataset_full;
		$dataset['core'] = array(
			'version'           => '7.0.1',
			'locale'            => 'ja',
			'available_updates' => array( '7.1' ),
			// No 'release_status' key — pre-1.5.2 shape.
		);

		$operator = \WPMAR_Runner::render_operator_markup( $dataset, '', true, 0, 10 );
		$client   = \WPMAR_Runner::render_client_markup( $dataset, '', 0, true );

		self::assertStringContainsString(
			'コアファイルに最新バージョンがリリースされています。可能な限り早くアップデートしてください。',
			$operator
		);
		self::assertStringContainsString( '* WordPress コアには新しいバージョン 7.1 があります。', $client );
		self::assertStringNotContainsString( 'セキュリティ上の修正', $client );
	}
}
