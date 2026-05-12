import { useCallback } from 'react';
import { undo, redo } from '@codemirror/commands';
import { openSearchPanel } from '@codemirror/search';
import { foldAll, unfoldAll } from '@codemirror/language';
import type { EditorView } from '@codemirror/view';
import { Tooltip } from '@wcpos/ui';
import { t } from '../translations';

interface EditorToolbarProps {
	viewRef: React.RefObject<EditorView | null>;
	wrap: boolean;
	onToggleWrap: () => void;
}

interface ToolButtonProps {
	label: string;
	onClick: () => void;
	pressed?: boolean;
	children: React.ReactNode;
}

function ToolButton({ label, onClick, pressed = false, children }: ToolButtonProps) {
	return (
		<Tooltip text={label}>
			<button
				type="button"
				onClick={onClick}
				aria-label={label}
				aria-pressed={pressed}
				className={[
					'wcpos:inline-flex wcpos:items-center wcpos:justify-center',
					'wcpos:w-7 wcpos:h-7 wcpos:rounded wcpos:border-0 wcpos:cursor-pointer',
					'wcpos:transition-colors wcpos:duration-150',
					pressed
						? 'wcpos:bg-blue-100 wcpos:text-wp-admin-theme-color'
						: 'wcpos:bg-transparent wcpos:text-gray-600 hover:wcpos:bg-gray-200 hover:wcpos:text-gray-900',
				].join(' ')}
			>
				{children}
			</button>
		</Tooltip>
	);
}

// Lightweight 14px icons — currentColor.
const Icons = {
	undo: (
		<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
			<path
				d="M5 4 L2 7 L5 10 M2 7 H9 a3 3 0 0 1 0 6 H6"
				stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round"
			/>
		</svg>
	),
	redo: (
		<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
			<path
				d="M9 4 L12 7 L9 10 M12 7 H5 a3 3 0 0 0 0 6 H8"
				stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round"
			/>
		</svg>
	),
	find: (
		<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
			<circle cx="6" cy="6" r="3.8" stroke="currentColor" strokeWidth="1.4" />
			<path d="M9 9 L12.5 12.5" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" />
		</svg>
	),
	wrap: (
		<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
			<path d="M1.5 3 H12.5 M1.5 7 H10.5 a2 2 0 1 1 0 4 H7 L8.5 9.5 M8.5 12.5 L7 11 M1.5 11 H4"
				stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" />
		</svg>
	),
	fold: (
		<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
			<path d="M3 4 L7 8 L11 4 M3 12 L7 8 L11 12" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" />
		</svg>
	),
	unfold: (
		<svg width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true">
			<path d="M3 10 L7 6 L11 10 M3 2 L7 6 M11 2 L7 6" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" strokeLinejoin="round" />
		</svg>
	),
};

export function EditorToolbar({ viewRef, wrap, onToggleWrap }: EditorToolbarProps) {
	const withView = useCallback(
		(fn: (view: EditorView) => void) => () => {
			const view = viewRef.current;
			if (!view) return;
			fn(view);
			view.focus();
		},
		[viewRef]
	);

	return (
		<div className="wcpos:flex wcpos:items-center wcpos:gap-1 wcpos:px-2 wcpos:py-1.5 wcpos:border-b wcpos:border-gray-200 wcpos:bg-gray-50">
			<ToolButton label={t('editor.toolbar.undo')} onClick={withView(undo)}>
				{Icons.undo}
			</ToolButton>
			<ToolButton label={t('editor.toolbar.redo')} onClick={withView(redo)}>
				{Icons.redo}
			</ToolButton>

			<span className="wcpos:w-px wcpos:h-5 wcpos:bg-gray-200 wcpos:mx-1" aria-hidden="true" />

			<ToolButton label={t('editor.toolbar.find')} onClick={withView(openSearchPanel)}>
				{Icons.find}
			</ToolButton>
			<ToolButton label={t('editor.toolbar.wrap')} onClick={onToggleWrap} pressed={wrap}>
				{Icons.wrap}
			</ToolButton>

			<span className="wcpos:w-px wcpos:h-5 wcpos:bg-gray-200 wcpos:mx-1" aria-hidden="true" />

			<ToolButton label={t('editor.toolbar.fold_all')} onClick={withView(foldAll)}>
				{Icons.fold}
			</ToolButton>
			<ToolButton label={t('editor.toolbar.unfold_all')} onClick={withView(unfoldAll)}>
				{Icons.unfold}
			</ToolButton>
		</div>
	);
}
