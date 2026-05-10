<?php
/**
 * Placeholder suite until Brain Monkey specs are added.
 *
 * @package WPMAR
 */

use PHPUnit\Framework\TestCase;

/**
 * PHPUnit placeholder covering nothing; keeps CI PHPUnit wiring alive.
 *
 * @coversNothing
 */
class PlaceholderTest extends TestCase {

	/**
	 * Smoke assertion that does not require WordPress bootstrap.
	 *
	 * @return void
	 */
	public function test_scaffolding_bootstraps_without_wordpress(): void {
		$this->assertTrue( true );
	}
}
