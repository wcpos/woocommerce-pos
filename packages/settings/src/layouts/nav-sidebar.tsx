import PosIcon from '../../assets/wcpos-icon.svg';
import { useRegisteredPages } from '../store/use-registry';
import { t } from '../translations';

import { NavGroup } from './nav-group';
import { NavItem } from './nav-item';

export function NavSidebar() {
	const toolsPages = useRegisteredPages('tools');
	const accountPages = useRegisteredPages('account');

	return (
		<aside className="wcpos:w-56 wcpos:shrink-0 wcpos:border-r wcpos:border-gray-200 wcpos:bg-gray-50 wcpos:flex wcpos:flex-col">
			{/* Logo + title */}
			<div className="wcpos:flex wcpos:items-center wcpos:gap-3 wcpos:px-4 wcpos:py-5">
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
					<NavItem to="/general" label={t('common.general')} />
					<NavItem to="/checkout" label={t('common.checkout')} />
					<NavItem to="/access" label={t('common.access')} />
					<NavItem to="/sessions" label={t('sessions.sessions')} />
					<NavItem to="/license" label={t('common.license')} />
				</NavGroup>

				{toolsPages.length > 0 && (
					<NavGroup heading="Tools">
						{toolsPages.map((page) => (
							<NavItem key={page.id} to={`/${page.id}`} label={page.label} />
						))}
					</NavGroup>
				)}

				{accountPages.length > 0 && (
					<NavGroup heading="Account">
						{accountPages.map((page) => (
							<NavItem key={page.id} to={`/${page.id}`} label={page.label} />
						))}
					</NavGroup>
				)}
			</div>
		</aside>
	);
}
