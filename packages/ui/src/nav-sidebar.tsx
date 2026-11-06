import type { ReactNode } from 'react';

export interface NavSidebarProps {
	/** Drawer state below `lg`; the sidebar is always shown at `lg` and up. */
	isOpen: boolean;
	/** The brand row content (icon + word). The caller owns any SVG import. */
	brand: ReactNode;
	/** The nav groups. */
	children: ReactNode;
	/** Pinned below the scroll area, e.g. a "Move store to Trash" link. */
	foot?: ReactNode;
	/** When given, renders a close control in the brand row below `lg`. */
	onClose?: () => void;
	ariaLabel?: string;
	/**
	 * Accessible label for the close control. Consumers should pass a
	 * translated string. Defaults to "Close menu" if not provided.
	 */
	closeLabel?: string;
}

export function NavSidebar({
	isOpen,
	brand,
	children,
	foot,
	onClose,
	ariaLabel,
	closeLabel = 'Close menu',
}: NavSidebarProps) {
	return (
		<aside
			aria-label={ariaLabel}
			className={[
				'wcpos:w-56 wcpos:shrink-0 wcpos:border-r wcpos:border-gray-200 wcpos:bg-gray-50 wcpos:flex wcpos:flex-col wcpos:transition-[margin] wcpos:duration-300 wcpos:ease-in-out',
				'wcpos:lg:ml-0',
				isOpen ? 'wcpos:ml-0' : 'wcpos:-ml-56',
			].join(' ')}
		>
			{/* Logo + title */}
			<div className="wcpos:flex wcpos:items-center wcpos:gap-3 wcpos:px-4 wcpos:border-b wcpos:border-gray-200 wcpos:h-12">
				{brand}
				{onClose && (
					<button
						type="button"
						aria-label={closeLabel}
						onClick={onClose}
						className="wcpos:lg:hidden wcpos:ml-auto wcpos:p-2 wcpos:rounded-md wcpos:text-lg wcpos:leading-none wcpos:text-gray-600 wcpos:hover:bg-gray-100"
					>
						&times;
					</button>
				)}
			</div>

			{/* Nav groups */}
			<div className="wcpos:flex-1 wcpos:overflow-y-auto wcpos:py-2">{children}</div>

			{foot && (
				<div className="wcpos:border-t wcpos:border-gray-200 wcpos:px-4 wcpos:py-3">{foot}</div>
			)}
		</aside>
	);
}
