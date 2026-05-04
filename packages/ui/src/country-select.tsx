import * as React from 'react';
import * as ReactDOM from 'react-dom';

import classNames from 'classnames';

export interface CountrySelectProps {
	/** Map of country code → display name (e.g. WC()->countries->get_countries()). */
	countries: Record<string, string>;
	/** Selected country code (controlled). Empty string means none selected. */
	value: string;
	/** Called with the selected country code, or '' when cleared. */
	onChange: (value: string) => void;
	/** Visible placeholder when nothing is selected. */
	placeholder?: string;
	/** Placeholder for the filter input inside the popover. */
	searchPlaceholder?: string;
	/** Text shown when filter matches no countries. */
	noResultsLabel?: string;
	/** Optional accessible label when there is no visible <label>. */
	'aria-label'?: string;
	/** Allow clearing the selection from inside the popover. */
	clearable?: boolean;
	/** Label for the clear option. Only used when `clearable`. */
	clearLabel?: string;
	disabled?: boolean;
	className?: string;
	/** Optional id for the trigger element (for label association). */
	id?: string;
}

const TRIGGER_CLASSES =
	'wcpos:flex wcpos:w-full wcpos:items-center wcpos:justify-between wcpos:gap-2 wcpos:rounded-md wcpos:border wcpos:border-gray-300 wcpos:bg-white wcpos:px-2.5 wcpos:py-1.5 wcpos:text-left wcpos:text-sm wcpos:shadow-xs wcpos:focus:outline-none wcpos:focus:ring-2 wcpos:focus:ring-wp-admin-theme-color wcpos:focus:border-wp-admin-theme-color';

const TRIGGER_DISABLED_CLASSES =
	'wcpos:bg-gray-50 wcpos:text-gray-500 wcpos:cursor-not-allowed';

function normalize(text: string): string {
	return text.toLowerCase();
}

interface ComboboxOption {
	value: string;
	label: string;
	isClear?: boolean;
}

export function CountrySelect({
	countries,
	value,
	onChange,
	placeholder,
	searchPlaceholder,
	noResultsLabel,
	clearable = false,
	clearLabel,
	disabled,
	className,
	id,
	'aria-label': ariaLabel,
}: CountrySelectProps) {
	const triggerRef = React.useRef<HTMLButtonElement>(null);
	const popoverRef = React.useRef<HTMLDivElement>(null);
	const filterRef = React.useRef<HTMLInputElement>(null);
	const listRef = React.useRef<HTMLUListElement>(null);

	const [open, setOpen] = React.useState(false);
	const [filter, setFilter] = React.useState('');
	const [activeIndex, setActiveIndex] = React.useState(0);
	const [coords, setCoords] = React.useState({ top: 0, left: 0, width: 0 });

	const listboxId = React.useId();

	const allOptions = React.useMemo<ComboboxOption[]>(
		() =>
			Object.entries(countries)
				.map(([code, name]) => ({ value: code, label: name }))
				.sort((a, b) => a.label.localeCompare(b.label)),
		[countries]
	);

	// Country matches that satisfy the current filter — without the clear entry.
	// We track this separately so the no-results message reflects countries only.
	const filteredCountries = React.useMemo(() => {
		const q = normalize(filter.trim());
		if (!q) return allOptions;
		return allOptions.filter(
			(opt) =>
				normalize(opt.label).includes(q) || normalize(opt.value).includes(q)
		);
	}, [allOptions, filter]);

	// The full navigable list. The clear entry sits at the top so arrow-key
	// navigation can reach it like any other option (CodeRabbit #2).
	const filteredOptions = React.useMemo<ComboboxOption[]>(() => {
		if (!clearable) return filteredCountries;
		return [{ value: '', label: clearLabel ?? '—', isClear: true }, ...filteredCountries];
	}, [clearable, clearLabel, filteredCountries]);

	const selectedLabel = value ? countries[value] : '';

	const updatePosition = React.useCallback(() => {
		if (!triggerRef.current) return;
		const rect = triggerRef.current.getBoundingClientRect();
		setCoords({ top: rect.bottom + 4, left: rect.left, width: rect.width });
	}, []);

	const close = React.useCallback(() => {
		setOpen(false);
		setFilter('');
	}, []);

	const openPopover = React.useCallback(() => {
		if (disabled) return;
		setOpen(true);
		// Default the active option to the currently selected value when present;
		// otherwise land on the first country (skipping the clear entry, if any).
		const initialIndex = value
			? Math.max(
					0,
					filteredOptions.findIndex((opt) => !opt.isClear && opt.value === value)
				)
			: clearable
				? 1
				: 0;
		setActiveIndex(Math.min(initialIndex, Math.max(0, filteredOptions.length - 1)));
	}, [clearable, disabled, filteredOptions, value]);

	const commit = React.useCallback(
		(next: string) => {
			onChange(next);
			close();
			triggerRef.current?.focus();
		},
		[close, onChange]
	);

	React.useLayoutEffect(() => {
		if (!open) return;
		updatePosition();
		// Move focus into the filter once the popover renders.
		requestAnimationFrame(() => filterRef.current?.focus());
	}, [open, updatePosition]);

	React.useEffect(() => {
		if (!open) return;

		const handleClickOutside = (event: MouseEvent) => {
			const target = event.target as Node;
			if (
				triggerRef.current?.contains(target) ||
				popoverRef.current?.contains(target)
			) {
				return;
			}
			close();
		};

		document.addEventListener('mousedown', handleClickOutside);
		window.addEventListener('scroll', updatePosition, true);
		window.addEventListener('resize', updatePosition);

		return () => {
			document.removeEventListener('mousedown', handleClickOutside);
			window.removeEventListener('scroll', updatePosition, true);
			window.removeEventListener('resize', updatePosition);
		};
	}, [open, close, updatePosition]);

	// Reset/clamp the active index whenever the visible result set changes.
	React.useEffect(() => {
		if (!open) return;
		setActiveIndex((prev) => {
			if (filteredOptions.length === 0) return 0;
			if (prev >= filteredOptions.length) return filteredOptions.length - 1;
			return prev;
		});
	}, [open, filteredOptions]);

	// Scroll the active option into view as the user navigates.
	React.useEffect(() => {
		if (!open || !listRef.current) return;
		const node = listRef.current.querySelector<HTMLLIElement>(
			`[data-index="${activeIndex}"]`
		);
		node?.scrollIntoView?.({ block: 'nearest' });
	}, [open, activeIndex]);

	const handleTriggerKeyDown = (event: React.KeyboardEvent<HTMLButtonElement>) => {
		if (disabled) return;
		if (
			event.key === 'ArrowDown' ||
			event.key === 'ArrowUp' ||
			event.key === 'Enter' ||
			event.key === ' '
		) {
			event.preventDefault();
			openPopover();
		}
	};

	const handleFilterKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
		switch (event.key) {
			case 'ArrowDown':
				event.preventDefault();
				setActiveIndex((prev) =>
					filteredOptions.length === 0 ? 0 : (prev + 1) % filteredOptions.length
				);
				break;
			case 'ArrowUp':
				event.preventDefault();
				setActiveIndex((prev) =>
					filteredOptions.length === 0
						? 0
						: (prev - 1 + filteredOptions.length) % filteredOptions.length
				);
				break;
			case 'Home':
				event.preventDefault();
				setActiveIndex(0);
				break;
			case 'End':
				event.preventDefault();
				setActiveIndex(Math.max(0, filteredOptions.length - 1));
				break;
			case 'Enter':
				event.preventDefault();
				if (filteredOptions[activeIndex]) {
					commit(filteredOptions[activeIndex].value);
				}
				break;
			case 'Escape':
				event.preventDefault();
				close();
				triggerRef.current?.focus();
				break;
			case 'Tab':
				close();
				break;
		}
	};

	const activeId =
		filteredOptions.length > 0
			? `${listboxId}-option-${activeIndex}`
			: undefined;

	return (
		<>
			<button
				ref={triggerRef}
				id={id}
				type="button"
				role="combobox"
				aria-haspopup="listbox"
				aria-expanded={open}
				aria-controls={open ? listboxId : undefined}
				aria-label={ariaLabel}
				disabled={disabled}
				onClick={() => (open ? close() : openPopover())}
				onKeyDown={handleTriggerKeyDown}
				className={classNames(
					TRIGGER_CLASSES,
					disabled && TRIGGER_DISABLED_CLASSES,
					className
				)}
			>
				<span
					className={classNames(
						'wcpos:truncate',
						!selectedLabel && 'wcpos:text-gray-500'
					)}
				>
					{selectedLabel || placeholder || ''}
				</span>
				<svg
					aria-hidden="true"
					className="wcpos:h-4 wcpos:w-4 wcpos:text-gray-400 wcpos:shrink-0"
					viewBox="0 0 20 20"
					fill="currentColor"
				>
					<path
						fillRule="evenodd"
						d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 011.08 1.04l-4.24 4.38a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"
						clipRule="evenodd"
					/>
				</svg>
			</button>
			{open &&
				ReactDOM.createPortal(
					<div
						ref={popoverRef}
						className="wcpos:fixed wcpos:z-[99999] wcpos:rounded-md wcpos:border wcpos:border-gray-200 wcpos:bg-white wcpos:shadow-lg"
						style={{ top: coords.top, left: coords.left, width: coords.width }}
					>
						<div className="wcpos:p-2 wcpos:border-b wcpos:border-gray-100">
							<input
								ref={filterRef}
								type="text"
								role="searchbox"
								aria-autocomplete="list"
								aria-controls={listboxId}
								aria-activedescendant={activeId}
								value={filter}
								onChange={(event) => setFilter(event.target.value)}
								onKeyDown={handleFilterKeyDown}
								placeholder={searchPlaceholder}
								className="wcpos:block wcpos:w-full wcpos:rounded wcpos:border wcpos:border-gray-300 wcpos:bg-white wcpos:px-2 wcpos:py-1 wcpos:text-sm wcpos:focus:outline-none wcpos:focus:ring-2 wcpos:focus:ring-wp-admin-theme-color wcpos:focus:border-wp-admin-theme-color"
							/>
						</div>
						<ul
							ref={listRef}
							id={listboxId}
							role="listbox"
							aria-label={ariaLabel}
							className="wcpos:m-0 wcpos:max-h-60 wcpos:list-none wcpos:overflow-auto wcpos:p-1"
						>
							{filteredOptions.map((option, index) => {
								const isActive = index === activeIndex;
								const isSelected = option.isClear
									? value === ''
									: option.value === value;
								return (
									<li
										key={option.isClear ? '__clear__' : option.value}
										id={`${listboxId}-option-${index}`}
										data-index={index}
										role="option"
										aria-selected={isSelected}
										onMouseDown={(event) => event.preventDefault()}
										onMouseEnter={() => setActiveIndex(index)}
										onClick={() => commit(option.value)}
										className={classNames(
											'wcpos:flex wcpos:cursor-pointer wcpos:items-center wcpos:gap-2 wcpos:rounded wcpos:px-2 wcpos:py-1 wcpos:text-sm',
											option.isClear
												? 'wcpos:text-gray-500'
												: 'wcpos:justify-between wcpos:text-gray-700',
											isActive && 'wcpos:bg-gray-100',
											isSelected && (option.isClear
												? 'wcpos:font-medium wcpos:text-gray-700'
												: 'wcpos:font-medium')
										)}
									>
										{option.isClear ? (
											<span className="wcpos:truncate">{option.label}</span>
										) : (
											<>
												<span className="wcpos:truncate">{option.label}</span>
												<span className="wcpos:text-xs wcpos:text-gray-400">
													{option.value}
												</span>
											</>
										)}
									</li>
								);
							})}
							{filteredCountries.length === 0 && (
								<li
									role="presentation"
									className="wcpos:px-2 wcpos:py-2 wcpos:text-sm wcpos:text-gray-500"
								>
									{noResultsLabel ?? 'No results'}
								</li>
							)}
						</ul>
					</div>,
					document.body
				)}
		</>
	);
}
