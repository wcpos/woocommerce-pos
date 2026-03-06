import { EditorView } from '@codemirror/view';

export const wordpressTheme = EditorView.theme({
	'&': {
		fontSize: '13px',
		fontFamily: 'Menlo, Consolas, Monaco, "Liberation Mono", "Lucida Console", monospace',
		border: '1px solid #ddd',
		borderRadius: '0',
	},
	'.cm-content': {
		padding: '8px 0',
	},
	'.cm-gutters': {
		backgroundColor: '#f6f7f7',
		borderRight: '1px solid #ddd',
		color: '#999',
	},
	'.cm-activeLineGutter': {
		backgroundColor: '#e8f0fe',
	},
	'.cm-activeLine': {
		backgroundColor: '#f0f6fc',
	},
	'.cm-mustache': {
		color: '#b5533c',
		fontWeight: 'bold',
	},
});
