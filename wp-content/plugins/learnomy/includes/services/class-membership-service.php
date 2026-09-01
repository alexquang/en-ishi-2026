<?php
/**
 * Backward-compatibility shim for `Membership_Service` → `Subscription_Service`.
 *
 * Renamed in PR-0 (2026-05-18). The autoloader needs a file at the
 * original `class-membership-service.php` path so that any extension
 * still calling `new Membership_Service()` resolves to the alias
 * registered by `class-subscription-service.php`.
 *
 * Remove on v1.2 alongside dropping the `class_alias()` call.
 *
 * @package Learnomy
 *
 * @deprecated since 1.1 — use \Learnomy\Services\Subscription_Service.
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-subscription-service.php';
