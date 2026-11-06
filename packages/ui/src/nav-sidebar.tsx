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
	/** Accessible name for the `<aside>`. Consumers should pass a translated string. */
	ariaLabel?: string;
	/**
	 * Accessible label for the close control. Consumers should pass a
	 * translated string. Defaults to "Close menu" if not provided.
	 */
	closeLabel?: string;
	/**
	 * Below `lg`, the open sidebar floats over the page under the WP admin bar,
	 * with a backdrop that calls `onClose`, instead of pushing the content
	 * aside. Both stack above the WP admin menu (z-index 9990) and below the
	 * admin bar (99999). When closed it is `invisible` below `lg` in place of
	 * `inert`, which cannot be scoped to a breakpoint: that keeps its links out
	 * of the tab order. At `lg` and up it is the static sidebar either way.
	 * Defaults to `false`.
	 */
	overlay?: boolean;
}

export function NavSidebar({
	isOpen,
	brand,
	children,
	foot,
	onClose,
	ariaLabel,
	closeLabel = 'Close menu',
	overlay = false,
}: NavSidebarProps) {
	const aside = (
		<aside
			aria-label={ariaLabel}
			className={[
				'wcpos:w-56 wcpos:shrink-0 wcpos:border-r wcpos:border-gray-200 wcpos:bg-gray-50 wcpos:flex wcpos:flex-col',
				// Visibility flips after the slide-out, so a closed overlay leaves the tab order.
				overlay ? 'wcpos:transition-[margin,visibility]' : 'wcpos:transition-[margin]',
				'wcpos:duration-300 wcpos:ease-in-out',
				'wcpos:lg:ml-0',
				isOpen ? 'wcpos:ml-0' : 'wcpos:-ml-56',
				overlay &&
					'wcpos:fixed wcpos:top-[var(--wp-admin--admin-bar--height,0px)] wcpos:bottom-0 wcpos:left-0 wcpos:z-[9992] wcpos:lg:static wcpos:lg:top-auto wcpos:lg:z-auto',
				overlay && !isOpen && 'wcpos:invisible wcpos:lg:visible',
			]
				.filter(Boolean)
				.join(' ')}
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

	if (!overlay) return aside;

	return (
		<>
			{aside}
			{isOpen && onClose && (
				// Pointer-only: the × in the brand row is the accessible close control.
				<div
					aria-hidden="true"
					data-testid="nav-backdrop"
					onClick={onClose}
					className="wcpos:fixed wcpos:inset-0 wcpos:z-[9991] wcpos:bg-black/40 wcpos:lg:hidden"
				/>
			)}
		</>
	);
}
