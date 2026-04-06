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

	thermal: `<receipt paper-width="48">
  <align mode="center">
    <bold><size width="2" height="2">{{store.name}}</size></bold>
    <text>{{store.address_lines.0}}</text>
  </align>
  <line />
  <row>
    <col width="24">Order #{{meta.order_number}}</col>
    <col width="24" align="right">{{meta.created_at_local}}</col>
  </row>
  <line />
  {{#lines}}
  <row>
    <col width="24">{{name}} x{{qty}}</col>
    <col width="24" align="right">{{line_total_incl}}</col>
  </row>
  {{/lines}}
  <line />
  <row>
    <col width="24"><bold>Total</bold></col>
    <col width="24" align="right"><bold>{{totals.total_incl}}</bold></col>
  </row>
  <feed lines="3" />
  <cut />
</receipt>`,

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
	const getDefaultDoc = (eng: EditorConfig['engine']) =>
		config.postContent || STARTER_SHELLS[eng];

	const [engine, setEngine] = useState(config.engine);
	const [initialDoc, setInitialDoc] = useState(() => getDefaultDoc(config.engine));
	const [content, setContent] = useState(() => getDefaultDoc(config.engine));

	const insertRef = useRef<((text: string) => void) | null>(null);
	const syncContent = useContentSync();
	const preview = usePreviewData(config.sampleData, config.templateId, config.hasPosOrders);

	// Sync initial content to the hidden textarea on mount.
	useEffect(() => {
		syncContent(getDefaultDoc(config.engine));
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, []);

	// Listen for engine changes dispatched by the PHP metabox select.
	useEffect(() => {
		const handler = (e: Event) => {
			const newEngine = (e as CustomEvent<{ engine: EditorConfig['engine'] }>).detail.engine;
			setEngine(newEngine);

			// Only replace content if it is still the starter shell (or empty) —
			// don't wipe real work the user has already typed.
			setContent(prev => {
				const isStarterOrEmpty = prev === '' || prev === STARTER_SHELLS[engine];
				const nextDoc = isStarterOrEmpty ? STARTER_SHELLS[newEngine] : prev;
				setInitialDoc(nextDoc); // triggers CodeMirror reinit with new language + content
				syncContent(nextDoc);
				return nextDoc;
			});
		};

		window.addEventListener('wcposEngineChange', handler);
		return () => window.removeEventListener('wcposEngineChange', handler);
	}, [engine, syncContent]);

	const handleChange = useCallback((newContent: string) => {
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
			<TemplateInfoBar engine={engine} paperWidth={config.paperWidth} />
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
