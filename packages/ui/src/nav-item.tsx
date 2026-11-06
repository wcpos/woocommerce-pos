import * as React from 'react';

import classNames from 'classnames';

import { Chip, type ChipVariant } from './chip';

export interface SeverityBadge {
	error?: number;
	warning?: number;
}

export interface TextBadge {
	label: string;
	variant: ChipVariant;
}

export interface NavItemProps {
	href: string;
	label: string;
	active: boolean;
	badge?: number | SeverityBadge | TextBadge;
	onClick?: (e: React.MouseEvent<HTMLAnchorElement>) => void;
	testId?: string;
}

function renderBadge(badge: NavItemProps['badge']) {
	if (badge == null) return null;

	if (typeof badge === 'number') {
		if (badge <= 0) return null;
		return (
			<Chip variant="neutral" shape="round" size="xs">
				{badge}
			</Chip>
		);
	}

	if ('label' in badge) {
		return (
			<Chip variant={badge.variant} size="xs">
				{badge.label}
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
}

export function NavItem({ href, label, active, badge, onClick, testId }: NavItemProps) {
	return (
		<a
			href={href}
			onClick={onClick}
			aria-current={active ? 'page' : undefined}
			data-testid={testId}
			className={classNames(
				'wcpos:flex wcpos:items-center wcpos:justify-between wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:no-underline wcpos:border-l-3 wcpos:transition-colors wcpos:hover:bg-gray-100 wcpos:focus-visible:outline-none wcpos:focus-visible:bg-gray-100',
				active
					? 'wcpos:border-wp-admin-theme-color wcpos:bg-wp-admin-theme-color-lightest wcpos:text-gray-900 wcpos:font-semibold'
					: 'wcpos:border-transparent wcpos:text-gray-600 wcpos:hover:text-gray-900 wcpos:hover:bg-gray-50'
			)}
		>
			{label}
			{renderBadge(badge)}
		</a>
	);
}
