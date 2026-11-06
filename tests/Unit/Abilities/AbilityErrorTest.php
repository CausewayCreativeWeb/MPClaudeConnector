<?php
/**
 * Error format sent to Claude (docs/03 "Errors").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Unit\Abilities;

use Causeway\ClaudeBridge\Abilities\AbilityError;
use Causeway\ClaudeBridge\Tests\Unit\UnitTestCase;

/**
 * @covers \Causeway\ClaudeBridge\Abilities\AbilityError
 */
final class AbilityErrorTest extends UnitTestCase {

	protected function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WP_Error' ) ) {
			require_once dirname( __DIR__, 3 ) . '/tests/Unit/stubs/WP_Error.php';
		}
	}

	public function test_message_carries_code_field_fix_and_reference_for_claude(): void {
		$error = AbilityError::make( 'ccb_post_changed', 'The post was changed by someone else.', 409, 'post_id', 'Reload it and try again.', 'abc123def456' );

		$this->assertSame( 'ccb_post_changed', $error->get_error_code() );
		$this->assertSame(
			'The post was changed by someone else. Fix: Reload it and try again. [code: ccb_post_changed; field: post_id; ref: abc123def456]',
			$error->get_error_message()
		);
		$data = $error->get_error_data();
		$this->assertSame( 409, $data['status'] );
		$this->assertSame( 'post_id', $data['field'] );
		$this->assertSame( 'The post was changed by someone else.', AbilityError::plain( $error ) );
	}

	public function test_retry_after_is_included_for_rate_limits(): void {
		$error = AbilityError::make( 'ccb_rate_limited', 'Too many requests.', 429, null, null, 'r', array( 'retry_after' => 42 ) );
		$this->assertStringEndsWith( '[code: ccb_rate_limited; retry_after: 42; ref: r]', $error->get_error_message() );
		$this->assertSame( 42, $error->get_error_data()['retry_after'] );
	}

	public function test_reference_is_added_once(): void {
		$error = AbilityError::make( 'ccb_section_unknown', 'No such section.', 400, 'section', 'Use news.' );
		$this->assertStringNotContainsString( 'ref:', $error->get_error_message() );

		$with = AbilityError::with_ref( $error, 'aaaaaaaaaaaa' );
		$this->assertSame( 'No such section. Fix: Use news. [code: ccb_section_unknown; field: section; ref: aaaaaaaaaaaa]', $with->get_error_message() );
		$this->assertSame( $with, AbilityError::with_ref( $with, 'bbbbbbbbbbbb' ), 'An existing reference is kept' );
	}
}
