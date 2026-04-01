import { t } from '../translations';

interface PreviewToggleProps {
	source: 'sample' | 'order';
	loading: boolean;
	disabled: boolean;
	onToggle: (source: 'sample' | 'order') => void;
}

export function PreviewToggle({ source, loading, disabled, onToggle }: PreviewToggleProps) {
	const pillBase = 'wcpos:px-2.5 wcpos:py-1 wcpos:text-xs wcpos:font-medium wcpos:transition-colors';
	const active = 'wcpos:bg-indigo-500 wcpos:text-white';
	const inactive = 'wcpos:text-slate-500 wcpos:cursor-pointer hover:wcpos:text-slate-700';
	const disabledClass = 'wcpos:text-slate-300 wcpos:cursor-not-allowed';

	return (
		<div className="wcpos:flex wcpos:bg-slate-100 wcpos:rounded wcpos:border wcpos:border-slate-200 wcpos:overflow-hidden">
			<button
				type="button"
				className={`${pillBase} ${source === 'sample' ? active : inactive}`}
				onClick={() => onToggle('sample')}
			>
				{t('editor.sample_data')}
			</button>
			<button
				type="button"
				className={`${pillBase} ${source === 'order' ? active : disabled ? disabledClass : inactive}`}
				onClick={() => !disabled && onToggle('order')}
				disabled={disabled}
				title={disabled ? t('editor.no_pos_orders') : undefined}
			>
				{loading ? t('editor.loading_data') : t('editor.order')}
			</button>
		</div>
	);
}
