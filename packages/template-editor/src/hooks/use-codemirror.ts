import { useEffect, useRef, useCallback } from 'react';
import { EditorState } from '@codemirror/state';
import { EditorView, keymap, lineNumbers, highlightActiveLine, highlightActiveLineGutter } from '@codemirror/view';
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands';
import { bracketMatching } from '@codemirror/language';
import { closeBrackets, closeBracketsKeymap } from '@codemirror/autocomplete';
import { searchKeymap, highlightSelectionMatches } from '@codemirror/search';
import { html } from '@codemirror/lang-html';
import { php } from '@codemirror/lang-php';
import { wordpressTheme } from '../codemirror/theme';
import { mustacheLanguage } from '../codemirror/mustache-language';

interface UseCodemirrorOptions {
	initialDoc: string;
	engine: 'logicless' | 'legacy-php';
	onChange: (content: string) => void;
}

export function useCodemirror({ initialDoc, engine, onChange }: UseCodemirrorOptions) {
	const containerRef = useRef<HTMLDivElement>(null);
	const viewRef = useRef<EditorView | null>(null);
	const onChangeRef = useRef(onChange);
	onChangeRef.current = onChange;

	useEffect(() => {
		if (!containerRef.current) return;

		const languageExtension = engine === 'logicless'
			? [html(), mustacheLanguage]
			: [php()];

		const state = EditorState.create({
			doc: initialDoc,
			extensions: [
				lineNumbers(),
				highlightActiveLine(),
				highlightActiveLineGutter(),
				history(),
				bracketMatching(),
				closeBrackets(),
				highlightSelectionMatches(),
				keymap.of([
					...defaultKeymap,
					...historyKeymap,
					...closeBracketsKeymap,
					...searchKeymap,
				]),
				...languageExtension,
				wordpressTheme,
				EditorView.lineWrapping,
				EditorView.updateListener.of((update) => {
					if (update.docChanged) {
						onChangeRef.current(update.state.doc.toString());
					}
				}),
			],
		});

		const view = new EditorView({
			state,
			parent: containerRef.current,
		});

		viewRef.current = view;

		return () => {
			view.destroy();
			viewRef.current = null;
		};
	}, [initialDoc, engine]);

	const insertAtCursor = useCallback((text: string) => {
		const view = viewRef.current;
		if (!view) return;

		const { from, to } = view.state.selection.main;
		view.dispatch({
			changes: { from, to, insert: text },
			selection: { anchor: from + text.length },
		});
		view.focus();
	}, []);

	return { containerRef, viewRef, insertAtCursor };
}
