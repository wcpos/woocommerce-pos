import { t } from '../translations';

interface PreviewToggleProps {
	source: 'sample' | 'order';
	loading: boolean;
	disabled: boolean;
	onToggle: (source: 'sample' | 'order') => void;
}

function pillClass(isActive: boolean, isDisabled: boolean): string {
	if (isActive) {
		return 'wcpos:px-2.5 wcpos:py-1 wcpos:text-xs wcpos:font-medium wcpos:transition-colors wcpos:bg-indigo-500 wcpos:text-white';
	}
	if (isDisabled) {
		return 'wcpos:px-2.5 wcpos:py-1 wcpos:text-xs wcpos:font-medium wcpos:transition-colors wcpos:text-slate-300 wcpos:cursor-not-allowed';
	}
	return 'wcpos:px-2.5 wcpos:py-1 wcpos:text-xs wcpos:font-medium wcpos:transition-colors wcpos:text-slate-500 wcpos:cursor-pointer';
}

export function PreviewToggle({ source, loading, disabled, onToggle }: PreviewToggleProps) {
	return (
		<div
			className="wcpos:flex wcpos:bg-slate-100 wcpos:rounded wcpos:border wcpos:border-slate-200 wcpos:overflow-hidden"
			role="radiogroup"
			aria-label={t('editor.preview')}
		>
			<button
				type="button"
				role="radio"
				aria-checked={source === 'sample'}
				className={pillClass(source === 'sample', false)}
				onClick={() => onToggle('sample')}
			>
				{t('editor.sample_data')}
			</button>
			<button
				type="button"
				role="radio"
				aria-checked={source === 'order'}
				aria-disabled={disabled || undefined}
				aria-label={disabled ? `${t('editor.order')} — ${t('editor.no_pos_orders')}` : undefined}
				className={pillClass(source === 'order', disabled)}
				onClick={() => !disabled && onToggle('order')}
				disabled={disabled}
				title={disabled ? t('editor.no_pos_orders') : undefined}
			>
				{loading ? t('editor.loading_data') : t('editor.order')}
			</button>
		</div>
	);
}
