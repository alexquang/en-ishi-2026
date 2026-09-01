<?php
/**
 * Thrown when a student attempts to write against a lesson that is not open to
 * them yet — the drip schedule has not released it, or a sequence lock
 * (`require_sequential` / `lock_next_until_complete`) still holds it shut.
 *
 * Deliberately NOT a {@see CompletionRulesNotMet}. That exception means "you have
 * not done the work yet" and the client renders an "X of Y requirements met"
 * toast from its payload. This one means "this lesson is not open yet", where
 * there is usually nothing the student can do today — showing them a requirements
 * checklist for a date-locked lesson invites them to look for a control that does
 * not exist. Different meaning, different HTTP status (403 vs 422), different UI.
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

class LessonNotReachable extends \RuntimeException {

	/**
	 * Why the lesson is shut. Machine-readable so a client can pick its copy.
	 *
	 * `drip`     — the unlock date/offset has not arrived.
	 * `sequence` — an earlier lesson must be completed first.
	 *
	 * @var string
	 */
	private string $reason;

	/**
	 * Lesson the caller tried to write against.
	 *
	 * @var int
	 */
	private int $lesson_id;

	/**
	 * @param int    $lesson_id Lesson that is not reachable.
	 * @param string $reason    'drip' or 'sequence'.
	 * @param string $message   Optional override for the exception message.
	 */
	public function __construct( int $lesson_id, string $reason = 'drip', string $message = '' ) {
		parent::__construct( $message ?: 'This lesson is not available yet.' );
		$this->lesson_id = $lesson_id;
		$this->reason    = $reason;
	}

	public function get_reason(): string {
		return $this->reason;
	}

	public function get_lesson_id(): int {
		return $this->lesson_id;
	}
}
