import { useCallback, useLayoutEffect, useState } from 'react';
import { useCodemirror, type CursorInfo } from '../hooks/use-codemirror';
import type { EditorConfig } from '../types';
import { EditorToolbar } from './editor-toolbar';
import { EditorStatusLine } from './editor-status-line';

interface CodeEditorProps {
	initialDoc: string;
	engine: EditorConfig['engine'];
	onChange: (content: string) => void;
	onInsertRef?: React.MutableRefObject<((text: string) => void) | null>;
}

export function CodeEditor({ initialDoc, engine, onChange, onInsertRef }: CodeEditorProps) {
	const [wrap, setWrap] = useState(true);
	const [cursor, setCursor] = useState<CursorInfo>({ line: 1, col: 1, lineCount: 1 });
	const handleChange = useCallback((content: string) => {
		onChange(content);
	}, [onChange]);

	const { containerRef, viewRef, insertAtCursor } = useCodemirror({
		initialDoc,
		engine,
		wrap,
		onChange: handleChange,
		onCursorChange: setCursor,
	});

	useLayoutEffect(() => {
		if (onInsertRef) {
			onInsertRef.current = insertAtCursor;
		}
	}, [onInsertRef, insertAtCursor]);

	const toggleWrap = useCallback(() => setWrap((current) => !current), []);

	return (
		<div className="wcpos:flex-1 wcpos:min-w-0 wcpos:flex wcpos:flex-col wcpos:border wcpos:border-gray-200 wcpos:rounded-lg wcpos:overflow-hidden wcpos:bg-white">
			<EditorToolbar viewRef={viewRef} wrap={wrap} onToggleWrap={toggleWrap} />
			<div
				ref={containerRef}
				className="wcpos:flex-1 wcpos:min-w-0 wcpos:overflow-auto"
				style={{ minHeight: 360, maxHeight: 'calc(100vh - 380px)' }}
			/>
			<EditorStatusLine
				engine={engine}
				line={cursor.line}
				col={cursor.col}
				lineCount={cursor.lineCount}
			/>
		</div>
	);
}
