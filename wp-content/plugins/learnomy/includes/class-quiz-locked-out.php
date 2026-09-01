<?php
/**
 * Thrown when a student has reached the per-quiz failed-attempt ceiling.
 *
 * Separate from the "attempt_limit" cap — that applies to total attempts
 * regardless of outcome. This one only fires once the student has failed
 * `max_failed_attempts` times and is locked out until an admin reset.
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

class QuizLockedOut extends \RuntimeException {

	private int $failed_attempts;

	public function __construct( int $failed_attempts, string $message = '' ) {
		$this->failed_attempts = max( 0, $failed_attempts );
		if ( '' === $message ) {
			$message = __( 'You have exhausted the allowed failed attempts for this quiz. Please contact your instructor.', 'learnomy' );
		}
		parent::__construct( $message );
	}

	public function get_failed_attempts(): int {
		return $this->failed_attempts;
	}
}
