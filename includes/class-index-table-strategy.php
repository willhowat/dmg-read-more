<?php
/**
 * Index table search strategy for the DMG Read More block.
 *
 * @package dmg-read-more
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Finds published posts containing the dmg/read-more block by querying the dedicated
 * index table (wp_dmg_read_more_index).
 *
 * Uses keyset pagination (post_id > cursor) rather than OFFSET to avoid full-index
 * scans on large tables. The index table must be created via `wp dmg-read-more migrate`
 * and seeded via `wp dmg-read-more backfill` before this strategy can be used.
 */
class DMG_Index_Table_Strategy implements DMG_Block_Search_Strategy {

	/**
	 * Number of rows fetched per query iteration.
	 */
	private const CHUNK = 100;

	/**
	 * {@inheritdoc}
	 *
	 * @param string   $date_after  ISO 8601 date (YYYY-MM-DD), inclusive.
	 * @param string   $date_before ISO 8601 date (YYYY-MM-DD), inclusive.
	 * @param string[] $post_types  Post type slugs to restrict results. Empty means all types.
	 * @return int[]
	 */
	public function search( string $date_after, string $date_before, array $post_types = [] ): array {
		global $wpdb;

		$table   = $wpdb->prefix . 'dmg_read_more_index';
		$last_id = 0;
		$results = [];
		$fetched = 0;

		$where         = "p.post_status = 'publish' AND p.post_date >= %s AND p.post_date < DATE_ADD(%s, INTERVAL 1 DAY) AND i.post_id > %d";
		$static_params = [ $date_after, $date_before ];

		if ( ! empty( $post_types ) ) {
			$where .= ' AND p.post_type IN (' . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ')';
		}

		do {
			$iter_params = array_merge( $static_params, [ $last_id ], $post_types, [ self::CHUNK ] );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT i.post_id
					FROM {$table} i
					INNER JOIN {$wpdb->posts} p ON p.ID = i.post_id
					WHERE {$where}
					ORDER BY i.post_id ASC
					LIMIT %d",
					...$iter_params
				)
			);
			// phpcs:enable

			if ( $wpdb->last_error ) {
				WP_CLI::error( $wpdb->last_error );
			}

			foreach ( $rows as $post_id ) {
				$results[] = (int) $post_id;
			}

			if ( ! empty( $rows ) ) {
				$last_id = (int) end( $rows );
			}

			WP_CLI::debug(
				sprintf( 'Last ID %d — peak memory: %s', $last_id, size_format( memory_get_peak_usage( true ) ) ),
				'dmg-read-more'
			);

			$wpdb->flush();
			\WP_CLI\Utils\wp_clear_object_cache();

			$fetched = count( $rows );
		} while ( self::CHUNK === $fetched );

		return $results;
	}
}
