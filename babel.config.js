module.exports = function (api) {
  api.cache(true);

	const presets = [
		"@babel/preset-env",
		"@babel/preset-react",
    "@babel/preset-typescript"
	];
	
  const plugins = [
    [
      "@babel/plugin-transform-runtime",
      {
        "regenerator": true
      }
    ],
    [
			"@wordpress/babel-plugin-makepot",
      {
        "output": "languages/woocommerce-pos-js.pot"
      }
		]
  ];

  return {
    presets,
    plugins
  };
}