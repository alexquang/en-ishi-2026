<?php
/**
 * Migration 3.32.0 — backfill the FULLTEXT text projections that no write path
 * outside one REST controller ever filled in.
 *
 * `lrn_courses.description_plain` and `lrn_lessons.content_plain` are the
 * tag-stripped halves of the `ft_title_description` / `ft_title_content` FULLTEXT
 * indexes — the columns catalog and lesson search actually match against. Both
 * were derived at individual SAVE SITES rather than in the model, so every write
 * path that wasn't one of those sites stored an empty projection: courses from the
 * demo seeder, WP-CLI, the importer and the LearnDash migrator were invisible to
 * description search, and a lesson body stopped being re-indexed the moment the
 * lesson was edited through anything other than the lessons REST controller
 * (Basecamp 10134139389).
 *
 * The derivation moved into the model layer
 * ({@see \Learnomy\Models\Model::search_projections()}), so from here on every
 * writer maintains both columns. That fix is forward-only: it repairs the next
 * write, not the rows already stored. A site upgrading with a catalogue of
 * unindexed courses would keep answering "no courses match your filters" for words
 * that are plainly in the description until somebody happened to re-save each one,
 * which is the entire reported symptom. So this repairs the stored projections.
 *
 * Why recomputing is safe here, where migration 3.28.0 deliberately declined to
 * recompute historical quiz scores: a projection is not a fact about a person. It
 * is a lossy derived copy of a column that is still sitting right there, and the
 * only thing that reads it is the search index. Recomputing cannot change what a
 * student was told, what an owner priced, or what a report shows — the worst case
 * is that a row becomes findable by words it already contains.
 *
 * Scope:
 *
 * - Only rows whose projection is EMPTY and whose source is not. A row with a
 *   populated projection is left alone even where the derivation would now produce
 *   slightly different text (block-boundary spacing); rewriting correct rows would
 *   touch every course and lesson on the site to fix nothing.
 * - Rows with no source content have nothing to derive and are skipped, so they
 *   stay empty rather than being stamped with an empty string.
 * - `lrn_quizzes.description` is deliberately NOT included: that table has no
 *   projection column and no FULLTEXT index (Content_Migrator::direct_fields()).
 *
 * Batched by an id cursor so a large catalogue never loads in one pass, and capped
 * per request so the inline `plugins_loaded` run cannot blow a front-end request on
 * a six-figure lesson table. Forward-only and idempotent — a re-run matches no rows
 * an earlier pass already filled, which is also how the remainder gets picked up on
 * a later request.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_32_0 {

	/** Rows read + rewritten per pass. Each row costs one render plus one UPDATE. */
	private const BATCH_SIZE = 200;

	/**
	 * Ceiling on rows repaired per column, per request.
	 *
	 * The migrator runs inline on `plugins_loaded`, so an unbounded backfill on a
	 * six-figure lesson table would blow the upgrade request. The DB version is
	 * stamped once this returns either way; anything left over is repaired by the
	 * next save of that row, so search degrades gracefully for the remainder
	 * instead of the upgrade timing out.
	 */
	private const MAX_ROWS = 20000;

	public function up(): void {
		global $wpdb;

		$this->backfill( $wpdb->prefix . 'lrn_courses', 'description', 'description_plain' );
		$this->backfill( $wpdb->prefix . 'lrn_lessons', 'content', 'content_plain' );
	}

	/**
	 * Fill one table's plain projection from its rich source column.
	 *
	 * Walks forward on an id cursor rather than OFFSET: the WHERE clause stops
	 * matching the rows this loop just fixed, so paging by offset would skip rows.
	 *
	 * @param string $table  Fully-prefixed table name.
	 * @param string $source Rich column (Editor.js JSON or canonical HTML).
	 * @param string $target Plain FULLTEXT projection column.
	 */
	private function backfill( string $table, string $source, string $target ): void {
		global $wpdb;

		$after   = 0;
		$scanned = 0;

		do {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- identifiers come from up() above, never from input.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT `id`, `{$source}` AS source FROM `{$table}`
					  WHERE `id` > %d
					    AND `{$source}` IS NOT NULL AND `{$source}` <> ''
					    AND ( `{$target}` IS NULL OR `{$target}` = '' )
					  ORDER BY `id` ASC
					  LIMIT %d",
					$after,
					self::BATCH_SIZE
				)
			);

			if ( empty( $rows ) ) {
				return;
			}

			foreach ( $rows as $row ) {
				$after = (int) $row->id;
				++$scanned;

				$plain = \Learnomy\search_text_projection( (string) $row->source );
				if ( '' === $plain ) {
					// The source is markup with no words in it (an image-only or
					// embed-only body). There is nothing to index; leave the column
					// empty rather than writing a value that only makes the row look
					// repaired.
					continue;
				}

				$wpdb->update( $table, array( $target => $plain ), array( 'id' => (int) $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			}
		} while ( $scanned < self::MAX_ROWS );
	}
}
