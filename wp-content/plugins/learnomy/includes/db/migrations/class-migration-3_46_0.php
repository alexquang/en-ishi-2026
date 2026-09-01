<?php
/**
 * Migration 3.46.0 — repair tokenless fill-in-the-blank questions.
 *
 * A fill_blank question renders inputs only when its `content` carries
 * `{{blankN}}` tokens and each accepted answer's `group_id` equals a token key.
 * Two demo seeders wrote neither: the blank lived as `____` in the title,
 * `content` was empty and answers carried group_id '1' — so the player drew
 * ZERO inputs, the student could not answer, and the pre-submit guard reported
 * the question as unanswered (Basecamp 10171508719).
 *
 * The save seam now derives tokens on every write (Question::normalise_fill_blank)
 * and both seeders author real token bodies, but rows written before the fix
 * are still broken on every site that seeded a demo academy. This repairs them
 * in place:
 *
 * 1. For each fill_blank row whose content has no token: derive the body from
 *    wherever the underscores are (content first, else title), converting each
 *    run of 3+ underscores to {{blank1}}, {{blank2}}, … — the same rule as the
 *    save seam, so repaired rows and newly-saved rows are indistinguishable.
 * 2. Normalise that question's answer group_ids onto the token keys: numeric
 *    '1'/'2'/… map positionally to blank1/blank2; ''/'0' map to blank1 on a
 *    single-blank question. Group_ids already matching a token are untouched.
 * 3. A row with no tokens and no underscores anywhere cannot be repaired
 *    without inventing where the blank goes — it is unpublished (status draft)
 *    instead, so it stops costing students marks and surfaces to the author.
 *
 * Forward-only and idempotent: pass 1's WHERE excludes rows that already carry
 * tokens, so a re-run finds nothing to touch; pass 3 only demotes rows pass 1
 * could not fix.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_46_0 {

	public function up(): void {
		global $wpdb;

		$questions = $wpdb->prefix . 'lrn_questions';
		$answers   = $wpdb->prefix . 'lrn_question_answers';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$broken = $wpdb->get_results(
			"SELECT id, title, content FROM `{$questions}`
			 WHERE type = 'fill_blank'
			   AND ( content IS NULL OR content NOT REGEXP '\\\\{\\\\{[[:space:]]*blank[0-9]+[[:space:]]*\\\\}\\\\}' )"
		);

		foreach ( $broken as $row ) {
			$body = (string) ( $row->content ?? '' );
			if ( ! preg_match( '/_{3,}/', $body ) ) {
				$body = preg_match( '/_{3,}/', (string) $row->title ) ? (string) $row->title : '';
			}

			if ( '' === $body ) {
				// Unrepairable: no tokens, no underscores. Unpublish rather than
				// guess — an invented blank position would grade real students.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $questions, [ 'status' => 'draft' ], [ 'id' => (int) $row->id ] );
				continue;
			}

			$n         = 0;
			$converted = preg_replace_callback(
				'/_{3,}/',
				static function () use ( &$n ) {
					++$n;
					return '{{blank' . $n . '}}';
				},
				$body
			);

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update( $questions, [ 'content' => $converted ], [ 'id' => (int) $row->id ] );

			// Re-key this question's answers onto the token keys. Positional:
			// group '1' → blank1, '2' → blank2; ''/'0' → blank1 when there is
			// exactly one blank (the only unambiguous reading).
			for ( $k = 1; $k <= $n; $k++ ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$answers,
					[ 'group_id' => 'blank' . $k ],
					[
						'question_id' => (int) $row->id,
						'group_id'    => (string) $k,
					]
				);
			}
			if ( 1 === $n ) {
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->query(
					$wpdb->prepare(
						"UPDATE `{$answers}` SET group_id = 'blank1'
						 WHERE question_id = %d AND ( group_id IS NULL OR group_id IN ( '', '0' ) )",
						(int) $row->id
					)
				);
			}
		}

		// Model reads key off the group incrementor (Model::invalidate_cache);
		// these raw writes bypass the models, so bump both groups by hand or
		// repaired rows keep serving from cache.
		wp_cache_set_last_changed( 'learnomy_questions' );
		wp_cache_set_last_changed( 'learnomy_question_answers' );
	}
}
