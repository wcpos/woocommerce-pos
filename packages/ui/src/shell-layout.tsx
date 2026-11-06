import type { ReactNode } from 'react';

export interface ShellLayoutProps {
	/** A `<NavSidebar>`. */
	sidebar: ReactNode;
	/**
	 * Title-bar content, rendered right after the hamburger in a wrapping flex
	 * row. A consumer with more than a heading lays out its own bar in here.
	 */
	title: ReactNode;
	mobileNavOpen: boolean;
	onToggleMobileNav: () => void;
	footer?: ReactNode;
	/**
	 * Accessible label for the hamburger. Consumers should pass a translated
	 * string. Defaults to "Open main menu".
	 */
	menuLabel?: string;
	/** Main content. */
	children: ReactNode;
}

export function ShellLayout({
	sidebar,
	title,
	mobileNavOpen,
	onToggleMobileNav,
	footer,
	menuLabel = 'Open main menu',
	children,
}: ShellLayoutProps) {
	return (
		<div className="wcpos:flex-1 wcpos:flex wcpos:bg-white">
			{sidebar}

			<div className="wcpos:flex-1 wcpos:flex wcpos:flex-col wcpos:min-w-0">
				{/* Title bar */}
				<div className="wcpos:flex wcpos:items-center wcpos:px-6 wcpos:border-b wcpos:border-gray-200 wcpos:shrink-0 wcpos:min-h-12 wcpos:flex-wrap">
					<button
						type="button"
						aria-label={menuLabel}
						aria-expanded={mobileNavOpen}
						onClick={onToggleMobileNav}
						className="wcpos:lg:hidden wcpos:p-2 wcpos:mr-2 wcpos:rounded-md wcpos:text-gray-600 wcpos:hover:bg-gray-100"
					>
						<svg
							className="wcpos:h-5 wcpos:w-5"
							fill="none"
							viewBox="0 0 24 24"
							stroke="currentColor"
						>
							<path
								strokeLinecap="round"
								strokeLinejoin="round"
								strokeWidth={2}
								d="M4 6h16M4 12h16M4 18h16"
							/>
						</svg>
					</button>
					{title}
				</div>

				<main className="wcpos:flex-1 wcpos:px-6 wcpos:py-6">{children}</main>

				{footer}
			</div>
		</div>
	);
}
