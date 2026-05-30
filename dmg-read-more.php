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
 *
 * @package dmg-read-more
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DMG_READ_MORE_VERSION', '1.0.0' );
define( 'DMG_READ_MORE_PATH', plugin_dir_path( __FILE__ ) );

require_once DMG_READ_MORE_PATH . 'includes/class-cli-command.php';

add_action(
	'init',
	static function () {
		register_block_type( __DIR__ . '/build/block' );
		wp_set_script_translations( 'dmg-read-more-editor-script', 'dmg-read-more', DMG_READ_MORE_PATH . 'languages' );
	}
);

/**
 * Passes the PHP-side post type allowlist to the block editor via an inline script.
 *
 * The filter runs here — at enqueue time — so it has access to the full WordPress
 * context (e.g. capability checks, site settings) before the value is handed to JS.
 */
add_action(
	'enqueue_block_editor_assets',
	static function () {
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
	}
);

/**
 * Restricts the dmg/read-more block to a specific set of post types in the editor inserter.
 *
 * When the filter returns a non-empty array the block is hidden from the inserter on any
 * post type not in that list. When it returns an empty array (the default) the block is
 * available everywhere.
 *
 * Example — allow the block in posts and a custom post type only:
 *   add_filter( 'dmg_read_more_allowed_in_post_types', fn() => [ 'post', 'case_study' ] );
 *
 * Note: if another plugin or theme has already narrowed $allowed_block_types to an array,
 * this filter removes dmg/read-more from that array rather than replacing it entirely.
 */
add_filter(
	'allowed_block_types_all',
	static function ( $allowed_block_types, $editor_context ) {
		/**
		 * Filters the post types in which the dmg/read-more block may be inserted.
		 *
		 * @param string[] $post_types Post type slugs. Default [] (no restriction).
		 * @return string[]
		 */
		$allowed_post_types = apply_filters( 'dmg_read_more_allowed_in_post_types', [] );
		$allowed_post_types = array_values( array_filter( (array) $allowed_post_types, 'is_string' ) );

		if ( empty( $allowed_post_types ) ) {
			return $allowed_block_types;
		}

		$post_type = isset( $editor_context->post ) ? get_post_type( $editor_context->post ) : null;

		if ( ! $post_type || in_array( $post_type, $allowed_post_types, true ) ) {
			return $allowed_block_types;
		}

		// Post type is not in the allowed list — remove dmg/read-more from the inserter.
		if ( true === $allowed_block_types ) {
			$allowed_block_types = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
		}

		return array_values( array_diff( (array) $allowed_block_types, [ 'dmg/read-more' ] ) );
	},
	10,
	2
);

/**
 * Maintains the index table as posts are saved.
 *
 * Adds the post ID when a published post contains the block; removes it otherwise.
 * Silently skips autosaves, revisions, and requests made before `migrate` has run.
 */
add_action(
	'save_post',
	static function ( int $post_id, WP_Post $post ): void {
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'dmg_read_more_index';

		static $table_ready = null;
		if ( null === $table_ready ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table_ready = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
		}
		if ( ! $table_ready ) {
			return;
		}

		if ( 'publish' === $post->post_status && has_block( 'dmg/read-more', $post_id ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->replace( $table, [ 'post_id' => $post_id ], [ '%d' ] );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, [ 'post_id' => $post_id ], [ '%d' ] );
		}
	},
	10,
	2
);

/**
 * Removes a post from the index when it is permanently deleted.
 *
 * Acts as a safety net for programmatic deletions that bypass trash. In the normal
 * flow, trashing a post already removes it via save_post above.
 */
add_action(
	'deleted_post',
	static function ( int $post_id ): void {
		global $wpdb;
		$prev = $wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $wpdb->prefix . 'dmg_read_more_index', [ 'post_id' => $post_id ], [ '%d' ] );
		$wpdb->suppress_errors( $prev );
	}
);

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'dmg-read-more', 'DMG_Read_More_CLI' );
}
