<?php
defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__ ) . '/' );

// WordPress helper stubs required by the CLI class.

function size_format( int $bytes ): string {
	return $bytes . ' B';
}

function wp_cache_flush(): void {}

function sanitize_key( string $key ): string {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
}

/**
 * Thrown by the WP_CLI stub instead of calling exit, so tests can catch it.
 */
class WP_CLI_Exception extends RuntimeException {}

/**
 * Minimal WP_CLI stub that captures output and throws on error().
 */
class WP_CLI {
	/** @var string[] */
	public static array $lines = [];

	/** @var string[] */
	public static array $success = [];

	/** @var string[] */
	public static array $errors = [];

	public static function reset(): void {
		self::$lines   = [];
		self::$success = [];
		self::$errors  = [];
	}

	public static function line( string $message ): void {
		self::$lines[] = $message;
	}

	public static function success( string $message ): void {
		self::$success[] = $message;
	}

	/** Throws instead of calling exit so assertions can follow in the test. */
	public static function error( string $message ): never {
		self::$errors[] = $message;
		throw new WP_CLI_Exception( $message );
	}

	public static function debug( string $message, string $group = '' ): void {}

	public static function log( string $message ): void {}
}

/**
 * Configurable wpdb stub.
 *
 * Set up query returns via set_table_exists(), set_get_col_sequence(),
 * and set_col_error() before calling the method under test.
 */
class Wpdb_Stub {
	public string $prefix        = 'wp_';
	public string $posts         = 'wp_posts';
	public string $last_error    = '';
	public int    $rows_affected = 0;

	private mixed $get_var_return    = null;
	private array $get_col_sequence  = [];
	private bool  $col_error         = false;

	/** Make table_exists() return true or false. */
	public function set_table_exists( bool $exists ): void {
		$this->get_var_return = $exists ? ( $this->prefix . 'dmg_read_more_index' ) : null;
	}

	/**
	 * Supply an ordered list of arrays that get_col() should return on successive calls.
	 * Once the sequence is exhausted, get_col() returns [].
	 *
	 * @param array<array<string>> $sequence
	 */
	public function set_get_col_sequence( array $sequence ): void {
		$this->get_col_sequence = $sequence;
	}

	/** Make get_col() simulate a DB error on the next call. */
	public function set_col_error( bool $error ): void {
		$this->col_error = $error;
	}

	public function get_var( string $query ): mixed {
		return $this->get_var_return;
	}

	public function get_col( string $query ): array {
		if ( $this->col_error ) {
			$this->last_error = 'MySQL error: Table not found';
			return [];
		}
		if ( ! empty( $this->get_col_sequence ) ) {
			return array_shift( $this->get_col_sequence );
		}
		return [];
	}

	public function prepare( string $query, mixed ...$args ): string {
		return $query;
	}

	public function query( string $sql ): void {}

	public function flush(): void {}

	public function esc_like( string $text ): string {
		return $text;
	}
}

require_once dirname( __DIR__ ) . '/includes/class-cli-command.php';
