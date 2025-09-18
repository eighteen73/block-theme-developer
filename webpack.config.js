const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

module.exports = {
	...defaultConfig,
	entry: {
		patterns: './src/patterns/index.js',
		templates: './src/templates/index.js',
	},
};
