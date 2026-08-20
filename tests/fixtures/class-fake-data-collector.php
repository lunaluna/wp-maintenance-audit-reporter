<?php
/**
 * Scripted WPMAR_Data_Collector double for Runner tests.
 *
 * Not itself a test case — a shared fixture required directly by test files
 * that need WPMAR_Runner::make_data_collector() to hand back a canned dataset
 * (or blow up mid-run) instead of touching real wp.org / filesystem calls.
 *
 * @package WPMAR\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns a canned gather() payload, or throws when scripted to simulate a mid-run failure.
 */
class WPMAR_Test_Fake_Data_Collector extends WPMAR_Data_Collector {

	/**
	 * Canned gather() return value.
	 *
	 * @var array<string,mixed>
	 */
	protected $dataset;

	/**
	 * When set, gather() throws this instead of returning $dataset.
	 *
	 * @var Throwable|null
	 */
	protected $throw;

	/**
	 * Intentionally skips the parent constructor — no WPMAR_WPOrg_Client is needed
	 * since this double never performs wp.org lookups.
	 *
	 * @param array<string,mixed> $dataset Canned gather() return value.
	 * @param Throwable|null      $throw   Exception to raise from gather() instead of returning.
	 */
	public function __construct( array $dataset = array(), ?Throwable $throw = null ) {
		$this->dataset = $dataset;
		$this->throw   = $throw;
	}

	/**
	 * @return array<string,mixed>
	 */
	public function gather() {
		if ( null !== $this->throw ) {
			throw $this->throw;
		}

		return $this->dataset;
	}
}
