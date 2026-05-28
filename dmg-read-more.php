<?php
/**
 * Plugin Name: DMG Read More
 * Description: A Gutenberg block and WP-CLI command for surfacing related content via a styled Read More link.
 * Version:     1.0.0
 * Requires at least: 7.0
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

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::add_command( 'dmg-read-more', 'DMG_Read_More_CLI' );
}
