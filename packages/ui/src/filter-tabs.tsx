import * as React from 'react';

import classNames from 'classnames';

export interface FilterTabItem {
	key: string;
	label: React.ReactNode;
	disabled?: boolean;
}

export interface FilterTabsProps extends Omit<React.HTMLAttributes<HTMLDivElement>, 'onChange'> {
	items: FilterTabItem[];
	value: string;
	onChange: (value: string) => void;
}

interface IndicatorRect {
	left: number;
	width: number;
}

export function FilterTabs({ items, value, onChange, className, ...props }: FilterTabsProps) {
	const containerRef = React.useRef<HTMLDivElement | null>(null);
	const tabRefs = React.useRef(new Map<string, HTMLButtonElement>());
	const [indicator, setIndicator] = React.useState<IndicatorRect | null>(null);

	const measure = React.useCallback(() => {
		const container = containerRef.current;
		const tab = tabRefs.current.get(value);
		if (!container || !tab) {
			setIndicator(null);
			return;
		}

		const containerRect = container.getBoundingClientRect();
		const tabRect = tab.getBoundingClientRect();

		setIndicator({
			left: tabRect.left - containerRect.left,
			width: tabRect.width,
		});
	}, [value]);

	React.useLayoutEffect(() => {
		measure();
	}, [measure, items]);

	React.useEffect(() => {
		const container = containerRef.current;
		if (!container || typeof ResizeObserver === 'undefined') return;

		const observer = new ResizeObserver(() => measure());
		observer.observe(container);
		return () => observer.disconnect();
	}, [measure]);

	const setTabRef = (key: string) => (node: HTMLButtonElement | null) => {
		if (node) {
			tabRefs.current.set(key, node);
		} else {
			tabRefs.current.delete(key);
		}
	};

	return (
		<div
			ref={containerRef}
			className={classNames(
				'wcpos:relative wcpos:inline-flex wcpos:items-center wcpos:gap-1 wcpos:rounded-full wcpos:bg-gray-100 wcpos:p-1',
				className
			)}
			{...props}
		>
			{indicator && (
				<div
					data-testid="filter-tabs-indicator"
					aria-hidden="true"
					className="wcpos:absolute wcpos:top-1 wcpos:bottom-1 wcpos:rounded-full wcpos:bg-wp-admin-theme-color wcpos:transition-all wcpos:duration-200 wcpos:ease-out"
					style={{ transform: `translateX(${indicator.left - 4}px)`, width: indicator.width }}
				/>
			)}
			{items.map((item) => {
				const isActive = value === item.key;
				return (
					<button
						key={item.key}
						ref={setTabRef(item.key)}
						type="button"
						disabled={item.disabled}
						aria-pressed={isActive}
						onClick={() => onChange(item.key)}
						className={classNames(
							'wcpos:relative wcpos:z-10 wcpos:px-3 wcpos:py-1 wcpos:rounded-full wcpos:text-sm wcpos:font-medium wcpos:transition-colors wcpos:bg-transparent wcpos:border-0',
							item.disabled
								? 'wcpos:cursor-not-allowed wcpos:opacity-50'
								: 'wcpos:cursor-pointer',
							isActive ? 'wcpos:text-white' : 'wcpos:text-gray-600 hover:wcpos:text-gray-900'
						)}
					>
						{item.label}
					</button>
				);
			})}
		</div>
	);
}
