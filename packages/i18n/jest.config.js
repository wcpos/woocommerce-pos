/** @type {import('jest').Config} */
const config = {
	testEnvironment: 'jsdom',
	transform: {
		'^.+\\.(ts|tsx|js|jsx)$': 'babel-jest',
	},
};

module.exports = config;
