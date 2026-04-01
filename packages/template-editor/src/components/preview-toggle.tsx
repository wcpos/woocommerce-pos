import type { CSSProperties } from 'react';
import { t } from '../translations';

interface PreviewToggleProps {
	source: 'sample' | 'order';
	disabled: boolean;
	onToggle: (source: 'sample' | 'order') => void;
}

const base = 'wcpos:px-2.5 wcpos:py-1 wcpos:text-xs wcpos:font-medium wcpos:transition-colors';

function pillProps(isActive: boolean, isDisabled: boolean): { className: string; style?: CSSProperties } {
	if (isActive) {
		return {
			className: `${base} wcpos:text-white`,
			style: { backgroundColor: 'var(--wp-admin-theme-color, #007cba)' },
		};
	}
	if (isDisabled) {
		return { className: `${base} wcpos:text-slate-300 wcpos:cursor-not-allowed` };
	}
	return { className: `${base} wcpos:text-slate-500 wcpos:cursor-pointer` };
}

export function PreviewToggle({ source, disabled, onToggle }: PreviewToggleProps) {
	const sampleProps = pillProps(source === 'sample', false);
	const orderProps = pillProps(source === 'order', disabled);

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
				className={sampleProps.className}
				style={sampleProps.style}
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
				className={orderProps.className}
				style={orderProps.style}
				onClick={() => !disabled && onToggle('order')}
				disabled={disabled}
				title={disabled ? t('editor.no_pos_orders') : undefined}
			>
				{t('editor.order')}
			</button>
		</div>
	);
}
