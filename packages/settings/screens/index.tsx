import * as React from 'react';

import { ErrorBoundary } from 'react-error-boundary';

import Error from '../components/error';
import Tabs from '../components/tabs';
import { t } from '../translations';
import Access from './access';
import Checkout from './checkout';
import Footer from './footer';
import General from './general';
import Header from './header';
import License from './license';

const screens = {
	general: General,
	checkout: Checkout,
	access: Access,
	license: License,
};

interface Props {
	initialScreen: keyof typeof screens;
}

const Main = ({ initialScreen }: Props) => {
	const routes = [
		{ key: 'general', title: t('General', { _tags: 'wp-admin-settings ' }) },
		{ key: 'checkout', title: t('Checkout', { _tags: 'wp-admin-settings' }) },
		{ key: 'access', title: t('Access', { _tags: 'wp-admin-settings' }) },
		{ key: 'license', title: t('License', { _tags: 'wp-admin-settings' }) },
	];

	const [index, setIndex] = React.useState(
		routes.findIndex((route) => route.key === initialScreen) || 0
	);

	const renderScene = ({ route }) => {
		const Component = screens[route.key];

		return (
			<ErrorBoundary FallbackComponent={Error}>
				{/* {notice && (
					<Notice status={notice.type} onRemove={() => setNotice(null)}>
						{notice.message}
					</Notice>
				)} */}
				<React.Suspense fallback={<></>}>
					<Component />
				</React.Suspense>
			</ErrorBoundary>
		);
	};

	return (
		<div className="wcpos-container wcpos-mx-auto wcpos-max-w-screen-md wcpos-py-0 md:wcpos-py-4 md:wcpos-pr-4 wcpos-space-y-4">
			<div className="wcpos-bg-white wcpos-rounded-lg">
				<Header />
				<Tabs<typeof routes[number]>
					renderScene={renderScene}
					navigationState={{ index, routes }}
					onIndexChange={setIndex}
				/>
			</div>
			<div className="wcpos-bg-white wcpos-rounded-lg wcpos-py-4">
				<Footer />
			</div>
		</div>
	);
};

export default Main;
