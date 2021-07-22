const path = require("path");
const ForkTsCheckerWebpackPlugin = require('fork-ts-checker-webpack-plugin');
// const ESLintPlugin = require('eslint-webpack-plugin');
const TerserPlugin = require('terser-webpack-plugin');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

const NODE_ENV = process.env.NODE_ENV || 'development';

module.exports = function (_env, argv) {
    return {
        mode: NODE_ENV,
        devtool: 'inline-source-map',
        entry: {
            settings: "./src/settings.tsx",
        },
        output: {
            path: path.resolve(__dirname, "build"),
            filename: "js/[name].js",
            publicPath: "/"
        },
        externals: {
            react: 'React',
            "react-dom": 'ReactDOM',
            wp: 'wp',
            '@wordpress/element': 'wp.element',
            '@wordpress/components': 'wp.components',
            '@wordpress/i18n': 'wp.i18n',
            '@wordpress/api-fetch': 'wp.apiFetch',
            '@wordpress/data': 'wp.data'
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
                {
                    test: /\.s[ac]ss$/i,
                    use: [
                        {
                            loader: MiniCssExtractPlugin.loader
                        },
                        // Creates `style` nodes from JS strings
                        // "style-loader",
                        // Translates CSS into CommonJS
                        "css-loader",
                        // Compiles Sass to CSS
                        "sass-loader",
                    ],
                },
                {
                    test: /\.(png|jpg)$/,
                    loader: 'url-loader'
                }
            ],
        },
        resolve: {
            extensions: [".tsx", ".ts", ".js"],
        },
        plugins: [
            new ForkTsCheckerWebpackPlugin({
                async: false
            }),
            // new ESLintPlugin({
            // 	extensions: ["js", "jsx", "ts", "tsx"],
            // }),
            new MiniCssExtractPlugin({
                filename: './css/[name].css'
            }),
        ],
        optimization: {
            minimize: NODE_ENV !== 'development',
            minimizer: [new TerserPlugin()],
            splitChunks: {
                name: false,
            },
        },
    };
};