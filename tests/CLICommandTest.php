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
}
