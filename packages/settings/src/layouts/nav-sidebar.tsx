import PosIcon from '../../assets/wcpos-icon.svg';
import { useRegisteredPages } from '../store/use-registry';
import { t } from '../translations';

import { NavGroup } from './nav-group';
import { NavItem } from './nav-item';

interface NavSidebarProps {
	isOpen: boolean;
	onNavItemClick?: () => void;
}

export function NavSidebar({ isOpen, onNavItemClick }: NavSidebarProps) {
	const toolsPages = useRegisteredPages('tools');
	const accountPages = useRegisteredPages('account');

	return (
		<aside
			aria-hidden={!isOpen}
			className={[
				'wcpos:w-56 wcpos:shrink-0 wcpos:border-r wcpos:border-gray-200 wcpos:bg-gray-50 wcpos:flex wcpos:flex-col wcpos:transition-[margin] wcpos:duration-300 wcpos:ease-in-out',
				'wcpos:lg:ml-0',
				isOpen ? 'wcpos:ml-0' : 'wcpos:-ml-56 wcpos:pointer-events-none wcpos:invisible wcpos:lg:visible wcpos:lg:pointer-events-auto',
			].join(' ')}
		>
			{/* Logo + title */}
			<div className="wcpos:flex wcpos:items-center wcpos:gap-3 wcpos:px-4 wcpos:py-3 wcpos:border-b wcpos:border-gray-200">
				<div className="wcpos:w-8">
					<PosIcon />
				</div>
				<span className="wcpos:text-lg wcpos:font-semibold wcpos:text-gray-900">
					{t('common.settings')}
				</span>
			</div>

			{/* Nav groups */}
			<div className="wcpos:flex-1 wcpos:overflow-y-auto wcpos:py-2">
				<NavGroup heading={t('common.settings')}>
					<NavItem to="/general" label={t('common.general')} onClick={onNavItemClick} />
					<NavItem to="/checkout" label={t('common.checkout')} onClick={onNavItemClick} />
					<NavItem to="/access" label={t('common.access')} onClick={onNavItemClick} />
					<NavItem to="/sessions" label={t('sessions.sessions')} onClick={onNavItemClick} />
					<NavItem to="/license" label={t('common.license')} onClick={onNavItemClick} />
				</NavGroup>

				{toolsPages.length > 0 && (
					<NavGroup heading="Tools">
						{toolsPages.map((page) => (
							<NavItem
								key={page.id}
								to={`/${page.id}`}
								label={page.label}
								onClick={onNavItemClick}
							/>
						))}
					</NavGroup>
				)}

				{accountPages.length > 0 && (
					<NavGroup heading="Account">
						{accountPages.map((page) => (
							<NavItem
								key={page.id}
								to={`/${page.id}`}
								label={page.label}
								onClick={onNavItemClick}
							/>
						))}
					</NavGroup>
				)}
			</div>
		</aside>
	);
}
