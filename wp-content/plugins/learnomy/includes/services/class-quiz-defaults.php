<?php
/**
 * Quiz Defaults — seed values for new quizzes and runtime gates.
 *
 * The admin "Quiz Settings" tab persists six toggles under the global
 * `learnomy_settings` option. Before Wave 2D those toggles were decorative:
 * the consumer code read straight from the per-quiz columns at runtime, so
 * a saved default never influenced anything.
 *
 * This class is the wiring fix:
 *
 *   1. Five of the six settings act as SEED VALUES on quiz creation. When the
 *      REST create payload omits a column, the global default is written into
 *      the new row so the next "load quiz" call reads the customer's chosen
 *      default. Existing quizzes are not touched — re-seeding on edit would
 *      silently wipe a customer's per-quiz override.
 *
 *   2. The sixth setting (`quiz_track_tabs`) is a runtime gate, not a seed.
 *      The per-attempt `tab_switches` counter previously ran no matter what
 *      the toggle was set to. We now consult the global flag inside
 *      {@see Quiz_Defaults::is_tab_tracking_enabled()} and short-circuit the
 *      REST endpoint when it's off.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

class Quiz_Defaults {

	/**
	 * Map of "Quiz Settings" admin keys to the per-quiz columns they seed.
	 *
	 * The keys stay grouped here (rather than scattered across the controller)
	 * so adding a new "default" in the admin tab is a one-line change in this
	 * class plus a UI row in `includes/admin/views/settings.php`.
	 *
	 * @return array<string,string>
	 */
	private static function field_map(): array {
		return array(
			'quiz_pass_mark'    => 'passing_score',
			'quiz_max_attempts' => 'attempt_limit',
			'quiz_randomize'    => 'shuffle_questions',
			'quiz_show_answers' => 'feedback_timing',
			'quiz_time_limit'   => 'time_limit_seconds',
		);
	}

	/**
	 * Apply admin defaults to a quiz-create payload.
	 *
	 * For every mapped setting:
	 *   - If the destination column is already present in `$data` (explicit
	 *     value from the caller) → leave it untouched. The REST caller wins
	 *     so a client that explicitly POSTs `passing_score: 60` overrides
	 *     whatever the global default is set to.
	 *   - Otherwise → read the global default, shape it for the column, and
	 *     write it into `$data`.
	 *
	 * Note this only runs on CREATE. Wiring it into update would let a stale
	 * global silently overwrite a per-quiz override on the next save round-trip.
	 *
	 * @param array<string,mixed> $data Quiz row data heading into Quiz::create().
	 * @return array<string,mixed>
	 */
	public static function apply_seed_defaults( array $data ): array {
		$settings = (array) get_option( 'learnomy_settings', array() );

		foreach ( self::field_map() as $setting_key => $column ) {
			// Caller already set this column — respect their choice. Use
			// array_key_exists so an explicit null/0/false from the request
			// is preserved against the global.
			if ( array_key_exists( $column, $data ) ) {
				continue;
			}

			// Global default not configured → nothing to seed for this column.
			if ( ! array_key_exists( $setting_key, $settings ) ) {
				continue;
			}

			$seeded = self::shape_for_column( $setting_key, $settings[ $setting_key ] );
			if ( null === $seeded && 'attempt_limit' !== $column && 'time_limit_seconds' !== $column ) {
				// shape_for_column returned null for a column that doesn't
				// allow NULL — skip rather than corrupt the row.
				continue;
			}

			$data[ $column ] = $seeded;
		}

		return $data;
	}

	/**
	 * Runtime gate for tab-switch recording.
	 *
	 * Default is enabled-on-blank so existing data keeps recording even if the
	 * customer has never visited the Quiz Settings tab. An explicit `0` from
	 * the admin form turns the recorder off across every attempt and every
	 * quiz globally — there is no per-quiz override for this in the schema.
	 */
	public static function is_tab_tracking_enabled(): bool {
		$settings = (array) get_option( 'learnomy_settings', array() );

		if ( ! array_key_exists( 'quiz_track_tabs', $settings ) ) {
			// No saved value → default behaviour is "record". Customers who
			// want the recorder off have to explicitly opt out via the form.
			return true;
		}

		return ! empty( $settings['quiz_track_tabs'] );
	}

	/**
	 * The number of tab switches after which a quiz auto-submits (proctoring).
	 * 0 = disabled, which is the default. Global setting on the Quiz Settings
	 * tab; a site can override it per quiz with the
	 * `learnomy_tab_switch_auto_submit_limit` filter. Only meaningful when tab
	 * tracking is enabled.
	 *
	 * @param object|null $quiz Optional quiz row, for a per-quiz override.
	 * @return int Non-negative limit; 0 means no auto-submit.
	 */
	public static function tab_switch_auto_submit_limit( $quiz = null ): int {
		$settings = (array) get_option( 'learnomy_settings', array() );
		$limit    = isset( $settings['quiz_tab_switch_limit'] ) ? (int) $settings['quiz_tab_switch_limit'] : 0;

		/**
		 * Filter the tab-switch auto-submit limit (0 = off), e.g. to vary it
		 * per quiz.
		 *
		 * @param int         $limit The resolved global limit.
		 * @param object|null $quiz  The quiz row, when available.
		 */
		$limit = (int) apply_filters( 'learnomy_tab_switch_auto_submit_limit', $limit, $quiz );

		return max( 0, $limit );
	}

	/**
	 * Cast the saved setting value into the shape the column expects.
	 *
	 * The admin form sanitizes inputs with `absint()` / checkbox 1-or-0 /
	 * `sanitize_text_field()`, but the destination columns each have their
	 * own range and unit constraints. The conversions are kept here so the
	 * REST controller never has to know about UI-vs-storage mismatches:
	 *
	 *   - passing_score → tinyint(3) unsigned, clamped 0–100.
	 *   - attempt_limit → int nullable; 0 stored as NULL (means "unlimited").
	 *   - shuffle_questions → tinyint(1); checkbox already 0/1.
	 *   - feedback_timing → varchar enum. The admin uses three UI options
	 *     (after_submit | after_pass | never) but the column enum has five
	 *     values. `after_pass` has no native column equivalent, so we map it
	 *     to `after_attempt` which is the closest semantic (score + pass/fail
	 *     only, no per-question reveal). `after_submit` and `never` map 1-1.
	 *   - time_limit_seconds → int nullable. Admin form is in minutes per the
	 *     "Default Time Limit (min)" label, so multiply by 60. 0 stored as
	 *     NULL (means "no limit") to match how the column is used elsewhere.
	 *
	 * @param string $setting_key Admin setting key (the `quiz_*` form name).
	 * @param mixed  $raw         Raw saved value from `learnomy_settings`.
	 * @return int|string|null Shaped value, or null if the column allows NULL.
	 */
	private static function shape_for_column( string $setting_key, $raw ) {
		switch ( $setting_key ) {
			case 'quiz_pass_mark':
				return max( 0, min( 100, (int) $raw ) );

			case 'quiz_max_attempts':
				$value = max( 0, (int) $raw );
				return $value > 0 ? $value : null;

			case 'quiz_randomize':
				return ! empty( $raw ) ? 1 : 0;

			case 'quiz_show_answers':
				$value = (string) $raw;
				// 'after_pass' is now a first-class feedback_timing value — the
				// quiz results endpoint reveals the full review only to students
				// who passed and shows score-only to everyone else, so the
				// "Only after passing" default is honoured rather than silently
				// degraded to 'after_attempt'.
				if ( in_array( $value, array( 'after_submit', 'after_pass', 'never' ), true ) ) {
					return $value;
				}
				// Fall back to the column's schema default rather than a
				// surprising in_array() miss.
				return 'after_submit';

			case 'quiz_time_limit':
				$minutes = max( 0, (int) $raw );
				return $minutes > 0 ? $minutes * 60 : null;
		}

		return null;
	}
}
