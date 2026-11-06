import { useMatchRoute } from '@tanstack/react-router';

import { NavItem as UINavItem, type SeverityBadge } from '@wcpos/ui';

interface NavItemProps {
	to: string;
	label: string;
	badge?: number | SeverityBadge;
	onClick?: () => void;
}

export function NavItem({ to, label, badge, onClick }: NavItemProps) {
	const matchRoute = useMatchRoute();

	return (
		<UINavItem
			href={`#${to}`}
			label={label}
			active={!!matchRoute({ to })}
			badge={badge}
			onClick={onClick}
			testId={`settings-nav-${to.replace(/^\//, '')}`}
		/>
	);
}
