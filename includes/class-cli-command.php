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
	 * Search strategy resolved at construction time based on the runtime environment.
	 *
	 * @var DMG_Block_Search_Strategy
	 */
	private DMG_Block_Search_Strategy $search_strategy;

	/**
	 * Resolves the correct search strategy for the current environment.
	 */
	public function __construct() {
		$this->search_strategy = dmg_read_more_is_vip()
			? new DMG_VIP_Search_Strategy()
			: new DMG_Index_Table_Strategy();
	}

	/**
	 * Create the index table.
	 *
	 * Safe to re-run — does nothing if the table already exists.
	 * Once created, new and updated posts are indexed automatically via save_post.
	 * Run `wp dmg-read-more backfill` afterwards to index existing posts.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Run on all sites in the network (multisite only).
	 *
	 * ## EXAMPLES
	 *
	 *     # Create the index table (run once on first deployment)
	 *     wp dmg-read-more migrate
	 *
	 *     # Create the index table on every site in the network
	 *     wp dmg-read-more migrate --network
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function migrate( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( dmg_read_more_is_vip() ) {
			WP_CLI::log( 'Not required in VIP environments — the index table is not used.' );
			return;
		}

		$this->maybe_warn_network_single_site( $assoc_args );

		if ( ! empty( $assoc_args['network'] ) && is_multisite() ) {
			$this->iterate_network( fn() => $this->run_migrate() );
			return;
		}

		$this->run_migrate();
	}

	/**
	 * Seed the index table from existing published posts.
	 *
	 * Performs a one-time chunked scan of wp_posts to populate the index for historical
	 * data. Safe to re-run — uses INSERT IGNORE so duplicate entries are skipped.
	 * After this runs, ongoing maintenance is handled automatically by save_post.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Run on all sites in the network (multisite only).
	 *
	 * ## EXAMPLES
	 *
	 *     # Seed the index from existing posts (run once after migrate)
	 *     wp dmg-read-more backfill
	 *
	 *     # Seed the index on every site in the network
	 *     wp dmg-read-more backfill --network
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function backfill( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( dmg_read_more_is_vip() ) {
			WP_CLI::log( 'Not required in VIP environments — the index table is not used.' );
			return;
		}

		$this->maybe_warn_network_single_site( $assoc_args );

		if ( ! empty( $assoc_args['network'] ) && is_multisite() ) {
			$this->iterate_network( fn() => $this->run_backfill() );
			return;
		}

		$this->run_backfill();
	}

	/**
	 * Reconcile the index against the current state of wp_posts.
	 *
	 * Removes stale entries (block removed or post unpublished while the plugin was
	 * inactive) and adds missing entries (posts not yet in the index). Safe to re-run.
	 * Recommended after any period of plugin deactivation.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Run on all sites in the network (multisite only).
	 *
	 * ## EXAMPLES
	 *
	 *     # Resync after the plugin was deactivated
	 *     wp dmg-read-more sync
	 *
	 *     # Resync on every site in the network
	 *     wp dmg-read-more sync --network
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function sync( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( dmg_read_more_is_vip() ) {
			WP_CLI::log( 'Not required in VIP environments — the index table is not used.' );
			return;
		}

		$this->maybe_warn_network_single_site( $assoc_args );

		if ( ! empty( $assoc_args['network'] ) && is_multisite() ) {
			$this->iterate_network( fn() => $this->run_sync() );
			return;
		}

		$this->run_sync();
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
	 * [--network]
	 * : Search all sites in the network (multisite only). IDs are prefixed with site_id:.
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
	 *     # Search across all sites in the network
	 *     wp dmg-read-more search --network
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function search( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! dmg_read_more_is_vip() && ! $this->table_exists() ) {
			WP_CLI::error( 'Index table not found. Run `wp dmg-read-more migrate` then `wp dmg-read-more backfill` first.' );
		}

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
			$requested  = array_values(
				array_filter( array_map( 'sanitize_key', explode( ',', $assoc_args['post-type'] ) ) )
			);
			$registered = get_post_types();
			$unknown    = array_diff( $requested, array_keys( $registered ) );
			foreach ( $unknown as $slug ) {
				WP_CLI::warning( sprintf( 'Unknown post type: "%s" — it will be ignored.', $slug ) );
			}
			$post_types = array_values( array_diff( $requested, $unknown ) );
			if ( ! empty( $requested ) && empty( $post_types ) ) {
				WP_CLI::error( 'All supplied --post-type values are unrecognised. Aborting.' );
			}
		}

		$this->maybe_warn_network_single_site( $assoc_args );

		if ( ! empty( $assoc_args['network'] ) && is_multisite() ) {
			$grand_total = 0;
			$this->iterate_network(
				function ( int $site_id ) use ( $date_after, $date_before, $post_types, $format, &$grand_total ) {
					if ( ! dmg_read_more_is_vip() && ! $this->table_exists() ) {
						WP_CLI::warning( sprintf( 'Site %d: index table not found, skipping.', $site_id ) );
						return;
					}
					$ids          = $this->search_strategy->search( $date_after, $date_before, $post_types );
					$grand_total += count( $ids );
					if ( 'ids' === $format ) {
						foreach ( $ids as $post_id ) {
							WP_CLI::line( $site_id . ':' . $post_id );
						}
					}
				}
			);

			if ( 'count' === $format ) {
				WP_CLI::line( (string) $grand_total );
				return;
			}

			if ( 0 === $grand_total ) {
				WP_CLI::success( 'No matching posts found.' );
				return;
			}

			WP_CLI::success( sprintf( 'Found %d matching post(s).', $grand_total ) );
			return;
		}

		$ids   = $this->search_strategy->search( $date_after, $date_before, $post_types );
		$total = count( $ids );

		if ( 'count' === $format ) {
			WP_CLI::line( (string) $total );
			return;
		}

		foreach ( $ids as $post_id ) {
			WP_CLI::line( (string) $post_id );
		}

		if ( 0 === $total ) {
			WP_CLI::success( 'No matching posts found.' );
			return;
		}

		WP_CLI::success( sprintf( 'Found %d matching post(s).', $total ) );
	}

	/**
	 * Creates the index table for the current site.
	 */
	private function run_migrate(): void {
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
	 * Seeds the index table from existing published posts for the current site.
	 */
	private function run_backfill(): void {
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
	 * Reconciles the index table against wp_posts for the current site.
	 */
	private function run_sync(): void {
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
	 * Show the number of published posts containing the dmg/read-more block.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Show counts for every site in the network (multisite only).
	 *
	 * ## EXAMPLES
	 *
	 *     # Count on the current site
	 *     wp dmg-read-more audit
	 *
	 *     # Count on every site in the network
	 *     wp dmg-read-more audit --network
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function audit( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$this->maybe_warn_network_single_site( $assoc_args );

		if ( ! empty( $assoc_args['network'] ) && is_multisite() ) {
			$rows = [];
			$this->iterate_network(
				function ( int $site_id ) use ( &$rows ) {
					if ( ! $this->table_exists() ) {
						WP_CLI::warning( sprintf( 'Site %d: index table not found, skipping.', $site_id ) );
						return;
					}
					$rows[] = [
						'site_id' => $site_id,
						'posts'   => $this->get_index_count(),
					];
				}
			);

			if ( empty( $rows ) ) {
				WP_CLI::success( 'No index data found across the network.' );
				return;
			}

			\WP_CLI\Utils\format_items( 'table', $rows, [ 'site_id', 'posts' ] );
			return;
		}

		if ( ! $this->table_exists() ) {
			WP_CLI::error( 'Index table not found. Run `wp dmg-read-more migrate` then `wp dmg-read-more backfill` first.' );
		}

		WP_CLI::success( sprintf( '%d post(s) contain the dmg/read-more block.', $this->get_index_count() ) );
	}

	/**
	 * Remove the dmg/read-more block from all posts that contain it.
	 *
	 * Uses parse_blocks/serialize_block for safe block-level removal. Updates
	 * post_content and removes the post from the index.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : List affected posts without making any changes.
	 *
	 * [--network]
	 * : Run on all sites in the network (multisite only).
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview what would be changed
	 *     wp dmg-read-more remove --dry-run
	 *
	 *     # Remove from all posts (prompts for confirmation)
	 *     wp dmg-read-more remove
	 *
	 *     # Remove across the network without prompting
	 *     wp dmg-read-more remove --network --yes
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Named arguments.
	 */
	public function remove( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		$this->maybe_warn_network_single_site( $assoc_args );

		if ( ! empty( $assoc_args['network'] ) && is_multisite() ) {
			$this->iterate_network( fn() => $this->run_remove( $assoc_args ) );
			return;
		}

		$this->run_remove( $assoc_args );
	}

	/**
	 * Replace the dmg/read-more block with another block in all posts that contain it.
	 *
	 * Swaps the block name using parse_blocks/serialize_block; attributes are preserved.
	 * Removes the old index entries (the new block is not tracked by this index).
	 *
	 * ## OPTIONS
	 *
	 * <new-block>
	 * : The namespaced block name to replace dmg/read-more with, e.g. core/paragraph.
	 *
	 * [--dry-run]
	 * : List affected posts without making any changes.
	 *
	 * [--network]
	 * : Run on all sites in the network (multisite only).
	 *
	 * ## EXAMPLES
	 *
	 *     # Preview what would be changed
	 *     wp dmg-read-more replace core/paragraph --dry-run
	 *
	 *     # Replace in all posts (prompts for confirmation)
	 *     wp dmg-read-more replace core/paragraph
	 *
	 *     # Replace across the network without prompting
	 *     wp dmg-read-more replace core/paragraph --network --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function replace( array $args, array $assoc_args ): void {
		if ( empty( $args[0] ) || strpos( $args[0], '/' ) === false ) {
			WP_CLI::error( 'Provide a namespaced block name as the first argument, e.g. core/paragraph.' );
		}

		$new_block = $args[0];

		$this->maybe_warn_network_single_site( $assoc_args );

		if ( ! empty( $assoc_args['network'] ) && is_multisite() ) {
			$this->iterate_network( fn() => $this->run_replace( $new_block, $assoc_args ) );
			return;
		}

		$this->run_replace( $new_block, $assoc_args );
	}

	/**
	 * Removes all dmg/read-more blocks from post content on the current site.
	 *
	 * @param array $assoc_args WP-CLI named arguments (used for --dry-run and --yes).
	 */
	private function run_remove( array $assoc_args ): void {
		if ( ! $this->table_exists() ) {
			WP_CLI::error( 'Index table not found. Run `wp dmg-read-more migrate` then `wp dmg-read-more backfill` first.' );
		}

		$dry_run = ! empty( $assoc_args['dry-run'] );

		global $wpdb;
		$table = $wpdb->prefix . 'dmg_read_more_index';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = $wpdb->get_col( "SELECT post_id FROM {$table} ORDER BY post_id ASC" );

		if ( $wpdb->last_error ) {
			WP_CLI::error( $wpdb->last_error );
		}

		$count = count( $post_ids );

		if ( 0 === $count ) {
			WP_CLI::success( 'No posts contain the dmg/read-more block.' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::log( sprintf( 'Dry run: %d post(s) would have dmg/read-more removed.', $count ) );
			foreach ( $post_ids as $post_id ) {
				WP_CLI::line( (string) $post_id );
			}
			return;
		}

		WP_CLI::confirm( sprintf( 'Remove dmg/read-more from %d post(s)?', $count ), $assoc_args );

		$processed = 0;
		foreach ( $post_ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post ) {
				continue;
			}

			$blocks   = parse_blocks( $post->post_content );
			$filtered = array_values( array_filter( $blocks, fn( $b ) => 'dmg/read-more' !== $b['blockName'] ) );
			$updated  = implode( '', array_map( 'serialize_block', $filtered ) );

			wp_update_post(
				[
					'ID'           => (int) $post_id,
					'post_content' => $updated,
				]
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, [ 'post_id' => $post_id ], [ '%d' ] );

			++$processed;
			WP_CLI::debug( sprintf( 'Processed post %d', $post_id ), 'dmg-read-more' );
		}

		WP_CLI::success( sprintf( 'Removed dmg/read-more from %d post(s).', $processed ) );
	}

	/**
	 * Replaces all dmg/read-more blocks with another block on the current site.
	 *
	 * @param string $new_block  The replacement block name.
	 * @param array  $assoc_args WP-CLI named arguments (used for --dry-run and --yes).
	 */
	private function run_replace( string $new_block, array $assoc_args ): void {
		if ( ! $this->table_exists() ) {
			WP_CLI::error( 'Index table not found. Run `wp dmg-read-more migrate` then `wp dmg-read-more backfill` first.' );
		}

		$dry_run = ! empty( $assoc_args['dry-run'] );

		global $wpdb;
		$table = $wpdb->prefix . 'dmg_read_more_index';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$post_ids = $wpdb->get_col( "SELECT post_id FROM {$table} ORDER BY post_id ASC" );

		if ( $wpdb->last_error ) {
			WP_CLI::error( $wpdb->last_error );
		}

		$count = count( $post_ids );

		if ( 0 === $count ) {
			WP_CLI::success( 'No posts contain the dmg/read-more block.' );
			return;
		}

		if ( $dry_run ) {
			WP_CLI::log( sprintf( 'Dry run: dmg/read-more would be replaced with %s in %d post(s).', $new_block, $count ) );
			foreach ( $post_ids as $post_id ) {
				WP_CLI::line( (string) $post_id );
			}
			return;
		}

		WP_CLI::confirm( sprintf( 'Replace dmg/read-more with %s in %d post(s)?', $new_block, $count ), $assoc_args );

		$processed = 0;
		foreach ( $post_ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post ) {
				continue;
			}

			$blocks  = parse_blocks( $post->post_content );
			$updated = array_map(
				function ( array $block ) use ( $new_block ): array {
					if ( 'dmg/read-more' === $block['blockName'] ) {
						$block['blockName'] = $new_block;
					}
					return $block;
				},
				$blocks
			);
			$content = implode( '', array_map( 'serialize_block', $updated ) );

			wp_update_post(
				[
					'ID'           => (int) $post_id,
					'post_content' => $content,
				]
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete( $table, [ 'post_id' => $post_id ], [ '%d' ] );

			++$processed;
			WP_CLI::debug( sprintf( 'Processed post %d', $post_id ), 'dmg-read-more' );
		}

		WP_CLI::success( sprintf( 'Replaced dmg/read-more with %s in %d post(s).', $new_block, $processed ) );
	}

	/**
	 * Returns the number of posts in the index table for the current site.
	 */
	private function get_index_count(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'dmg_read_more_index';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		if ( $wpdb->last_error ) {
			WP_CLI::error( $wpdb->last_error );
		}
		return $count;
	}

	/**
	 * Iterates over all sites in the network, switching context for each.
	 *
	 * Restoration of blog context is guaranteed via finally even if the callback errors.
	 *
	 * @param callable(int):void $callback Receives the integer site ID.
	 */
	private function iterate_network( callable $callback ): void {
		$sites = get_sites(
			[
				'number' => 0,
				'fields' => 'ids',
			]
		);
		foreach ( $sites as $site_id ) {
			WP_CLI::log( sprintf( 'Processing site %d...', $site_id ) );
			switch_to_blog( (int) $site_id );
			try {
				$callback( (int) $site_id );
			} finally {
				restore_current_blog();
			}
		}
	}

	/**
	 * Emits a warning when --network is passed on a single-site install.
	 *
	 * @param array $assoc_args WP-CLI named arguments.
	 */
	private function maybe_warn_network_single_site( array $assoc_args ): void {
		if ( ! empty( $assoc_args['network'] ) && ! is_multisite() ) {
			WP_CLI::warning( '--network has no effect on single-site installs.' );
		}
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
