import { useState, useRef, useCallback } from 'react';
import { CodeEditor } from './components/code-editor';
import { FieldPicker } from './components/field-picker';
import { LivePreview } from './components/live-preview';
import { PhpPreview } from './components/php-preview';
import { useContentSync } from './hooks/use-content-sync';
import type { EditorConfig } from './types';

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

	const isLogicless = config.engine === 'logicless';

	return (
		<div className="wcpos:flex wcpos:gap-0 wcpos:mt-4 wcpos:flex-wrap lg:wcpos:flex-nowrap">
			{isLogicless && (
				<FieldPicker
					schema={config.fieldSchema}
					onInsertField={handleInsertField}
				/>
			)}

			<CodeEditor
				initialDoc={config.postContent}
				engine={config.engine}
				onChange={handleChange}
				onInsertRef={insertRef}
			/>

			{isLogicless ? (
				<LivePreview
					content={content}
					sampleData={config.sampleData}
					previewUrl={config.previewUrl}
				/>
			) : (
				<PhpPreview previewUrl={config.previewUrl} />
			)}
		</div>
	);
}
