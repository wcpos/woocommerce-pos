<?php
/**
 * The command envelope every money route body carries (ADR 0040 rules 5 and 6).
 *
 * @package WCPOS\WooCommercePOS\Tests\Helpers
 */

namespace WCPOS\WooCommercePOS\Tests\Helpers;

use WCPOS\WooCommercePOS\Sync\Order_Serializer;

/** Adds a fresh command id and the order's current revision to a money route body. */
trait Money_Route_Envelope {
	/**
	 * The body with a new `command_id` and the current `revision` (the body's own win).
	 *
	 * @param \WC_Order|int $order Order or order id.
	 * @param array         $body  Route body.
	 */
	protected function envelope( $order, array $body = array() ): array {
		return array_merge(
			array(
				'command_id' => wp_generate_uuid4(),
				'revision'   => $this->current_revision( $order ),
			),
			$body
		);
	}

	/**
	 * The order's revision as the money routes compare it; a placeholder when it cannot be read
	 * (a user without read access), so the route answers with its own refusal.
	 *
	 * @param \WC_Order|int $order Order or order id.
	 */
	protected function current_revision( $order ): string {
		$state = Order_Serializer::current_state( is_int( $order ) ? $order : $order->get_id() );
		return is_wp_error( $state ) ? 'sha256:unreadable' : $state['revision'];
	}
}
