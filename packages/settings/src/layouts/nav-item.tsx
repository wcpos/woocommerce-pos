import { Link, useMatchRoute } from '@tanstack/react-router';
import classNames from 'classnames';

interface NavItemProps {
	to: string;
	label: string;
	onClick?: () => void;
}

export function NavItem({ to, label, onClick }: NavItemProps) {
	const matchRoute = useMatchRoute();
	const isActive = matchRoute({ to });

	return (
		<Link
			to={to}
			onClick={onClick}
			className={classNames(
				'wcpos:block wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:no-underline wcpos:border-l-3 wcpos:transition-colors wcpos:hover:bg-gray-100 wcpos:focus-visible:outline-none wcpos:focus-visible:bg-gray-100',
				isActive
					? 'wcpos:border-wp-admin-theme-color wcpos:bg-wp-admin-theme-color-lightest wcpos:text-gray-900 wcpos:font-semibold'
					: 'wcpos:border-transparent wcpos:text-gray-600 wcpos:hover:text-gray-900 wcpos:hover:bg-gray-50'
			)}
		>
			{label}
		</Link>
	);
}
