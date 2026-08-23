<?php
/**
 * Raster Thermal Emitter Class.
 *
 * Renders a thermal AST to a monochrome PNG of the whole receipt. `image/png` is
 * the one media type every Star CloudPRNT model decodes, including the Line
 * Mode-only ones (TSP650II/TSP700II/TSP800II) that cannot decode StarPRNT at
 * all — so this is the floor beneath native emission, and the only path on which
 * a template's logo, barcode and QR reach that hardware at all.
 *
 * The receipt is a character grid, so this draws one: glyphs are painted cell by
 * cell rather than as whole strings, which keeps columns aligned no matter what
 * the font's own advance rounds to, and lets a full-width glyph occupy the two
 * cells `Thermal_Text_Layout::display_width()` already counts for it.
 *
 * Geometry is 203 dpi, the resolution of every Star thermal head: 576 dots for
 * 80 mm paper and 384 for 58 mm. Both divide into exactly 12 px per cell at the
 * usual column counts (48 and 32), and a count that does not divide evenly gets
 * a floored cell and a centred block rather than an overhang past the paper.
 *
 * Like `Text_Thermal_Emitter`, the format carries no commands, so cut and drawer
 * are reported for the transport to request with `X-Star-Cut` /
 * `X-Star-CashDrawer` headers, and the drawer connector cannot be selected.
 *
 * Glyph coverage is the bundled DejaVu Sans Mono's: Latin, Greek, Cyrillic,
 * Arabic and currency symbols, but not CJK, Hebrew or Thai. Sites needing those
 * point `woocommerce_pos_receipt_raster_font` at a face that has them — see
 * issue #1682.
 *
 * Complex scripts are a harder limit than coverage. Placing glyphs in cells
 * means drawing them in logical order, left to right, with no contextual
 * shaping — so Arabic and Persian come out as unjoined, unreversed letterforms
 * even though the font has the glyphs. GD has no HarfBuzz binding, so shaping
 * cannot be done here at all; an RTL store is better served by native StarPRNT,
 * which leads the offer anyway. Tracked with the font question on #1682.
 *
 * @package WCPOS\WooCommercePOS\Templates\Thermal
 */

namespace WCPOS\WooCommercePOS\Templates\Thermal;

use WCPOS\WooCommercePOS\Services\Local_Image_Resolver;
use WCPOS\WooCommercePOS\Templates\Barcode_Image;

/**
 * Raster_Thermal_Emitter class.
 */
class Raster_Thermal_Emitter {

	use Thermal_Text_Layout;

	/**
	 * Printable dots across 80 mm paper at 203 dpi.
	 */
	private const DOTS_80MM = 576;

	/**
	 * Printable dots across 58 mm paper at 203 dpi.
	 */
	private const DOTS_58MM = 384;

	/**
	 * Column count at or above which paper is treated as 80 mm.
	 *
	 * 58 mm rolls carry 32 columns; 80 mm rolls carry 42 or 48.
	 */
	private const WIDE_PAPER_COLUMNS = 40;

	/**
	 * Line box height as a multiple of the cell width.
	 *
	 * Monospace cells are about twice as tall as they are wide; 1.7 leaves the
	 * receipt readable without wasting paper.
	 */
	private const LINE_HEIGHT_RATIO = 1.7;

	/**
	 * Where the text baseline sits inside its line box.
	 */
	private const BASELINE_RATIO = 0.78;

	/**
	 * Hard ceiling on the rendered height, in dots.
	 *
	 * A runaway template must not allocate an unbounded image. 40 000 dots is
	 * about five metres of paper — far past any real receipt.
	 */
	private const MAX_HEIGHT = 40000;

	/**
	 * Render options.
	 *
	 * @var array
	 */
	private $options = array();

	/**
	 * Display list built by the measure pass.
	 *
	 * @var array<int, array>
	 */
	private $ops = array();

	/**
	 * Styled runs buffered for the line currently being built.
	 *
	 * A line is a list of runs rather than one string because styling nests
	 * inside `<text>`: `<text>plain <bold>bold</bold></text>` is one printed line
	 * carrying two different inks, and collapsing it to a single style would
	 * render the whole line in whichever style happened to be current when the
	 * line closed.
	 *
	 * @var array<int, array{text:string, bold:bool, invert:bool, w:int, h:int}>
	 */
	private $runs = array();

	/**
	 * The paper width in character columns.
	 *
	 * @var int
	 */
	private $columns = 48;

	/**
	 * The paper width in dots.
	 *
	 * @var int
	 */
	private $dots = self::DOTS_80MM;

	/**
	 * The width of one character cell in dots.
	 *
	 * @var int
	 */
	private $cell = 12;

	/**
	 * Left inset that centres the character grid on the paper.
	 *
	 * @var int
	 */
	private $margin = 0;

	/**
	 * The base font size whose advance fits one cell.
	 *
	 * @var int
	 */
	private $font_size = 15;

	/**
	 * The current alignment mode (left|center|right).
	 *
	 * @var string
	 */
	private $align = 'left';

	/**
	 * The current text width multiplier.
	 *
	 * @var int
	 */
	private $width = 1;

	/**
	 * The current text height multiplier.
	 *
	 * @var int
	 */
	private $height = 1;

	/**
	 * Whether bold is currently active.
	 *
	 * @var bool
	 */
	private $bold = false;

	/**
	 * Whether invert is currently active.
	 *
	 * @var bool
	 */
	private $invert = false;

	/**
	 * The cut requested by the AST, or null when it asked for none.
	 *
	 * @var string|null
	 */
	private $cut_type = null;

	/**
	 * The drawer kick requested by the AST/options, or null when none.
	 *
	 * @var string|null
	 */
	private $drawer = null;

	/**
	 * Constructor.
	 *
	 * @param array $options Render options.
	 */
	public function __construct( array $options = array() ) {
		$this->options = $options;
	}

	/**
	 * Whether this build can rasterize at all.
	 *
	 * GD with FreeType is near-universal on WordPress hosts (core needs an image
	 * library for media), but it is not guaranteed, and a caller that offers
	 * `image/png` without checking would advertise a format it cannot produce.
	 *
	 * @return bool
	 */
	public static function is_supported(): bool {
		return \function_exists( 'imagecreatetruecolor' )
			&& \function_exists( 'imagettftext' )
			&& \function_exists( 'imagettfbbox' )
			&& \function_exists( 'imagepng' )
			&& '' !== self::font_path( false );
	}

	/**
	 * Emit a monochrome PNG of the receipt.
	 *
	 * @param array $ast The thermal AST root (a receipt node).
	 *
	 * @return string The PNG bytes, or '' when this build cannot rasterize.
	 */
	public function emit( array $ast ): string {
		if ( ! self::is_supported() ) {
			return '';
		}

		$this->ops      = array();
		$this->runs     = array();
		$this->align    = 'left';
		$this->width    = 1;
		$this->height   = 1;
		$this->bold     = false;
		$this->invert   = false;
		$this->cut_type = null;
		$this->drawer   = null;

		$this->configure_geometry( $ast );

		$children = isset( $ast['children'] ) && \is_array( $ast['children'] ) ? $ast['children'] : array();
		$this->walk_nodes( $children );
		$this->flush_line();

		if ( null === $this->drawer && ! empty( $this->options['auto_open_drawer'] ) ) {
			$this->drawer = 'end';
		}

		return $this->paint();
	}

	/**
	 * The cut the rendered AST asked for, for the `X-Star-Cut` header.
	 *
	 * @return string|null 'full', 'partial', or null when the receipt cuts nothing.
	 */
	public function cut_type(): ?string {
		return $this->cut_type;
	}

	/**
	 * The drawer kick the rendered job asked for, for `X-Star-CashDrawer`.
	 *
	 * @return string|null 'end', or null when no drawer should fire.
	 */
	public function drawer(): ?string {
		return $this->drawer;
	}

	/**
	 * Resolve paper width, cell size and the font size that fits a cell.
	 *
	 * @param array $ast The thermal AST root.
	 *
	 * @return void
	 */
	private function configure_geometry( array $ast ): void {
		$this->columns = isset( $ast['paper_width'] ) ? max( 1, (int) $ast['paper_width'] ) : 48;
		$this->dots    = $this->columns >= self::WIDE_PAPER_COLUMNS ? self::DOTS_80MM : self::DOTS_58MM;

		// Floor the cell so the grid can never run past the paper edge, then centre
		// whatever slack that leaves. 48 and 32 columns divide exactly; 42 does not.
		$this->cell   = max( 1, (int) floor( $this->dots / $this->columns ) );
		$this->margin = (int) floor( ( $this->dots - ( $this->cell * $this->columns ) ) / 2 );

		$this->font_size = $this->fitting_font_size( $this->cell );
	}

	/**
	 * The largest font size whose advance still fits inside one cell.
	 *
	 * Measured rather than derived: the em-to-advance ratio is not perfectly
	 * linear at small sizes once hinting rounds glyphs to the pixel grid, so a
	 * computed size can overflow the paper by a few dots per line.
	 *
	 * @param int $cell The cell width in dots.
	 *
	 * @return int
	 */
	private function fitting_font_size( int $cell ): int {
		$font = self::font_path();
		$best = 1;

		for ( $size = 4; $size <= 72; $size++ ) {
			$box = imagettfbbox( $size, 0, $font, str_repeat( 'M', 20 ) );
			if ( ! \is_array( $box ) ) {
				break;
			}
			if ( ( ( $box[2] - $box[0] ) / 20 ) > $cell ) {
				break;
			}
			$best = $size;
		}

		return $best;
	}

	/**
	 * Path to the receipt raster font.
	 *
	 * Defaults to the DejaVu Sans Mono that already ships inside the bundled
	 * dompdf, so no font is added to the plugin. A site whose receipts need
	 * glyphs DejaVu lacks — CJK, Hebrew, Thai — points this at a face that has
	 * them.
	 *
	 * @param bool $filtered Whether to run the filter. The support probe skips it
	 *                       so a broken filter cannot make the emitter look absent.
	 *
	 * @return string The readable font path, or '' when none resolves.
	 */
	private static function font_path( bool $filtered = true ): string {
		$bundled = \dirname( __DIR__, 3 ) . '/vendor_prefixed/dompdf/dompdf/lib/fonts/DejaVuSansMono.ttf';

		if ( ! $filtered ) {
			return is_readable( $bundled ) ? $bundled : '';
		}

		/**
		 * Filters the TrueType font used to rasterize receipts for cloud printers.
		 *
		 * @since 1.10.0
		 *
		 * @param string $path Absolute path to a .ttf file.
		 */
		$path = (string) apply_filters( 'woocommerce_pos_receipt_raster_font', $bundled );

		if ( '' === $path || ! is_readable( $path ) ) {
			return is_readable( $bundled ) ? $bundled : '';
		}

		return $path;
	}

	/**
	 * Path to the bold companion of the raster font.
	 *
	 * Falls back to the regular face when a filtered font has no `-Bold` sibling,
	 * so a custom font never breaks bold text — it just stops looking bold.
	 *
	 * @return string
	 */
	private static function bold_font_path(): string {
		$regular = self::font_path();
		$bold    = preg_replace( '/\.ttf$/i', '-Bold.ttf', $regular );

		return ( \is_string( $bold ) && is_readable( $bold ) ) ? $bold : $regular;
	}

	/**
	 * Walk a list of AST nodes.
	 *
	 * @param array $nodes The AST nodes.
	 *
	 * @return void
	 */
	private function walk_nodes( array $nodes ): void {
		foreach ( $nodes as $node ) {
			if ( \is_array( $node ) ) {
				$this->walk_node( $node );
			}
		}
	}

	/**
	 * Walk a single AST node.
	 *
	 * @param array $node The AST node.
	 *
	 * @return void
	 */
	private function walk_node( array $node ): void {
		$type     = isset( $node['type'] ) ? $node['type'] : '';
		$children = isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array();

		switch ( $type ) {
			case 'raw-text':
				$this->append_text( $this->normalize_text( isset( $node['value'] ) ? (string) $node['value'] : '' ) );
				break;
			case 'text':
				$this->emit_text_line( $children );
				break;
			case 'bold':
				$previous     = $this->bold;
				$this->bold   = true;
				$this->walk_nodes( $children );
				$this->bold   = $previous;
				break;
			case 'invert':
				$previous     = $this->invert;
				$this->invert = true;
				$this->walk_nodes( $children );
				$this->invert = $previous;
				break;
			case 'underline':
				// No raster expression yet; the children still print.
				$this->walk_nodes( $children );
				break;
			case 'size':
				$this->emit_size( $node );
				break;
			case 'align':
				$previous    = $this->align;
				$this->align = isset( $node['mode'] ) ? (string) $node['mode'] : 'left';
				$this->walk_nodes( $children );
				$this->align = $previous;
				break;
			case 'row':
				$this->emit_row( $node );
				break;
			case 'line':
				$this->emit_rule( $node );
				break;
			case 'barcode':
				$this->emit_barcode( $node );
				break;
			case 'qrcode':
				$this->emit_qrcode( $node );
				break;
			case 'image':
				$this->emit_image( $node );
				break;
			case 'cut':
				$this->cut_type = isset( $node['cut_type'] ) ? (string) $node['cut_type'] : 'partial';
				break;
			case 'feed':
				$this->push(
					array(
						'op'    => 'feed',
						'lines' => isset( $node['lines'] ) ? max( 1, (int) $node['lines'] ) : 1,
					)
				);
				break;
			case 'drawer':
				$this->drawer = 'end';
				break;
			case 'receipt':
				$this->walk_nodes( $children );
				break;
		}
	}

	/**
	 * Walk a size-wrapped block with its multipliers applied.
	 *
	 * GD cannot scale a glyph's axes independently, so the height multiplier
	 * drives the glyph size and the width multiplier drives the cell advance.
	 * Templates in practice scale both together, where the two agree.
	 *
	 * @param array $node The size AST node.
	 *
	 * @return void
	 */
	private function emit_size( array $node ): void {
		$previous_width  = $this->width;
		$previous_height = $this->height;

		$this->width  = isset( $node['width'] ) ? max( 1, min( 8, (int) $node['width'] ) ) : 1;
		$this->height = isset( $node['height'] ) ? max( 1, min( 8, (int) $node['height'] ) ) : 1;

		$this->walk_nodes( isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array() );

		$this->width  = $previous_width;
		$this->height = $previous_height;
	}

	/**
	 * Emit one printed text line, aligned within the paper width.
	 *
	 * @param array $children The child nodes of the text node.
	 *
	 * @return void
	 */
	private function emit_text_line( array $children ): void {
		$this->walk_nodes( $children );
		$this->close_line( $this->align );
	}

	/**
	 * Emit a row as one physical line of fixed columns.
	 *
	 * @param array $node The row AST node.
	 *
	 * @return void
	 */
	private function emit_row( array $node ): void {
		$cols   = isset( $node['children'] ) && \is_array( $node['children'] ) ? $node['children'] : array();
		$widths = $this->resolve_row_widths( $cols, $this->columns );

		$row = '';
		foreach ( $cols as $index => $col ) {
			$width = isset( $widths[ $index ] ) ? $widths[ $index ] : 1;
			$text  = $this->normalize_text( $this->extract_text( isset( $col['children'] ) ? $col['children'] : array() ) );
			$text  = $this->truncate_display( $text, $width );
			$pad   = max( 0, $width - $this->display_width( $text ) );
			$align = isset( $col['align'] ) ? (string) $col['align'] : 'left';
			$row  .= 'right' === $align ? str_repeat( ' ', $pad ) . $text : $text . str_repeat( ' ', $pad );
		}

		$this->append_text( $row );
		$this->close_line( 'left' );
	}

	/**
	 * Emit a horizontal rule line.
	 *
	 * @param array $node The line AST node.
	 *
	 * @return void
	 */
	private function emit_rule( array $node ): void {
		$style = isset( $node['style'] ) ? (string) $node['style'] : 'single';

		if ( 'dotted' === $style ) {
			$pattern = '. ';
			$repeat  = (int) ceil( $this->columns / \strlen( $pattern ) );
			$text    = substr( str_repeat( $pattern, $repeat ), 0, $this->columns );
		} elseif ( 'double' === $style ) {
			$text = str_repeat( '=', $this->columns );
		} else {
			$text = str_repeat( '-', $this->columns );
		}

		$this->append_text( $text );
		$this->close_line( 'left' );
	}

	/**
	 * Rasterize a barcode into the receipt.
	 *
	 * @param array $node The barcode AST node.
	 *
	 * @return void
	 */
	private function emit_barcode( array $node ): void {
		$png = Barcode_Image::barcode_png(
			isset( $node['barcode_type'] ) ? (string) $node['barcode_type'] : 'code128',
			isset( $node['value'] ) ? (string) $node['value'] : '',
			isset( $node['height'] ) ? (int) $node['height'] : 40
		);

		$this->push_image( $png );

		// The human-readable value, as the HTML and PDF paths render it — and, when
		// generation failed (a non-numeric EAN-13, say), the only trace of the
		// barcode left on the receipt. Suppressing it with the image would leave a
		// silent gap where a scannable code should be.
		$value = $this->normalize_text( isset( $node['value'] ) ? (string) $node['value'] : '' );
		if ( '' !== $value ) {
			$this->append_text( $value );
			$this->close_line( 'center' );
		}
	}

	/**
	 * Rasterize a QR code into the receipt.
	 *
	 * @param array $node The qrcode AST node.
	 *
	 * @return void
	 */
	private function emit_qrcode( array $node ): void {
		$this->push_image(
			Barcode_Image::qrcode_png(
				isset( $node['value'] ) ? (string) $node['value'] : '',
				isset( $node['size'] ) ? (int) $node['size'] : 4
			)
		);
	}

	/**
	 * Composite a template `<image>` (typically the store logo).
	 *
	 * Templates carry the logo as an ordinary WordPress URL, not a data URI, so
	 * dropping everything that is not inline would drop every real store logo —
	 * the main thing this format exists to carry. Local URLs are read from disk;
	 * remote ones are left out rather than fetched, because this runs inside the
	 * printer's job fetch and an outbound request there would stall the print.
	 *
	 * @param array $node The image AST node.
	 *
	 * @return void
	 */
	private function emit_image( array $node ): void {
		$bytes = ( new Local_Image_Resolver() )->bytes( isset( $node['src'] ) ? (string) $node['src'] : '' );

		$this->push_image( $bytes, isset( $node['width'] ) ? (int) $node['width'] : 0 );
	}

	/**
	 * Queue an image block, scaled to fit the paper.
	 *
	 * @param string $png            Raw image bytes.
	 * @param int    $requested_dots Preferred width in dots, or 0 for natural size.
	 *
	 * @return void
	 */
	private function push_image( string $png, int $requested_dots = 0 ): void {
		if ( '' === $png ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid image data returns false rather than warning.
		$image = @imagecreatefromstring( $png );
		if ( false === $image ) {
			return;
		}

		// A decoded image always has at least one pixel in each axis, so there is no
		// zero-width case to guard the division below against.
		$natural_width  = imagesx( $image );
		$natural_height = imagesy( $image );

		// unset() rather than imagedestroy(), here and at the other four GD
		// handles in this class. imagedestroy() is deprecated as of PHP 8.5 and
		// has been a no-op since 8.0, where GD started returning GdImage objects
		// that the collector frees. Dropping the only reference does the same
		// work on 8.x AND on 7.4, where the handle is still a resource freed by
		// refcount — so this needs no version branch. Do not reintroduce the
		// call; the lint gate fails on it once CI moves past PHP 8.1.
		unset( $image );

		$target = $requested_dots > 0 ? min( $requested_dots, $this->dots ) : min( $natural_width, $this->dots );
		$scale  = $target / $natural_width;

		$this->push(
			array(
				'op'     => 'image',
				'png'    => $png,
				'width'  => max( 1, (int) round( $natural_width * $scale ) ),
				'height' => max( 1, (int) round( $natural_height * $scale ) ),
			)
		);
	}

	/**
	 * Append text to the buffered line under the current style.
	 *
	 * @param string $text The text.
	 *
	 * @return void
	 */
	private function append_text( string $text ): void {
		if ( '' === $text ) {
			return;
		}

		$last = \count( $this->runs ) - 1;
		if ( $last >= 0
			&& $this->runs[ $last ]['bold'] === $this->bold
			&& $this->runs[ $last ]['invert'] === $this->invert
			&& $this->runs[ $last ]['w'] === $this->width
			&& $this->runs[ $last ]['h'] === $this->height
		) {
			$this->runs[ $last ]['text'] .= $text;

			return;
		}

		$this->runs[] = array(
			'text'   => $text,
			'bold'   => $this->bold,
			'invert' => $this->invert,
			'w'      => $this->width,
			'h'      => $this->height,
		);
	}

	/**
	 * Close the buffered runs into one display-list entry per physical line.
	 *
	 * A thermal printer wraps a line that runs past the paper; nothing wraps a
	 * raster, so the wrapping happens here. Without it an over-long product name
	 * would simply be cut off at the paper edge — text the other emitters print
	 * in full on the next line.
	 *
	 * @param string $align Alignment for the closed lines.
	 *
	 * @return void
	 */
	private function close_line( string $align ): void {
		$runs       = $this->runs;
		$this->runs = array();

		if ( array() === $runs ) {
			$this->push_line( array(), $align );

			return;
		}

		$line  = array();
		$cells = 0;

		foreach ( $runs as $run ) {
			$pending = '';
			foreach ( $this->split_chars( $run['text'] ) as $char ) {
				if ( "\n" === $char ) {
					$line = $this->close_run( $line, $run, $pending );
					$this->push_line( $line, $align );
					$line    = array();
					$cells   = 0;
					$pending = '';
					continue;
				}

				$cost = ( $this->is_full_width( $char ) ? 2 : 1 ) * max( 1, (int) $run['w'] );
				if ( $cells + $cost > $this->columns && ( array() !== $line || '' !== $pending ) ) {
					$line = $this->close_run( $line, $run, $pending );
					$this->push_line( $line, $align );
					$line    = array();
					$cells   = 0;
					$pending = '';
				}

				$pending .= $char;
				$cells   += $cost;
			}

			$line = $this->close_run( $line, $run, $pending );
		}

		$this->push_line( $line, $align );
	}

	/**
	 * Append a run's accumulated characters to the line being assembled.
	 *
	 * @param array  $line    The line so far.
	 * @param array  $run     The run supplying the style.
	 * @param string $pending The characters accumulated for this run.
	 *
	 * @return array The line.
	 */
	private function close_run( array $line, array $run, string $pending ): array {
		if ( '' !== $pending ) {
			$line[] = array_merge( $run, array( 'text' => $pending ) );
		}

		return $line;
	}

	/**
	 * Push one physical line onto the display list.
	 *
	 * @param array  $line  Runs making up the line.
	 * @param string $align Alignment mode.
	 *
	 * @return void
	 */
	private function push_line( array $line, string $align ): void {
		$line = $this->rtrim_line( $line );

		$cells  = 0;
		$scale  = 1;
		foreach ( $line as $run ) {
			$cells += $this->display_width( $run['text'] ) * max( 1, (int) $run['w'] );
			$scale  = max( $scale, max( 1, (int) $run['h'] ) );
		}

		$this->push(
			array(
				'op'     => 'text',
				'runs'   => $line,
				'indent' => 'left' === $align ? 0 : $this->alignment_padding( $align, $cells, $this->columns ),
				'height' => $scale,
			)
		);
	}

	/**
	 * Drop trailing whitespace from a line's last run.
	 *
	 * @param array $line Runs making up the line.
	 *
	 * @return array
	 */
	private function rtrim_line( array $line ): array {
		for ( $index = \count( $line ) - 1; $index >= 0; $index-- ) {
			$trimmed = rtrim( $line[ $index ]['text'], " \t" );
			if ( '' !== $trimmed ) {
				$line[ $index ]['text'] = $trimmed;
				break;
			}
			unset( $line[ $index ] );
		}

		return array_values( $line );
	}

	/**
	 * Flush text buffered outside a line-terminating node.
	 *
	 * @return void
	 */
	private function flush_line(): void {
		if ( array() !== $this->runs ) {
			$this->close_line( $this->align );
		}
	}

	/**
	 * Append a display-list entry.
	 *
	 * @param array $op The entry.
	 *
	 * @return void
	 */
	private function push( array $op ): void {
		$this->ops[] = $op;
	}

	/**
	 * Height in dots of one display-list entry.
	 *
	 * @param array $op The entry.
	 *
	 * @return int
	 */
	private function op_height( array $op ): int {
		$line = (int) round( $this->cell * self::LINE_HEIGHT_RATIO );

		switch ( $op['op'] ) {
			case 'text':
				return $line * (int) $op['height'];
			case 'feed':
				return $line * (int) $op['lines'];
			case 'image':
				return (int) $op['height'];
			default:
				return 0;
		}
	}

	/**
	 * Draw the display list onto a canvas and encode it.
	 *
	 * @return string The PNG bytes.
	 */
	private function paint(): string {
		$total = 0;
		foreach ( $this->ops as $op ) {
			$total += $this->op_height( $op );
		}
		$total = max( 1, min( self::MAX_HEIGHT, $total ) );

		// A palette canvas, not a truecolour one: allocating white and then black
		// gives an image with exactly those two colours and a white background.
		// Drawing truecolour and calling imagetruecolortopalette() afterwards does
		// NOT — GD's quantizer rewrites pure white as (252,254,252), which is not
		// what a thermal head should be handed.
		$canvas = imagecreate( $this->dots, $total );
		if ( false === $canvas ) {
			return '';
		}

		$white = imagecolorallocate( $canvas, 255, 255, 255 );
		$black = imagecolorallocate( $canvas, 0, 0, 0 );

		// Antialiasing is switched off by passing imagettftext() a *negative* colour
		// index — but white is index 0 here, and -0 is 0, so inverted text would
		// silently keep antialiasing and stipple grey into a two-colour image. A
		// second, deliberately duplicate white allocation gives it an index that can
		// actually be negated.
		$white_ink = imagecolorallocate( $canvas, 255, 255, 255 );

		$y = 0;
		foreach ( $this->ops as $op ) {
			$height = $this->op_height( $op );
			if ( $y >= $total ) {
				break;
			}

			if ( 'text' === $op['op'] && array() !== $op['runs'] ) {
				$this->draw_text( $canvas, $op, $y, $white, $white_ink, $black );
			} elseif ( 'image' === $op['op'] ) {
				$this->draw_image( $canvas, $op, $y, $white, $black );
			}

			$y += $height;
		}

		ob_start();
		imagepng( $canvas, null, 9 );
		$png = (string) ob_get_clean();
		unset( $canvas );

		return $png;
	}

	/**
	 * Draw one physical line, run by run and cell by cell.
	 *
	 * @param resource|object $canvas    The target canvas.
	 * @param array           $op        The display-list entry.
	 * @param int             $top       Top of the line box, in dots.
	 * @param int             $white     Background colour index.
	 * @param int             $white_ink Negatable white index, for inverted text.
	 * @param int             $black     Foreground colour index.
	 *
	 * @return void
	 */
	private function draw_text( $canvas, array $op, int $top, int $white, int $white_ink, int $black ): void {
		$box = (int) round( $this->cell * self::LINE_HEIGHT_RATIO ) * max( 1, (int) $op['height'] );
		$x   = $this->margin + ( (int) $op['indent'] * $this->cell );

		foreach ( $op['runs'] as $run ) {
			$x = $this->draw_run( $canvas, $run, $x, $top, $box, $white, $white_ink, $black );
			if ( $x >= $this->dots ) {
				break;
			}
		}
	}

	/**
	 * Draw one styled run, returning the x it ends at.
	 *
	 * Unscaled runs are painted straight onto the canvas. Scaled ones are drawn
	 * at base size into a scratch canvas and copied across at integer factors,
	 * because GD cannot scale a glyph's axes independently: doubling the font
	 * size for `<size height="2">` would also double the glyph's width while the
	 * cell advance stayed put, overlapping every character with its neighbour.
	 * Nearest-neighbour scaling of a two-colour bitmap stays two-colour, so this
	 * costs no crispness.
	 *
	 * @param resource|object $canvas    The target canvas.
	 * @param array           $run       The run.
	 * @param int             $x         Left edge, in dots.
	 * @param int             $top       Top of the line box, in dots.
	 * @param int             $box       Line box height, in dots.
	 * @param int             $white     Background colour index.
	 * @param int             $white_ink Negatable white index.
	 * @param int             $black     Foreground colour index.
	 *
	 * @return int The x after the run.
	 */
	private function draw_run( $canvas, array $run, int $x, int $top, int $box, int $white, int $white_ink, int $black ): int {
		$scale_x = max( 1, (int) $run['w'] );
		$scale_y = max( 1, (int) $run['h'] );
		$chars   = $this->split_chars( (string) $run['text'] );
		$cells   = 0;
		foreach ( $chars as $char ) {
			$cells += $this->is_full_width( $char ) ? 2 : 1;
		}

		$span = $cells * $this->cell * $scale_x;
		if ( $run['invert'] ) {
			imagefilledrectangle( $canvas, $x, $top, min( $this->dots - 1, $x + $span - 1 ), $top + $box - 1, $black );
		}

		if ( 1 === $scale_x && 1 === $scale_y ) {
			$this->draw_cells( $canvas, $chars, $x, $top + (int) round( $box * self::BASELINE_RATIO ), $this->cell, $this->font_size, $run, $white_ink, $black );

			return $x + $span;
		}

		// Scratch is one line box at base scale; the copy below stretches it.
		$base_box   = (int) round( $this->cell * self::LINE_HEIGHT_RATIO );
		$base_span  = max( 1, $cells * $this->cell );
		$scratch    = imagecreatetruecolor( $base_span, $base_box );
		if ( false === $scratch ) {
			return $x + $span;
		}

		$scratch_bg  = imagecolorallocate( $scratch, 255, 255, 255 );
		$scratch_ink = imagecolorallocate( $scratch, 0, 0, 0 );
		if ( $run['invert'] ) {
			$swap        = $scratch_bg;
			$scratch_bg  = $scratch_ink;
			$scratch_ink = $swap;
		}
		imagefilledrectangle( $scratch, 0, 0, $base_span - 1, $base_box - 1, $scratch_bg );
		$this->draw_cells(
			$scratch,
			$chars,
			0,
			(int) round( $base_box * self::BASELINE_RATIO ),
			$this->cell,
			$this->font_size,
			$run,
			$scratch_ink,
			$scratch_ink
		);

		$this->composite_thresholded( $canvas, $scratch, $x, $top, $base_span * $scale_x, $base_box * $scale_y, $white, $black );
		unset( $scratch );

		return $x + $span;
	}

	/**
	 * Paint a run's glyphs at fixed cell positions.
	 *
	 * @param resource|object $canvas    The target canvas.
	 * @param array           $chars     The characters.
	 * @param int             $x         Left edge, in dots.
	 * @param int             $baseline  Text baseline, in dots.
	 * @param int             $cell      Cell width, in dots.
	 * @param int             $size      Font size.
	 * @param array           $run       The run, for its bold flag.
	 * @param int             $white_ink Negatable white index.
	 * @param int             $black     Foreground colour index.
	 *
	 * @return void
	 */
	private function draw_cells( $canvas, array $chars, int $x, int $baseline, int $cell, int $size, array $run, int $white_ink, int $black ): void {
		$font  = $run['bold'] ? self::bold_font_path() : self::font_path();
		$ink   = $run['invert'] ? $white_ink : $black;
		$limit = imagesx( $canvas );

		foreach ( $chars as $char ) {
			if ( $x >= $limit ) {
				break;
			}
			if ( ' ' !== $char ) {
				// A negative colour index turns antialiasing off, so a glyph lands as
				// the exact ink colour instead of allocating grey palette entries the
				// printer would have to guess at.
				imagettftext( $canvas, $size, 0, $x, $baseline, -$ink, $font, $char );
			}
			// A full-width glyph occupies the two cells display_width() counts.
			$x += $cell * ( $this->is_full_width( $char ) ? 2 : 1 );
		}
	}

	/**
	 * Composite an image block, centred on the paper.
	 *
	 * @param resource|object $canvas The target canvas (GD resource on PHP 7.4, GdImage on 8+).
	 * @param array           $op     The display-list entry.
	 * @param int             $top    Top of the block, in dots.
	 * @param int             $white  Background colour index.
	 * @param int             $black  Foreground colour index.
	 *
	 * @return void
	 */
	private function draw_image( $canvas, array $op, int $top, int $white, int $black ): void {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Invalid image data returns false rather than warning.
		$source = @imagecreatefromstring( (string) $op['png'] );
		if ( false === $source ) {
			return;
		}

		$left = max( 0, (int) floor( ( $this->dots - (int) $op['width'] ) / 2 ) );
		$this->composite_thresholded( $canvas, $source, $left, $top, (int) $op['width'], (int) $op['height'], $white, $black );
		unset( $source );
	}

	/**
	 * Scale a source image onto the canvas as pure black and white.
	 *
	 * Copying straight onto the palette canvas would allocate a grey entry for
	 * every interpolated pixel, leaving the printer to dither a receipt we want
	 * crisp — so the resample lands in a scratch buffer and each pixel is
	 * thresholded on its way across.
	 *
	 * @param resource|object $canvas The target canvas.
	 * @param resource|object $source The source image.
	 * @param int             $left   Destination left, in dots.
	 * @param int             $top    Destination top, in dots.
	 * @param int             $width  Destination width, in dots.
	 * @param int             $height Destination height, in dots.
	 * @param int             $white  Background colour index.
	 * @param int             $black  Foreground colour index.
	 *
	 * @return void
	 */
	private function composite_thresholded( $canvas, $source, int $left, int $top, int $width, int $height, int $white, int $black ): void {
		$width  = max( 1, $width );
		$height = max( 1, $height );

		$scaled = imagecreatetruecolor( $width, $height );
		if ( false === $scaled ) {
			return;
		}

		imagefilledrectangle( $scaled, 0, 0, $width - 1, $height - 1, imagecolorallocate( $scaled, 255, 255, 255 ) );
		imagecopyresampled( $scaled, $source, 0, 0, 0, 0, $width, $height, imagesx( $source ), imagesy( $source ) );

		$canvas_width  = imagesx( $canvas );
		$canvas_height = imagesy( $canvas );

		for ( $row = 0; $row < $height; $row++ ) {
			$y = $top + $row;
			if ( $y < 0 || $y >= $canvas_height ) {
				continue;
			}
			for ( $column = 0; $column < $width; $column++ ) {
				$x = $left + $column;
				if ( $x < 0 || $x >= $canvas_width ) {
					continue;
				}

				$rgb = imagecolorat( $scaled, $column, $row );
				// Rec. 601 luma, the standard grey weighting, thresholded at mid-grey.
				$luma = ( 0.299 * ( ( $rgb >> 16 ) & 0xFF ) )
					+ ( 0.587 * ( ( $rgb >> 8 ) & 0xFF ) )
					+ ( 0.114 * ( $rgb & 0xFF ) );

				imagesetpixel( $canvas, $x, $y, $luma < 128 ? $black : $white );
			}
		}

		unset( $scaled );
	}
}
