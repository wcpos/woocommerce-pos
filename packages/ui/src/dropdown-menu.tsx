import * as React from 'react';
import * as ReactDOM from 'react-dom';

import classNames from 'classnames';

export interface DropdownMenuProps {
	/** Element that opens the menu when clicked. */
	trigger: React.ReactNode;
	/** Menu items (use DropdownMenuItem). */
	children: React.ReactNode;
	/** Horizontal alignment of the menu relative to the trigger. */
	align?: 'start' | 'end';
	/** Pixel gap between trigger and menu. */
	offset?: number;
	/** Optional className applied to the trigger wrapper. */
	className?: string;
	/** Optional aria-label for the menu. */
	label?: string;
}

export interface DropdownMenuItemProps {
	children: React.ReactNode;
	onSelect?: () => void;
	href?: string;
	disabled?: boolean;
	target?: string;
	rel?: string;
}

interface MenuContextValue {
	close: () => void;
}

const MenuContext = React.createContext<MenuContextValue | null>(null);

export function DropdownMenu({
	trigger,
	children,
	align = 'end',
	offset = 4,
	className,
	label,
}: DropdownMenuProps) {
	const triggerRef = React.useRef<HTMLSpanElement>(null);
	const menuRef = React.useRef<HTMLDivElement>(null);
	const [open, setOpen] = React.useState(false);
	const [coords, setCoords] = React.useState({ top: 0, left: 0 });
	const menuId = React.useId();

	const updatePosition = React.useCallback(() => {
		if (!triggerRef.current) return;
		const rect = triggerRef.current.getBoundingClientRect();
		const menuWidth = menuRef.current?.offsetWidth ?? 0;
		const left = align === 'end' ? rect.right - menuWidth : rect.left;
		setCoords({ top: rect.bottom + offset, left });
	}, [align, offset]);

	const close = React.useCallback(() => setOpen(false), []);

	const toggle = React.useCallback(() => {
		setOpen((prev) => !prev);
	}, []);

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
				menuRef.current?.contains(target)
			) {
				return;
			}
			close();
		};

		const handleKeyDown = (event: KeyboardEvent) => {
			if (event.key === 'Escape') {
				close();
				triggerRef.current?.focus();
			}
		};

		document.addEventListener('mousedown', handleClickOutside);
		document.addEventListener('keydown', handleKeyDown);
		window.addEventListener('scroll', updatePosition, true);
		window.addEventListener('resize', updatePosition);

		return () => {
			document.removeEventListener('mousedown', handleClickOutside);
			document.removeEventListener('keydown', handleKeyDown);
			window.removeEventListener('scroll', updatePosition, true);
			window.removeEventListener('resize', updatePosition);
		};
	}, [open, close, updatePosition]);

	const contextValue = React.useMemo(() => ({ close }), [close]);

	return (
		<>
			<span
				ref={triggerRef}
				className={classNames('wcpos:inline-flex', className)}
				onClick={toggle}
				onKeyDown={(event) => {
					if (event.key === 'Enter' || event.key === ' ') {
						event.preventDefault();
						toggle();
					}
				}}
				role="button"
				tabIndex={0}
				aria-haspopup="menu"
				aria-expanded={open}
				aria-controls={open ? menuId : undefined}
			>
				{trigger}
			</span>
			{open &&
				ReactDOM.createPortal(
					<div
						ref={menuRef}
						id={menuId}
						role="menu"
						aria-label={label}
						className="wcpos:fixed wcpos:z-[99999] wcpos:min-w-[180px] wcpos:rounded-md wcpos:border wcpos:border-gray-200 wcpos:bg-white wcpos:py-1 wcpos:shadow-lg"
						style={{ top: coords.top, left: coords.left }}
					>
						<MenuContext.Provider value={contextValue}>{children}</MenuContext.Provider>
					</div>,
					document.body
				)}
		</>
	);
}

export function DropdownMenuItem({
	children,
	onSelect,
	href,
	disabled,
	target,
	rel,
}: DropdownMenuItemProps) {
	const menu = React.useContext(MenuContext);

	const baseClasses =
		'wcpos:flex wcpos:w-full wcpos:items-center wcpos:px-3 wcpos:py-1.5 wcpos:text-sm wcpos:text-left wcpos:text-gray-700 wcpos:no-underline wcpos:bg-transparent wcpos:border-0 wcpos:cursor-pointer wcpos:hover:bg-gray-100 wcpos:focus:bg-gray-100 wcpos:focus:outline-none';
	const disabledClasses = 'wcpos:opacity-50 wcpos:cursor-not-allowed wcpos:hover:bg-transparent';
	const className = classNames(baseClasses, disabled && disabledClasses);

	const handleSelect = (event: React.SyntheticEvent) => {
		if (disabled) {
			event.preventDefault();
			return;
		}
		onSelect?.();
		menu?.close();
	};

	if (href) {
		return (
			<a
				href={disabled ? undefined : href}
				target={target}
				rel={rel}
				role="menuitem"
				className={className}
				aria-disabled={disabled}
				onClick={handleSelect}
			>
				{children}
			</a>
		);
	}

	return (
		<button
			type="button"
			role="menuitem"
			className={className}
			disabled={disabled}
			onClick={handleSelect}
		>
			{children}
		</button>
	);
}

DropdownMenu.Item = DropdownMenuItem;
