const path = require("path");
const ForkTsCheckerWebpackPlugin = require('fork-ts-checker-webpack-plugin');
const ESLintPlugin = require('eslint-webpack-plugin');
const TerserPlugin = require('terser-webpack-plugin');

const NODE_ENV = process.env.NODE_ENV || 'development';

module.exports = function(_env, argv) {
	return {
		mode: NODE_ENV,
    devtool: 'inline-source-map',
    entry: "./admin-client/src/index.tsx",
    output: {
      path: path.resolve(__dirname, "admin-client"),
      filename: "js/bundle.js",
      publicPath: "/"
		},
		externals: {
			react: 'React',
			"react-dom": 'ReactDOM',
			wp: 'wp',
			'@wordpress/components': 'wp.components'
		},
		module: {
			rules: [
				{
					test: /\.(ts|js)x?$/i,
					exclude: /node_modules/,
					use: {
						loader: "babel-loader",
						options: {
							presets: [
								"@babel/preset-env",
								"@babel/preset-react",
								"@babel/preset-typescript",
							],
						},
					},
				},
			],
		},
		resolve: {
			extensions: [".tsx", ".ts", ".js"],
		},
		plugins: [
			new ForkTsCheckerWebpackPlugin({
				async: false
			}),
			new ESLintPlugin({
				extensions: ["js", "jsx", "ts", "tsx"],
			}),
		],
		optimization: {
			minimize: NODE_ENV !== 'development',
			minimizer: [ new TerserPlugin() ],
			splitChunks: {
				name: false,
			},
		},
  };
};