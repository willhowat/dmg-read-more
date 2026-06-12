<?php
/**
 * VIP Search strategy for the DMG Read More block.
 *
 * @package dmg-read-more
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds published posts containing the dmg/read-more block using WP_Query.
 *
 * Intended for WordPress VIP environments where VIP Search (Elasticsearch) handles
 * post_content queries efficiently at scale, making the custom index table redundant.
 */
class DMG_VIP_Search_Strategy implements DMG_Block_Search_Strategy {

	/**
	 * Number of posts fetched per WP_Query page.
	 */
	private const POSTS_PER_PAGE = 100;

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $date_after  ISO 8601 date (YYYY-MM-DD), inclusive.
	 * @param string   $date_before ISO 8601 date (YYYY-MM-DD), inclusive.
	 * @param string[] $post_types  Post type slugs to restrict results. Empty means all types.
	 * @return int[]
	 */
	public function search( string $date_after, string $date_before, array $post_types = [] ): array {
		$results = [];
		$page    = 1;

		do {
			$query = new WP_Query(
				[
					'post_status'    => 'publish',
					'post_type'      => empty( $post_types ) ? 'any' : $post_types,
					's'              => '<!-- wp:dmg/read-more',
					'date_query'     => [
						[
							'after'     => $date_after,
							'before'    => $date_before,
							'inclusive' => true,
						],
					],
					'fields'         => 'ids',
					'posts_per_page' => self::POSTS_PER_PAGE,
					'paged'          => $page,
				]
			);

			$results = array_merge( $results, $query->posts );

			WP_CLI::debug(
				sprintf( 'Page %d — peak memory: %s', $page, size_format( memory_get_peak_usage( true ) ) ),
				'dmg-read-more'
			);

			++$page;
			\WP_CLI\Utils\wp_clear_object_cache();
		} while ( $page <= $query->max_num_pages );

		return array_map( 'intval', $results );
	}
}
