import { t } from '../translations';
import type { EditorConfig } from '../types';

interface EditorStatusLineProps {
	engine: EditorConfig['engine'];
	line: number;
	col: number;
	lineCount: number;
	saved: boolean;
}

function getEngineLabel(engine: EditorConfig['engine']): string {
	if (engine === 'thermal') return t('editor.status.engine_thermal');
	if (engine === 'legacy-php') return t('editor.status.engine_php');
	return t('editor.status.engine_html');
}

export function EditorStatusLine({ engine, line, col, lineCount, saved }: EditorStatusLineProps) {
	return (
		<div
			role="status"
			aria-live="polite"
			className="wcpos:flex wcpos:items-center wcpos:gap-3 wcpos:px-3 wcpos:py-1.5 wcpos:border-t wcpos:border-gray-200 wcpos:bg-gray-50 wcpos:text-xs wcpos:text-gray-600 wcpos:font-mono wcpos:tabular-nums"
		>
			<span>{getEngineLabel(engine)}</span>
			<span className="wcpos:text-gray-300" aria-hidden="true">·</span>
			<span>{t('editor.status.line_col', { line, col })}</span>
			<span className="wcpos:text-gray-300" aria-hidden="true">·</span>
			<span>{t('editor.status.lines', { count: lineCount })}</span>
			<span className="wcpos:ml-auto wcpos:flex wcpos:items-center wcpos:gap-1.5">
				<span
					className={saved ? 'wcpos:w-1.5 wcpos:h-1.5 wcpos:rounded-full wcpos:bg-green-500' : 'wcpos:w-1.5 wcpos:h-1.5 wcpos:rounded-full wcpos:bg-amber-500'}
					aria-hidden="true"
				/>
				<span className={saved ? 'wcpos:text-green-700' : 'wcpos:text-amber-700'}>
					{saved ? t('editor.status.saved') : t('editor.status.unsaved')}
				</span>
			</span>
		</div>
	);
}
