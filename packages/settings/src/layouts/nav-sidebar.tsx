import { NavGroup, NavSidebar } from '@wcpos/ui';

import { RouteNavItem } from './nav-item';
import PosIcon from '../../assets/wcpos-icon.svg';
import { useUpdateExtensionsCount } from '../screens/extensions/use-update-extensions-count';
import { useUnreadLogCounts } from '../screens/logs/use-unread-log-counts';
import { useRegisteredPages } from '../store/use-registry';
import { t } from '../translations';

interface SettingsNavSidebarProps {
	isOpen: boolean;
	onNavItemClick?: () => void;
}

export function SettingsNavSidebar({ isOpen, onNavItemClick }: SettingsNavSidebarProps) {
	const toolsPages = useRegisteredPages('tools');
	const accountPages = useRegisteredPages('account');
	const updateExtensionsCount = useUpdateExtensionsCount();
	const unreadLogCounts = useUnreadLogCounts();

	return (
		<NavSidebar
			isOpen={isOpen}
			brand={
				<>
					<div className="wcpos:w-8">
						<PosIcon />
					</div>
					<span className="wcpos:text-lg wcpos:font-semibold wcpos:text-gray-900">WCPOS</span>
				</>
			}
		>
			<NavGroup heading={t('common.settings')}>
				<RouteNavItem to="/general" label={t('common.general')} onClick={onNavItemClick} />
				<RouteNavItem to="/checkout" label={t('common.checkout')} onClick={onNavItemClick} />
				<RouteNavItem
					to="/cloud-print"
					label={t('common.cloud_print', 'Cloud Print')}
					onClick={onNavItemClick}
				/>
				<RouteNavItem to="/access" label={t('common.access')} onClick={onNavItemClick} />
				<RouteNavItem to="/sessions" label={t('sessions.sessions')} onClick={onNavItemClick} />
				<RouteNavItem
					to="/extensions"
					label={t('common.extensions', 'Extensions')}
					badge={updateExtensionsCount ?? undefined}
					onClick={onNavItemClick}
				/>
			</NavGroup>

			<NavGroup heading={t('common.tools', 'Tools')}>
				<RouteNavItem
					to="/export-closures"
					label={t('export_closures.title', 'Export closures')}
					onClick={onNavItemClick}
				/>
				<RouteNavItem
					to="/logs"
					label={t('common.logs', 'Logs')}
					badge={unreadLogCounts}
					onClick={onNavItemClick}
				/>
				{toolsPages.map((page) => (
					<RouteNavItem
						key={page.id}
						to={`/${page.id}`}
						label={page.label}
						onClick={onNavItemClick}
					/>
				))}
			</NavGroup>

			<NavGroup heading={t('common.account', 'Account')}>
				<RouteNavItem to="/license" label={t('common.license')} onClick={onNavItemClick} />
				{accountPages.map((page) => (
					<RouteNavItem
						key={page.id}
						to={`/${page.id}`}
						label={page.label}
						onClick={onNavItemClick}
					/>
				))}
			</NavGroup>
		</NavSidebar>
	);
}
