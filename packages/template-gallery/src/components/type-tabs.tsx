import { useNavigate } from '@tanstack/react-router';
import classnames from 'classnames';

import { t } from '../translations';

const tabs = [
	{ id: 'receipt', labelKey: 'tabs.receipts' },
	{ id: 'report', labelKey: 'tabs.reports' },
	{ id: 'closure', labelKey: 'tabs.closures' },
	{ id: 'display', labelKey: 'tabs.display' },
	{ id: 'email', labelKey: 'tabs.email' },
] as const;

interface TypeTabsProps {
	activeType: string;
}

export function TypeTabs({ activeType }: TypeTabsProps) {
	const navigate = useNavigate();

	return (
		<div className="wcpos:flex wcpos:gap-1 wcpos:border-b wcpos:border-gray-200 wcpos:mb-4">
			{tabs.map((tab) => (
				<button
					key={tab.id}
					type="button"
					aria-current={activeType === tab.id ? 'page' : undefined}
					onClick={() => navigate({ to: '/', search: { type: tab.id } })}
					className={classnames(
						'wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:font-medium wcpos:border-b-2 wcpos:-mb-px wcpos:bg-transparent wcpos:cursor-pointer',
						tab.id === activeType
							? 'wcpos:border-wp-admin-theme-color wcpos:text-wp-admin-theme-color'
							: 'wcpos:border-transparent wcpos:text-gray-500'
					)}
				>
					{t(tab.labelKey)}
				</button>
			))}
		</div>
	);
}
