import { useState, useRef, useCallback } from 'react';
import { CodeEditor } from './components/code-editor';
import { FieldPicker } from './components/field-picker';
import { LivePreview } from './components/live-preview';
import { PhpPreview } from './components/php-preview';
import { ThermalPreview } from './components/thermal-preview';
import { useContentSync } from './hooks/use-content-sync';
import type { EditorConfig } from './types';

function TemplateInfoBar({ engine, paperWidth }: { engine: string; paperWidth: string | null }) {
	let icon: string;
	let text: string;
	let bgClass: string;

	if (engine === 'thermal') {
		icon = '\uD83D\uDDA8\uFE0F'; // printer emoji
		const size = paperWidth === '58mm' ? '58mm' : '80mm';
		text = `Receipt Printer template (${size} paper) \u2014 Sends output directly to a thermal printer like Epson or Star. Renders on the device without needing a server connection.`;
		bgClass = 'wcpos:bg-blue-50 wcpos:border-blue-200 wcpos:text-blue-800';
	} else if (engine === 'legacy-php') {
		icon = '\uD83D\uDDA5\uFE0F'; // monitor emoji
		text = 'Browser template (Legacy PHP) \u2014 Prints using your browser\u2019s print dialog. Requires a server connection to generate the receipt.';
		bgClass = 'wcpos:bg-amber-50 wcpos:border-amber-200 wcpos:text-amber-800';
	} else {
		icon = '\uD83D\uDDA5\uFE0F'; // monitor emoji
		text = 'Browser template \u2014 Prints using your browser\u2019s print dialog. Renders on the device without needing a server connection.';
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
	const [content, setContent] = useState(config.postContent);
	const insertRef = useRef<((text: string) => void) | null>(null);
	const syncContent = useContentSync();

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
					<ThermalPreview content={content} sampleData={config.sampleData} />
				) : config.engine === 'logicless' ? (
					<LivePreview
						content={content}
						sampleData={config.sampleData}
						previewUrl={config.previewUrl}
					/>
				) : (
					<PhpPreview previewUrl={config.previewUrl} />
				)}
			</div>
		</>
	);
}
