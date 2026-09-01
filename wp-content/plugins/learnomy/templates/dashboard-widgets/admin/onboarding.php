<?php
/**
 * Admin dashboard — Getting Started onboarding panel widget.
 *
 * Locals: $view_data containing $onboarding_resolved, $demo_seeded, $user
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;

// Match the variable names the existing partial expects so we can include
// it verbatim. The original dashboard view computed these inline; this
// widget hosts the same partial under the registry shell.
$resolved    = $onboarding_resolved ?? \Learnomy\Admin\Onboarding_State::resolve();
$dismissed   = (bool) ( $onboarding_dismissed ?? false );
$demo_seeded = (bool) ( $demo_seeded ?? false );
$user        = $user ?? wp_get_current_user();

require LEARNOMY_DIR . 'includes/admin/views/partials/onboarding-guide.php';
