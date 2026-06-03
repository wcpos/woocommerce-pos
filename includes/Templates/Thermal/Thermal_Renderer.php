<?php
/**
 * Thermal Renderer Orchestrator Class.
 *
 * Ties together the thermal pipeline shipped in earlier phases: it Mustache-renders
 * a thermal template against canonical receipt data, parses the resulting markup
 * into an AST, and emits the requested wire format (ESC/POS raw bytes or Epson
 * ePOS-Print XML).
 *
 * The Mustache engine configuration mirrors Logicless_Renderer so that data values
 * containing XML-significant characters (`&`, `<`, `>`, quotes) are escaped to valid
 * XML before the markup is parsed.
 *
 * @author   Paul Kilmurray <paul@kilbot.com>
 *
 * @see     http://wcpos.com
 * @package WCPOS\WooCommercePOS
 */

namespace WCPOS\WooCommercePOS\Templates\Thermal;

use InvalidArgumentException;
use Mustache\Engine as Mustache_Engine;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Builder;
use WCPOS\WooCommercePOS\Services\Receipt_Data_Schema;
use WC_Abstract_Order;

/**
 * Thermal_Renderer class.
 */
class Thermal_Renderer {

	/**
	 * Render a thermal template for an order into the requested wire format.
	 *
	 * @param array             $template    Template metadata/content.
	 * @param WC_Abstract_Order $order       The order to render.
	 * @param string            $wire_format The target wire format ('escpos' or 'epos-xml').
	 *
	 * @throws InvalidArgumentException When the wire format is not supported.
	 *
	 * @return string The rendered wire-format payload.
	 */
	public function render( array $template, WC_Abstract_Order $order, string $wire_format ): string {
		$ast = $this->build_ast( $template, $order );

		switch ( $wire_format ) {
			case 'escpos':
				return ( new Escpos_Thermal_Emitter() )->emit( $ast );
			case 'epos-xml':
				return ( new Epos_Xml_Thermal_Emitter() )->emit( $ast );
			case 'star-markup':
				return ( new Star_Markup_Thermal_Emitter() )->emit( $ast );
			default:
				throw new InvalidArgumentException(
					esc_html( "Unsupported thermal wire format: {$wire_format}" )
				);
		}
	}

	/**
	 * Build the thermal AST for an order from a template.
	 *
	 * Shared pipeline used by both render() and the PDF path: Mustache-render the
	 * template against canonical receipt data, strip XML-illegal control characters,
	 * then parse the markup into an AST.
	 *
	 * @param array             $template Template metadata/content.
	 * @param WC_Abstract_Order $order    The order to render.
	 *
	 * @return array The thermal AST root (a receipt node).
	 */
	public function build_ast( array $template, WC_Abstract_Order $order ): array {
		$content = (string) ( $template['content'] ?? '' );

		$data = ( new Receipt_Data_Builder() )->build( $order, 'live' );

		// Pre-format money/display fields so {{*_display}} placeholders resolve,
		// mirroring Logicless_Renderer.
		$currency = $data['order']['currency'] ?? 'USD';
		$data     = Receipt_Data_Schema::format_money_fields( $data, $currency );

		// Safety net for templates that wrap content in {{#t}}...{{/t}} markers.
		$data['t'] = true;

		$flags    = ENT_QUOTES | ENT_SUBSTITUTE;
		$mustache = new Mustache_Engine(
			array(
				'entity_flags' => $flags,
				'escape'       => function ( $value ) use ( $flags ) {
					if ( \is_array( $value ) ) {
						return '';
					}

					return htmlspecialchars( (string) $value, $flags, 'UTF-8' );
				},
			)
		);

		$xml = $mustache->render( $content, $data );

		// Strip control characters that XML 1.0 forbids (everything below 0x20
		// except tab, LF and CR). Order data can carry these — e.g. a customer
		// note pasted with a form-feed — and Mustache's HTML escaping leaves them
		// intact, so they would make DOMDocument::loadXML() fail downstream. They
		// can never print meaningfully, so removing them is safe.
		$stripped = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $xml );
		if ( null !== $stripped ) {
			$xml = $stripped;
		}

		return ( new Thermal_Markup_Parser() )->parse( $xml );
	}
}
