/** @type {import('jest').Config} */
const config = {
	maxWorkers: 2,
	testEnvironment: 'jsdom',
	transform: {
		'^.+\\.(ts|tsx|js|jsx)$': 'babel-jest',
	},
};

module.exports = config;
