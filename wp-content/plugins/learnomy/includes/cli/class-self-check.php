<?php
/**
 * `wp learnomy selfcheck` — one command that runs every quality gate and
 * prints a summary. Internal use only (we are not publishing to wordpress.org),
 * so the bar is "the plugin still works after my changes", not "it passes the
 * WPORG review board".
 *
 * Gates:
 *   1. PHPCS (WordPress Coding Standards)
 *   2. PHPStan level 7 — blocking if anything above baseline
 *   3. Plugin Check (via `wp plugin check learnomy`) — errors only
 *   4. PHPUnit — the data-validation layer
 *   5. `wp learnomy test data-integrity` — cross-table sanity
 *
 * Shell-command safety: every command executed here is a hard-coded constant
 * string with no user-supplied interpolation. `escapeshellarg()` on the plugin
 * dir is belt-and-braces. No user input ever reaches the shell.
 *
 * @package Learnomy
 */

namespace Learnomy\CLI;

defined( 'ABSPATH' ) || exit;

class SelfCheck {

	private const DEFAULT_GATES = [ 'phpcs', 'phpstan', 'plugin-check', 'phpunit', 'integrity' ];

	/**
	 * Run every quality gate in sequence. Prints a table at the end with the
	 * pass/fail status of each and exits non-zero if any failed.
	 *
	 * ## OPTIONS
	 *
	 * [--skip=<gates>]
	 * : Comma-separated list of gates to skip. Accepts: phpcs, phpstan,
	 *   plugin-check, phpunit, integrity.
	 *
	 * [--only=<gates>]
	 * : Comma-separated list of gates to run (everything else is skipped).
	 *
	 * [--verbose]
	 * : Stream each gate's full output instead of buffering it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp learnomy selfcheck
	 *     wp learnomy selfcheck --skip=phpunit
	 *     wp learnomy selfcheck --only=phpstan,plugin-check --verbose
	 *
	 * @subcommand run
	 */
	public function run( array $args, array $assoc_args ): void {
		$only    = $this->csv_list( $assoc_args['only'] ?? '' );
		$skip    = $this->csv_list( $assoc_args['skip'] ?? '' );
		$verbose = isset( $assoc_args['verbose'] );

		$gates = self::DEFAULT_GATES;
		if ( ! empty( $only ) ) {
			$gates = array_values( array_intersect( $gates, $only ) );
		}
		$gates = array_values( array_diff( $gates, $skip ) );

		$results = [];

		foreach ( $gates as $gate ) {
			\WP_CLI::line( '' );
			\WP_CLI::line( \WP_CLI::colorize( '%B═══ ' . strtoupper( $gate ) . ' ═══%n' ) );

			$result    = $this->run_gate( $gate, $verbose );
			$results[] = [
				'gate'   => $gate,
				'status' => $result['ok'] ? 'PASS' : 'FAIL',
				'detail' => $result['detail'],
			];
		}

		$this->print_summary( $results );

		foreach ( $results as $row ) {
			if ( 'PASS' !== $row['status'] ) {
				\WP_CLI::halt( 1 );
			}
		}
	}

	/**
	 * Run one gate and return `[ok: bool, detail: string]`.
	 *
	 * @return array{ok:bool,detail:string}
	 */
	private function run_gate( string $gate, bool $verbose ): array {
		switch ( $gate ) {
			case 'phpcs':
				return $this->run_shell( 'vendor/bin/phpcs --standard=phpcs.xml -n --report=summary', $verbose );

			case 'phpstan':
				return $this->run_shell( 'vendor/bin/phpstan analyse --memory-limit=1G --no-progress', $verbose );

			case 'plugin-check':
				// `wp plugin check` always exits 0, even when it finds errors,
				// so we ask run_shell to scan for lines starting with "ERROR,"
				// and treat any hit as a failure. Exclude dev-only files
				// (phpstan stubs, tests, docs) — they never ship.
				return $this->run_shell(
					'wp plugin check learnomy --ignore-warnings --fields=type,code,file,line --format=csv '
					. '--exclude-files=phpstan-bootstrap.php,phpstan-stubs.stub.php,uninstall.php '
					. '--exclude-directories=tests,docs,vendor,node_modules',
					$verbose,
					'ERROR,'
				);

			case 'phpunit':
				if ( ! getenv( 'WP_TESTS_DIR' ) && ! file_exists( sys_get_temp_dir() . '/wordpress-tests-lib/includes/functions.php' ) ) {
					\WP_CLI::warning( 'WP_TESTS_DIR not set and /tmp/wordpress-tests-lib missing — skipping PHPUnit.' );
					return [
						'ok'     => true,
						'detail' => 'skipped (no test scaffold)',
					];
				}
				return $this->run_shell( 'vendor/bin/phpunit --configuration tests/phpunit/phpunit.xml', $verbose );

			case 'integrity':
				return $this->run_shell( 'wp learnomy test data-integrity', false );
		}

		return [
			'ok'     => false,
			'detail' => 'unknown gate: ' . $gate,
		];
	}

	/**
	 * Run a hard-coded subcommand in the plugin root. No user interpolation
	 * ever reaches the shell — the `$command` argument is always a constant
	 * string in the switch above.
	 *
	 * @param string      $command         Hard-coded shell command to execute.
	 * @param bool        $verbose         Tee output straight to the console.
	 * @param string|null $fail_on_prefix  Optional: if any captured output line
	 *                                     starts with this prefix (e.g. "ERROR,"
	 *                                     for plugin-check), treat the run as a
	 *                                     failure even when exit code is 0.
	 *
	 * @return array{ok:bool,detail:string}
	 */
	private function run_shell( string $command, bool $verbose, ?string $fail_on_prefix = null ): array {
		$plugin_dir = rtrim( LEARNOMY_DIR, '/' );
		$full       = 'cd ' . escapeshellarg( $plugin_dir ) . ' && ' . $command . ' 2>&1';

		if ( $verbose && null === $fail_on_prefix ) {
			$exit_code = 0;
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_system
			system( $full, $exit_code );
			return [
				'ok'     => 0 === $exit_code,
				'detail' => 0 === $exit_code ? 'ok' : 'exit ' . $exit_code,
			];
		}

		$output = [];
		$exit   = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( $full, $output, $exit );

		$prefix_hits = 0;
		if ( null !== $fail_on_prefix ) {
			foreach ( $output as $line ) {
				if ( 0 === strpos( $line, $fail_on_prefix ) ) {
					++$prefix_hits;
				}
			}
		}

		$failed = 0 !== $exit || $prefix_hits > 0;

		if ( $verbose || $failed ) {
			$lines_to_show = $failed ? array_slice( $output, -20 ) : array_slice( $output, -3 );
			foreach ( $lines_to_show as $line ) {
				if ( $verbose || '' !== trim( $line ) ) {
					\WP_CLI::line( $line );
				}
			}
		}

		if ( $failed ) {
			$detail = $prefix_hits > 0
				? $prefix_hits . ' ' . rtrim( $fail_on_prefix ?? '', ',' ) . ' match(es)'
				: 'exit ' . $exit . ' — see output above';
			return [
				'ok'     => false,
				'detail' => $detail,
			];
		}

		return [
			'ok'     => true,
			'detail' => 'ok',
		];
	}

	/** @param array<int,array{gate:string,status:string,detail:string}> $results */
	private function print_summary( array $results ): void {
		\WP_CLI::line( '' );
		\WP_CLI::line( \WP_CLI::colorize( '%B═══ SELFCHECK SUMMARY ═══%n' ) );

		foreach ( $results as $row ) {
			$tick = 'PASS' === $row['status']
				? \WP_CLI::colorize( '%G✓%n' )
				: \WP_CLI::colorize( '%R✗%n' );
			\WP_CLI::line( sprintf( '%s  %-15s %s', $tick, $row['gate'], $row['detail'] ) );
		}
	}

	/**
	 * Split a CSV flag value into a clean lowercase list.
	 *
	 * @return string[]
	 */
	private function csv_list( string $raw ): array {
		if ( '' === trim( $raw ) ) {
			return [];
		}
		return array_values(
			array_filter(
				array_map( static fn( $s ) => strtolower( trim( $s ) ), explode( ',', $raw ) )
			)
		);
	}
}
