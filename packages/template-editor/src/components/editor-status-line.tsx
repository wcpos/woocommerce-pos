import { t } from '../translations';
import type { EditorConfig } from '../types';

interface EditorStatusLineProps {
	engine: EditorConfig['engine'];
	line: number;
	col: number;
	lineCount: number;
}

function getEngineLabel(engine: EditorConfig['engine']): string {
	if (engine === 'thermal') return t('editor.status.engine_thermal');
	if (engine === 'legacy-php') return t('editor.status.engine_php');
	return t('editor.status.engine_html');
}

export function EditorStatusLine({ engine, line, col, lineCount }: EditorStatusLineProps) {
	return (
		<div
			className="wcpos:flex wcpos:items-center wcpos:gap-3 wcpos:px-3 wcpos:py-1.5 wcpos:border-t wcpos:border-gray-200 wcpos:bg-gray-50 wcpos:text-xs wcpos:text-gray-600 wcpos:font-mono wcpos:tabular-nums"
		>
			<span>{getEngineLabel(engine)}</span>
			<span className="wcpos:text-gray-300" aria-hidden="true">·</span>
			<span>{t('editor.status.line_col', { line, col })}</span>
			<span className="wcpos:text-gray-300" aria-hidden="true">·</span>
			<span>{t('editor.status.lines', { count: lineCount })}</span>
		</div>
	);
}
