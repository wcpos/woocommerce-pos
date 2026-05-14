import { useState, useRef, useCallback, useEffect, type CSSProperties } from 'react';
import { CodeEditor } from './components/code-editor';
import { FieldPicker } from './components/field-picker';
import { LivePreview } from './components/live-preview';
import { PhpPreview } from './components/php-preview';
import { ThermalPreview } from './components/thermal-preview';
import { PreviewToggle } from './components/preview-toggle';
import { useContentSync } from './hooks/use-content-sync';
import { usePreviewData } from './hooks/use-preview-data';
import { t } from './translations';
import type { EditorConfig } from './types';

const PAPER_WIDTH_CHARS: Record<string, number> = {
	'80mm': 48,
	'58mm': 32,
};

export function getThermalStarterShell(paperWidth: string): string {
	const chars = PAPER_WIDTH_CHARS[paperWidth] ?? 48;
	const half = Math.floor(chars / 2);
	return `<receipt paper-width="${chars}">
  <align mode="center">
    <bold><size width="2" height="2"><text>{{store.name}}</text></size></bold>
    {{#store.address_lines}}
    <text>{{.}}</text>
    {{/store.address_lines}}
  </align>
  <line />
  <row>
    <col width="${half}">{{i18n.order}} #{{order.number}}</col>
    <col width="${half}" align="right">{{order.created.datetime}}</col>
  </row>
  <line />
  {{#lines}}
  <row>
    <col width="${half}">{{name}} x{{qty}}</col>
    <col width="${half}" align="right">{{line_total_display}}</col>
  </row>
  {{/lines}}
  <line />
  <row>
    <col width="${half}"><bold>{{i18n.total}}</bold></col>
    <col width="${half}" align="right"><bold>{{totals.total_incl_display}}</bold></col>
  </row>
  <feed lines="1" />
  <align mode="center"><text>{{i18n.thank_you_purchase}}</text></align>
  <feed lines="3" />
  <cut />
</receipt>`;
}

export const STARTER_SHELLS: Record<EditorConfig['engine'], string> = {
	logicless: `<div style="box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 13px; line-height: 1.45; color: #1f2937; padding: 32px 36px;">

  <header style="display: flex; align-items: flex-start; gap: 22px; padding-bottom: 18px; border-bottom: 2px solid #111827;">
    <div style="flex: 1 1 auto; min-width: 0;">
      <div style="font-size: 22px; font-weight: 700; letter-spacing: -0.01em;">{{store.name}}</div>
      {{#store.address_lines}}
      <div style="margin-top: 2px; color: #6b7280; font-size: 12px;">{{.}}</div>
      {{/store.address_lines}}
    </div>
    <div style="flex: 0 0 auto; text-align: right;">
      <div style="font-size: 11px; letter-spacing: 0.16em; color: #6b7280; text-transform: uppercase;">{{i18n.order}}</div>
      <div style="font-size: 22px; font-weight: 700; margin-top: 2px;">#{{order.number}}</div>
      <div style="margin-top: 6px; color: #6b7280; font-size: 12px;">{{order.created.datetime}}</div>
    </div>
  </header>

  <table style="width: 100%; border-collapse: collapse; margin-top: 22px;">
    <thead>
      <tr>
        <th style="text-align: left; font-weight: 600; color: #6b7280; padding: 8px 6px; border-bottom: 1px solid #e5e7eb; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em;">{{i18n.item_short}}</th>
        <th style="text-align: right; font-weight: 600; color: #6b7280; padding: 8px 6px; border-bottom: 1px solid #e5e7eb; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em;">{{i18n.qty_short}}</th>
        <th style="text-align: right; font-weight: 600; color: #6b7280; padding: 8px 6px; border-bottom: 1px solid #e5e7eb; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em;">{{i18n.total}}</th>
      </tr>
    </thead>
    <tbody>
      {{#lines}}
      <tr>
        <td style="padding: 10px 6px; border-bottom: 1px solid #f3f4f6; font-weight: 600;">{{name}}</td>
        <td style="padding: 10px 6px; border-bottom: 1px solid #f3f4f6; text-align: right; font-variant-numeric: tabular-nums;">{{qty}}</td>
        <td style="padding: 10px 6px; border-bottom: 1px solid #f3f4f6; text-align: right; font-variant-numeric: tabular-nums;">{{line_total_display}}</td>
      </tr>
      {{/lines}}
    </tbody>
  </table>

  <div style="margin-top: 20px; margin-left: auto; max-width: 280px;">
    <div style="display: flex; justify-content: space-between; padding: 2px 0;">
      <span style="color: #6b7280;">{{i18n.subtotal}}</span>
      <span style="font-variant-numeric: tabular-nums;">{{totals.subtotal_display}}</span>
    </div>
    <div style="border-top: 2px solid #111827; margin: 8px 0;"></div>
    <div style="display: flex; justify-content: space-between; padding: 4px 0; font-size: 18px; font-weight: 700;">
      <span>{{i18n.total}}</span>
      <span style="font-variant-numeric: tabular-nums;">{{totals.total_incl_display}}</span>
    </div>
  </div>

  <div style="margin-top: 24px; padding-top: 14px; border-top: 1px solid #e5e7eb; text-align: center; color: #6b7280; font-size: 12px; font-style: italic;">{{i18n.thank_you_purchase}}</div>
</div>`,

	thermal: getThermalStarterShell('80mm'),

	'legacy-php': `<?php
/**
 * Custom Receipt Template (PHP)
 *
 * Receives $receipt_data — an array with all receipt info (store, order,
 * lines, totals, i18n…). Escape text with esc_html() and format money
 * with wc_price().
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$i18n          = $receipt_data['i18n'] ?? array();
$currency_args = array( 'currency' => $receipt_data['order']['currency'] ?? get_woocommerce_currency() );
?>
<!DOCTYPE html>
<html>
<head>
	<meta charset="utf-8">
	<title>Receipt</title>
	<?php do_action( 'woocommerce_pos_receipt_head' ); ?>
</head>
<body style="box-sizing: border-box; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; font-size: 13px; line-height: 1.45; color: #1f2937; margin: 0; padding: 32px 36px;">

	<header style="display: flex; align-items: flex-start; gap: 22px; padding-bottom: 18px; border-bottom: 2px solid #111827;">
		<div style="flex: 1 1 auto; min-width: 0;">
			<div style="font-size: 22px; font-weight: 700; letter-spacing: -0.01em;"><?php echo esc_html( $receipt_data['store']['name'] ?? '' ); ?></div>
			<?php if ( ! empty( $receipt_data['store']['address_lines'] ) ) : ?>
				<?php foreach ( $receipt_data['store']['address_lines'] as $address_line ) : ?>
					<div style="margin-top: 2px; color: #6b7280; font-size: 12px;"><?php echo esc_html( $address_line ); ?></div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<div style="flex: 0 0 auto; text-align: right;">
			<div style="font-size: 11px; letter-spacing: 0.16em; color: #6b7280; text-transform: uppercase;"><?php echo esc_html( $i18n['order'] ?? __( 'Order', 'woocommerce-pos' ) ); ?></div>
			<div style="font-size: 22px; font-weight: 700; margin-top: 2px;">#<?php echo esc_html( $receipt_data['order']['number'] ?? '' ); ?></div>
			<div style="margin-top: 6px; color: #6b7280; font-size: 12px;"><?php echo esc_html( $receipt_data['order']['created']['datetime'] ?? '' ); ?></div>
		</div>
	</header>

	<table style="width: 100%; border-collapse: collapse; margin-top: 22px;">
		<thead>
			<tr>
				<th style="text-align: left; font-weight: 600; color: #6b7280; padding: 8px 6px; border-bottom: 1px solid #e5e7eb; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em;"><?php echo esc_html( $i18n['item_short'] ?? __( 'Item', 'woocommerce-pos' ) ); ?></th>
				<th style="text-align: right; font-weight: 600; color: #6b7280; padding: 8px 6px; border-bottom: 1px solid #e5e7eb; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em;"><?php echo esc_html( $i18n['qty_short'] ?? __( 'Qty', 'woocommerce-pos' ) ); ?></th>
				<th style="text-align: right; font-weight: 600; color: #6b7280; padding: 8px 6px; border-bottom: 1px solid #e5e7eb; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em;"><?php echo esc_html( $i18n['total'] ?? __( 'Total', 'woocommerce-pos' ) ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $receipt_data['lines'] as $line ) : ?>
				<tr>
					<td style="padding: 10px 6px; border-bottom: 1px solid #f3f4f6; font-weight: 600;"><?php echo esc_html( $line['name'] ?? '' ); ?></td>
					<td style="padding: 10px 6px; border-bottom: 1px solid #f3f4f6; text-align: right; font-variant-numeric: tabular-nums;"><?php echo esc_html( $line['qty'] ?? 0 ); ?></td>
					<td style="padding: 10px 6px; border-bottom: 1px solid #f3f4f6; text-align: right; font-variant-numeric: tabular-nums;"><?php echo wp_kses_post( wc_price( $line['line_total'] ?? 0, $currency_args ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<div style="margin-top: 20px; margin-left: auto; max-width: 280px;">
		<div style="display: flex; justify-content: space-between; padding: 2px 0;">
			<span style="color: #6b7280;"><?php echo esc_html( $i18n['subtotal'] ?? __( 'Subtotal', 'woocommerce-pos' ) ); ?></span>
			<span style="font-variant-numeric: tabular-nums;"><?php echo wp_kses_post( wc_price( $receipt_data['totals']['subtotal'] ?? 0, $currency_args ) ); ?></span>
		</div>
		<div style="border-top: 2px solid #111827; margin: 8px 0;"></div>
		<div style="display: flex; justify-content: space-between; padding: 4px 0; font-size: 18px; font-weight: 700;">
			<span><?php echo esc_html( $i18n['total'] ?? __( 'Total', 'woocommerce-pos' ) ); ?></span>
			<span style="font-variant-numeric: tabular-nums;"><?php echo wp_kses_post( wc_price( $receipt_data['totals']['total_incl'] ?? 0, $currency_args ) ); ?></span>
		</div>
	</div>

	<div style="margin-top: 24px; padding-top: 14px; border-top: 1px solid #e5e7eb; text-align: center; color: #6b7280; font-size: 12px; font-style: italic;"><?php echo esc_html( $i18n['thank_you_purchase'] ?? __( 'Thank you for your purchase!', 'woocommerce-pos' ) ); ?></div>

</body>
</html>`,
};

function getDefaultDoc(postContent: string, engine: EditorConfig['engine']): string {
	return postContent || STARTER_SHELLS[engine];
}

function TemplateInfoBar({ engine, paperWidth }: { engine: EditorConfig['engine']; paperWidth: string | null }) {
	let icon: string;
	let text: string;
	let bgClass: string;

	if (engine === 'thermal') {
		icon = '\uD83D\uDDA8\uFE0F';
		const size = paperWidth === '58mm' ? '58mm' : '80mm';
		text = t('editor.info_thermal', { size });
		bgClass = 'wcpos:bg-blue-50 wcpos:border-blue-200 wcpos:text-blue-800';
	} else if (engine === 'legacy-php') {
		icon = '\uD83D\uDDA5\uFE0F';
		text = t('editor.info_legacy_php');
		bgClass = 'wcpos:bg-amber-50 wcpos:border-amber-200 wcpos:text-amber-800';
	} else {
		icon = '\uD83D\uDDA5\uFE0F';
		text = t('editor.info_browser');
		bgClass = 'wcpos:bg-gray-50 wcpos:border-gray-200 wcpos:text-gray-700';
	}

	return (
		<div className={`wcpos:flex wcpos:items-start wcpos:gap-2 wcpos:px-3 wcpos:py-2 wcpos:rounded wcpos:border wcpos:text-sm wcpos:mb-4 ${bgClass}`}>
			<span className="wcpos:shrink-0">{icon}</span>
			<span>{text}</span>
		</div>
	);
}

interface AppProps {
	config: EditorConfig;
}

export function getEditorLayoutStyle(): CSSProperties {
	return {
		height: 'calc(100vh - 320px)',
		minHeight: 440,
		maxHeight: 720,
	};
}

export function App({ config }: AppProps) {
	const defaultDoc = getDefaultDoc(config.postContent, config.engine);

	const [engine, setEngine] = useState(config.engine);
	const [paperWidth, setPaperWidth] = useState(config.paperWidth ?? '80mm');
	// initialDoc drives CodeMirror reinit (via useEffect deps in use-codemirror).
	// Only update this when you want to reset the editor to new content + language.
	const [initialDoc, setInitialDoc] = useState(defaultDoc);
	const [content, setContent] = useState(defaultDoc);

	// Keep refs so event handlers can read current values without stale closures
	// and without adding them to effect dependency arrays.
	const contentRef = useRef(defaultDoc);
	const engineRef = useRef(config.engine);
	const paperWidthRef = useRef(config.paperWidth ?? '80mm');

	const insertRef = useRef<((text: string) => void) | null>(null);
	const syncContent = useContentSync();
	const preview = usePreviewData(config.sampleData, config.templateId, config.hasPosOrders);

	// Sync initial content to the hidden WP textarea on mount.
	// Use a stable ref so this effect has no deps other than the stable syncContent.
	const defaultDocRef = useRef(defaultDoc);
	useEffect(() => {
		syncContent(defaultDocRef.current);
	}, [syncContent]);

	// Listen for engine changes dispatched by the PHP metabox select
	// (see Single_Template.php — dispatches wcposEngineChange on <select> change).
	useEffect(() => {
		const handler = (e: Event) => {
			const detail = (e as CustomEvent<{ engine: string }>).detail;
			// Guard: ignore unknown engine values that are not in STARTER_SHELLS.
			if (!(detail.engine in STARTER_SHELLS)) return;
			const newEngine = detail.engine as EditorConfig['engine'];

			const currentContent = contentRef.current;
			// Resolve the current starter, taking paper width into account for thermal.
			const currentStarter =
				engineRef.current === 'thermal'
					? getThermalStarterShell(paperWidthRef.current)
					: STARTER_SHELLS[engineRef.current];
			// Resolve the next starter the same way so thermal always respects paperWidthRef.
			const nextStarter =
				newEngine === 'thermal'
					? getThermalStarterShell(paperWidthRef.current)
					: STARTER_SHELLS[newEngine];
			// Only replace with a starter shell if the editor still holds the old
			// starter (or is empty). Preserve real work the user has already typed.
			const isStarterOrEmpty = currentContent === '' || currentContent === currentStarter;
			const nextDoc = isStarterOrEmpty ? nextStarter : currentContent;

			engineRef.current = newEngine;
			setEngine(newEngine);
			setInitialDoc(nextDoc); // triggers CodeMirror reinit with new language + content
			setContent(nextDoc);
			contentRef.current = nextDoc;
			syncContent(nextDoc);
		};

		window.addEventListener('wcposEngineChange', handler);
		return () => window.removeEventListener('wcposEngineChange', handler);
	}, [syncContent]);

	// Listen for paper width changes dispatched by the PHP metabox select
	// (see Single_Template.php — dispatches wcposPaperWidthChange on <select> change).
	useEffect(() => {
		const handler = (e: Event) => {
			// Guard: paper width only applies to the thermal engine.
			if (engineRef.current !== 'thermal') return;

			const detail = (e as CustomEvent<{ paperWidth: string }>).detail;
			const newPaperWidth = detail.paperWidth;

			const currentContent = contentRef.current;
			// If the editor still holds a thermal starter shell (generated by the current
			// version of getThermalStarterShell), replace it with the correct starter for
			// the new paper width so col widths also update.
			// Note: this equality check will silently fail if getThermalStarterShell output
			// ever changes — existing users would fall through to the regex path.
			const isStarterShell = currentContent === getThermalStarterShell(paperWidthRef.current);
			let nextDoc: string;
			if (isStarterShell) {
				nextDoc = getThermalStarterShell(newPaperWidth);
			} else {
				const newChars = PAPER_WIDTH_CHARS[newPaperWidth] ?? 48;
				nextDoc = currentContent.replace(
					/paper-width\s*=\s*(['"])\d+\1/g,
					(_match, quote) => `paper-width=${quote}${newChars}${quote}`,
				);
			}

			paperWidthRef.current = newPaperWidth;
			setPaperWidth(newPaperWidth);
			setInitialDoc(nextDoc); // triggers CodeMirror reinit with new content
			setContent(nextDoc);
			contentRef.current = nextDoc;
			syncContent(nextDoc);
		};

		window.addEventListener('wcposPaperWidthChange', handler);
		return () => window.removeEventListener('wcposPaperWidthChange', handler);
	}, [syncContent]);

	const handleChange = useCallback((newContent: string) => {
		contentRef.current = newContent;
		setContent(newContent);
		syncContent(newContent);
	}, [syncContent]);

	const handleInsertField = useCallback((text: string) => {
		if (insertRef.current) {
			insertRef.current(text);
		}
	}, []);

	const showFieldPicker = engine === 'logicless' || engine === 'thermal';

	const previewToggle = (
		<PreviewToggle
			source={preview.source}
			disabled={!config.hasPosOrders}
			onToggle={preview.selectSource}
		/>
	);

	return (
		<>
			<TemplateInfoBar engine={engine} paperWidth={paperWidth} />
			<div
				className="wcpos:flex wcpos:gap-3 wcpos:mt-4 wcpos:items-stretch"
				style={getEditorLayoutStyle()}
			>
				{showFieldPicker && (
					<FieldPicker
						schema={config.fieldSchema}
						engine={engine}
						onInsertField={handleInsertField}
					/>
				)}

				<CodeEditor
					initialDoc={initialDoc}
					engine={engine}
					onChange={handleChange}
					onInsertRef={insertRef}
				/>
			</div>

			<div className="wcpos:mt-4">
				{engine === 'thermal' ? (
					<ThermalPreview
						content={content}
						sampleData={preview.data}
						loading={preview.loading}
						sourcePicker={previewToggle}
						paperWidth={paperWidth}
					/>
				) : engine === 'logicless' ? (
					<LivePreview
						content={content}
						sampleData={preview.data}
						loading={preview.loading}
						sourcePicker={previewToggle}
					/>
				) : (
					// Legacy-php renders server-side PHP against a real WC_Order, so it
					// has no Sample Data | Order toggle — $order->-based templates would
					// fatal on sample data with no order. It previews the latest POS
					// order; refresh by saving the template (see php_save_notice).
					<PhpPreview previewUrl={config.previewUrl} />
				)}
			</div>
		</>
	);
}
