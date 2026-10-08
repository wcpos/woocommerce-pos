<?php
/**
 * Normalized host-owned gateway fields.
 *
 * @package WCPOS\WooCommercePOS\Payments\Contract
 */

namespace WCPOS\WooCommercePOS\Payments\Contract;

\defined( 'ABSPATH' ) || die;

use WC_Payment_Gateway;
use WCPOS\WooCommercePOS\Logger;

/** Keeps gateway declarations inside the flat host-component vocabulary. */
class Declared_Fields {
	public const COMPONENTS = array( 'field', 'checkbox', 'select', 'note' );
	public const INPUTS = array( 'text', 'email', 'tel', 'number' );
	public const PREFILLS = array( 'order.billing.email', 'order.billing.phone', 'customer.email', 'customer.phone' );
	public const VERB_KINDS = array( 'take', 'send' );

	/**
	 * Read and normalize a gateway's optional declaration.
	 *
	 * @param WC_Payment_Gateway $gateway Gateway instance.
	 */
	public static function for_gateway( WC_Payment_Gateway $gateway ): ?array {
		/**
		 * Filters the POS payment method's declared host components.
		 *
		 * @since 2.0.0
		 * @hook wcpos_payment_method_fields
		 *
		 * @param array|null         $fields  Declared fields, or null when absent.
		 * @param WC_Payment_Gateway $gateway Gateway instance.
		 */
		// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Public POS payments contract filter.
		$block = apply_filters( 'wcpos_payment_method_fields', null, $gateway );
		// phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		if ( ! is_array( $block ) ) {
			return null;
		}
		$text = static fn( $value ) => is_string( $value ) ? sanitize_text_field( $value ) : '';
		$verb = is_array( $block['verb'] ?? null ) ? $block['verb'] : array();
		$components = array();
		$seen = array();
		foreach ( (array) ( $block['components'] ?? array() ) as $component ) {
			$component = is_array( $component ) ? $component : array();
			$type = $component['component'] ?? null;
			$id = $text( $component['id'] ?? null );
			if ( ! in_array( $type, self::COMPONENTS, true ) || ( 'note' === $type ? '' === $text( $component['text'] ?? null ) : ( '' === $id || isset( $seen[ $id ] ) ) ) ) {
				Logger::log( sprintf( 'Skipped invalid WCPOS field component for gateway "%s".', $gateway->id ) );
				continue;
			}
			if ( 'note' === $type ) {
				$components[] = array(
					'component' => 'note',
					'text' => $text( $component['text'] ),
				);
				continue;
			}
			$options = array();
			foreach ( (array) ( $component['options'] ?? array() ) as $option ) {
				if ( ! is_array( $option ) || ! is_string( $option['value'] ?? null ) || ! is_string( $option['label'] ?? null ) ) {
					$options = array();
					break;
				}
				$options[] = array(
					'value' => $text( $option['value'] ),
					'label' => $text( $option['label'] ),
				);
			}
			if ( 'select' === $type && ! $options ) {
				Logger::log( sprintf( 'Skipped invalid WCPOS select component for gateway "%s".', $gateway->id ) );
				continue;
			}
			$item = array(
				'component' => $type,
				'id' => $id,
			);
			if ( 'field' === $type ) {
				$item['input'] = in_array( $component['input'] ?? null, self::INPUTS, true ) ? $component['input'] : 'text';
			}
			$item['label'] = $text( $component['label'] ?? null );
			if ( 'checkbox' !== $type ) {
				$item['required'] = (bool) ( $component['required'] ?? false );
			}
			$item['default'] = 'checkbox' === $type ? (bool) ( $component['default'] ?? false ) : $text( $component['default'] ?? null );
			if ( 'select' === $type ) {
				$item['options'] = $options;
			} else {
				$item['prefill'] = in_array( $component['prefill'] ?? null, self::PREFILLS, true ) ? $component['prefill'] : null;
			}
			$seen[ $id ] = true;
			$components[] = $item;
		}
		return array(
			'schema' => 1,
			'verb' => array(
				'kind' => in_array( $verb['kind'] ?? null, self::VERB_KINDS, true ) ? $verb['kind'] : 'take',
				'label' => '' !== $text( $verb['label'] ?? null ) ? $text( $verb['label'] ) : $text( $gateway->get_title() ),
			),
			'components' => $components,
		);
	}
}
