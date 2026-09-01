<?php
/**
 * Course section — groups lessons within a course into ordered chapters.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;


use function Learnomy\now;

class Section extends Model {

	/**
	 * A section is a part of its course and goes with it. Already true in code
	 * (Course_Service::delete() walks Section::get_for_course()); declared so
	 * the orphan check and the repair pass know it too.
	 *
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'course' => 'course_id',
	);


	protected const INT_COLUMNS = [
		'course_id',
		'sort_order',
	];

	protected static function table_name(): string {
		return 'sections';
	}

	public static function create( array $section ): int {
		return static::insert(
			array_merge(
				[
					'sort_order' => 0,
					'created_at' => now(),
				],
				$section
			)
		);
	}

	public static function get_for_course( int $course_id ): array {
		$rows = static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE course_id = %d ORDER BY sort_order ASC',
				$course_id
			)
		) ?: [];
		// scale C2: prime find() so per-lesson Section::find() calls hit cache.
		static::prime_find_cache( $rows );
		return $rows;
	}

	/**
	 * Delete a section AND everything the section owns, then the section row.
	 *
	 * **This method is the single answer to "what does a section contain".**
	 * Nothing else may enumerate a section's children. The bug this exists to
	 * kill (Basecamp 10132809117) was two hand-maintained cascade lists that
	 * A section is a SEPARATOR, not a container.
	 *
	 * Deleting one detaches its lessons and quizzes and leaves them in the
	 * course; it does not remove them. `section_id = NULL` is a normal,
	 * supported state everywhere else in the schema -- a lesson or quiz can
	 * belong to a course with no section at all -- so removing the divider has
	 * somewhere safe to put what it was dividing.
	 *
	 * WHY THIS IS NOT A CASCADE
	 *
	 * No instructor deleting a heading expects to lose the material under it.
	 * Reorganising a curriculum and deleting its content are different
	 * intentions and must not share a button. The old cascade destroyed every
	 * lesson in the section, and later every section-attached quiz too, taking
	 * their attempts and graded responses with them -- so a tidy-up cost a
	 * teacher their material and cost students their record.
	 *
	 * Repair migration 3_31_0 already worked this way and explained why: it
	 * re-homes orphaned quizzes to course level rather than deleting them,
	 * "because they may carry graded attempts, which is a student's record, not
	 * the plugin's to discard". The live delete disagreed with our own
	 * migration; it no longer does.
	 *
	 * Deleting the content is still possible -- it is a separate, explicit
	 * action on the lesson or quiz, where the author can see what they are
	 * removing and choose it deliberately.
	 *
	 * Every status on both queries: a draft lesson or draft quiz still belongs
	 * to the section, and skipping them would leave rows pointing at a section
	 * that no longer exists.
	 *
	 * @param int $id Section row id.
	 * @return bool True when the section row was removed.
	 */
	public static function delete( int $id ): bool {
		if ( $id <= 0 ) {
			return false;
		}

		// Resolve the owning course once so every child hook carries a complete
		// payload without one Lesson::find() per lesson.
		$section   = static::find( $id );
		$course_id = $section ? (int) $section->course_id : 0;

		// A SECTION IS A SEPARATOR, NOT A CONTAINER.
		//
		// Sections exist to organise a curriculum. A lesson or a quiz can live
		// in a course with no section at all -- that is a normal, supported
		// state, not a broken one -- so removing the divider must not remove
		// what it was dividing. Deleting a section detaches its contents and
		// leaves them in the course.
		//
		// This used to cascade: deleting a section destroyed every lesson under
		// it, and (since the quiz half was added) every quiz too. An author
		// reorganising their curriculum would lose the material, along with any
		// attempts and graded responses attached to it. Reorganising is not
		// deleting, and the two must not share a button.
		//
		// Note the repair migration 3_31_0 already worked this way and said so:
		// it re-homes orphaned quizzes to course level rather than deleting
		// them, "because they may carry graded attempts, which is a student's
		// record, not the plugin's to discard". The live delete disagreed with
		// our own migration. It no longer does.
		//
		// Deleting the CONTENT is still available -- it is just a separate,
		// explicit action on the lesson or quiz itself, where the author can
		// see what they are removing.
		foreach ( Lesson::get_for_section( $id, 0, 0, null ) as $lesson ) {
			Lesson::update( (int) $lesson->id, array( 'section_id' => null ) );
		}

		foreach ( Quiz::get_for_section( $id, null ) as $quiz ) {
			Quiz::update( (int) $quiz->id, array( 'section_id' => null ) );
		}

		return parent::delete( $id );
	}
}
