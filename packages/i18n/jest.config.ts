import type { Config } from 'jest';

const config: Config = {
	testEnvironment: 'jsdom',
	transform: {
		'^.+\\.(ts|tsx|js|jsx)$': 'babel-jest',
	},
};

export default config;
