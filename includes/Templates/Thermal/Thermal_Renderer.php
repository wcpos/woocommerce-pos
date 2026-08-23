<?php
/**
 * Thermal Renderer Orchestrator Class.
 *
 * Ties together the thermal pipeline shipped in earlier phases: it Mustache-renders
 * a thermal template against canonical receipt data, parses the resulting markup
 * into an AST, and emits the requested wire format — ESC/POS or StarPRNT command
 * bytes, Epson ePOS-Print XML, Star Document Markup, or plain text.
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
	 * @param string            $wire_format The target wire format ('escpos', 'starprnt', 'epos-xml', 'star-markup' or 'text').
	 * @param array             $options     Render options.
	 *
	 * @throws InvalidArgumentException When the wire format is not supported.
	 *
	 * @return string The rendered wire-format payload.
	 */
	public function render( array $template, WC_Abstract_Order $order, string $wire_format, array $options = array() ): string {
		return $this->render_with_control( $template, $order, $wire_format, $options )['body'];
	}

	/**
	 * Render a thermal template, reporting peripherals the payload cannot carry.
	 *
	 * Command formats (ESC/POS, StarPRNT, ePOS-XML) express cut and cash-drawer
	 * in-band, so they report null for both. Command-free formats — `text` today,
	 * raster tomorrow — cannot, and the transport has to ask for them instead:
	 * on Star CloudPRNT that means the `X-Star-Cut` / `X-Star-CashDrawer` headers
	 * on the job fetch. Callers serving those formats must forward what comes
	 * back here or the receipt will neither cut nor open the drawer.
	 *
	 * @param array             $template    Template metadata/content.
	 * @param WC_Abstract_Order $order       The order to render.
	 * @param string            $wire_format The target wire format.
	 * @param array             $options     Render options.
	 *
	 * @throws InvalidArgumentException When the wire format is not supported.
	 *
	 * @return array{body:string, cut:string|null, drawer:string|null}
	 */
	public function render_with_control( array $template, WC_Abstract_Order $order, string $wire_format, array $options = array() ): array {
		$ast = $this->build_ast( $template, $order );

		switch ( $wire_format ) {
			case 'escpos':
				return self::in_band( ( new Escpos_Thermal_Emitter( $options ) )->emit( $ast ) );
			case 'starprnt':
				return self::in_band( ( new Starprnt_Thermal_Emitter( $options ) )->emit( $ast ) );
			case 'epos-xml':
				return self::in_band( ( new Epos_Xml_Thermal_Emitter( $options ) )->emit( $ast ) );
			case 'star-markup':
				return self::in_band( ( new Star_Markup_Thermal_Emitter() )->emit( $ast ) );
			case 'text':
				$emitter = new Text_Thermal_Emitter( $options );
				$body    = $emitter->emit( $ast );

				return array(
					'body'   => $body,
					'cut'    => $emitter->cut_type(),
					'drawer' => $emitter->drawer(),
				);
			default:
				throw new InvalidArgumentException(
					esc_html( "Unsupported thermal wire format: {$wire_format}" )
				);
		}
	}

	/**
	 * Wrap a payload that carries its own cut and drawer commands.
	 *
	 * @param string $body The rendered payload.
	 *
	 * @return array{body:string, cut:string|null, drawer:string|null}
	 */
	private static function in_band( string $body ): array {
		return array(
			'body'   => $body,
			'cut'    => null,
			'drawer' => null,
		);
	}

	/**
	 * Build the thermal AST for an order from a template.
	 *
	 * Shared pipeline used by both render() and the PDF path: Mustache-render the
	 * template against canonical receipt data, strip XML-illegal control characters,
	 * then parse the markup into an AST.
	 *
	 * @param array             $template     Template metadata/content.
	 * @param WC_Abstract_Order $order        The order to render.
	 * @param array|null        $receipt_data Optional canonical receipt payload.
	 *
	 * @return array The thermal AST root (a receipt node).
	 */
	public function build_ast( array $template, WC_Abstract_Order $order, ?array $receipt_data = null ): array {
		$content = (string) ( $template['content'] ?? '' );

		$data = null === $receipt_data ? ( new Receipt_Data_Builder() )->build( $order, 'live' ) : $receipt_data;

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
