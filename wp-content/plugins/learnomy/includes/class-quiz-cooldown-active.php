<?php
/**
 * Thrown when a student tries to start a new attempt before the quiz's
 * configured cooldown window has elapsed since their last submission.
 *
 * Carries the remaining seconds so the REST layer can surface a precise
 * "try again in Xs" message on HTTP 429 responses.
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

class QuizCooldownActive extends \RuntimeException {

	private int $seconds_remaining;

	public function __construct( int $seconds_remaining, string $message = '' ) {
		$this->seconds_remaining = max( 0, $seconds_remaining );
		if ( '' === $message ) {
			$message = sprintf(
				/* translators: %d: seconds remaining before the student can retake the quiz. */
				__( 'You can retake this quiz in %d seconds.', 'learnomy' ),
				$this->seconds_remaining
			);
		}
		parent::__construct( $message );
	}

	public function get_seconds_remaining(): int {
		return $this->seconds_remaining;
	}
}
