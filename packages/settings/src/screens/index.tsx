import * as React from 'react';

import { useQueryClient } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';
import { ErrorBoundary } from 'react-error-boundary';

import Access from './access';
import Checkout from './checkout';
import Footer from './footer';
import General from './general';
import Header from './header';
import License from './license';
import Sessions from './sessions';
import Error from '../components/error';
import Notice from '../components/notice';
import Tabs from '../components/tabs';
import useNotices from '../hooks/use-notices';
import { t } from '../translations';

const screens = {
	general: General,
	checkout: Checkout,
	access: Access,
	sessions: Sessions,
	license: License,
};

export type ScreenKeys = keyof typeof screens;

interface Route {
	key: ScreenKeys;
	title: string;
}

interface Props {
	initialScreen: ScreenKeys;
}

const Main = ({ initialScreen }: Props) => {
	const queryClient = useQueryClient();
	const { notice, setNotice } = useNotices();

	const routes: Route[] = [
		{ key: 'general', title: t('common.general') },
		{ key: 'checkout', title: t('common.checkout') },
		{ key: 'access', title: t('common.access') },
		{ key: 'sessions', title: t('sessions.sessions') },
		{ key: 'license', title: t('common.license') },
	];

	const [index, setIndex] = React.useState(
		routes.findIndex((route) => route.key === initialScreen) || 0
	);

	const renderScene = ({ route }: { route: Route }) => {
		const Component = screens[route.key];

		return (
			<ErrorBoundary FallbackComponent={Error}>
				{notice && (
					<div className="wcpos:p-4">
						<Notice status={notice.type} onRemove={() => setNotice(null)}>
							{notice.message}
						</Notice>
					</div>
				)}
				<React.Suspense fallback={<></>}>
					<Component />
				</React.Suspense>
			</ErrorBoundary>
		);
	};

	return (
		<div className="wcpos:container wcpos:mx-auto wcpos:max-w-screen-md wcpos:py-0 wcpos:md:py-4 wcpos:md:pr-4 wcpos:space-y-4">
			<div className="wcpos:bg-white wcpos:rounded-lg">
				<Header />
				<Tabs<(typeof routes)[number]>
					renderScene={renderScene}
					navigationState={{ index, routes }}
					onIndexChange={(idx) => {
						history.pushState(null, '', `#${routes[idx].key}`);
						setIndex(idx);
					}}
					onTabItemHover={(idx, route) => {
						// Skip prefetch for sessions tab as it uses different API endpoints
						if (route.key === 'sessions') {
							return;
						}
						
						queryClient.prefetchQuery({
							queryKey: [route.key],
							queryFn: async () => {
								const response = await apiFetch({
									path: `wcpos/v1/settings/${route.key}?wcpos=1`,
									method: 'GET',
								});

								return response;
							},
						});
					}}
				/>
			</div>
			<div className="wcpos:bg-white wcpos:rounded-lg">
				<Footer />
			</div>
		</div>
	);
};

export default Main;
