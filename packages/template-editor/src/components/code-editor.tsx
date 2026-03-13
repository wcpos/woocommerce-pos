import { useLayoutEffect } from 'react';
import { useCodemirror } from '../hooks/use-codemirror';
import type { EditorConfig } from '../types';

interface CodeEditorProps {
	initialDoc: string;
	engine: EditorConfig['engine'];
	onChange: (content: string) => void;
	onInsertRef?: React.MutableRefObject<((text: string) => void) | null>;
}

export function CodeEditor({ initialDoc, engine, onChange, onInsertRef }: CodeEditorProps) {
	const { containerRef, insertAtCursor } = useCodemirror({
		initialDoc,
		engine,
		onChange,
	});

	useLayoutEffect(() => {
		if (onInsertRef) {
			onInsertRef.current = insertAtCursor;
		}
	}, [onInsertRef, insertAtCursor]);

	return (
		<div
			ref={containerRef}
			className="wcpos:flex-1 wcpos:min-w-0 wcpos:overflow-auto"
			style={{ minHeight: 300, maxHeight: 600 }}
		/>
	);
}
