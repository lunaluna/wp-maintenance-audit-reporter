<?php
/**
 * Unit tests for WPMAR_CLI_Flags negation semantics.
 *
 * @package WPMAR\Tests
 */

namespace WPMAR\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Regression coverage for bug B: `isset( $assoc_flags['x'] )` reads `--no-x`
 * (which WP-CLI resolves to `x => false`) as "flag present" instead of "negated".
 */
final class CliFlagsTest extends TestCase {

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();

		if ( ! defined( 'ABSPATH' ) ) {
			define( 'ABSPATH', __DIR__ . '/fixtures/fake-root/' );
		}

		require_once dirname( __DIR__ ) . '/includes/cli/class-wpmar-cli-flags.php';
	}

	public function test_bool_returns_default_when_flag_absent(): void {
		$this->assertFalse( \WPMAR_CLI_Flags::bool( array(), 'revert' ) );
		$this->assertTrue( \WPMAR_CLI_Flags::bool( array(), 'revert', true ) );
	}

	public function test_bool_true_when_flag_present(): void {
		$this->assertTrue( \WPMAR_CLI_Flags::bool( array( 'revert' => true ), 'revert' ) );
	}

	public function test_bool_false_when_flag_negated(): void {
		// This is bug B: WP-CLI passes `false` for `--no-revert`, not an absent key.
		$this->assertFalse( \WPMAR_CLI_Flags::bool( array( 'revert' => false ), 'revert', false ) );
		$this->assertFalse( \WPMAR_CLI_Flags::bool( array( 'revert' => false ), 'revert', true ) );
	}

	/**
	 * @dataProvider falsy_string_provider
	 */
	public function test_bool_treats_falsy_strings_as_false( $value ): void {
		$this->assertFalse( \WPMAR_CLI_Flags::bool( array( 'flag' => $value ), 'flag' ) );
	}

	public function falsy_string_provider(): array {
		return array(
			array( 'false' ),
			array( '0' ),
			array( '' ),
			array( null ),
		);
	}

	public function test_int_returns_default_when_flag_absent(): void {
		$this->assertSame( 20, \WPMAR_CLI_Flags::int( array(), 'batch', 20 ) );
	}

	public function test_int_returns_default_when_flag_negated(): void {
		// Without this guard, `(int) false` silently becomes 0.
		$this->assertSame( 20, \WPMAR_CLI_Flags::int( array( 'batch' => false ), 'batch', 20 ) );
	}

	public function test_int_casts_present_value(): void {
		$this->assertSame( 50, \WPMAR_CLI_Flags::int( array( 'batch' => '50' ), 'batch', 20 ) );
	}
}
