import * as React from 'react';
import * as ReactDOM from 'react-dom';

import classNames from 'classnames';

export interface ComboboxOption {
	value: string;
	label: string;
}

export interface ComboboxProps {
	/** Currently committed value (controlled). */
	value: string;
	/** Selectable options. */
	options: ComboboxOption[];
	/** Fires when an option is picked or — in editable mode — a custom value is committed. */
	onChange: (value: string) => void;
	/**
	 * If true, free-typed values that don't match an option are accepted on Enter, blur,
	 * or via an explicit "create" entry (when `createLabel` is supplied).
	 */
	allowCustomValue?: boolean;
	/**
	 * When set together with `allowCustomValue`, a synthetic "Create …" option is appended
	 * to the listbox whenever the typed query is non-empty and matches no existing option.
	 */
	createLabel?: (query: string) => string;
	/**
	 * Called whenever the user types in the input. When provided, the component skips its
	 * own client-side filtering and renders `options` as-is — let the parent fetch and
	 * supply the filtered list (e.g. server-side search).
	 */
	onQuery?: (query: string) => void;
	placeholder?: string;
	noResultsLabel?: string;
	loading?: boolean;
	loadingLabel?: string;
	disabled?: boolean;
	className?: string;
	id?: string;
	'aria-label'?: string;
	'aria-labelledby'?: string;
}

const TRIGGER_CLASSES =
	'wcpos:relative wcpos:flex wcpos:w-full wcpos:items-center wcpos:rounded-md wcpos:border wcpos:border-gray-300 wcpos:bg-white wcpos:text-sm wcpos:shadow-xs wcpos:transition-colors wcpos:duration-150 wcpos:focus-within:border-wp-admin-theme-color wcpos:focus-within:ring-2 wcpos:focus-within:ring-wp-admin-theme-color';

const TRIGGER_DISABLED_CLASSES =
	'wcpos:bg-gray-50 wcpos:text-gray-500 wcpos:cursor-not-allowed';

// Inherits font-size/family/color from the trigger so callers can restyle via
// className (e.g. wcpos:font-mono wcpos:text-xs for the tax-id override field).
const INPUT_CLASSES =
	'wcpos:block wcpos:w-full wcpos:flex-1 wcpos:border-0 wcpos:bg-transparent wcpos:px-2.5 wcpos:py-1.5 wcpos:pr-0 wcpos:text-inherit wcpos:focus:outline-none wcpos:focus:ring-0';

const CHEVRON_BUTTON_CLASSES =
	'wcpos:flex wcpos:h-full wcpos:items-center wcpos:px-1.5 wcpos:text-gray-400 wcpos:hover:text-gray-600 wcpos:focus:outline-none wcpos:disabled:cursor-not-allowed wcpos:disabled:hover:text-gray-400';

function normalize(text: string): string {
	return text.toLowerCase();
}

interface ResolvedOption extends ComboboxOption {
	isCreate?: boolean;
}

function visibleTextFor(
	value: string,
	options: ComboboxOption[],
	allowCustomValue: boolean
): string {
	const match = options.find((opt) => opt.value === value);
	if (match) return match.label;
	// In strict mode the value is meaningless without a matching option label —
	// drop it rather than show an opaque ID. Editable callers expect the raw text.
	return allowCustomValue ? value : '';
}

export function Combobox({
	value,
	options,
	onChange,
	allowCustomValue = false,
	createLabel,
	onQuery,
	placeholder,
	noResultsLabel,
	loading = false,
	loadingLabel,
	disabled,
	className,
	id,
	'aria-label': ariaLabel,
	'aria-labelledby': ariaLabelledBy,
}: ComboboxProps) {
	const triggerRef = React.useRef<HTMLDivElement>(null);
	const inputRef = React.useRef<HTMLInputElement>(null);
	const popoverRef = React.useRef<HTMLDivElement>(null);
	const listRef = React.useRef<HTMLUListElement>(null);

	const [open, setOpen] = React.useState(false);
	const [inputValue, setInputValue] = React.useState(() =>
		visibleTextFor(value, options, allowCustomValue)
	);
	// -1 means "nothing highlighted". We only auto-highlight when the user is filtering
	// — opening alone, or clearing the input, must not stage a commit on Enter.
	const [activeIndex, setActiveIndex] = React.useState(-1);
	const [coords, setCoords] = React.useState({ top: 0, left: 0, width: 0 });

	const listboxId = React.useId();
	// When the parent owns search, we render `options` as-is and skip client filtering.
	const remoteFiltered = onQuery !== undefined;

	// Sync the input contents back to the controlled `value` whenever we're not actively
	// editing. This handles external resets (e.g. blur committed an empty override).
	React.useEffect(() => {
		if (open) return;
		setInputValue(visibleTextFor(value, options, allowCustomValue));
	}, [value, options, open, allowCustomValue]);

	const filteredOptions = React.useMemo<ResolvedOption[]>(() => {
		const trimmed = inputValue.trim();
		const q = normalize(trimmed);
		const baseList: ResolvedOption[] =
			!remoteFiltered && open && q
				? options.filter(
						(opt) =>
							normalize(opt.label).includes(q) || normalize(opt.value).includes(q)
					)
				: options.slice();

		if (open && allowCustomValue && createLabel && trimmed) {
			const exact = options.some(
				(opt) => normalize(opt.label) === q || normalize(opt.value) === q
			);
			if (!exact) {
				baseList.push({
					value: trimmed,
					label: createLabel(trimmed),
					isCreate: true,
				});
			}
		}

		return baseList;
	}, [allowCustomValue, createLabel, inputValue, open, options, remoteFiltered]);

	const updatePosition = React.useCallback(() => {
		if (!triggerRef.current) return;
		const rect = triggerRef.current.getBoundingClientRect();
		setCoords({ top: rect.bottom + 4, left: rect.left, width: rect.width });
	}, []);

	const close = React.useCallback(() => {
		setOpen(false);
	}, []);

	const openPopover = React.useCallback(() => {
		if (disabled) return;
		setOpen(true);
		setActiveIndex(-1);
	}, [disabled]);

	const commitOption = React.useCallback(
		(option: ResolvedOption) => {
			const nextLabel = option.isCreate ? option.value : option.label;
			setInputValue(nextLabel);
			close();
			onChange(option.value);
		},
		[close, onChange]
	);

	const commitCurrentInput = React.useCallback(() => {
		const trimmed = inputValue.trim();
		const exactByLabel = options.find(
			(opt) => normalize(opt.label) === normalize(trimmed)
		);
		if (exactByLabel) {
			setInputValue(exactByLabel.label);
			close();
			if (exactByLabel.value !== value) onChange(exactByLabel.value);
			return;
		}

		if (allowCustomValue) {
			setInputValue(trimmed);
			close();
			if (trimmed !== value) onChange(trimmed);
			return;
		}

		// Strict mode: revert to the committed value, drop the typed query.
		setInputValue(visibleTextFor(value, options, allowCustomValue));
		close();
	}, [allowCustomValue, close, inputValue, onChange, options, value]);

	React.useLayoutEffect(() => {
		if (!open) return;
		updatePosition();
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
			commitCurrentInput();
		};

		document.addEventListener('mousedown', handleClickOutside);
		window.addEventListener('scroll', updatePosition, true);
		window.addEventListener('resize', updatePosition);

		return () => {
			document.removeEventListener('mousedown', handleClickOutside);
			window.removeEventListener('scroll', updatePosition, true);
			window.removeEventListener('resize', updatePosition);
		};
	}, [open, commitCurrentInput, updatePosition]);

	React.useEffect(() => {
		if (!open) return;
		setActiveIndex((prev) => {
			if (filteredOptions.length === 0) return -1;
			if (prev >= filteredOptions.length) return filteredOptions.length - 1;
			return prev;
		});
	}, [open, filteredOptions]);

	React.useEffect(() => {
		if (!open || !listRef.current) return;
		const node = listRef.current.querySelector<HTMLLIElement>(
			`[data-index="${activeIndex}"]`
		);
		node?.scrollIntoView?.({ block: 'nearest' });
	}, [open, activeIndex]);

	const handleInputChange = (event: React.ChangeEvent<HTMLInputElement>) => {
		const next = event.target.value;
		setInputValue(next);
		if (!open) setOpen(true);
		// Auto-highlight the first match while the user is actively filtering. Empty input
		// goes back to "no highlight" so Enter commits the typed (empty) value rather than
		// silently picking the first option.
		setActiveIndex(next.trim() === '' ? -1 : 0);
		onQuery?.(next);
	};

	const handleInputFocus = () => {
		if (disabled) return;
		setOpen(true);
		// Select all so a quick second focus replaces rather than appends.
		const node = inputRef.current;
		if (node) requestAnimationFrame(() => node.select());
	};

	const handleKeyDown = (event: React.KeyboardEvent<HTMLInputElement>) => {
		if (disabled) return;
		switch (event.key) {
			case 'ArrowDown':
				event.preventDefault();
				if (!open) {
					openPopover();
					return;
				}
				setActiveIndex((prev) => {
					if (filteredOptions.length === 0) return -1;
					if (prev === -1) return 0;
					return (prev + 1) % filteredOptions.length;
				});
				break;
			case 'ArrowUp':
				event.preventDefault();
				if (!open) {
					openPopover();
					return;
				}
				setActiveIndex((prev) => {
					if (filteredOptions.length === 0) return -1;
					if (prev === -1) return filteredOptions.length - 1;
					return (prev - 1 + filteredOptions.length) % filteredOptions.length;
				});
				break;
			case 'Home':
				if (open && filteredOptions.length > 0) {
					event.preventDefault();
					setActiveIndex(0);
				}
				break;
			case 'End':
				if (open && filteredOptions.length > 0) {
					event.preventDefault();
					setActiveIndex(filteredOptions.length - 1);
				}
				break;
			case 'Enter': {
				if (open && activeIndex >= 0 && filteredOptions[activeIndex]) {
					event.preventDefault();
					commitOption(filteredOptions[activeIndex]);
					return;
				}
				// No highlighted option: in editable mode commit the typed value;
				// in strict mode revert to the committed value. Both routes are safe.
				event.preventDefault();
				commitCurrentInput();
				break;
			}
			case 'Escape':
				if (open) {
					event.preventDefault();
					setInputValue(visibleTextFor(value, options, allowCustomValue));
					close();
				}
				break;
			case 'Tab':
				if (open) commitCurrentInput();
				break;
		}
	};

	const handleChevronMouseDown = (event: React.MouseEvent<HTMLButtonElement>) => {
		event.preventDefault();
		if (disabled) return;
		if (open) {
			close();
		} else {
			openPopover();
			inputRef.current?.focus();
		}
	};

	const activeId =
		open && activeIndex >= 0 && filteredOptions.length > 0
			? `${listboxId}-option-${activeIndex}`
			: undefined;

	return (
		<>
			<div
				ref={triggerRef}
				className={classNames(
					TRIGGER_CLASSES,
					disabled && TRIGGER_DISABLED_CLASSES,
					className
				)}
			>
				<input
					ref={inputRef}
					id={id}
					type="text"
					role="combobox"
					aria-haspopup="listbox"
					aria-expanded={open}
					aria-controls={open ? listboxId : undefined}
					aria-autocomplete="list"
					aria-activedescendant={activeId}
					aria-label={ariaLabel}
					aria-labelledby={ariaLabelledBy}
					autoComplete="off"
					data-1p-ignore
					data-lpignore="true"
					disabled={disabled}
					value={inputValue}
					placeholder={placeholder}
					onChange={handleInputChange}
					onFocus={handleInputFocus}
					onKeyDown={handleKeyDown}
					className={INPUT_CLASSES}
				/>
				<button
					type="button"
					tabIndex={-1}
					aria-hidden="true"
					disabled={disabled}
					onMouseDown={handleChevronMouseDown}
					className={CHEVRON_BUTTON_CLASSES}
				>
					<svg
						className="wcpos:h-4 wcpos:w-4"
						viewBox="0 0 20 20"
						fill="currentColor"
						aria-hidden="true"
					>
						<path
							fillRule="evenodd"
							d="M5.23 7.21a.75.75 0 011.06.02L10 11.06l3.71-3.83a.75.75 0 011.08 1.04l-4.24 4.38a.75.75 0 01-1.08 0L5.21 8.27a.75.75 0 01.02-1.06z"
							clipRule="evenodd"
						/>
					</svg>
				</button>
			</div>
			{open &&
				ReactDOM.createPortal(
					<div
						ref={popoverRef}
						className="wcpos:fixed wcpos:z-[99999] wcpos:rounded-md wcpos:border wcpos:border-gray-200 wcpos:bg-white wcpos:shadow-lg"
						style={{ top: coords.top, left: coords.left, width: coords.width }}
					>
						<ul
							ref={listRef}
							id={listboxId}
							role="listbox"
							aria-label={ariaLabel}
							className="wcpos:m-0 wcpos:max-h-60 wcpos:list-none wcpos:overflow-auto wcpos:p-1"
						>
							{loading ? (
								<li
									role="presentation"
									className="wcpos:px-2 wcpos:py-2 wcpos:text-sm wcpos:text-gray-500"
								>
									{loadingLabel ?? 'Loading…'}
								</li>
							) : filteredOptions.length === 0 ? (
								<li
									role="presentation"
									className="wcpos:px-2 wcpos:py-2 wcpos:text-sm wcpos:text-gray-500"
								>
									{noResultsLabel ?? 'No results'}
								</li>
							) : (
								filteredOptions.map((option, index) => {
									const isActive = index === activeIndex;
									const isSelected = !option.isCreate && option.value === value;
									return (
										<li
											key={option.isCreate ? '__create__' : option.value}
											id={`${listboxId}-option-${index}`}
											data-index={index}
											role="option"
											aria-selected={isSelected}
											onMouseDown={(event) => event.preventDefault()}
											onMouseEnter={() => setActiveIndex(index)}
											onClick={() => commitOption(option)}
											className={classNames(
												'wcpos:flex wcpos:cursor-pointer wcpos:items-center wcpos:rounded wcpos:px-2 wcpos:py-1 wcpos:text-sm',
												option.isCreate
													? 'wcpos:text-wp-admin-theme-color'
													: 'wcpos:text-gray-700',
												isActive && 'wcpos:bg-gray-100',
												isSelected && 'wcpos:font-medium'
											)}
										>
											<span className="wcpos:truncate">{option.label}</span>
										</li>
									);
								})
							)}
						</ul>
					</div>,
					document.body
				)}
		</>
	);
}
