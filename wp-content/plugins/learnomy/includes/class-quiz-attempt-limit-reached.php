<?php
/**
 * Thrown when a student has used every attempt a quiz allows them.
 *
 * Separate from {@see QuizLockedOut} — that one counts FAILURES against
 * `max_failed_attempts`; this one counts attempts of any outcome against
 * `attempt_limit`, after per-user grants and the `learnomy_quiz_max_attempts`
 * filter have been applied.
 *
 * It exists so the REST layer can answer 422 with a discriminable code, the way
 * cooldown answers 429 and lockout answers 423. A plain RuntimeException fell
 * through to the generic 400 `learnomy_validation` handler, leaving the client
 * to tell "you are out of attempts" apart from "that quiz has no questions" by
 * reading message text (Basecamp 10134140122).
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

class QuizAttemptLimitReached extends \RuntimeException {

	private int $attempt_limit;

	public function __construct( int $attempt_limit, string $message = '' ) {
		$this->attempt_limit = max( 0, $attempt_limit );
		if ( '' === $message ) {
			$message = sprintf(
				/* translators: %d: number of allowed attempts */
				__( 'You have reached the maximum number of attempts (%d) for this quiz.', 'learnomy' ),
				$this->attempt_limit
			);
		}
		parent::__construct( $message );
	}

	public function get_attempt_limit(): int {
		return $this->attempt_limit;
	}
}
