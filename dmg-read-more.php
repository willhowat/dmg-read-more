<?php
/**
 * Plugin Name: DMG Read More
 * Description: A Gutenberg block and WP-CLI command for surfacing related content via a styled Read More link.
 * Version:     1.0.0
 * Requires at least: 6.3
 * Requires PHP: 8.1
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dmg-read-more
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'DMG_READ_MORE_VERSION', '1.0.0' );
define( 'DMG_READ_MORE_PATH', plugin_dir_path( __FILE__ ) );

require_once DMG_READ_MORE_PATH . 'includes/class-cli-command.php';

add_action( 'init', static function () {
	register_block_type( __DIR__ . '/build/block' );
} );

/**
 * Passes the PHP-side post type allowlist to the block editor via an inline script.
 *
 * The filter runs here — at enqueue time — so it has access to the full WordPress
 * context (e.g. capability checks, site settings) before the value is handed to JS.
 */
add_action( 'enqueue_block_editor_assets', static function () {
	/**
	 * Filters the post types available for selection in the DMG Read More block.
	 *
	 * Return an array of post type slugs to restrict the searchable set to a
	 * specific subset. Return an empty array (the default) to allow all post
	 * types that are publicly available via the REST API.
	 *
	 * Example — restrict to posts only:
	 *   add_filter( 'dmg_read_more_post_types', fn() => [ 'post' ] );
	 *
	 * @param string[] $slugs Post type slugs. Default [] (no restriction).
	 * @return string[]
	 */
	$slugs = apply_filters( 'dmg_read_more_post_types', [] );

	// Sanitise: cast to array, keep non-empty strings only.
	$slugs = array_values( array_filter( (array) $slugs, 'is_string' ) );

	wp_add_inline_script(
		'dmg-read-more-editor-script',
		'window.dmgReadMore = ' . wp_json_encode( [ 'allowedPostTypes' => $slugs ] ) . ';',
		'before'
	);
} );

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'dmg-read-more', 'DMG_Read_More_CLI' );
}
