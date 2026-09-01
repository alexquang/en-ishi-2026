<?php
/**
 * Question types registry — canonical list of all supported question types.
 *
 * Single source of truth for question type slugs across admin forms, REST
 * whitelists, scoring services, and the Pro advanced-quizzes extension. Third
 * parties register additional types via the `learnomy_question_types` filter.
 *
 * Free ships only the types that Free's auto-grader at
 * {@see Quiz_Service::auto_grade()} (`class-quiz-service.php:280` whitelist)
 * actually scores. `ordering` and `matching` are NOT in Free's default list:
 * Free has no grader for them — they would silently score 0 in any attempt
 * authored against a Free-only site. Pro's `advanced-quizzes` extension
 * re-adds both types via the `learnomy_question_types` filter AND hooks
 * `learnomy_quiz_auto_grade_result` (also in `Quiz_Service::auto_grade()`,
 * `class-quiz-service.php:313`) to score them correctly when Pro is active.
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

final class QuestionTypes {

	/**
	 * Canonical type map: slug => translated label.
	 *
	 * The array order determines the order they appear in admin dropdowns.
	 * Filter via `learnomy_question_types` to add/remove/relabel entries.
	 *
	 * @return array<string,string>
	 */
	public static function all(): array {
		// Free defaults — every entry here is auto-gradeable by Free's
		// Quiz_Service. `ordering` + `matching` are intentionally absent:
		// Pro's advanced-quizzes extension appends them via the filter below
		// AND hooks `learnomy_quiz_auto_grade_result` to score them. Adding
		// them here would let Free authors create un-gradeable questions
		// that silently score 0 on Free-only sites.
		$types = [
			'mc_single'    => __( 'Multiple choice (single correct)', 'learnomy' ),
			'mc_multi'     => __( 'Multiple choice (multiple correct)', 'learnomy' ),
			'true_false'   => __( 'True / False', 'learnomy' ),
			'short_answer' => __( 'Short answer', 'learnomy' ),
			'fill_blank'   => __( 'Fill in the blank', 'learnomy' ),
			'essay'        => __( 'Essay', 'learnomy' ),
		];

		/**
		 * Filters the registered question types.
		 *
		 * Extensions register new question types by appending to the map.
		 * Removing core types via this filter is supported but discouraged —
		 * historical attempt responses may reference types that are no longer
		 * in the registry, which would hide them in the admin UI.
		 *
		 * Pro's `advanced-quizzes` extension (in `learnomy-pro`) uses this
		 * filter to re-add `ordering` and `matching` when active, and pairs
		 * it with a `learnomy_quiz_auto_grade_result` listener that grades
		 * the two extra types. Free intentionally ships without them — see
		 * the class docblock above for the full rationale.
		 *
		 * @param array<string,string> $types Map of slug => human-readable label.
		 */
		return apply_filters( 'learnomy_question_types', $types );
	}

	/**
	 * Flat list of registered question type slugs.
	 *
	 * Used for REST `enum` validation and `in_array()` whitelists.
	 *
	 * @return array<int,string>
	 */
	public static function slugs(): array {
		return array_keys( self::all() );
	}

	/**
	 * Map legacy REST aliases onto canonical admin slugs.
	 *
	 * Early REST clients shipped with `multiple_choice` / `multiple_select`.
	 * The database column stores `mc_single` / `mc_multi`. This helper keeps
	 * the old clients working while the storage format remains canonical.
	 * Unknown inputs pass through unchanged so validation can reject them.
	 *
	 * @param string $input Type slug received over the wire.
	 * @return string       Canonical slug.
	 */
	public static function normalize( string $input ): string {
		$aliases = [
			'multiple_choice' => 'mc_single',
			'multiple_select' => 'mc_multi',
		];

		return $aliases[ $input ] ?? $input;
	}

	/**
	 * Types whose stored response IS the learner's own text.
	 *
	 * Every other registered type stores REFERENCES to `lrn_question_answers`
	 * rows instead — an option id, or a list of them. That distinction decides
	 * whether a surface may resolve a stored value through the question's
	 * option map: doing it to a text answer would rewrite what the learner
	 * actually typed if their words happened to look like an id.
	 *
	 * @return array<int,string>
	 */
	public static function text_response_types(): array {
		/**
		 * Filters the types whose response is free text rather than option ids.
		 *
		 * A type registered through `learnomy_question_types` is treated as
		 * option-referencing unless it is listed here. That default is chosen
		 * deliberately: Pro's `ordering` and `matching` both store answer-row
		 * ids, so a new type is far more likely to reference options than to
		 * carry text, and the failure mode of the wrong default is visible
		 * (ids on screen) rather than silent (text quietly rewritten).
		 *
		 * @param array<int,string> $types Type slugs storing free text.
		 */
		return apply_filters(
			'learnomy_text_response_types',
			[ 'essay', 'short_answer', 'fill_blank' ]
		);
	}

	/**
	 * Does this type store references to answer rows rather than typed text?
	 *
	 * @param string $type Question type slug.
	 */
	public static function selects_options( string $type ): bool {
		return ! in_array( self::normalize( $type ), self::text_response_types(), true );
	}
}
