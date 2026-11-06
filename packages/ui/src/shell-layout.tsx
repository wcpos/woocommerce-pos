import type { ReactNode } from 'react';

export interface ShellLayoutProps {
	/** A `<NavSidebar>`. */
	sidebar: ReactNode;
	/** Title-bar content left of the spacer (an h1 or a breadcrumb). */
	title: ReactNode;
	/** Right side of the title bar (status, Create button). */
	titleEnd?: ReactNode;
	mobileNavOpen: boolean;
	onToggleMobileNav: () => void;
	footer?: ReactNode;
	/** Main content. */
	children: ReactNode;
}

export function ShellLayout({
	sidebar,
	title,
	titleEnd,
	mobileNavOpen,
	onToggleMobileNav,
	footer,
	children,
}: ShellLayoutProps) {
	return (
		<div className="wcpos:flex-1 wcpos:flex wcpos:bg-white">
			{sidebar}

			<div className="wcpos:flex-1 wcpos:flex wcpos:flex-col wcpos:min-w-0">
				{/* Title bar */}
				<div className="wcpos:flex wcpos:items-center wcpos:px-6 wcpos:border-b wcpos:border-gray-200 wcpos:shrink-0 wcpos:h-12">
					<button
						type="button"
						aria-label="Open main menu"
						aria-expanded={mobileNavOpen}
						onClick={onToggleMobileNav}
						className="wcpos:lg:hidden wcpos:p-2 wcpos:mr-2 wcpos:rounded-md wcpos:text-gray-600 hover:wcpos:bg-gray-100"
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
					{titleEnd && (
						<>
							<div className="wcpos:flex-1" />
							{titleEnd}
						</>
					)}
				</div>

				<main className="wcpos:flex-1 wcpos:px-6 wcpos:py-6">{children}</main>

				{footer}
			</div>
		</div>
	);
}
