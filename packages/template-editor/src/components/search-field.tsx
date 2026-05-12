import { TextInput } from '@wcpos/ui';
import { t } from '../translations';

interface SearchFieldProps {
	value: string;
	onChange: (value: string) => void;
}

function SearchIcon() {
	return (
		<svg
			width="14"
			height="14"
			viewBox="0 0 14 14"
			fill="none"
			aria-hidden="true"
			className="wcpos:text-gray-400"
		>
			<circle cx="6" cy="6" r="4" stroke="currentColor" strokeWidth="1.4" />
			<path d="M9 9 L12.5 12.5" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" />
		</svg>
	);
}

function ClearIcon() {
	return (
		<svg width="12" height="12" viewBox="0 0 12 12" fill="none" aria-hidden="true">
			<path d="M3 3 L9 9 M9 3 L3 9" stroke="currentColor" strokeWidth="1.4" strokeLinecap="round" />
		</svg>
	);
}

export function SearchField({ value, onChange }: SearchFieldProps) {
	return (
		<div className="wcpos:relative">
			<span className="wcpos:absolute wcpos:left-2.5 wcpos:top-1/2 wcpos:-translate-y-1/2 wcpos:pointer-events-none">
				<SearchIcon />
			</span>
			<TextInput
				value={value}
				onChange={(e) => onChange(e.target.value)}
				placeholder={t('editor.search_fields_placeholder')}
				aria-label={t('editor.search_fields_label')}
				className="wcpos:pl-8! wcpos:pr-8!"
			/>
			{value && (
				<button
					type="button"
					onClick={() => onChange('')}
					aria-label={t('editor.clear_search')}
					className="wcpos:absolute wcpos:right-2 wcpos:top-1/2 wcpos:-translate-y-1/2 wcpos:p-0.5 wcpos:rounded wcpos:bg-transparent wcpos:border-0 wcpos:text-gray-400 hover:wcpos:text-gray-700 hover:wcpos:bg-gray-100 wcpos:cursor-pointer wcpos:inline-flex"
				>
					<ClearIcon />
				</button>
			)}
		</div>
	);
}
