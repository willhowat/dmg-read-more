<?php
/**
 * Block search strategy interface.
 *
 * @package dmg-read-more
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Defines the contract for finding published posts containing the dmg/read-more block.
 *
 * Two implementations are provided:
 * - DMG_Index_Table_Strategy: queries the custom index table; for standard WordPress.
 * - DMG_VIP_Search_Strategy: queries via WP_Query with VIP Search; for WordPress VIP.
 *
 * The correct implementation is resolved at runtime in DMG_Read_More_CLI.
 */
interface DMG_Block_Search_Strategy {

	/**
	 * Return all published post IDs containing the dmg/read-more block within the date range.
	 *
	 * @param string   $date_after  ISO 8601 date (YYYY-MM-DD), inclusive.
	 * @param string   $date_before ISO 8601 date (YYYY-MM-DD), inclusive.
	 * @param string[] $post_types  Post type slugs to restrict results. Empty means all types.
	 * @return int[]
	 */
	public function search( string $date_after, string $date_before, array $post_types = [] ): array;
}
