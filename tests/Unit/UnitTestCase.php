<?php
/**
 * Base class for unit tests.
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Sets up Brain Monkey so WordPress functions can be stubbed.
 */
abstract class UnitTestCase extends TestCase {

	use MockeryPHPUnitIntegration;

	/**
	 * Starts Brain Monkey and stubs translation functions to return their input.
	 */
	protected function set_up(): void {
		parent::set_up();
		Monkey\setUp();
		Functions\stubTranslationFunctions();
	}

	/**
	 * Stops Brain Monkey.
	 */
	protected function tear_down(): void {
		Monkey\tearDown();
		parent::tear_down();
	}
}
