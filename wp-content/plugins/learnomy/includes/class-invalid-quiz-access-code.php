<?php
/**
 * Thrown when a student submits the wrong access code while starting a quiz.
 *
 * Distinct exception class so REST controllers can map to HTTP 403 with a
 * structured `invalid_access_code` error payload instead of falling back
 * to a generic validation error.
 *
 * @package Learnomy
 */

namespace Learnomy;

defined( 'ABSPATH' ) || exit;

class InvalidQuizAccessCode extends \RuntimeException {
}
