import * as React from 'react';

import classNames from 'classnames';

export interface NavItemProps extends Omit<
	React.AnchorHTMLAttributes<HTMLAnchorElement>,
	'children'
> {
	label: string;
	active: boolean;
	/** Rendered after the label, e.g. a `<Chip>`. The caller owns hide-on-zero. */
	badge?: React.ReactNode;
}

/** Forwards its ref to the `<a>`, so router link props (which carry a ref) spread straight in. */
export const NavItem = React.forwardRef<HTMLAnchorElement, NavItemProps>(function NavItem(
	{ label, active, badge, ...rest },
	ref
) {
	return (
		<a
			{...rest}
			ref={ref}
			aria-current={active ? 'page' : undefined}
			className={classNames(
				'wcpos:flex wcpos:items-center wcpos:justify-between wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:no-underline wcpos:border-l-3 wcpos:transition-colors wcpos:hover:bg-gray-100 wcpos:focus-visible:outline-none wcpos:focus-visible:bg-gray-100',
				active
					? 'wcpos:border-wp-admin-theme-color wcpos:bg-wp-admin-theme-color-lightest wcpos:text-gray-900 wcpos:font-semibold'
					: 'wcpos:border-transparent wcpos:text-gray-600 wcpos:hover:text-gray-900 wcpos:hover:bg-gray-50',
				rest.className
			)}
		>
			{label}
			{badge}
		</a>
	);
});
