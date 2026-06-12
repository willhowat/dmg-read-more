<?php

use PHPUnit\Framework\TestCase;

class CLICommandTest extends TestCase {

	private DMG_Read_More_CLI $cli;
	private Wpdb_Stub $wpdb;

	protected function setUp(): void {
		$this->cli            = new DMG_Read_More_CLI();
		$this->wpdb           = new Wpdb_Stub();
		$GLOBALS['wpdb']      = $this->wpdb;
		WP_CLI::reset();
	}

	protected function tearDown(): void {
		unset(
			$GLOBALS['_dmg_test_is_vip'],
			$GLOBALS['_dmg_test_is_multisite'],
			$GLOBALS['_dmg_test_sites']
		);
	}

	// -------------------------------------------------------------------------
	// Strategy resolution
	// -------------------------------------------------------------------------

	public function test_uses_index_table_strategy_on_standard_env(): void {
		$ref = new ReflectionProperty( DMG_Read_More_CLI::class, 'search_strategy' );
		$ref->setAccessible( true );
		$this->assertInstanceOf( DMG_Index_Table_Strategy::class, $ref->getValue( $this->cli ) );
	}

	public function test_uses_vip_strategy_in_vip_env(): void {
		$GLOBALS['_dmg_test_is_vip'] = true;
		$cli                          = new DMG_Read_More_CLI();

		$ref = new ReflectionProperty( DMG_Read_More_CLI::class, 'search_strategy' );
		$ref->setAccessible( true );
		$this->assertInstanceOf( DMG_VIP_Search_Strategy::class, $ref->getValue( $cli ) );
	}

	// -------------------------------------------------------------------------
	// search — existing tests
	// -------------------------------------------------------------------------

	public function test_search_errors_when_table_missing(): void {
		$this->wpdb->set_table_exists( false );

		$this->expectException( WP_CLI_Exception::class );
		$this->expectExceptionMessageMatches( '/migrate/' );

		$this->cli->search( [], [] );
	}

	public function test_search_errors_on_invalid_date_after(): void {
		$this->wpdb->set_table_exists( true );

		$this->expectException( WP_CLI_Exception::class );
		$this->expectExceptionMessageMatches( '/--date-after/' );

		$this->cli->search( [], [ 'date-after' => 'not-a-date' ] );
	}

	public function test_search_errors_on_invalid_date_before(): void {
		$this->wpdb->set_table_exists( true );

		$this->expectException( WP_CLI_Exception::class );
		$this->expectExceptionMessageMatches( '/--date-before/' );

		// Month 13 is invalid; is_valid_date catches it via format round-trip check.
		$this->cli->search( [], [ 'date-before' => '2024-13-01' ] );
	}

	public function test_search_reports_no_results(): void {
		$this->wpdb->set_table_exists( true );
		$this->wpdb->set_get_col_sequence( [ [] ] );

		$this->cli->search( [], [] );

		$this->assertContains( 'No matching posts found.', WP_CLI::$success );
		$this->assertEmpty( WP_CLI::$lines );
	}

	public function test_search_outputs_each_id_and_summary(): void {
		$this->wpdb->set_table_exists( true );
		$this->wpdb->set_get_col_sequence( [ [ '10', '20', '30' ] ] );

		$this->cli->search( [], [] );

		$this->assertSame( [ '10', '20', '30' ], WP_CLI::$lines );
		$this->assertContains( 'Found 3 matching post(s).', WP_CLI::$success );
	}

	public function test_search_paginates_across_full_chunks(): void {
		$chunk1 = array_map( 'strval', range( 1, 100 ) );
		$chunk2 = [ '101', '102', '103' ];

		$this->wpdb->set_table_exists( true );
		$this->wpdb->set_get_col_sequence( [ $chunk1, $chunk2 ] );

		$this->cli->search( [], [] );

		$this->assertCount( 103, WP_CLI::$lines );
		$this->assertContains( 'Found 103 matching post(s).', WP_CLI::$success );
	}

	public function test_search_format_count_outputs_total_without_ids(): void {
		$this->wpdb->set_table_exists( true );
		$this->wpdb->set_get_col_sequence( [ [ '10', '20', '30' ] ] );

		$this->cli->search( [], [ 'format' => 'count' ] );

		$this->assertSame( [ '3' ], WP_CLI::$lines );
		$this->assertEmpty( WP_CLI::$success );
	}

	public function test_search_errors_on_db_failure(): void {
		$this->wpdb->set_table_exists( true );
		$this->wpdb->set_col_error( true );

		$this->expectException( WP_CLI_Exception::class );
		$this->expectExceptionMessageMatches( '/MySQL error/' );

		$this->cli->search( [], [] );
	}

	public function test_search_passes_custom_dates_to_query(): void {
		$this->wpdb->set_table_exists( true );
		$this->wpdb->set_get_col_sequence( [ [ '5', '6' ] ] );

		$this->cli->search( [], [
			'date-after'  => '2024-01-01',
			'date-before' => '2024-06-30',
		] );

		$this->assertSame( [ '5', '6' ], WP_CLI::$lines );

		// prepare_calls[0] = table_exists (1 arg); [1] = first chunk (date_after, date_before, cursor, limit).
		$chunk_args = $this->wpdb->prepare_calls[1];
		$this->assertSame( '2024-01-01', $chunk_args[0], 'date-after should be the first query parameter' );
		$this->assertSame( '2024-06-30', $chunk_args[1], 'date-before should be the second query parameter' );
	}

	public function test_search_cursor_advances_between_chunks(): void {
		$chunk1 = array_map( 'strval', range( 1, 100 ) );

		$this->wpdb->set_table_exists( true );
		$this->wpdb->set_get_col_sequence( [ $chunk1, [] ] );

		$this->cli->search( [], [] );

		// prepare_calls[0] = table_exists; [1] = first chunk; [2] = second chunk.
		$this->assertSame( 0, $this->wpdb->prepare_calls[1][2], 'First chunk should start cursor at 0' );
		$this->assertSame( 100, $this->wpdb->prepare_calls[2][2], 'Second chunk cursor should equal last ID of first chunk' );
	}

	public function test_search_format_count_with_no_results(): void {
		$this->wpdb->set_table_exists( true );
		$this->wpdb->set_get_col_sequence( [ [] ] );

		$this->cli->search( [], [ 'format' => 'count' ] );

		$this->assertSame( [ '0' ], WP_CLI::$lines );
		$this->assertEmpty( WP_CLI::$success );
	}

	// -------------------------------------------------------------------------
	// search — network flag
	// -------------------------------------------------------------------------

	public function test_search_network_warns_on_single_site(): void {
		$this->wpdb->set_table_exists( true );
		$this->wpdb->set_get_col_sequence( [ [] ] );

		$this->cli->search( [], [ 'network' => true ] );

		$this->assertContains( '--network has no effect on single-site installs.', WP_CLI::$warnings );
	}

	public function test_search_network_outputs_site_prefixed_ids(): void {
		$GLOBALS['_dmg_test_is_multisite'] = true;
		$GLOBALS['_dmg_test_sites']        = [ 1, 2 ];

		$table = 'wp_dmg_read_more_index';
		// get_var calls: initial table_exists check, site 1 check, site 2 check.
		$this->wpdb->set_get_var_sequence( [ $table, $table, $table ] );
		$this->wpdb->set_get_col_sequence( [ [ '10', '20' ], [ '30' ] ] );

		$this->cli->search( [], [ 'network' => true ] );

		$this->assertSame( [ '1:10', '1:20', '2:30' ], WP_CLI::$lines );
		$this->assertContains( 'Found 3 matching post(s).', WP_CLI::$success );
	}

	public function test_search_network_skips_site_without_table(): void {
		$GLOBALS['_dmg_test_is_multisite'] = true;
		$GLOBALS['_dmg_test_sites']        = [ 1, 2 ];

		$table = 'wp_dmg_read_more_index';
		// Site 1 has the table; site 2 does not.
		$this->wpdb->set_get_var_sequence( [ $table, $table, null ] );
		$this->wpdb->set_get_col_sequence( [ [ '10' ] ] );

		$this->cli->search( [], [ 'network' => true ] );

		$this->assertSame( [ '1:10' ], WP_CLI::$lines );
		$this->assertNotEmpty( WP_CLI::$warnings );
		$this->assertStringContainsString( 'Site 2', WP_CLI::$warnings[0] );
	}

	public function test_search_network_format_count_returns_grand_total(): void {
		$GLOBALS['_dmg_test_is_multisite'] = true;
		$GLOBALS['_dmg_test_sites']        = [ 1, 2 ];

		$table = 'wp_dmg_read_more_index';
		$this->wpdb->set_get_var_sequence( [ $table, $table, $table ] );
		$this->wpdb->set_get_col_sequence( [ [ '10', '20' ], [ '30' ] ] );

		$this->cli->search( [], [ 'network' => true, 'format' => 'count' ] );

		$this->assertSame( [ '3' ], WP_CLI::$lines );
	}

	// -------------------------------------------------------------------------
	// audit
	// -------------------------------------------------------------------------

	public function test_audit_errors_when_table_missing(): void {
		$this->wpdb->set_table_exists( false );

		$this->expectException( WP_CLI_Exception::class );
		$this->expectExceptionMessageMatches( '/migrate/' );

		$this->cli->audit( [], [] );
	}

	public function test_audit_outputs_count(): void {
		// Sequence: table_exists check returns table name, COUNT(*) returns 42.
		$this->wpdb->set_get_var_sequence( [ 'wp_dmg_read_more_index', '42' ] );

		$this->cli->audit( [], [] );

		$this->assertContains( '42 post(s) contain the dmg/read-more block.', WP_CLI::$success );
	}

	public function test_audit_network_shows_per_site_counts(): void {
		$GLOBALS['_dmg_test_is_multisite'] = true;
		$GLOBALS['_dmg_test_sites']        = [ 1, 2 ];

		$table = 'wp_dmg_read_more_index';
		// Per-site: table_exists + COUNT for each site.
		$this->wpdb->set_get_var_sequence( [ $table, '10', $table, '5' ] );

		$this->cli->audit( [], [ 'network' => true ] );

		// format_items stub outputs "site_id,posts" per row.
		$this->assertContains( '1,10', WP_CLI::$lines );
		$this->assertContains( '2,5', WP_CLI::$lines );
	}

	// -------------------------------------------------------------------------
	// remove
	// -------------------------------------------------------------------------

	public function test_remove_errors_when_table_missing(): void {
		$this->wpdb->set_table_exists( false );

		$this->expectException( WP_CLI_Exception::class );
		$this->expectExceptionMessageMatches( '/migrate/' );

		$this->cli->remove( [], [] );
	}

	public function test_remove_dry_run_with_no_posts(): void {
		$this->wpdb->set_table_exists( true );
		$this->wpdb->set_get_col_sequence( [ [] ] );

		$this->cli->remove( [], [ 'dry-run' => true ] );

		$this->assertContains( 'No posts contain the dmg/read-more block.', WP_CLI::$success );
	}

	public function test_remove_dry_run_lists_post_ids(): void {
		$this->wpdb->set_table_exists( true );
		$this->wpdb->set_get_col_sequence( [ [ '5', '6', '7' ] ] );

		$this->cli->remove( [], [ 'dry-run' => true ] );

		$this->assertSame( [ '5', '6', '7' ], WP_CLI::$lines );
		$this->assertEmpty( WP_CLI::$success );
	}

	public function test_remove_processes_posts(): void {
		$this->wpdb->set_table_exists( true );
		$this->wpdb->set_get_col_sequence( [ [ '5', '6' ] ] );

		$this->cli->remove( [], [] );

		$this->assertContains( 'Removed dmg/read-more from 2 post(s).', WP_CLI::$success );
	}

	// -------------------------------------------------------------------------
	// replace
	// -------------------------------------------------------------------------

	public function test_replace_errors_on_missing_block_arg(): void {
		$this->expectException( WP_CLI_Exception::class );

		$this->cli->replace( [], [] );
	}

	public function test_replace_errors_on_invalid_block_name(): void {
		$this->expectException( WP_CLI_Exception::class );
		$this->expectExceptionMessageMatches( '/namespaced/' );

		$this->cli->replace( [ 'not-namespaced' ], [] );
	}

	public function test_replace_dry_run_lists_post_ids(): void {
		$this->wpdb->set_table_exists( true );
		$this->wpdb->set_get_col_sequence( [ [ '10', '20' ] ] );

		$this->cli->replace( [ 'core/paragraph' ], [ 'dry-run' => true ] );

		$this->assertSame( [ '10', '20' ], WP_CLI::$lines );
		$this->assertEmpty( WP_CLI::$success );
	}

	public function test_replace_processes_posts(): void {
		$this->wpdb->set_table_exists( true );
		$this->wpdb->set_get_col_sequence( [ [ '10', '20' ] ] );

		$this->cli->replace( [ 'core/paragraph' ], [] );

		$this->assertContains( 'Replaced dmg/read-more with core/paragraph in 2 post(s).', WP_CLI::$success );
	}
}
