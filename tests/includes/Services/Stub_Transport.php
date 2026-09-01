<?php
/**
 * In-memory Sentry transport used by the error reporter tests.
 *
 * @package WCPOS\WooCommercePOS\Tests\Services
 */

namespace WCPOS\WooCommercePOS\Tests\Services;

use WCPOS\Vendor\Sentry\Event;
use WCPOS\Vendor\Sentry\Transport\Result;
use WCPOS\Vendor\Sentry\Transport\ResultStatus;
use WCPOS\Vendor\Sentry\Transport\TransportInterface;

/**
 * Captures Sentry events without making network requests.
 */
class Stub_Transport implements TransportInterface {
	/**
	 * Events sent to the transport.
	 *
	 * @var Event[]
	 */
	public array $events = array();

	/**
	 * Whether send() should report a delivery failure.
	 *
	 * @var bool
	 */
	public bool $fail = false;

	/**
	 * Capture an event.
	 *
	 * A failed result carries no event, which is how the SDK's Client signals
	 * failure to callers: captureEvent() returns null.
	 *
	 * @param Event $event Event being sent.
	 */
	public function send( Event $event ): Result {
		if ( $this->fail ) {
			return new Result( ResultStatus::failed() );
		}

		$this->events[] = $event;

		return new Result( ResultStatus::success(), $event );
	}

	/**
	 * Close the transport.
	 *
	 * @param int|null $timeout Optional close timeout.
	 */
	public function close( ?int $timeout = null ): Result {
		return new Result( ResultStatus::success() );
	}
}
