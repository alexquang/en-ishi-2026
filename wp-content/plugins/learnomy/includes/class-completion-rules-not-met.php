<?php
/**
 * Thrown when a student attempts to mark a lesson complete but the
 * lesson-level completion rules (time-on-page, video watch percent,
 * scroll-to-end, quiz pass) have not yet been satisfied.
 *
 * The REST lesson controller catches this and returns a structured 422
 * response so the client knows exactly which rules are still outstanding.
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

class CompletionRulesNotMet extends \RuntimeException {

	/**
	 * Structured completion status as returned by
	 * Progress_Service::evaluate_completion_rules().
	 *
	 * Shape:
	 *   [
	 *     'lesson_id'       => int,
	 *     'completion_mode' => string,
	 *     'requirements'    => array<array{kind:string, met:bool, ...}>,
	 *     'all_met'         => bool,
	 *     'locks_next'      => bool,
	 *     'is_completed'    => bool,
	 *   ]
	 *
	 * @var array<string,mixed>
	 */
	private array $status;

	/**
	 * @param array<string,mixed> $status Completion status payload.
	 */
	public function __construct( array $status, string $message = '' ) {
		parent::__construct( $message ?: 'Lesson completion rules have not been met.' );
		$this->status = $status;
	}

	/**
	 * Return the status array so the REST controller can put it in the 422 body.
	 *
	 * @return array<string,mixed>
	 */
	public function get_status(): array {
		return $this->status;
	}
}
