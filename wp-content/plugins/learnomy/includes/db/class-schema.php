<?php
/**
 * Database schema.
 *
 * @package Learnomy
 */

namespace Learnomy\DB;

defined( 'ABSPATH' ) || exit;

class Schema {

	public static function create_tables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix;

		$sqls = self::get_table_definitions( $p, $charset_collate );

		// FULLTEXT indexes are not supported on temporary InnoDB tables
		// (used by the WP test suite). Strip them when running tests.
		$is_temp = defined( 'WP_TESTS_TABLE_PREFIX' ) || defined( 'WP_TESTS_DOMAIN' );

		foreach ( $sqls as $sql ) {
			if ( $is_temp ) {
				$sql = preg_replace( '/,\s*FULLTEXT KEY[^\n]+/i', '', $sql );
			}
			dbDelta( $sql );
		}
	}

	public static function drop_tables(): void {
		global $wpdb;

		$tables = array_reverse( self::get_table_names() );

		foreach ( $tables as $table ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "DROP TABLE IF EXISTS `{$wpdb->prefix}{$table}`" );
		}
	}

	public static function get_table_names(): array {
		return [
			// Course (6).
			'lrn_courses',
			'lrn_course_instructors',
			'lrn_categories',
			'lrn_sections',
			'lrn_lessons',
			'lrn_lesson_attachments',
			'lrn_lesson_unlocks',
			'lrn_enrollments',
			// Taxonomy (2).
			'lrn_tags',
			'lrn_tag_map',
			// Quiz (7).
			'lrn_quizzes',
			'lrn_quiz_slots',
			'lrn_questions',
			'lrn_question_answers',
			'lrn_question_categories',
			'lrn_quiz_attempts',
			'lrn_question_responses',
			// Student (1).
			'lrn_progress',
			// Certificates (2).
			'lrn_certificates',
			'lrn_certificate_templates',
			// Reviews (1).
			'lrn_reviews',
			// Membership (3).
			'lrn_membership_plans',
			'lrn_member_subscriptions',
			'lrn_membership_plan_courses',
			// Revenue (4).
			'lrn_commission_rules',
			'lrn_transactions',
			'lrn_commissions',
			'lrn_withdrawals',
			// Coupons (2).
			'lrn_coupons',
			'lrn_coupon_usages',
			// Webhooks (1).
			'lrn_webhooks',
			// App (2).
			'lrn_tokens',
			'lrn_devices',
			// Instructor (2).
			'lrn_instructor_applications',
			'lrn_instructors',
			// Engagement (1).
			'lrn_engagement_monthly',
			// System (2).
			'lrn_notifications',
			'lrn_audit_log',
			// Payments (1) — added 2.1.0.
			'lrn_membership_plan_providers',
			// Webhook audit log (1) — added 2.2.0.
			'lrn_webhook_events',
			// Moderation (1) — added 3.22.0.
			'lrn_blocked_users',
		];
	}

	private static function get_table_definitions( string $p, string $charset_collate ): array {
		$sqls = [];

		// ── Course (6) ──────────────────────────────────────────────────

		// 1. lrn_courses
		// `pricing_model` + `access_duration_days` + recurring_* + provider
		// price-ID caches were added in Migration 2.3.0 for flexible
		// pricing (free / one_time / fixed_term / recurring / members_only /
		// one_time_or_members). Fresh installs get them via this CREATE; the
		// migration ALTERs upgrade paths.
		// NO SEMICOLONS INSIDE COMMENT STRINGS, EVER.
		//
		// dbDelta splits statements on `;`, and it does not respect quoting. A
		// semicolon inside a COMMENT terminates the CREATE early, so MySQL sees
		// a truncated statement and the WHOLE TABLE is never created. It fails
		// SILENTLY on the happy path: $wpdb->last_error is empty afterwards and
		// dbDelta cheerfully returns "Created table wp_lrn_courses".
		//
		// 1.9.0 shipped two such comments on the new og_image_* columns. Fresh
		// installs got 83 of 84 tables and NO lrn_courses — the core table of
		// the product. Upgrades were unaffected, because the table already
		// existed and the migration ALTERed it, which is exactly why only a
		// pristine-install test could catch it (Basecamp card filed).
		//
		// Use a comma or a full stop. `bin/verify-schema-comments.php` fails the
		// build on any semicolon in a COMMENT.
		$sqls[] = "CREATE TABLE {$p}lrn_courses (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  slug varchar(255) NOT NULL DEFAULT '',
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(255) NOT NULL DEFAULT '',
  description longtext,
  description_plain longtext COMMENT 'Tag-stripped projection of description, FULLTEXT-indexed so course search matches clean text (mirrors lessons.content_plain). Regenerated on save (G16).',
  content longtext,
  thumbnail_id bigint(20) unsigned DEFAULT NULL,
  thumbnail_url varchar(500) DEFAULT NULL,
  short_description text,
  video_url varchar(500) DEFAULT NULL,
  seo_title varchar(255) DEFAULT NULL COMMENT 'Overrides the derived <title> and og:title.',
  meta_description varchar(320) DEFAULT NULL COMMENT 'Overrides the derived meta description. Wider than the 155-char derived cap on purpose: an owner who typed 200 characters meant them.',
  og_image_id bigint(20) unsigned DEFAULT NULL COMMENT 'Media-library id for the share image, used when og_image_url is empty.',
  og_image_url varchar(500) DEFAULT NULL COMMENT 'Direct share-image URL. Wins over og_image_id, mirroring thumbnail_url.',
  meta_robots varchar(64) DEFAULT NULL COMMENT 'Robots directive, e.g. noindex. Validated against a known set on read.',
  category_id bigint(20) unsigned NOT NULL DEFAULT 0,
  access_type varchar(30) NOT NULL DEFAULT 'free',
  price decimal(10,2) NOT NULL DEFAULT 0.00,
  sale_price decimal(10,2) DEFAULT NULL,
  pricing_model varchar(20) NOT NULL DEFAULT 'free',
  access_duration_days int(11) DEFAULT NULL,
  recurring_amount decimal(10,2) DEFAULT NULL,
  recurring_interval varchar(20) DEFAULT NULL,
  stripe_test_price_id varchar(255) DEFAULT NULL,
  stripe_live_price_id varchar(255) DEFAULT NULL,
  paypal_sandbox_plan_id varchar(255) DEFAULT NULL,
  paypal_live_plan_id varchar(255) DEFAULT NULL,
  sale_starts datetime DEFAULT NULL,
  sale_ends datetime DEFAULT NULL,
  completion_type varchar(30) NOT NULL DEFAULT 'all_lessons',
  completion_value tinyint(3) unsigned DEFAULT NULL,
  completion_enforcement varchar(20) DEFAULT NULL COMMENT 'Per-course override of completion-rule strictness. NULL=inherit from site setting. Values: strict (require all rules met), advisory (show but allow override), off (no requirements).',
  prerequisites longtext,
  instructors_json longtext,
  settings_json longtext DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  avg_rating decimal(3,2) NOT NULL DEFAULT 0.00,
  review_count int(11) NOT NULL DEFAULT 0,
  student_count int(11) NOT NULL DEFAULT 0,
  duration_seconds int(11) NOT NULL DEFAULT 0,
  difficulty varchar(20) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY category_status_created (category_id,status,created_at),
  KEY author_status (author_id,status),
  KEY access_type_status (access_type,status),
  KEY status_created (status,created_at),
  FULLTEXT KEY ft_title_description (title,description_plain)
) ENGINE=InnoDB $charset_collate;";

		// 2. lrn_course_instructors
		$sqls[] = "CREATE TABLE {$p}lrn_course_instructors (
  course_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  share_pct decimal(5,2) NOT NULL DEFAULT 100.00,
  is_primary tinyint(1) NOT NULL DEFAULT 0,
  added_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (course_id,user_id),
  KEY user_id (user_id)
) ENGINE=InnoDB $charset_collate;";

		// 3. lrn_categories
		$sqls[] = "CREATE TABLE {$p}lrn_categories (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  slug varchar(255) NOT NULL DEFAULT '',
  name varchar(255) NOT NULL DEFAULT '',
  description longtext,
  image_url varchar(500) DEFAULT NULL,
  icon varchar(100) DEFAULT NULL,
  type varchar(30) NOT NULL DEFAULT 'course',
  sort_order int(11) NOT NULL DEFAULT 0,
  course_count int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY slug_type (slug,type),
  KEY parent_sort (parent_id,sort_order)
) ENGINE=InnoDB $charset_collate;";

		// 4. lrn_sections
		$sqls[] = "CREATE TABLE {$p}lrn_sections (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  course_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(255) NOT NULL DEFAULT '',
  description longtext,
  sort_order int(11) NOT NULL DEFAULT 0,
  available_date timestamp NULL DEFAULT NULL COMMENT 'Section-level drip schedule. Lessons in this section are locked until this UTC datetime when their own available_date is NULL.',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY course_sort (course_id,sort_order)
) ENGINE=InnoDB $charset_collate;";

		// 5. lrn_lessons
		$sqls[] = "CREATE TABLE {$p}lrn_lessons (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  course_id bigint(20) unsigned NOT NULL DEFAULT 0,
  section_id bigint(20) unsigned DEFAULT NULL,
  parent_id bigint(20) unsigned DEFAULT NULL,
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(255) NOT NULL DEFAULT '',
  slug varchar(255) NOT NULL DEFAULT '',
  content longtext,
  content_plain longtext,
  content_type varchar(20) NOT NULL DEFAULT 'text',
  video_url varchar(500) DEFAULT NULL,
  duration_seconds int(11) NOT NULL DEFAULT 0,
  scheduled_start_at timestamp NULL DEFAULT NULL COMMENT 'When a live session starts (UTC). Distinct from available_date: this is WHEN THE SESSION IS, not when the lesson unlocks. Overloading available_date for this locked every scheduled session until the moment it began (fixed 3.18.0).',
  live_duration_minutes smallint unsigned DEFAULT NULL COMMENT 'How long the live session runs, in minutes. The Join button is shown from scheduled_start_at until start + this. NULL = the learnomy_live_session_duration default (60).',
  completion_mode varchar(20) NOT NULL DEFAULT 'manual',
  min_time_seconds int unsigned NOT NULL DEFAULT 0,
  min_video_watch_pct tinyint unsigned NOT NULL DEFAULT 0,
  require_scroll_end tinyint(1) NOT NULL DEFAULT 0,
  require_quiz_pass tinyint(1) NOT NULL DEFAULT 0,
  lock_next_until_complete tinyint(1) NOT NULL DEFAULT 0,
  completion_enforcement varchar(20) DEFAULT NULL COMMENT 'Per-lesson override of completion-rule strictness. NULL=inherit from course/site setting. Values: strict, advisory, off.',
  comments_enabled tinyint(1) DEFAULT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  available_date timestamp NULL DEFAULT NULL COMMENT 'Drip schedule: lesson is locked for non-admin/non-author until this datetime (UTC). NULL = available immediately.',
  drip_mode varchar(20) NOT NULL DEFAULT 'fixed' COMMENT 'Drip schedule mode. fixed = use available_date (calendar). relative = use drip_offset_days + per-student lrn_lesson_unlocks rows.',
  drip_offset_days int unsigned DEFAULT NULL COMMENT 'Days after enrollment when the lesson unlocks. Only used when drip_mode = relative.',
  is_preview tinyint(1) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'published',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY course_section_sort (course_id,section_id,sort_order),
  KEY parent_id (parent_id),
  UNIQUE KEY uniq_course_slug (course_id,slug),
  FULLTEXT KEY ft_title_content (title,content_plain)
) ENGINE=InnoDB $charset_collate;";

		// 5b. lrn_lesson_attachments — added in 1.6.0.
		$sqls[] = "CREATE TABLE {$p}lrn_lesson_attachments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  lesson_id bigint(20) unsigned NOT NULL DEFAULT 0,
  media_id bigint(20) unsigned NOT NULL DEFAULT 0,
  filename varchar(255) NOT NULL DEFAULT '',
  mime_type varchar(100) NOT NULL DEFAULT '',
  size_bytes bigint(20) unsigned NOT NULL DEFAULT 0,
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY lesson_id (lesson_id,sort_order)
) ENGINE=InnoDB $charset_collate;";

		// 5c. lrn_lesson_unlocks — added in 2.0.0 (Plan 11).
		// Per-(user, lesson) computed unlock rows for relative-drip lessons.
		// Populated by the `learnomy_student_enrolled` listener at enrolment
		// time, then scanned hourly by `Drip_Cron::process_window()`.
		$sqls[] = "CREATE TABLE {$p}lrn_lesson_unlocks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  lesson_id bigint(20) unsigned NOT NULL,
  unlocks_at timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY ux_user_lesson (user_id,lesson_id),
  KEY idx_unlocks_at (unlocks_at)
) ENGINE=InnoDB $charset_collate;";

		// 6. lrn_enrollments
		// Context columns (path_id, cohort_id, space_id) are owned by Pro
		// extensions but live on the core enrollments table so progress and
		// access checks can be O(1) regardless of which Pro extension scoped
		// the enrollment. Plan 00 §1.5 D11 + §7 Phase 0.
		// `subscription_id` added in Migration 2.3.0 so course-level recurring
		// enrollments link back to their billing relationship.
		$sqls[] = "CREATE TABLE {$p}lrn_enrollments (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  course_id bigint(20) unsigned NOT NULL DEFAULT 0,
  plan_id bigint(20) unsigned DEFAULT NULL,
  subscription_id bigint(20) unsigned DEFAULT NULL,
  path_id bigint(20) unsigned NOT NULL DEFAULT 0,
  cohort_id bigint(20) unsigned DEFAULT NULL,
  space_id bigint(20) unsigned DEFAULT NULL,
  source varchar(20) NOT NULL DEFAULT 'free',
  status varchar(20) NOT NULL DEFAULT 'active',
  progress_pct tinyint(3) unsigned NOT NULL DEFAULT 0,
  enrolled_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  expires_at datetime DEFAULT NULL,
  completed_at datetime DEFAULT NULL,
  last_accessed_at datetime DEFAULT NULL,
  reminder_sent_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_course_source (user_id,course_id,source,path_id),
  KEY user_status (user_id,status),
  KEY course_status_enrolled (course_id,status,enrolled_at),
  KEY course_progress (course_id,progress_pct),
  KEY plan_id (plan_id),
  KEY subscription_id (subscription_id),
  KEY path_id (path_id),
  KEY cohort_id (cohort_id),
  KEY space_id (space_id),
  KEY expires_at (expires_at)
) ENGINE=InnoDB $charset_collate;";

		// ── Taxonomy (2) ────────────────────────────────────────────────

		// 7. lrn_tags
		$sqls[] = "CREATE TABLE {$p}lrn_tags (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  name varchar(255) NOT NULL DEFAULT '',
  slug varchar(255) NOT NULL DEFAULT '',
  type varchar(30) NOT NULL DEFAULT 'course',
  usage_count int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY slug_type (slug,type)
) ENGINE=InnoDB $charset_collate;";

		// 8. lrn_tag_map
		$sqls[] = "CREATE TABLE {$p}lrn_tag_map (
  object_type varchar(30) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  tag_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (object_type,object_id,tag_id),
  KEY tag_object_type (tag_id,object_type)
) ENGINE=InnoDB $charset_collate;";

		// ── Quiz (7) ────────────────────────────────────────────────────

		// 9. lrn_quizzes
		$sqls[] = "CREATE TABLE {$p}lrn_quizzes (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(255) NOT NULL DEFAULT '',
  slug varchar(255) NOT NULL DEFAULT '',
  description longtext,
  course_id bigint(20) unsigned DEFAULT NULL,
  section_id bigint(20) unsigned DEFAULT NULL,
  lesson_id bigint(20) unsigned DEFAULT NULL,
  mode varchar(20) NOT NULL DEFAULT 'graded',
  time_limit_seconds int(11) DEFAULT NULL,
  attempt_limit int(11) DEFAULT NULL,
  attempt_cooldown_seconds int unsigned NOT NULL DEFAULT 0,
  max_failed_attempts tinyint unsigned NOT NULL DEFAULT 0,
  passing_score tinyint(3) unsigned NOT NULL DEFAULT 70,
  grading_method varchar(20) NOT NULL DEFAULT 'highest',
  shuffle_questions tinyint(1) NOT NULL DEFAULT 0,
  shuffle_answers tinyint(1) NOT NULL DEFAULT 0,
  group_by_category tinyint(1) NOT NULL DEFAULT 0,
  navigation varchar(20) NOT NULL DEFAULT 'free',
  section_navigation varchar(20) NOT NULL DEFAULT 'linear',
  questions_per_section smallint unsigned NOT NULL DEFAULT 10 COMMENT 'Sectioned template: how many questions each auto-built section holds. The client hardcoded 10 until 3.45.0.',
  feedback_timing varchar(20) NOT NULL DEFAULT 'after_submit',
  display_template varchar(30) NOT NULL DEFAULT 'focused',
  access_code varchar(50) DEFAULT NULL,
  open_at datetime DEFAULT NULL,
  close_at datetime DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'draft',
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY course_id (course_id),
  KEY author_id (author_id),
  KEY status (status),
  KEY section_id (section_id),
  KEY lesson_id (lesson_id),
  UNIQUE KEY uniq_course_slug (course_id,slug)
) ENGINE=InnoDB $charset_collate;";

		// 10. lrn_quiz_slots
		$sqls[] = "CREATE TABLE {$p}lrn_quiz_slots (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  quiz_id bigint(20) unsigned NOT NULL DEFAULT 0,
  question_id bigint(20) unsigned NOT NULL DEFAULT 0,
  sort_order int(11) NOT NULL DEFAULT 0,
  marks decimal(5,2) NOT NULL DEFAULT 1.00,
  PRIMARY KEY  (id),
  UNIQUE KEY quiz_question (quiz_id,question_id),
  KEY question_id (question_id)
) ENGINE=InnoDB $charset_collate;";

		// 11. lrn_questions
		$sqls[] = "CREATE TABLE {$p}lrn_questions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  type varchar(20) NOT NULL DEFAULT 'mc_single',
  title varchar(500) NOT NULL DEFAULT '',
  content longtext,
  stem longtext,
  explanation longtext,
  hint text NULL DEFAULT NULL,
  difficulty tinyint(1) unsigned NOT NULL DEFAULT 3,
  category_id bigint(20) unsigned DEFAULT NULL,
  version int(11) NOT NULL DEFAULT 1,
  status varchar(20) NOT NULL DEFAULT 'published',
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY category_difficulty (category_id,difficulty),
  KEY type_status (type,status),
  KEY author_id (author_id)
) ENGINE=InnoDB $charset_collate;";

		// 12. lrn_question_answers
		$sqls[] = "CREATE TABLE {$p}lrn_question_answers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  question_id bigint(20) unsigned NOT NULL DEFAULT 0,
  content longtext,
  fraction decimal(5,4) NOT NULL DEFAULT 0.0000,
  feedback longtext,
  group_id varchar(50) DEFAULT NULL,
  sort_order int(11) NOT NULL DEFAULT 0,
  is_correct tinyint(1) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY question_sort (question_id,sort_order)
) ENGINE=InnoDB $charset_collate;";

		// 13. lrn_question_categories
		$sqls[] = "CREATE TABLE {$p}lrn_question_categories (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  parent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(255) NOT NULL DEFAULT '',
  slug varchar(255) NOT NULL DEFAULT '',
  description longtext,
  question_count int(11) NOT NULL DEFAULT 0,
  sort_order int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY parent_sort (parent_id,sort_order)
) ENGINE=InnoDB $charset_collate;";

		// 14. lrn_quiz_attempts
		$sqls[] = "CREATE TABLE {$p}lrn_quiz_attempts (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  quiz_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  enrollment_id bigint(20) unsigned DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'in_progress',
  score decimal(10,2) NOT NULL DEFAULT 0.00,
  max_score decimal(10,2) NOT NULL DEFAULT 0.00,
  percentage decimal(5,2) NOT NULL DEFAULT 0.00,
  passed tinyint(1) NOT NULL DEFAULT 0,
  started_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  submitted_at datetime DEFAULT NULL,
  graded_at datetime DEFAULT NULL,
  time_spent_seconds int(11) NOT NULL DEFAULT 0,
  tab_switches int(11) NOT NULL DEFAULT 0,
  ip_address varchar(45) DEFAULT NULL,
  saved_state_json longtext,
  PRIMARY KEY  (id),
  KEY quiz_user_created (quiz_id,user_id,started_at),
  KEY user_status (user_id,status)
) ENGINE=InnoDB $charset_collate;";

		// 15. lrn_question_responses
		$sqls[] = "CREATE TABLE {$p}lrn_question_responses (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  attempt_id bigint(20) unsigned NOT NULL DEFAULT 0,
  question_id bigint(20) unsigned NOT NULL DEFAULT 0,
  question_version int(11) NOT NULL DEFAULT 1,
  response_json longtext,
  score decimal(10,2) NOT NULL DEFAULT 0.00,
  max_score decimal(10,2) NOT NULL DEFAULT 0.00,
  time_spent_seconds int(11) NOT NULL DEFAULT 0,
  grader_id bigint(20) unsigned DEFAULT NULL,
  grader_feedback longtext,
  graded_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY attempt_question (attempt_id,question_id),
  KEY question_id (question_id)
) ENGINE=InnoDB $charset_collate;";

		// ── Student (1) ─────────────────────────────────────────────────

		// 16. lrn_progress
		$sqls[] = "CREATE TABLE {$p}lrn_progress (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  item_type varchar(20) NOT NULL DEFAULT 'lesson',
  item_id bigint(20) unsigned NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'not_started',
  time_spent_seconds int(11) NOT NULL DEFAULT 0,
  video_watch_pct tinyint(3) unsigned NOT NULL DEFAULT 0,
  completed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY user_item (user_id,item_type,item_id),
  KEY item_type_id (item_type,item_id),
  KEY user_updated (user_id,updated_at)
) ENGINE=InnoDB $charset_collate;";

		// ── Certificates (2) ────────────────────────────────────────────

		// 17. lrn_certificates
		//
		// The `*_snapshot` columns are what the DOCUMENT asserts, frozen at issue
		// time. Everything else on this row is a pointer (user_id, course_id,
		// template_id) and pointers rot: deleting a course, renaming it, renaming
		// the site, or an instructor leaving used to retroactively rewrite — or
		// blank — every certificate ever issued for it, on the PDF, the REST verify
		// payload and the public verify page alike (Basecamp 10148480574).
		//
		// A certificate is the one artefact whose job is to stay true years after
		// the thing it describes is gone, so every merge value that resolves from a
		// mutable row is recorded here at issue and read back in preference to the
		// live lookup. The values deliberately NOT snapshotted are the ones that
		// are not assertions: the verify URL (an address, which must follow the
		// site), the uuid (already immutable) and the completion date (already
		// immutable in `issued_at`; only its formatting is resolved live, so it
		// follows the reader's locale).
		$sqls[] = "CREATE TABLE {$p}lrn_certificates (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  uuid char(36) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  course_id bigint(20) unsigned NOT NULL DEFAULT 0,
  template_id bigint(20) unsigned DEFAULT NULL,
  enrollment_id bigint(20) unsigned DEFAULT NULL,
  course_title_snapshot varchar(255) DEFAULT NULL,
  recipient_name_snapshot varchar(255) DEFAULT NULL,
  issuer_name_snapshot varchar(255) DEFAULT NULL,
  instructor_name_snapshot varchar(255) DEFAULT NULL,
  score_snapshot decimal(5,2) DEFAULT NULL,
  hours_snapshot decimal(7,2) DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  signature text,
  issued_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  expires_at datetime DEFAULT NULL,
  revoked_at datetime DEFAULT NULL,
  revoked_reason varchar(255) DEFAULT NULL,
  verification_count int(11) NOT NULL DEFAULT 0,
  last_verified_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY uuid (uuid),
  KEY user_status (user_id,status),
  KEY course_id (course_id),
  KEY expires_at (expires_at)
) ENGINE=InnoDB $charset_collate;";

		// 18. lrn_certificate_templates
		$sqls[] = "CREATE TABLE {$p}lrn_certificate_templates (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(255) NOT NULL DEFAULT '',
  design_json longtext,
  triggers_json longtext,
  expiration_days int(11) DEFAULT NULL,
  orientation varchar(20) NOT NULL DEFAULT 'landscape',
  status varchar(20) NOT NULL DEFAULT 'active',
  author_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id)
) ENGINE=InnoDB $charset_collate;";

		// ── Reviews (1) ─────────────────────────────────────────────────

		// 19. lrn_reviews
		$sqls[] = "CREATE TABLE {$p}lrn_reviews (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  course_id bigint(20) unsigned NOT NULL DEFAULT 0,
  rating tinyint(3) unsigned NOT NULL DEFAULT 0,
  title varchar(255) DEFAULT NULL,
  content longtext,
  status varchar(20) NOT NULL DEFAULT 'approved',
  instructor_reply longtext,
  instructor_reply_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY user_course (user_id,course_id),
  KEY course_status_created (course_id,status,created_at)
) ENGINE=InnoDB $charset_collate;";

		// ── Membership (3) ──────────────────────────────────────────────

		// 20. lrn_membership_plans
		$sqls[] = "CREATE TABLE {$p}lrn_membership_plans (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  title varchar(255) NOT NULL DEFAULT '',
  slug varchar(255) NOT NULL DEFAULT '',
  description longtext,
  price decimal(10,2) NOT NULL DEFAULT 0.00,
  currency char(3) NOT NULL DEFAULT 'USD',
  billing_interval varchar(20) NOT NULL DEFAULT 'monthly',
  interval_count int(11) NOT NULL DEFAULT 1,
  trial_days int(11) NOT NULL DEFAULT 0,
  course_limit int(11) DEFAULT NULL,
  features_json longtext DEFAULT NULL,
  is_featured tinyint(1) NOT NULL DEFAULT 0,
  price_monthly decimal(10,2) DEFAULT NULL,
  price_yearly decimal(10,2) DEFAULT NULL,
  thumbnail_url varchar(500) DEFAULT NULL,
  space_id bigint(20) unsigned DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  sort_order int(11) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY slug (slug),
  KEY status_sort (status,sort_order),
  KEY space_id (space_id)
) ENGINE=InnoDB $charset_collate;";

		// 21. lrn_member_subscriptions
		// `plan_id` made NULLable + `course_id` added in Migration 2.3.0 so
		// a single row can target a plan OR a course (mutually exclusive,
		// enforced in Subscription_Service::subscribe_to_course()).
		$sqls[] = "CREATE TABLE {$p}lrn_member_subscriptions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  plan_id bigint(20) unsigned DEFAULT NULL,
  course_id bigint(20) unsigned DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  payment_provider varchar(50) DEFAULT NULL,
  provider_subscription_id varchar(255) DEFAULT NULL,
  provider_price_id varchar(255) DEFAULT NULL,
  amount decimal(10,2) DEFAULT NULL,
  billing_interval varchar(20) DEFAULT NULL,
  current_period_start datetime DEFAULT NULL,
  current_period_end datetime DEFAULT NULL,
  trial_ends_at datetime DEFAULT NULL,
  cancelled_at datetime DEFAULT NULL,
  paused_at datetime DEFAULT NULL,
  cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY user_status (user_id,status),
  KEY plan_status (plan_id,status),
  KEY course_status (course_id,status),
  KEY provider_sub (provider_subscription_id),
  KEY period_end (current_period_end),
  KEY trial_ends (trial_ends_at)
) ENGINE=InnoDB $charset_collate;";

		// 22. lrn_membership_plan_courses
		$sqls[] = "CREATE TABLE {$p}lrn_membership_plan_courses (
  plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
  course_id bigint(20) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY  (plan_id,course_id),
  KEY course_id (course_id)
) ENGINE=InnoDB $charset_collate;";

		// ── Revenue (4) ─────────────────────────────────────────────────

		// 23. lrn_commission_rules
		$sqls[] = "CREATE TABLE {$p}lrn_commission_rules (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  rule_type varchar(30) NOT NULL DEFAULT 'global',
  entity_id bigint(20) unsigned DEFAULT NULL,
  platform_rate decimal(5,2) NOT NULL DEFAULT 30.00,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY rule_entity (rule_type,entity_id)
) ENGINE=InnoDB $charset_collate;";

		// 24. lrn_transactions
		$sqls[] = "CREATE TABLE {$p}lrn_transactions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  transaction_type varchar(30) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  course_id bigint(20) unsigned DEFAULT NULL,
  plan_id bigint(20) unsigned DEFAULT NULL,
  enrollment_id bigint(20) unsigned DEFAULT NULL,
  subscription_id bigint(20) unsigned DEFAULT NULL,
  coupon_id bigint(20) unsigned DEFAULT NULL,
  amount decimal(10,2) NOT NULL DEFAULT 0.00,
  currency char(3) NOT NULL DEFAULT 'USD',
  payment_provider varchar(50) DEFAULT NULL,
  provider_transaction_id varchar(255) DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'pending',
  refund_of bigint(20) unsigned DEFAULT NULL,
  metadata_json longtext,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY user_created (user_id,created_at),
  KEY provider_txn (provider_transaction_id),
  KEY course_id (course_id),
  KEY plan_id (plan_id),
  KEY status_created (status,created_at),
  KEY coupon_id (coupon_id),
  KEY refund_of (refund_of),
  KEY type_status (transaction_type,status)
) ENGINE=InnoDB $charset_collate;";

		// 25. lrn_commissions
		$sqls[] = "CREATE TABLE {$p}lrn_commissions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  transaction_id bigint(20) unsigned NOT NULL DEFAULT 0,
  recipient_type varchar(20) NOT NULL DEFAULT 'instructor',
  recipient_id bigint(20) unsigned NOT NULL DEFAULT 0,
  amount decimal(10,2) NOT NULL DEFAULT 0.00,
  currency char(3) NOT NULL DEFAULT 'USD',
  rate_applied decimal(5,2) NOT NULL DEFAULT 0.00,
  payout_period varchar(7) DEFAULT NULL,
  payout_status varchar(20) NOT NULL DEFAULT 'pending',
  withdrawal_id bigint(20) unsigned NOT NULL DEFAULT 0,
  requested_at datetime DEFAULT NULL,
  approved_at datetime DEFAULT NULL,
  paid_at datetime DEFAULT NULL,
  paid_via varchar(50) DEFAULT NULL,
  payout_reference varchar(255) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY transaction_id (transaction_id),
  KEY recipient_status (recipient_id,payout_status),
  KEY withdrawal_id (withdrawal_id),
  KEY period_recipient (payout_period,recipient_id)
) ENGINE=InnoDB $charset_collate;";

		// 26. lrn_withdrawals
		$sqls[] = "CREATE TABLE {$p}lrn_withdrawals (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  instructor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  amount decimal(10,2) NOT NULL DEFAULT 0.00,
  currency char(3) NOT NULL DEFAULT 'USD',
  status varchar(20) NOT NULL DEFAULT 'pending',
  method varchar(30) DEFAULT NULL,
  destination varchar(255) DEFAULT NULL,
  reference varchar(255) DEFAULT NULL,
  rejection_reason varchar(500) DEFAULT NULL,
  requested_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  approved_at datetime DEFAULT NULL,
  processed_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  KEY instructor_status (instructor_id,status),
  KEY status (status)
) ENGINE=InnoDB $charset_collate;";

		// ── Coupons (2) ─────────────────────────────────────────────────

		// 27. lrn_coupons
		$sqls[] = "CREATE TABLE {$p}lrn_coupons (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  code varchar(50) NOT NULL DEFAULT '',
  type varchar(20) NOT NULL DEFAULT 'percent',
  value decimal(10,2) NOT NULL DEFAULT 0.00,
  applies_to varchar(20) NOT NULL DEFAULT 'course',
  items_json longtext,
  usage_limit int(11) DEFAULT NULL,
  per_user_limit int(11) NOT NULL DEFAULT 1,
  min_purchase decimal(10,2) DEFAULT NULL,
  starts_at datetime DEFAULT NULL,
  expires_at datetime DEFAULT NULL,
  times_used int(11) NOT NULL DEFAULT 0,
  status varchar(20) NOT NULL DEFAULT 'active',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY code (code),
  KEY status_dates (status,starts_at,expires_at)
) ENGINE=InnoDB $charset_collate;";

		// 28. lrn_coupon_usages
		$sqls[] = "CREATE TABLE {$p}lrn_coupon_usages (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  coupon_id bigint(20) unsigned NOT NULL DEFAULT 0,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  transaction_id bigint(20) unsigned NOT NULL DEFAULT 0,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY coupon_user (coupon_id,user_id),
  KEY user_id (user_id)
) ENGINE=InnoDB $charset_collate;";

		// ── Webhooks (1) ────────────────────────────────────────────────

		// 29. lrn_webhooks
		$sqls[] = "CREATE TABLE {$p}lrn_webhooks (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  url varchar(500) NOT NULL DEFAULT '',
  events_json longtext,
  secret varchar(255) DEFAULT NULL,
  status varchar(20) NOT NULL DEFAULT 'active',
  last_triggered_at datetime DEFAULT NULL,
  last_status_code int(11) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY status (status)
) ENGINE=InnoDB $charset_collate;";

		// ── App (2) ─────────────────────────────────────────────────────

		// 30. lrn_tokens
		$sqls[] = "CREATE TABLE {$p}lrn_tokens (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  token_hash varchar(64) NOT NULL DEFAULT '',
  ip_address varchar(45) DEFAULT NULL,
  user_agent varchar(255) DEFAULT NULL,
  expires_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  revoked_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY token_hash (token_hash),
  KEY user_id (user_id),
  KEY expires_at (expires_at)
) ENGINE=InnoDB $charset_collate;";

		// 31. lrn_devices
		$sqls[] = "CREATE TABLE {$p}lrn_devices (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  device_token varchar(255) NOT NULL DEFAULT '',
  platform varchar(20) NOT NULL DEFAULT 'web',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY device_token (device_token),
  KEY user_id (user_id)
) ENGINE=InnoDB $charset_collate;";

		// ── Instructor (1) ──────────────────────────────────────────────

		// 32. lrn_instructor_applications
		$sqls[] = "CREATE TABLE {$p}lrn_instructor_applications (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  bio longtext,
  expertise varchar(500) DEFAULT NULL,
  teaching_experience varchar(500) DEFAULT NULL,
  course_idea longtext,
  payout_method varchar(30) DEFAULT NULL,
  payout_details_encrypted longtext,
  status varchar(20) NOT NULL DEFAULT 'pending',
  rejection_reason longtext,
  reviewed_by bigint(20) unsigned DEFAULT NULL,
  reviewed_at datetime DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY status_created (status,created_at),
  KEY user_id (user_id)
) ENGINE=InnoDB $charset_collate;";

		// ── Engagement (1) ──────────────────────────────────────────────

		// 33. lrn_engagement_monthly
		$sqls[] = "CREATE TABLE {$p}lrn_engagement_monthly (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  instructor_id bigint(20) unsigned NOT NULL DEFAULT 0,
  plan_id bigint(20) unsigned NOT NULL DEFAULT 0,
  period varchar(7) NOT NULL DEFAULT '',
  lesson_count int(11) NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  UNIQUE KEY instructor_plan_period (instructor_id,plan_id,period),
  KEY period (period)
) ENGINE=InnoDB $charset_collate;";

		// ── System (2) ──────────────────────────────────────────────────

		// 34. lrn_notifications
		$sqls[] = "CREATE TABLE {$p}lrn_notifications (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  actor_id bigint(20) unsigned DEFAULT NULL,
  type varchar(50) NOT NULL DEFAULT '',
  object_type varchar(50) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  title varchar(255) DEFAULT NULL,
  message varchar(500) NOT NULL DEFAULT '',
  is_read tinyint(1) NOT NULL DEFAULT 0,
  email_status varchar(20) NOT NULL DEFAULT 'skipped',
  email_error text DEFAULT NULL,
  broadcast_id varchar(32) NOT NULL DEFAULT '',
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY user_read_created (user_id,is_read,created_at),
  KEY type_created (type,created_at),
  KEY created_at (created_at),
  KEY broadcast_id (broadcast_id)
) ENGINE=InnoDB $charset_collate;";

		// 35. lrn_audit_log
		$sqls[] = "CREATE TABLE {$p}lrn_audit_log (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  type varchar(30) NOT NULL DEFAULT 'admin_action',
  action varchar(50) NOT NULL DEFAULT '',
  object_type varchar(50) NOT NULL DEFAULT '',
  object_id bigint(20) unsigned NOT NULL DEFAULT 0,
  metadata_json longtext,
  ip_address varchar(45) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY user_created (user_id,created_at),
  KEY type_created (type,created_at),
  KEY created_at (created_at),
  KEY object (object_type,object_id)
) ENGINE=InnoDB $charset_collate;";

		// ── Payments (1) — added 2.1.0 ────────────────────────────────────

		// 36. lrn_membership_plan_providers
		// Maps each membership plan to its provider-specific price/plan IDs, one
		// row per (plan_id, provider, mode) triple. The UNIQUE constraint on that
		// triple enforces at most one mapping per combination — upsert-safe via
		// INSERT … ON DUPLICATE KEY UPDATE. Phase 2 adapters read from this table
		// rather than scattered learnomy_stripe_price_<n> wp_options.
		$sqls[] = "CREATE TABLE {$p}lrn_membership_plan_providers (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  plan_id bigint(20) unsigned NOT NULL,
  provider varchar(20) NOT NULL,
  mode varchar(10) NOT NULL,
  provider_plan_id varchar(191) NOT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  UNIQUE KEY unique_plan_provider_mode (plan_id,provider,mode),
  KEY idx_provider_mode (provider,mode)
) ENGINE=InnoDB $charset_collate;";

		// ── Webhook audit log (1) — added 2.2.0 ────────────────────────────

		// 37. lrn_webhook_events
		// Permanent record of every inbound webhook from Stripe and PayPal.
		// payload_json stores the raw body verbatim so events can be replayed
		// from the admin viewer. UNIQUE (provider, event_id) enforces idempotency
		// at the DB level — duplicate deliveries produce a unique-key violation
		// that callers detect by comparing insert_id to 0.
		$sqls[] = "CREATE TABLE {$p}lrn_webhook_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  provider VARCHAR(20) NOT NULL,
  event_type VARCHAR(100) NOT NULL,
  event_id VARCHAR(191) NOT NULL,
  payload_json LONGTEXT NOT NULL,
  signature_status VARCHAR(20) NOT NULL,
  processed_at DATETIME NOT NULL,
  outcome VARCHAR(20) NOT NULL DEFAULT 'pending',
  error_message TEXT NULL,
  retry_count INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY unique_provider_event (provider, event_id),
  KEY idx_provider_processed (provider, processed_at),
  KEY idx_outcome (outcome),
  KEY idx_event_type (event_type)
) ENGINE=InnoDB $charset_collate;";

		// 40. lrn_instructors — consolidated instructor profile (added 3.1.0).
		// One row per instructor. Replaces the scatter where bio/expertise lived
		// in user_meta AND a write-once application row. FREE owns this table;
		// Pro extends via its own tables, never alters this one. `avg_rating` is
		// Pro-written (instructor rating system) and read live. Do NOT re-add
		// denormalized courses_count / distinct_students / total_earned here: they
		// were dead columns that nothing maintained and nothing read (the admin
		// list + earnings compute live and N+1-safe via Course/Commission), and a
		// misleading docblock invited a future ORDER BY on stale zeros — dropped
		// in migration 3.25.0. Compute instructor stats live, do not denormalize.
		//
		// `status` (active|suspended) is a SEPARATE axis from
		// `verification_status` (unverified|verified) and must not be folded into
		// it: verification is a claim about who somebody is, status is whether the
		// owner currently lets them take new business. A verified instructor can
		// be suspended, and an unverified one can be perfectly active.
		$sqls[] = "CREATE TABLE {$p}lrn_instructors (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  headline varchar(255) DEFAULT NULL,
  bio longtext,
  expertise varchar(500) DEFAULT NULL,
  location varchar(255) DEFAULT NULL,
  website_url varchar(500) DEFAULT NULL,
  twitter_handle varchar(100) DEFAULT NULL,
  linkedin_url varchar(500) DEFAULT NULL,
  extra_social_json longtext,
  verification_status varchar(20) NOT NULL DEFAULT 'unverified',
  status varchar(20) NOT NULL DEFAULT 'active',
  avg_rating decimal(3,2) DEFAULT NULL,
  created_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  updated_at datetime DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY user_id (user_id),
  KEY verification_status (verification_status),
  KEY status (status),
  KEY avg_rating (avg_rating)
) ENGINE=InnoDB $charset_collate;";

		// 41. lrn_blocked_users — member block list (App Store 1.2 UGC safety,
		// added 3.22.0). One row per (blocker → blocked) pair. Read on every
		// lesson-comment + course-review list to hide a blocked author from the
		// blocker. Business state, not audit signal, so it gets its own table.
		$sqls[] = "CREATE TABLE {$p}lrn_blocked_users (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  blocker_id bigint(20) unsigned NOT NULL,
  blocked_id bigint(20) unsigned NOT NULL,
  created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY  (id),
  UNIQUE KEY ux_pair (blocker_id, blocked_id),
  KEY idx_blocker (blocker_id),
  KEY idx_blocked (blocked_id)
) ENGINE=InnoDB $charset_collate;";

		// Note: a `lrn_cancellation_feedback` table existed briefly between
		// 2.4.0 and 2.5.0. It duplicated what `lrn_audit_log` already supports
		// (cancellation is a subscription event; reason/feedback/offer fit
		// `metadata_json`). Migration 2.5.0 copies any rows to audit_log and
		// drops the table. New cancel events go straight to audit_log.

		return $sqls;
	}
}
