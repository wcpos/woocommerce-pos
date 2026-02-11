import { Link, useMatchRoute } from '@tanstack/react-router';
import classNames from 'classnames';

interface NavItemProps {
	to: string;
	label: string;
	badge?: number;
	onClick?: () => void;
}

export function NavItem({ to, label, badge, onClick }: NavItemProps) {
	const matchRoute = useMatchRoute();
	const isActive = matchRoute({ to });
	return (
		<Link
			to={to}
			onClick={onClick}
			className={classNames(
				'wcpos:flex wcpos:items-center wcpos:justify-between wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:no-underline wcpos:border-l-3 wcpos:transition-colors wcpos:hover:bg-gray-100 wcpos:focus-visible:outline-none wcpos:focus-visible:bg-gray-100',
				isActive
					? 'wcpos:border-wp-admin-theme-color wcpos:bg-wp-admin-theme-color-lightest wcpos:text-gray-900 wcpos:font-semibold'
					: 'wcpos:border-transparent wcpos:text-gray-600 wcpos:hover:text-gray-900 wcpos:hover:bg-gray-50'
			)}
		>
			{label}
			{badge != null && badge > 0 && (
				<span className="wcpos:inline-flex wcpos:items-center wcpos:justify-center wcpos:min-w-5 wcpos:h-5 wcpos:px-1.5 wcpos:rounded-full wcpos:bg-wp-admin-theme-color wcpos:text-white wcpos:text-xs wcpos:font-medium wcpos:leading-none">
					{badge}
				</span>
			)}
		</Link>
	);
}
