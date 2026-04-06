import { useState, useRef, useCallback, useEffect } from 'react';
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

function getThermalStarterShell(paperWidth: string): string {
	const chars = PAPER_WIDTH_CHARS[paperWidth] ?? 48;
	const half = Math.floor(chars / 2);
	return `<receipt paper-width="${chars}">
  <align mode="center">
    <bold><size width="2" height="2">{{store.name}}</size></bold>
    <text>{{store.address_lines.0}}</text>
  </align>
  <line />
  <row>
    <col width="${half}">Order #{{meta.order_number}}</col>
    <col width="${half}" align="right">{{meta.created_at_local}}</col>
  </row>
  <line />
  {{#lines}}
  <row>
    <col width="${half}">{{name}} x{{qty}}</col>
    <col width="${half}" align="right">{{line_total_incl}}</col>
  </row>
  {{/lines}}
  <line />
  <row>
    <col width="${half}"><bold>Total</bold></col>
    <col width="${half}" align="right"><bold>{{totals.total_incl}}</bold></col>
  </row>
  <feed lines="3" />
  <cut />
</receipt>`;
}

const STARTER_SHELLS: Record<EditorConfig['engine'], string> = {
	logicless: `<div style="font-family: Arial, sans-serif; font-size: 13px; color: #333; padding: 24px; max-width: 380px; margin: 0 auto;">

  <h1 style="font-size: 16px; text-align: center; margin: 0 0 4px;">{{store.name}}</h1>
  <p style="text-align: center; margin: 0 0 16px; color: #666; font-size: 11px;">{{store.address_lines.0}}</p>

  <hr style="border: none; border-top: 1px solid #ddd; margin: 0 0 12px;">

  <p style="margin: 0 0 12px; font-size: 11px; color: #666;">
    Order #{{meta.order_number}} &mdash; {{meta.created_at_local}}
  </p>

  <table style="width: 100%; border-collapse: collapse; margin-bottom: 12px;">
    {{#lines}}
    <tr>
      <td style="padding: 3px 0;">{{name}} &times;{{qty}}</td>
      <td style="padding: 3px 0; text-align: right;">{{line_total_incl}}</td>
    </tr>
    {{/lines}}
  </table>

  <hr style="border: none; border-top: 1px solid #ddd; margin: 0 0 12px;">

  <table style="width: 100%;">
    <tr>
      <td><strong>Total</strong></td>
      <td style="text-align: right;"><strong>{{totals.total_incl}}</strong></td>
    </tr>
  </table>

  <p style="text-align: center; margin: 20px 0 0; font-size: 11px; color: #666;">Thank you for your purchase!</p>
</div>`,

	thermal: getThermalStarterShell('80mm'),

	'legacy-php': `<?php
/**
 * Custom Receipt Template
 *
 * @var WC_Order $order The WooCommerce order object.
 */
?>
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>Receipt</title>
</head>
<body style="font-family: Arial, sans-serif; font-size: 13px; padding: 24px; max-width: 380px; margin: 0 auto;">
  <h1><?php echo esc_html( get_bloginfo( 'name' ) ); ?></h1>
  <p>Order #<?php echo esc_html( $order->get_order_number() ); ?></p>
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
	}, [engine, syncContent]);

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
			<div className="wcpos:flex wcpos:gap-0 wcpos:mt-4">
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
					/>
				) : engine === 'logicless' ? (
					<LivePreview
						content={content}
						sampleData={preview.data}
						loading={preview.loading}
						previewUrl={config.previewUrl}
						sourcePicker={previewToggle}
					/>
				) : (
					<PhpPreview previewUrl={config.previewUrl} />
				)}
			</div>
		</>
	);
}
