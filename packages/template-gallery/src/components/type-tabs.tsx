import classnames from 'classnames';

import { t } from '../translations';

const tabs = [
	{ id: 'receipt', labelKey: 'tabs.receipts', enabled: true },
	{ id: 'report', labelKey: 'tabs.reports', enabled: false },
	{ id: 'email', labelKey: 'tabs.email', enabled: false },
] as const;

interface TypeTabsProps {
	activeType: string;
}

export function TypeTabs({ activeType }: TypeTabsProps) {
	return (
		<div className="wcpos:flex wcpos:gap-1 wcpos:border-b wcpos:border-gray-200 wcpos:mb-4">
			{tabs.map((tab) => (
				<button
					key={tab.id}
					type="button"
					disabled={!tab.enabled}
					className={classnames(
						'wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:font-medium wcpos:border-b-2 wcpos:-mb-px wcpos:bg-transparent wcpos:cursor-pointer',
						tab.id === activeType
							? 'wcpos:border-wp-admin-theme-color wcpos:text-wp-admin-theme-color'
							: 'wcpos:border-transparent wcpos:text-gray-500',
						!tab.enabled && 'wcpos:opacity-50 wcpos:cursor-not-allowed',
					)}
				>
					{t(tab.labelKey)}
					{!tab.enabled && (
						<span className="wcpos:ml-1 wcpos:text-xs wcpos:bg-gray-100 wcpos:text-gray-500 wcpos:rounded wcpos:px-1">
							{t('tabs.soon')}
						</span>
					)}
				</button>
			))}
		</div>
	);
}
