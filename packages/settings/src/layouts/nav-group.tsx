import type { ReactNode } from 'react';

interface NavGroupProps {
	heading: string;
	children: ReactNode;
}

export function NavGroup({ heading, children }: NavGroupProps) {
	return (
		<div className="wcpos:mb-4">
			<h3 className="wcpos:px-4 wcpos:mb-1 wcpos:text-xs wcpos:font-semibold wcpos:uppercase wcpos:tracking-wider wcpos:text-gray-400">
				{heading}
			</h3>
			<nav>{children}</nav>
		</div>
	);
}
