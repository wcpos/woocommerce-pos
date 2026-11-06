import { useLinkProps, useMatchRoute } from '@tanstack/react-router';

import { Chip, NavItem } from '@wcpos/ui';

interface SeverityBadge {
	error?: number;
	warning?: number;
}

interface RouteNavItemProps {
	to: string;
	label: string;
	badge?: number | SeverityBadge;
	onClick?: () => void;
}

export function RouteNavItem({ to, label, badge, onClick }: RouteNavItemProps) {
	const matchRoute = useMatchRoute();
	const linkProps = useLinkProps({ to, onClick });
	const active = !!matchRoute({ to });

	const renderBadge = () => {
		if (badge == null) return null;

		if (typeof badge === 'number') {
			if (badge <= 0) return null;
			return (
				<Chip variant="brand" shape="round" size="xs">
					{badge}
				</Chip>
			);
		}

		const { error = 0, warning = 0 } = badge;
		if (error <= 0 && warning <= 0) return null;

		return (
			<span className="wcpos:inline-flex wcpos:items-center wcpos:gap-1">
				{error > 0 && (
					<Chip variant="critical" shape="round" size="xs">
						{error}
					</Chip>
				)}
				{warning > 0 && (
					<Chip variant="warning" shape="round" size="xs">
						{warning}
					</Chip>
				)}
			</span>
		);
	};

	return (
		<NavItem
			{...linkProps}
			active={active}
			label={label}
			badge={renderBadge()}
			data-testid={`settings-nav-${to.replace(/^\//, '')}`}
		/>
	);
}
