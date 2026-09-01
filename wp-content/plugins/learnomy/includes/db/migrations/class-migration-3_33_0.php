<?php
/**
 * Migration 3.33.0 — record on every certificate what it actually certifies.
 *
 * `lrn_certificates` stored `user_id`, `course_id`, `template_id`,
 * `enrollment_id`, a signature and some dates, and NOTHING about what was
 * achieved. Every surface — the PDF, the REST verify payload, the public verify
 * page — resolved the course title live, so deleting a course gutted every
 * credential ever issued for it: "has successfully completed" followed by
 * nothing on a document a learner hands to an employer (Basecamp 10148480574).
 * Renaming was quieter and worse: retitling a course silently rewrote what every
 * past certificate claimed, and nothing looked broken.
 *
 * Forward, `Certificate_Service::issue()` now writes the asserted facts onto the
 * row. This migration adds the columns and repairs the certificates that were
 * issued before it existed.
 *
 * WHAT IS BACKFILLED, AND FROM WHERE
 *
 * 1. Course still exists — the title, the author's display name and the course
 *    duration come straight off the live rows. Exactly what those certificates
 *    render today, so the backfill changes nothing a learner can see; it just
 *    stops the value drifting from tomorrow on.
 *
 * 2. Course is GONE — the title is not recoverable from `lrn_courses`, and
 *    `lrn_audit_log` turned out to hold nothing usable (course deletion is not
 *    logged there; verified on a database with three orphaned certificates and
 *    zero matching audit rows). `lrn_notifications` DOES retain it: the
 *    "You completed X" / "You are enrolled in X" rows quote the course title in
 *    their message and are keyed by the same `object_id`. That is a real record
 *    written by us at the time the student was studying, so it is evidence, not
 *    a guess — and it recovered all three orphans on the reference database.
 *
 *    It is still a parse of a translated string, so it is fenced hard: only for
 *    certificates whose course row is missing (it can never override a live
 *    title), only from `object_type = 'course'` rows for that exact course id,
 *    only when the message contains a quoted run, and only when every candidate
 *    notification agrees on the answer. Anything less certain is left NULL —
 *    a blank we can explain beats a title we invented.
 *
 * 3. Recipient and issuer names are stamped from the live user and the live
 *    `blogname`. Those are today's values, not the values at issue — we have no
 *    record of the old ones — but they are precisely what every surface already
 *    prints for these rows, so freezing them cannot make an existing certificate
 *    less true, and it stops the next rename rewriting them.
 *
 * 4. `score_snapshot` is deliberately NOT backfilled. It is derived from the
 *    student's best graded attempt, which still exists, so leaving it NULL keeps
 *    these certificates rendering exactly as they do today (the reader falls back
 *    to the live lookup when the snapshot is NULL) without running the migration's
 *    most expensive query over every attempt in the table. New certificates
 *    capture it at issue, which is where the drift actually starts.
 *
 * The signature is untouched, and deliberately so: it is computed over
 * `uuid|user_id|course_id`, none of which this migration writes, so every
 * existing certificate verifies exactly as before. Re-signing rows to cover the
 * new columns would mean minting a fresh signature over data we cannot prove was
 * ever authentic, which is the opposite of what a signature is for.
 *
 * Forward-only and idempotent: columns are added only when missing, and every
 * backfill statement matches only rows whose snapshot is still NULL.
 *
 * @package Learnomy
 */

namespace Learnomy\DB\Migrations;

defined( 'ABSPATH' ) || exit;

class Migration_3_33_0 {

	/** Rows repaired per statement, so a long history never locks the table. */
	private const BATCH_SIZE = 500;

	/** Safety stop, so a pathological data set can never spin here forever. */
	private const MAX_BATCHES = 2000;

	public function up(): void {
		$this->add_snapshot_columns();
		$this->backfill_from_live_course();
		$this->backfill_recipient_and_issuer();
		$this->recover_titles_from_notifications();

		// Every row in the table just changed shape. On a persistent object cache
		// a certificate read cached earlier in this request (or by a warm Redis)
		// would still be the pre-snapshot copy, which is exactly the stale-read
		// class coding-rules Rule 14 exists to prevent — bump the model's group
		// incrementor so those entries orphan.
		wp_cache_set_last_changed( 'learnomy_certificates' );
	}

	// ── Schema ────────────────────────────────────────────────────────────

	/**
	 * Add the snapshot columns when they are missing.
	 *
	 * One ALTER for all six: the table is rebuilt once instead of six times,
	 * which matters on the installs that have issued a lot of certificates.
	 */
	private function add_snapshot_columns(): void {
		$table = $this->db()->prefix . 'lrn_certificates';

		$columns = array(
			'course_title_snapshot'    => "varchar(255) DEFAULT NULL COMMENT 'Course title as it read when the certificate was issued.'",
			'recipient_name_snapshot'  => "varchar(255) DEFAULT NULL COMMENT 'Recipient name as printed on the certificate.'",
			'issuer_name_snapshot'     => "varchar(255) DEFAULT NULL COMMENT 'Issuing academy/site name at issue time.'",
			'instructor_name_snapshot' => "varchar(255) DEFAULT NULL COMMENT 'Instructor named on the certificate at issue time.'",
			'score_snapshot'           => "decimal(5,2) DEFAULT NULL COMMENT 'Best graded percentage at issue time, when the design shows one.'",
			'hours_snapshot'           => "decimal(7,2) DEFAULT NULL COMMENT 'Course length in hours at issue time, when the design shows one.'",
		);

		$missing = array();
		foreach ( $columns as $column => $definition ) {
			if ( ! $this->column_exists( $table, $column ) ) {
				$missing[] = "ADD COLUMN `{$column}` {$definition}";
			}
		}

		if ( empty( $missing ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$this->db()->query( "ALTER TABLE `{$table}` " . implode( ', ', $missing ) );
	}

	// ── Backfill ──────────────────────────────────────────────────────────

	/**
	 * Stamp the course-derived facts onto every certificate whose course still
	 * resolves: the title, the author's display name, and the course length in
	 * hours (stored in seconds, printed as hours by the `{hours}` merge tag).
	 *
	 * Collected by id then written by id list rather than run as one
	 * `UPDATE ... JOIN`: migrations run on a front-end request during upgrade,
	 * and the join form would hold locks across the whole certificates table for
	 * its duration.
	 */
	private function backfill_from_live_course(): void {
		$certificates = $this->db()->prefix . 'lrn_certificates';
		$courses      = $this->db()->prefix . 'lrn_courses';
		$users        = $this->db()->users;

		$batches = 0;
		$after   = 0;

		do {
			// Walked by an id cursor, not by "rows still NULL": a course whose
			// title is genuinely empty stays NULL after its pass, and a WHERE-only
			// loop would keep re-selecting it until MAX_BATCHES ran out.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $this->db()->get_results(
				$this->db()->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
					"SELECT c.id, co.title, co.duration_seconds, u.display_name AS instructor
					   FROM `{$certificates}` c
					   INNER JOIN `{$courses}` co ON co.id = c.course_id
					   LEFT JOIN `{$users}` u ON u.ID = co.author_id
					  WHERE c.course_title_snapshot IS NULL AND c.id > %d
					  ORDER BY c.id ASC
					  LIMIT %d",
					$after,
					self::BATCH_SIZE
				)
			);

			$found = count( (array) $rows );
			if ( 0 === $found ) {
				return;
			}

			foreach ( $rows as $row ) {
				$after = (int) $row->id;
				// A course with no title at all leaves the snapshot NULL rather
				// than recording an empty string as if it were the answer — the
				// notification recovery below then gets its turn.
				$title = $this->clip( (string) $row->title );

				$this->db()->update(
					$certificates,
					array(
						'course_title_snapshot'    => '' !== $title ? $title : null,
						'instructor_name_snapshot' => '' !== (string) $row->instructor ? $this->clip( (string) $row->instructor ) : null,
						'hours_snapshot'           => $row->duration_seconds > 0 ? round( (int) $row->duration_seconds / HOUR_IN_SECONDS, 2 ) : null,
					),
					array( 'id' => (int) $row->id ),
					array( '%s', '%s', '%f' ),
					array( '%d' )
				);
			}

			++$batches;
		} while ( $found === self::BATCH_SIZE && $batches < self::MAX_BATCHES );
	}

	/**
	 * Stamp the recipient's name and the issuing academy on every certificate
	 * that still lacks them.
	 *
	 * Runs for ALL rows, including the ones whose course is gone — a credential
	 * that cannot name its course can at least keep naming its holder and its
	 * issuer, which is most of what an employer reads.
	 */
	private function backfill_recipient_and_issuer(): void {
		$certificates = $this->db()->prefix . 'lrn_certificates';
		$users        = $this->db()->users;
		$issuer       = $this->clip( (string) get_bloginfo( 'name' ) );

		$batches = 0;
		$after   = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $this->db()->get_results(
				$this->db()->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names only.
					"SELECT c.id, u.display_name
					   FROM `{$certificates}` c
					   LEFT JOIN `{$users}` u ON u.ID = c.user_id
					  WHERE c.issuer_name_snapshot IS NULL AND c.id > %d
					  ORDER BY c.id ASC
					  LIMIT %d",
					$after,
					self::BATCH_SIZE
				)
			);

			$found = count( (array) $rows );
			if ( 0 === $found ) {
				return;
			}

			foreach ( $rows as $row ) {
				$after  = (int) $row->id;
				$data   = array( 'issuer_name_snapshot' => $issuer );
				$format = array( '%s' );

				// A deleted or anonymized holder leaves the name NULL rather than
				// inventing a placeholder — and anonymized rows must stay nameless.
				if ( '' !== (string) $row->display_name ) {
					$data['recipient_name_snapshot'] = $this->clip( (string) $row->display_name );
					$format[]                        = '%s';
				}

				$this->db()->update( $certificates, $data, array( 'id' => (int) $row->id ), $format, array( '%d' ) );
			}

			++$batches;
		} while ( $found === self::BATCH_SIZE && $batches < self::MAX_BATCHES );
	}

	/**
	 * Last-resort title recovery for certificates whose course row is gone.
	 *
	 * See the file docblock for why `lrn_notifications` is trustworthy evidence
	 * here and `lrn_audit_log` is not. Only runs for rows this migration could
	 * not fill from a live course, so it can never contradict a real title.
	 */
	private function recover_titles_from_notifications(): void {
		$certificates = $this->db()->prefix . 'lrn_certificates';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$orphan_course_ids = $this->db()->get_col(
			"SELECT DISTINCT course_id FROM `{$certificates}`
			  WHERE course_title_snapshot IS NULL AND course_id > 0
			  LIMIT " . ( self::BATCH_SIZE * 4 )
		);

		foreach ( (array) $orphan_course_ids as $course_id ) {
			$title = $this->title_from_notifications( (int) $course_id );
			if ( null === $title ) {
				continue;
			}

			$this->db()->update(
				$certificates,
				array( 'course_title_snapshot' => $title ),
				array(
					'course_id'             => (int) $course_id,
					'course_title_snapshot' => null,
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
		}
	}

	/**
	 * The course title quoted inside the notifications we wrote about a course,
	 * or null when the evidence is missing or inconsistent.
	 *
	 * Notification messages are built from our own `"%s"` templates, so the title
	 * sits between the first and the last straight double quote — taking the
	 * outermost pair rather than the first pair means a title that itself
	 * contains a quote survives intact. Every candidate row must agree: if two
	 * notifications for the same course quote different names, we do not know
	 * which one the certificate was for and say nothing.
	 */
	private function title_from_notifications( int $course_id ): ?string {
		$notifications = $this->db()->prefix . 'lrn_notifications';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$messages = $this->db()->get_col(
			$this->db()->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name only.
				"SELECT message FROM `{$notifications}`
				  WHERE object_type = 'course' AND object_id = %d AND message LIKE %s
				  ORDER BY id ASC LIMIT 50",
				$course_id,
				'%"%"%'
			)
		);

		$candidate = null;

		foreach ( (array) $messages as $message ) {
			$first = strpos( (string) $message, '"' );
			$last  = strrpos( (string) $message, '"' );
			if ( false === $first || false === $last || $last <= $first + 1 ) {
				continue;
			}

			$title = trim( substr( (string) $message, $first + 1, $last - $first - 1 ) );
			if ( '' === $title || str_contains( $title, '"' ) ) {
				continue;
			}

			if ( null === $candidate ) {
				$candidate = $title;
				continue;
			}

			if ( $candidate !== $title ) {
				return null; // Inconsistent evidence — say nothing.
			}
		}

		return null === $candidate ? null : $this->clip( $candidate );
	}

	// ── Helpers ───────────────────────────────────────────────────────────

	private function db(): \wpdb {
		global $wpdb;
		return $wpdb;
	}

	/** Fit a value to the varchar(255) the snapshot columns declare. */
	private function clip( string $value ): string {
		$value = trim( $value );
		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 255 ) : substr( $value, 0, 255 );
	}

	private function column_exists( string $table, string $column ): bool {
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $this->db()->get_var(
			$this->db()->prepare(
				'SELECT COUNT(*) FROM information_schema.COLUMNS
				 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				$column
			)
		) > 0;
	}
}
