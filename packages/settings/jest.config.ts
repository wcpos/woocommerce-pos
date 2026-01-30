import type { Config } from 'jest';

const config: Config = {
	testEnvironment: 'jsdom',
	transform: {
		'^.+\\.(ts|tsx|js|jsx)$': 'babel-jest',
	},
	moduleNameMapper: {
		'\\.svg$': '<rootDir>/src/__mocks__/fileMock.ts',
		'\\.css$': '<rootDir>/src/__mocks__/fileMock.ts',
	},
	setupFilesAfterEnv: ['@testing-library/jest-dom'],
};

export default config;
