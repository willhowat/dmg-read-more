const defaultConfig = require( '@wordpress/scripts/config/webpack.config' );

/**
 * Extends the default wp-scripts webpack config to add CSS-only entry points
 * for editorStyle and viewStyle. wp-scripts auto-discovery only registers
 * *Script fields as webpack entries, so CSS assets need explicit entries here.
 *
 * The CSS entries produce build/block/editor.css and build/block/view.css,
 * which are referenced by editorStyle and viewStyle in block.json. Webpack
 * also emits empty .js sidecars for CSS-only entries; these are not
 * registered in block.json and are safe to ignore.
 */
module.exports = {
	...defaultConfig,
	entry: () => ( {
		...defaultConfig.entry(),
		'block/editor': './src/block/editor.css',
		'block/view': './src/block/view.css',
	} ),
};
