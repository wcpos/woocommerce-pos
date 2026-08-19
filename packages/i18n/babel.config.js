module.exports = function (api) {
	api.cache(true);

	const presets = ['@babel/preset-env', '@babel/preset-react', '@babel/preset-typescript'];

	// No transform-runtime: helpers are inlined so prebuilt deps keep their own
	// @babel/runtime v7 helper paths (v8 removed helpers/esm/*).
	const plugins = [];

	return {
		presets,
		plugins,
	};
};
