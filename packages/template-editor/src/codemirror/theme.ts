import { EditorView } from '@codemirror/view';

const MONO_FONT_STACK =
	'ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", "Lucida Console", monospace';

export const wordpressTheme = EditorView.theme({
	'&': {
		fontSize: '13px',
		fontFamily: MONO_FONT_STACK,
		border: '1px solid #e5e7eb',
		borderRadius: '0',
		backgroundColor: '#ffffff',
	},
	'&.cm-focused': {
		outline: 'none',
		borderColor: '#2271b1',
		boxShadow: '0 0 0 1px #2271b1',
	},
	'.cm-scroller': {
		fontFamily: MONO_FONT_STACK,
		lineHeight: '1.6',
	},
	'.cm-content': {
		padding: '10px 0',
		caretColor: '#2271b1',
	},
	'.cm-gutters': {
		backgroundColor: '#f9fafb',
		borderRight: '1px solid #e5e7eb',
		color: '#9ca3af',
		userSelect: 'none',
	},
	'.cm-lineNumbers .cm-gutterElement': {
		padding: '0 8px 0 10px',
		minWidth: '32px',
	},
	'.cm-activeLineGutter': {
		backgroundColor: '#dbeafe',
		color: '#2271b1',
		fontWeight: '500',
	},
	'.cm-activeLine': {
		backgroundColor: '#eff6ff',
	},
	'.cm-selectionBackground, &.cm-focused .cm-selectionBackground, .cm-content ::selection': {
		backgroundColor: '#bfdbfe !important',
	},
	'.cm-cursor': {
		borderLeftColor: '#2271b1',
		borderLeftWidth: '2px',
	},
	'.cm-matchingBracket, &.cm-focused .cm-matchingBracket': {
		backgroundColor: '#fef3c7',
		outline: '1px solid #f59e0b',
	},
	'.cm-mustache': {
		color: '#2271b1',
		fontWeight: '500',
	},
	'.cm-mustache-section-match': {
		backgroundColor: '#dbeafe',
		outline: '1px solid #2271b1',
		borderRadius: '2px',
	},
	'.cm-foldPlaceholder': {
		backgroundColor: '#e5e7eb',
		border: '1px solid #d1d5db',
		color: '#6b7280',
		borderRadius: '3px',
		margin: '0 2px',
		padding: '0 4px',
	},
	'.cm-searchMatch': {
		backgroundColor: '#fef3c7',
		outline: '1px solid #f59e0b',
	},
	'.cm-searchMatch-selected': {
		backgroundColor: '#fcd34d',
	},
});
