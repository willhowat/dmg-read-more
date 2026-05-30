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
}
