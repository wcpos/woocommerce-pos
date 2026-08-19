import jsdomPackage from 'jsdom/package.json';

import rootPackage from '../../../package.json';
import analyticsBabelConfig from '../../analytics/babel.config.js';
import i18nBabelConfig from '../../i18n/babel.config.js';

type PackageManifest = {
	engines?: {
		node?: string;
	};
};

type BabelConfigFactory = (api: { cache: (enabled: boolean) => void }) => {
	plugins: unknown[];
};

describe('development toolchain configuration', () => {
	it('declares the Node versions supported by jsdom', () => {
		const rootManifest = rootPackage as PackageManifest;
		const jsdomManifest = jsdomPackage as PackageManifest;

		expect(rootManifest.engines?.node).toBe(jsdomManifest.engines?.node);
	});

	it.each([
		['analytics', analyticsBabelConfig],
		['i18n', i18nBabelConfig],
	])('configures the Babel runtime plugin without obsolete options in %s', (_name, config) => {
		const babelConfig = (config as BabelConfigFactory)({ cache: () => undefined });

		expect(babelConfig.plugins).toContain('@babel/plugin-transform-runtime');
	});
});
