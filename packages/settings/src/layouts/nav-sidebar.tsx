import { NavSidebar as UINavSidebar } from '@wcpos/ui';

import { NavGroup } from './nav-group';
import { NavItem } from './nav-item';
import PosIcon from '../../assets/wcpos-icon.svg';
import { useUpdateExtensionsCount } from '../screens/extensions/use-update-extensions-count';
import { useUnreadLogCounts } from '../screens/logs/use-unread-log-counts';
import { useRegisteredPages } from '../store/use-registry';
import { t } from '../translations';

interface NavSidebarProps {
	isOpen: boolean;
	onNavItemClick?: () => void;
}

export function NavSidebar({ isOpen, onNavItemClick }: NavSidebarProps) {
	const toolsPages = useRegisteredPages('tools');
	const accountPages = useRegisteredPages('account');
	const updateExtensionsCount = useUpdateExtensionsCount();
	const unreadLogCounts = useUnreadLogCounts();

	return (
		<UINavSidebar
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
				<NavItem to="/general" label={t('common.general')} onClick={onNavItemClick} />
				<NavItem to="/checkout" label={t('common.checkout')} onClick={onNavItemClick} />
				<NavItem
					to="/cloud-print"
					label={t('common.cloud_print', 'Cloud Print')}
					onClick={onNavItemClick}
				/>
				<NavItem to="/access" label={t('common.access')} onClick={onNavItemClick} />
				<NavItem to="/sessions" label={t('sessions.sessions')} onClick={onNavItemClick} />
				<NavItem
					to="/extensions"
					label={t('common.extensions', 'Extensions')}
					badge={updateExtensionsCount ?? undefined}
					onClick={onNavItemClick}
				/>
			</NavGroup>

			<NavGroup heading={t('common.tools', 'Tools')}>
				<NavItem
					to="/export-closures"
					label={t('export_closures.title', 'Export closures')}
					onClick={onNavItemClick}
				/>
				<NavItem
					to="/logs"
					label={t('common.logs', 'Logs')}
					badge={unreadLogCounts}
					onClick={onNavItemClick}
				/>
				{toolsPages.map((page) => (
					<NavItem key={page.id} to={`/${page.id}`} label={page.label} onClick={onNavItemClick} />
				))}
			</NavGroup>

			<NavGroup heading={t('common.account', 'Account')}>
				<NavItem to="/license" label={t('common.license')} onClick={onNavItemClick} />
				{accountPages.map((page) => (
					<NavItem key={page.id} to={`/${page.id}`} label={page.label} onClick={onNavItemClick} />
				))}
			</NavGroup>
		</UINavSidebar>
	);
}
