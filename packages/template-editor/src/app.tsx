import { useState, useRef, useCallback, useEffect } from 'react';
import { CodeEditor } from './components/code-editor';
import { FieldPicker } from './components/field-picker';
import { LivePreview } from './components/live-preview';
import { PhpPreview } from './components/php-preview';
import { ThermalPreview } from './components/thermal-preview';
import { PreviewSourcePicker } from './components/preview-source-picker';
import { useContentSync } from './hooks/use-content-sync';
import { usePreviewData } from './hooks/use-preview-data';
import { t } from './translations';
import type { EditorConfig } from './types';

function TemplateInfoBar({ engine, paperWidth }: { engine: string; paperWidth: string | null }) {
	let icon: string;
	let text: string;

	if (engine === 'thermal') {
		icon = '\uD83D\uDDA8\uFE0F'; // printer emoji
		const size = paperWidth === '58mm' ? '58mm' : '80mm';
		text = t('editor.info_thermal', { size });
	} else if (engine === 'legacy-php') {
		icon = '\uD83D\uDDA5\uFE0F'; // monitor emoji
		text = t('editor.info_legacy_php');
	} else {
		icon = '\uD83D\uDDA5\uFE0F'; // monitor emoji
		text = t('editor.info_browser');
	}

	return (
		<div className="wcpos:flex wcpos:items-start wcpos:gap-2 wcpos:rounded-md wcpos:border wcpos:border-l-4 wcpos:px-3 wcpos:py-2.5 wcpos:text-sm wcpos:mb-4 wcpos:bg-blue-50 wcpos:border-blue-200 wcpos:text-blue-800 wcpos:border-l-blue-500">
			<span className="wcpos:shrink-0">{icon}</span>
			<span>{text}</span>
		</div>
	);
}

interface AppProps {
	config: EditorConfig;
}

export function App({ config }: AppProps) {
	const [content, setContent] = useState(config.postContent);
	const insertRef = useRef<((text: string) => void) | null>(null);
	const syncContent = useContentSync();
	const preview = usePreviewData(config.sampleData, config.templateId);

	// Sync raw content to the hidden textarea on mount so the form always
	// submits the correct value — even when the user saves without editing.
	// The PHP-rendered textarea uses esc_textarea() which entity-encodes the
	// content in the HTML source. Browsers decode this, but syncing on mount
	// guarantees the textarea holds the raw value from config.postContent.
	useEffect(() => {
		syncContent(config.postContent);
	}, [syncContent, config.postContent]);

	const handleChange = useCallback((newContent: string) => {
		setContent(newContent);
		syncContent(newContent);
	}, [syncContent]);

	const handleInsertField = useCallback((text: string) => {
		if (insertRef.current) {
			insertRef.current(text);
		}
	}, []);

	const showFieldPicker = config.engine === 'logicless' || config.engine === 'thermal';

	const previewSourcePicker = (
		<PreviewSourcePicker
			source={preview.source}
			orders={preview.orders}
			ordersLoading={preview.ordersLoading}
			dataLoading={preview.dataLoading}
			error={preview.error}
			onSelectSource={preview.selectSource}
			onRequestOrders={preview.fetchOrders}
		/>
	);

	return (
		<>
			<TemplateInfoBar engine={config.engine} paperWidth={config.paperWidth} />
			<div className="wcpos:flex wcpos:gap-0 wcpos:mt-4">
				{showFieldPicker && (
					<FieldPicker
						schema={config.fieldSchema}
						engine={config.engine}
						onInsertField={handleInsertField}
					/>
				)}

				<CodeEditor
					initialDoc={config.postContent}
					engine={config.engine}
					onChange={handleChange}
					onInsertRef={insertRef}
				/>
			</div>

			<div className="wcpos:mt-4">
				{config.engine === 'thermal' ? (
					<ThermalPreview
						content={content}
						sampleData={preview.data}
						sourcePicker={previewSourcePicker}
					/>
				) : config.engine === 'logicless' ? (
					<LivePreview
						content={content}
						sampleData={preview.data}
						previewUrl={config.previewUrl}
						sourcePicker={previewSourcePicker}
					/>
				) : (
					<PhpPreview previewUrl={config.previewUrl} />
				)}
			</div>
		</>
	);
}
