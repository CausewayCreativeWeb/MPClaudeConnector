<?php
/**
 * Secrets never reach the activity log (docs/09 "Secrets").
 *
 * @package Causeway\ClaudeBridge
 */

declare(strict_types=1);

namespace Causeway\ClaudeBridge\Tests\Unit\Audit;

use Causeway\ClaudeBridge\Audit\ActivityLog;
use Causeway\ClaudeBridge\Tests\Unit\UnitTestCase;

/**
 * @covers \Causeway\ClaudeBridge\Audit\ActivityLog
 */
final class ActivityLogTest extends UnitTestCase {

	public function test_secret_and_content_keys_are_redacted_at_any_depth(): void {
		$redacted = ActivityLog::redact(
			array(
				'token'   => 'abc',
				'changes' => array(
					'title'       => array( 'Old', 'New' ),
					'content'     => '<p>Full body</p>',
					'data_base64' => 'AAAA',
				),
				'Password' => 'x',
				'section'  => 'news',
			)
		);

		$this->assertSame( '[redacted]', $redacted['token'] );
		$this->assertSame( '[redacted]', $redacted['changes']['content'] );
		$this->assertSame( '[redacted]', $redacted['changes']['data_base64'] );
		$this->assertSame( '[redacted]', $redacted['Password'] );
		$this->assertSame( array( 'Old', 'New' ), $redacted['changes']['title'] );
		$this->assertSame( 'news', $redacted['section'] );
	}
}
