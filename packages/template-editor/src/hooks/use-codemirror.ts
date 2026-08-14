import { useEffect, useRef, useCallback } from 'react';

import { closeBrackets, closeBracketsKeymap } from '@codemirror/autocomplete';
import { defaultKeymap, history, historyKeymap } from '@codemirror/commands';
import { html } from '@codemirror/lang-html';
import { php } from '@codemirror/lang-php';
import { bracketMatching, foldGutter, foldKeymap } from '@codemirror/language';
import { searchKeymap, highlightSelectionMatches } from '@codemirror/search';
import { Compartment, EditorState } from '@codemirror/state';
import {
	EditorView,
	keymap,
	lineNumbers,
	highlightActiveLine,
	highlightActiveLineGutter,
} from '@codemirror/view';

import { mustacheOverlay } from '../codemirror/mustache-language';
import { mustacheSectionMatcher } from '../codemirror/mustache-section-matcher';
import { wordpressTheme } from '../codemirror/theme';

export interface CursorInfo {
	line: number;
	col: number;
	lineCount: number;
}

interface UseCodemirrorOptions {
	initialDoc: string;
	engine: 'logicless' | 'legacy-php' | 'thermal';
	wrap: boolean;
	onChange: (content: string) => void;
	onCursorChange?: (info: CursorInfo) => void;
}

function readCursor(state: EditorState): CursorInfo {
	const head = state.selection.main.head;
	const lineInfo = state.doc.lineAt(head);
	return {
		line: lineInfo.number,
		col: head - lineInfo.from + 1,
		lineCount: state.doc.lines,
	};
}

export function useCodemirror({
	initialDoc,
	engine,
	wrap,
	onChange,
	onCursorChange,
}: UseCodemirrorOptions) {
	const containerRef = useRef<HTMLDivElement>(null);
	const viewRef = useRef<EditorView | null>(null);
	const wrapCompartment = useRef(new Compartment());

	// Latest-ref pattern: updated in an effect (not during render) so the
	// CodeMirror listeners always see the newest callbacks.
	const onChangeRef = useRef(onChange);
	const onCursorChangeRef = useRef(onCursorChange);
	useEffect(() => {
		onChangeRef.current = onChange;
		onCursorChangeRef.current = onCursorChange;
	});

	useEffect(() => {
		if (!containerRef.current) return;

		const languageExtension =
			engine === 'legacy-php' ? [php()] : [html(), mustacheOverlay, mustacheSectionMatcher];

		const state = EditorState.create({
			doc: initialDoc,
			extensions: [
				lineNumbers(),
				foldGutter({
					openText: '▾',
					closedText: '▸',
				}),
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
					...foldKeymap,
				]),
				...languageExtension,
				wordpressTheme,
				wrapCompartment.current.of(wrap ? EditorView.lineWrapping : []),
				EditorView.updateListener.of((update) => {
					if (update.docChanged) {
						onChangeRef.current(update.state.doc.toString());
					}
					if (update.docChanged || update.selectionSet) {
						onCursorChangeRef.current?.(readCursor(update.state));
					}
				}),
			],
		});

		const view = new EditorView({
			state,
			parent: containerRef.current,
		});

		viewRef.current = view;
		onCursorChangeRef.current?.(readCursor(state));

		return () => {
			view.destroy();
			viewRef.current = null;
		};
		// initialDoc/engine drive a full reinit (matches previous behavior).
	}, [initialDoc, engine]);

	useEffect(() => {
		const view = viewRef.current;
		if (!view) return;
		view.dispatch({
			effects: wrapCompartment.current.reconfigure(wrap ? EditorView.lineWrapping : []),
		});
	}, [wrap]);

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
