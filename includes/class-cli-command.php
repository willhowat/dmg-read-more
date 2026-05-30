<?php
/**
 * WP-CLI command class for DMG Read More.
 *
 * Queries use a dedicated index table (wp_dmg_read_more_index) maintained by the
 * save_post and deleted_post hooks in the main plugin file. This gives O(matching posts)
 * query performance rather than a full post_content table scan, making it suitable for
 * wp_posts tables with tens of millions of rows.
 *
 * Direct $wpdb queries are used throughout rather than WP_Query. Both are native
 * WordPress APIs; $wpdb is the appropriate choice here because: (a) the search command
 * queries a custom table, which WP_Query cannot do, and (b) the backfill scan benefits
 * from avoiding WP_Query overhead — no object hydration, no hook pipeline, no
 * SQL_CALC_FOUND_ROWS — fetching IDs only via keyset pagination.
 *
 * The table must be created with `wp dmg-read-more migrate` and seeded for historical
 * posts with `wp dmg-read-more backfill` before `search` can be used.
 *
 * @package dmg-read-more
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages the DMG Read More block index.
 *
 * The index table must be created and seeded before search can be used.
 * Once in place, new and updated posts are indexed automatically via save_post.
 *
 * ## EXAMPLES
 *
 *     # First-time setup
 *     wp dmg-read-more migrate
 *     wp dmg-read-more backfill
 *
 *     # Resync after the plugin was deactivated
 *     wp dmg-read-more sync
 *
 *     # Search posts from the last 30 days (default)
 *     wp dmg-read-more search
 *
 *     # Search within a specific date range
 *     wp dmg-read-more search --date-after=2024-01-01 --date-before=2024-06-01
 *
 *     # Restrict results to specific post types
 *     wp dmg-read-more search --post-type=post,page
 */
class DMG_Read_More_CLI {

	/**
	 * Create the index table.
	 *
	 * Safe to re-run — does nothing if the table already exists.
	 * Once created, new and updated posts are indexed automatically via save_post.
	 * Run `wp dmg-read-more backfill` afterwards to index existing posts.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create the index table (run once on first deployment)
	 *     wp dmg-read-more migrate
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments (unused).
	 */
	public function migrate( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( $this->table_exists() ) {
			WP_CLI::success( 'Index table already exists, nothing to do.' );
			return;
		}

		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table   = $wpdb->prefix . 'dmg_read_more_index';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$table} (
				post_id BIGINT(20) UNSIGNED NOT NULL,
				PRIMARY KEY (post_id)
			) {$charset};"
		);

		if ( $wpdb->last_error ) {
			WP_CLI::error( $wpdb->last_error );
		}

		WP_CLI::success( 'Migration complete. Run `wp dmg-read-more backfill` to index existing posts.' );
	}

	/**
	 * Seed the index table from existing published posts.
	 *
	 * Performs a one-time chunked scan of wp_posts to populate the index for historical
	 * data. Safe to re-run — uses INSERT IGNORE so duplicate entries are skipped.
	 * After this runs, ongoing maintenance is handled automatically by save_post.
	 *
	 * ## EXAMPLES
	 *
	 *     # Seed the index from existing posts (run once after migrate)
	 *     wp dmg-read-more backfill
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments (unused).
	 */
	public function backfill( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $this->table_exists() ) {
			WP_CLI::error( 'Index table not found. Run `wp dmg-read-more migrate` first.' );
		}

		global $wpdb;

		$table   = $wpdb->prefix . 'dmg_read_more_index';
		$like    = '%' . $wpdb->esc_like( '<!-- wp:dmg/read-more' ) . '%';
		$last_id = 0;
		$chunk   = 100;
		$indexed = 0;
		$fetched = 0;

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts}
					WHERE post_status = 'publish'
					  AND post_content LIKE %s
					  AND ID > %d
					ORDER BY ID ASC
					LIMIT %d",
					$like,
					$last_id,
					$chunk
				)
			);
			// phpcs:enable

			if ( $wpdb->last_error ) {
				WP_CLI::error( $wpdb->last_error );
			}

			if ( ! empty( $ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $ids ), '(%d)' ) );

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$table} (post_id) VALUES {$placeholders}",
						...$ids
					)
				);
				// phpcs:enable

				if ( $wpdb->last_error ) {
					WP_CLI::error( $wpdb->last_error );
				}

				$indexed += (int) $wpdb->rows_affected;
				$last_id  = (int) end( $ids );
			}

			WP_CLI::debug(
				sprintf( 'Last ID %d — peak memory: %s', $last_id, size_format( memory_get_peak_usage( true ) ) ),
				'dmg-read-more'
			);

			$wpdb->flush();
			\WP_CLI\Utils\wp_clear_object_cache();

			$fetched = count( $ids );
		} while ( $fetched === $chunk );

		WP_CLI::success( sprintf( 'Backfill complete. Indexed %d post(s).', $indexed ) );
	}

	/**
	 * Reconcile the index against the current state of wp_posts.
	 *
	 * Removes stale entries (block removed or post unpublished while the plugin was
	 * inactive) and adds missing entries (posts not yet in the index). Safe to re-run.
	 * Recommended after any period of plugin deactivation.
	 *
	 * ## EXAMPLES
	 *
	 *     # Resync after the plugin was deactivated
	 *     wp dmg-read-more sync
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments (unused).
	 */
	public function sync( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! $this->table_exists() ) {
			WP_CLI::error( 'Index table not found. Run `wp dmg-read-more migrate` first.' );
		}

		global $wpdb;

		$table   = $wpdb->prefix . 'dmg_read_more_index';
		$like    = '%' . $wpdb->esc_like( '<!-- wp:dmg/read-more' ) . '%';
		$last_id = 0;
		$chunk   = 100;
		$removed = 0;
		$added   = 0;
		$fetched = 0;

		// Phase 1: remove stale entries — posts deleted, unpublished, or block removed.
		WP_CLI::log( 'Phase 1/2: removing stale entries...' );

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$stale_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT i.post_id
					FROM {$table} i
					LEFT JOIN {$wpdb->posts} p ON p.ID = i.post_id
					WHERE ( p.ID IS NULL OR p.post_status != 'publish' OR p.post_content NOT LIKE %s )
					  AND i.post_id > %d
					ORDER BY i.post_id ASC
					LIMIT %d",
					$like,
					$last_id,
					$chunk
				)
			);
			// phpcs:enable

			if ( $wpdb->last_error ) {
				WP_CLI::error( $wpdb->last_error );
			}

			if ( ! empty( $stale_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $stale_ids ), '%d' ) );

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$table} WHERE post_id IN ({$placeholders})",
						...$stale_ids
					)
				);
				// phpcs:enable

				if ( $wpdb->last_error ) {
					WP_CLI::error( $wpdb->last_error );
				}

				$removed += (int) $wpdb->rows_affected;
				$last_id  = (int) end( $stale_ids );
			}

			WP_CLI::debug(
				sprintf( 'Last ID %d — peak memory: %s', $last_id, size_format( memory_get_peak_usage( true ) ) ),
				'dmg-read-more'
			);

			$wpdb->flush();
			\WP_CLI\Utils\wp_clear_object_cache();

			$fetched = count( $stale_ids );
		} while ( $fetched === $chunk );

		// Phase 2: add missing entries — published posts with the block not yet indexed.
		WP_CLI::log( 'Phase 2/2: adding missing entries...' );

		$last_id = 0;

		do {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$missing_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID
					FROM {$wpdb->posts} p
					LEFT JOIN {$table} i ON i.post_id = p.ID
					WHERE p.post_status = 'publish'
					  AND p.post_content LIKE %s
					  AND p.ID > %d
					  AND i.post_id IS NULL
					ORDER BY p.ID ASC
					LIMIT %d",
					$like,
					$last_id,
					$chunk
				)
			);
			// phpcs:enable

			if ( $wpdb->last_error ) {
				WP_CLI::error( $wpdb->last_error );
			}

			if ( ! empty( $missing_ids ) ) {
				$placeholders = implode( ',', array_fill( 0, count( $missing_ids ), '(%d)' ) );

				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$table} (post_id) VALUES {$placeholders}",
						...$missing_ids
					)
				);
				// phpcs:enable

				if ( $wpdb->last_error ) {
					WP_CLI::error( $wpdb->last_error );
				}

				$added  += (int) $wpdb->rows_affected;
				$last_id = (int) end( $missing_ids );
			}

			WP_CLI::debug(
				sprintf( 'Last ID %d — peak memory: %s', $last_id, size_format( memory_get_peak_usage( true ) ) ),
				'dmg-read-more'
			);

			$wpdb->flush();
			\WP_CLI\Utils\wp_clear_object_cache();

			$fetched = count( $missing_ids );
		} while ( $fetched === $chunk );

		WP_CLI::success( sprintf( 'Sync complete. Removed %d stale, added %d missing.', $removed, $added ) );
	}

	/**
	 * Find published posts that contain the dmg/read-more block.
	 *
	 * Queries the index table for O(matching posts) performance. Requires
	 * `wp dmg-read-more migrate` and `wp dmg-read-more backfill` to have run first.
	 *
	 * ## OPTIONS
	 *
	 * [--date-after=<date>]
	 * : ISO 8601 date. Only return posts published on or after this date. Defaults to 30 days ago.
	 *
	 * [--date-before=<date>]
	 * : ISO 8601 date. Only return posts published on or before this date. Defaults to today.
	 *
	 * [--post-type=<slug>]
	 * : Comma-separated post type slugs to restrict results. Defaults to all post types.
	 *
	 * [--format=<format>]
	 * : Output format. Accepts: ids, count. Default: ids.
	 * ---
	 * default: ids
	 * options:
	 *   - ids
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Search posts from the last 30 days (default date range)
	 *     wp dmg-read-more search
	 *
	 *     # Search within a specific date range
	 *     wp dmg-read-more search --date-after=2024-01-01 --date-before=2024-06-01
	 *
	 *     # Restrict results to specific post types
	 *     wp dmg-read-more search --post-type=post,page
	 *
	 *     # Combine date range and post type filters
	 *     wp dmg-read-more search --date-after=2024-01-01 --post-type=post
	 *
	 *     # Output only the count (useful for monitoring)
	 *     wp dmg-read-more search --format=count
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function search( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! $this->table_exists() ) {
			WP_CLI::error( 'Index table not found. Run `wp dmg-read-more migrate` then `wp dmg-read-more backfill` first.' );
		}

		global $wpdb;

		$date_after  = $assoc_args['date-after'] ?? wp_date( 'Y-m-d', strtotime( '-30 days' ) );
		$date_before = $assoc_args['date-before'] ?? wp_date( 'Y-m-d' );

		if ( ! $this->is_valid_date( $date_after ) ) {
			WP_CLI::error( sprintf( 'Invalid --date-after value: "%s". Expected ISO 8601 (YYYY-MM-DD).', $date_after ) );
		}

		if ( ! $this->is_valid_date( $date_before ) ) {
			WP_CLI::error( sprintf( 'Invalid --date-before value: "%s". Expected ISO 8601 (YYYY-MM-DD).', $date_before ) );
		}

		$format = $assoc_args['format'] ?? 'ids';

		if ( ! in_array( $format, [ 'ids', 'count' ], true ) ) {
			WP_CLI::error( sprintf( 'Invalid --format value: "%s". Accepted values: ids, count.', $format ) );
		}

		$post_types = [];
		if ( ! empty( $assoc_args['post-type'] ) ) {
			$post_types = array_values(
				array_filter( array_map( 'sanitize_key', explode( ',', $assoc_args['post-type'] ) ) )
			);
		}

		$table   = $wpdb->prefix . 'dmg_read_more_index';
		$last_id = 0;
		$chunk   = 100;
		$total   = 0;
		$fetched = 0;

		// Build WHERE once; $last_id is the only value that changes per chunk.
		$where         = "p.post_status = 'publish' AND p.post_date >= %s AND p.post_date < DATE_ADD(%s, INTERVAL 1 DAY) AND i.post_id > %d";
		$static_params = [ $date_after, $date_before ];

		if ( ! empty( $post_types ) ) {
			$where .= ' AND p.post_type IN (' . implode( ',', array_fill( 0, count( $post_types ), '%s' ) ) . ')';
		}

		do {
			$iter_params = array_merge( $static_params, [ $last_id ], $post_types, [ $chunk ] );

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
				if ( 'ids' === $format ) {
					WP_CLI::line( $post_id );
				}
				++$total;
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
		} while ( $fetched === $chunk );

		if ( 'count' === $format ) {
			WP_CLI::line( (string) $total );
			return;
		}

		if ( 0 === $total ) {
			WP_CLI::success( 'No matching posts found.' );
			return;
		}

		WP_CLI::success( sprintf( 'Found %d matching post(s).', $total ) );
	}

	/**
	 * Returns true if the index table exists in the database.
	 */
	private function table_exists(): bool {
		global $wpdb;
		$table = $wpdb->prefix . 'dmg_read_more_index';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		) === $table;
	}

	/**
	 * Returns true if $date is a valid YYYY-MM-DD string.
	 *
	 * @param string $date Date string to validate.
	 */
	private function is_valid_date( string $date ): bool {
		$d = \DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
