<?php
/**
 * Assertion helper used by Learnomy CLI test scenarios.
 *
 * Prints one line per assertion (green tick or red cross) and keeps a
 * running tally so a scenario can exit with a non-zero code when any
 * assertion failed — making the tests CI-friendly.
 *
 * @package Learnomy
 */

namespace Learnomy\CLI;

defined( 'ABSPATH' ) || exit;

class Assert {

	/** Running count of passed assertions since the last {@see self::reset()}. */
	private static int $passed = 0;

	/** Running count of failed assertions since the last {@see self::reset()}. */
	private static int $failed = 0;

	/**
	 * Labels of failed assertions, kept so the final summary can reprint them
	 * without the caller having to scroll back through the log.
	 *
	 * @var string[]
	 */
	private static array $failures = [];

	/**
	 * Hook counters keyed by hook name.
	 *
	 * Populated only for hooks the scenario explicitly starts listening for
	 * via {@see self::listen()} so we never double-count side effects from
	 * unrelated code running in the same request.
	 *
	 * @var array<string,int>
	 */
	private static array $hook_counts = [];

	/**
	 * Reset all counters and hook listeners. Call at the start of each
	 * scenario so two back-to-back `wp learnomy test …` invocations in the
	 * same process (e.g. `test:all`) don't bleed into each other.
	 */
	public static function reset(): void {
		self::$passed      = 0;
		self::$failed      = 0;
		self::$failures    = [];
		self::$hook_counts = [];
	}

	/**
	 * Register a listener that bumps the counter for the given hook every
	 * time it fires. Safe to call multiple times — we only attach one
	 * listener per hook per process.
	 */
	public static function listen( string $hook ): void {
		if ( isset( self::$hook_counts[ $hook ] ) ) {
			return;
		}
		self::$hook_counts[ $hook ] = 0;
		add_action(
			$hook,
			static function () use ( $hook ) {
				// Defensive coalesce — PHP 8.x emits "Undefined array key" when
				// a hook fires before listen() has registered its counter slot
				// (real-world: bootstrap-time hooks like plugins_loaded that
				// fire once before the CLI test setup attaches the listener).
				self::$hook_counts[ $hook ] = ( self::$hook_counts[ $hook ] ?? 0 ) + 1;
			},
			1,
			0
		);
	}

	public static function equals( mixed $actual, mixed $expected, string $label ): void {
		if ( $actual === $expected || ( is_numeric( $actual ) && is_numeric( $expected ) && (float) $actual === (float) $expected ) ) {
			self::pass( $label );
			return;
		}
		self::fail( $label, self::stringify( $actual ), self::stringify( $expected ) );
	}

	public static function not_equals( mixed $actual, mixed $forbidden, string $label ): void {
		if ( $actual !== $forbidden ) {
			self::pass( $label );
			return;
		}
		self::fail( $label, self::stringify( $actual ), '!= ' . self::stringify( $forbidden ) );
	}

	public static function truthy( mixed $value, string $label ): void {
		if ( $value ) {
			self::pass( $label );
			return;
		}
		self::fail( $label, self::stringify( $value ), 'truthy' );
	}

	public static function falsy( mixed $value, string $label ): void {
		if ( ! $value ) {
			self::pass( $label );
			return;
		}
		self::fail( $label, self::stringify( $value ), 'falsy' );
	}

	public static function count_is( array|\Countable $collection, int $expected, string $label ): void {
		$actual = count( $collection );
		if ( $actual === $expected ) {
			self::pass( $label );
			return;
		}
		self::fail( $label, (string) $actual, (string) $expected );
	}

	public static function gte( float|int $actual, float|int $min, string $label ): void {
		if ( $actual >= $min ) {
			self::pass( $label );
			return;
		}
		self::fail( $label, self::stringify( $actual ), '>= ' . $min );
	}

	public static function hook_fired( string $hook, int $expected = 1, string $label = '' ): void {
		$count = self::$hook_counts[ $hook ] ?? -1;
		$label = $label ?: sprintf( 'Hook %s fired %d time(s)', $hook, $expected );

		if ( -1 === $count ) {
			self::fail( $label, 'listener not registered', 'call Assert::listen() first' );
			return;
		}
		if ( $count === $expected ) {
			self::pass( $label );
			return;
		}
		self::fail( $label, (string) $count, (string) $expected );
	}

	/** @return array{passed:int,failed:int,failures:string[]} */
	public static function summary(): array {
		return [
			'passed'   => self::$passed,
			'failed'   => self::$failed,
			'failures' => self::$failures,
		];
	}

	public static function has_failures(): bool {
		return self::$failed > 0;
	}

	/**
	 * Print a section header so long scenarios stay readable in a terminal.
	 */
	public static function section( string $title ): void {
		\WP_CLI::line( '' );
		\WP_CLI::line( '── ' . $title . ' ' . str_repeat( '─', max( 3, 60 - strlen( $title ) ) ) );
	}

	/**
	 * Print the end-of-scenario summary. Returns the exit code the caller
	 * should pass to `WP_CLI::halt()` (0 on success, 1 on any failure).
	 */
	public static function print_summary(): int {
		$summary = self::summary();

		\WP_CLI::line( '' );
		\WP_CLI::line( sprintf( 'Passed: %d   Failed: %d', $summary['passed'], $summary['failed'] ) );

		if ( $summary['failed'] > 0 ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( 'Failures:' );
			foreach ( $summary['failures'] as $line ) {
				\WP_CLI::line( '  - ' . $line );
			}
			return 1;
		}

		return 0;
	}

	private static function pass( string $label ): void {
		++self::$passed;
		\WP_CLI::line( \WP_CLI::colorize( '%G✓%n ' ) . $label );
	}

	private static function fail( string $label, string $actual, string $expected ): void {
		++self::$failed;
		$line             = sprintf( '%s  (expected=%s, actual=%s)', $label, $expected, $actual );
		self::$failures[] = $line;
		\WP_CLI::line( \WP_CLI::colorize( '%R✗%n ' ) . $line );
	}

	private static function stringify( mixed $value ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'true' : 'false';
		}
		if ( null === $value ) {
			return 'null';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_object( $value ) && isset( $value->id ) ) {
			return get_class( $value ) . '#' . $value->id;
		}
		$encoded = wp_json_encode( $value );
		if ( false === $encoded ) {
			return gettype( $value );
		}
		return strlen( $encoded ) > 120 ? substr( $encoded, 0, 117 ) . '…' : $encoded;
	}
}
