<?php
/**
 * Pro feature catalog — translation shim (GENERATED, do not hand-edit).
 *
 * Every user-facing string in data/pro-features.json is a raw JSON value that
 * `wp i18n make-pot` cannot see. This file lists each name / description /
 * user-story inside a __() call so they land in learnomy.pot. It is NEVER
 * executed for output — Pro_Catalog::normalise() re-translates the JSON values
 * at runtime via translate(), and these msgids give the translator a home.
 *
 * Regenerate from data/pro-features.json during the i18n build step (bin/i18n.sh).
 *
 * @package Learnomy\Admin
 */

defined( 'ABSPATH' ) || exit;

__( 'Advanced Quizzes', 'learnomy' );
__( 'Five Pro question types (matching, ordering, numeric, math, calculated), timed questions, random pools, rubric grading, and KaTeX math rendering in the quiz player.', 'learnomy' );
__( 'Instructor builds a quiz from a pool of 50 questions, picks 10 at random per attempt', 'learnomy' );
__( 'Instructor sets a 60-second timer per question (server-enforced on every layout; client auto-advance-on-timeout on the focused quiz layout)', 'learnomy' );
__( 'Instructor grades essay questions against a 5-criterion rubric (admin Rubric Grading subpage)', 'learnomy' );
__( 'Instructor authors matching + ordering question types — types only appear in the question-author dropdown when this extension is active (Free\'s QuestionTypes::all() default omits them; the learnomy_question_types filter re-adds them on top of whatever Free returns)', 'learnomy' );
__( 'Instructor authors a numeric question with an answer and a tolerance (absolute or percent) so near-miss values still grade correct', 'learnomy' );
__( 'Instructor authors a math-expression question; the student\'s algebraically-equivalent answer (e.g. 2(x+1) vs 2x+2) grades correct via sampling-based symbolic equivalence, evaluated in a sandbox with no eval()', 'learnomy' );
__( 'Instructor authors a calculated question with variable ranges; each attempt seeds deterministic per-attempt values, substitutes them into the stem, and grades against the freshly computed answer', 'learnomy' );
__( 'Student sees LaTeX math in a quiz stem/option/explanation rendered as typeset math via vendored KaTeX, with dynamically injected math re-rendered on the fly', 'learnomy' );
__( 'AI Authoring', 'learnomy' );
__( 'AI-assisted course, lesson, and question authoring on top of WordPress core AI Connectors. Admin + Instructor, draft-first.', 'learnomy' );
__( 'Advanced Analytics', 'learnomy' );
__( 'Revenue trends, course performance, student engagement, quiz statistics, and CSV/PDF exports.', 'learnomy' );
__( 'Admin opens Analytics → Overview, sees revenue summary + active student count + average progress + average time-on-lesson, all from real data', 'learnomy' );
__( 'Admin filters by date period (7d / 30d / 90d / 365d) — all queries refetch with the new range', 'learnomy' );
__( 'Admin clicks any course → drilldown shows enrollments + revenue + completion rate for that course', 'learnomy' );
__( 'Admin exports any report to CSV or PDF for offline review', 'learnomy' );
__( 'Assignments', 'learnomy' );
__( 'Lesson-attached assignments with text submission and inline instructor grading.', 'learnomy' );
__( 'Admin opens Learnomy → Assignments, clicks Add New, picks a lesson + sets max score + due date, saves', 'learnomy' );
__( 'Enrolled student opens that lesson, sees the assignment widget below the lesson content, types text + submits — sees Submitted state immediately', 'learnomy' );
__( 'Admin clicks Grade for the assignment, sees all submissions with student name + content, types score + optional feedback, clicks Grade', 'learnomy' );
__( 'Student returns to lesson, sees their score (X / max) + feedback in the widget', 'learnomy' );
__( 'Certificates Pro', 'learnomy' );
__( 'Open Badges 3.0, Credly integration, a tamper-evident certificate hash registry, and employer verification.', 'learnomy' );
__( 'Student earns a certificate, the issuer auto-pushes it to their Credly account', 'learnomy' );
__( 'Employer scans QR code on cert, lands on verification page showing OB3 badge.json + signature status', 'learnomy' );
__( 'Site owner anchors a SHA-256 hash of each cert to local registry (placeholder; future OpenTimestamps)', 'learnomy' );
__( 'Cohorts', 'learnomy' );
__( 'Group learning with scheduled start/end dates and cohort-scoped enrollment.', 'learnomy' );
__( 'Site owner clicks Add New Cohort, enters name + selects course + sets dates + capacity, saves — cohort persists with status', 'learnomy' );
__( 'Admin edits an existing cohort\'s status from draft → open to start accepting members', 'learnomy' );
__( 'Admin adds a student to the cohort by email; student is auto-enrolled in the cohort\'s course with cohort_id stored on the enrollment row', 'learnomy' );
__( 'Student visits /cohorts/{id}/ and sees the cohort name, course, dates, capacity. If they\'re a member, sees a Continue Learning button to the course.', 'learnomy' );
__( 'Content Drip', 'learnomy' );
__( 'Schedule when lessons unlock — by days, date, or prerequisite completion.', 'learnomy' );
__( 'Instructor sets Lesson 5 to unlock 7 days after enrollment', 'learnomy' );
__( 'Instructor sets Lesson 10 to unlock only after Quiz 3 passes', 'learnomy' );
__( 'Instructor sets all Module 2 lessons to unlock on a fixed calendar date', 'learnomy' );
__( 'Frontend Course Builder', 'learnomy' );
__( 'Instructors create and manage courses entirely from the frontend — no wp-admin access required. Multi-step wizard for create, full editor for ongoing management.', 'learnomy' );
__( 'Instructor visits /instructor/create-course/ and walks the 4-step wizard (Course Info → Curriculum → Settings → Review & Publish)', 'learnomy' );
__( 'On Publish, the course is created via POST /learnomy/v1/courses and the browser server-redirects to /instructor/edit-course/{id}/?published=1 so the persistent editor template loads', 'learnomy' );
__( 'From the editor, the instructor can add sections, lessons, quizzes, drip rules, certificate templates, prerequisites, tags, and co-instructors — every action persists via REST', 'learnomy' );
__( 'Inline category creation hits POST /learnomy/v1/categories (gated to course creators by Permission_Engine — Free@bdd93fb)', 'learnomy' );
__( 'Inline tag creation hits POST /learnomy/v1/tags (same gate)', 'learnomy' );
__( 'Students who follow a stale /instructor/create-course/ link are redirected to the catalog with ?access=instructors_only — themed page, not a raw wp_die()', 'learnomy' );
__( 'Gradebook', 'learnomy' );
__( 'Weighted per-course grade computed from the existing quiz, assignment, and SCORM scores, with manual instructor overrides and CSV export.', 'learnomy' );
__( 'As an instructor I set how much quizzes, assignments and standards packages each count toward the course grade, and a pass threshold.', 'learnomy' );
__( 'As an instructor I see every enrolled student\'s weighted grade in one paginated grid and can override a grade with a reason.', 'learnomy' );
__( 'As a student I see my own weighted grade per course at /account/grades/.', 'learnomy' );
__( 'As a site owner I export a course\'s grades to CSV.', 'learnomy' );
__( 'H5P', 'learnomy' );
__( 'Attach existing H5P interactive content as a lesson (bridge to the official H5P plugin). Completion flows into the one progress pipeline; the score stays in H5P\'s own store.', 'learnomy' );
__( 'Learning Paths', 'learnomy' );
__( 'Curated, ordered course sequences with sequential gating and a path-level certificate on completion.', 'learnomy' );
__( 'Admin creates a learning path with title, description, sequential flag, and certificate template', 'learnomy' );
__( 'Admin adds course/quiz steps via the REST API or admin AJAX (save/delete/add-step/remove-step)', 'learnomy' );
__( 'Student visits /paths/ to browse the catalog of published paths', 'learnomy' );
__( 'Student visits /paths/{slug}/ to view curriculum and click \'Enroll\' — auto-enrolls in every course step', 'learnomy' );
__( 'On a sequential path, can_access_step() refuses access to a step until all earlier required steps are complete', 'learnomy' );
__( 'When the student finishes the last required step, on_course_completed() fires, the path is marked complete, and the path-level certificate is issued (course_id column repurposed to store path_id)', 'learnomy' );
__( 'LTI 1.3', 'learnomy' );
__( 'Let an LMS (Canvas, Moodle, Blackboard) launch Learnomy courses as an LTI 1.3 tool, with AGS grade passback.', 'learnomy' );
__( 'Memberships Pro', 'learnomy' );
__( 'Plan changes with fair credit, pause/resume, payment retries, refunds, gifts, and revenue analytics.', 'learnomy' );
__( 'Site owner upgrades a subscriber\'s plan with fair credit calculated from remaining period', 'learnomy' );
__( 'Site owner pauses a subscription and resumes later with time-aware extension', 'learnomy' );
__( 'Failed payment triggers configurable dunning retry sequence (conservative/standard/aggressive)', 'learnomy' );
__( 'Buyer purchases a gift; Gift_Code::issue() creates a row in lrn_pro_gift_codes (PR #136); buyer shares code with recipient; recipient redeems via /memberships/gifts/redeem and Gift_Code::claim_atomically() prevents double-redeem via row-level INSERT-on-claim guard', 'learnomy' );
__( 'Notes & Bookmarks', 'learnomy' );
__( 'Searchable, tag-organized lesson notes and bookmarks for students, backed by dedicated indexed tables. Multiple notes per lesson, FULLTEXT search, per-student tags, and a paginated account hub.', 'learnomy' );
__( 'Enrolled student opens any lesson, sees every note they wrote on it, edits each inline (title + body) with autosave, adds per-note tags from their own tag vocabulary, adds more notes, and toggles a bookmark', 'learnomy' );
__( 'Student visits My Account > Notes & Bookmarks: a full-text search bar, a course filter, tag chips, a Notes/Bookmarks switch, and a paginated newest-first list (20/page) with prev/next + an accurate total', 'learnomy' );
__( 'Student searches a word, narrows by course and tag, paginates, edits a note\'s tags from the lesson, deletes a note, and clicks straight back to the lesson', 'learnomy' );
__( 'Student dashboard shows a Recent Notes widget with live counts that links into the account hub', 'learnomy' );
__( 'QTI', 'learnomy' );
__( 'Import IMS QTI 2.x / 3.0 assessments as native questions and quizzes, played and graded by the built-in quiz engine.', 'learnomy' );
__( 'SCORM', 'learnomy' );
__( 'Upload SCORM 1.2 / 2004 packages as a lesson type; learners play them and completion + score flow into progress.', 'learnomy' );
__( 'Learning Spaces', 'learnomy' );
__( 'Distributor mini-schools — branded student portals with own course catalogs and member rosters.', 'learnomy' );
__( 'Distributor admin clicks Add New Space, names it Acme Training, sets a logo, saves', 'learnomy' );
__( 'Admin opens the space\'s Manage page, assigns 3 courses (only published unassigned courses appear in picker)', 'learnomy' );
__( 'Admin types 5 student emails one at a time → users are added with the chosen role and auto-enrolled in all assigned courses', 'learnomy' );
__( 'Admin copies the 7-day invite link and shares with a batch of new students; clicking the link auto-joins the space (after sign-in if needed) and redirects to /spaces/acme-training/?joined=1', 'learnomy' );
__( 'Member visits /spaces/acme-training/ and sees a logo + member count + clickable course cards leading directly to each course page', 'learnomy' );
__( 'Stripe Connect Payouts', 'learnomy' );
__( 'Instructor payouts via Stripe Connect with KYC pre-flight and webhook-driven status sync. Instructors self-onboard from the front-end Earnings page and dashboard widget; admin-managed payouts on top.', 'learnomy' );
__( 'Admin processes a payout; create_transfer() does a KYC pre-flight check (charges_enabled + payouts_enabled + requirements.currently_due) and refuses with a clear error if the account isn\'t ready', 'learnomy' );
__( 'Stripe sends transfer.paid webhook → on_stripe_webhook() updates lrn_pro_payouts.status=\'paid\' + paid_at', 'learnomy' );
__( 'Stripe sends transfer.reversed webhook → status=\'reversed\' + error_message; admin payouts page shows the reversal', 'learnomy' );
__( 'Customer refunded after instructor was paid: Refund_Handler attempts transfer reversal; if the instructor balance is insufficient, the unpaid amount is booked in lrn_pro_payout_debts and netted against the next payout — admin sees the debt in the Outstanding Debts section on the Payouts page', 'learnomy' );
__( 'User Journey', 'learnomy' );
__( 'Skill scores, learning timeline, achievements, and personalized recommendations.', 'learnomy' );
__( 'Admin opens User Journeys → sees a list of all students with skill counts + courses completed + quizzes passed + last activity', 'learnomy' );
__( 'Admin clicks a student → drilldown shows skills as progress bars + achievement list + chronological activity timeline', 'learnomy' );
__( 'Student visits My Courses → My Journey tab shows their own skill scores, achievements earned, recent activity', 'learnomy' );
__( 'Webhooks Pro', 'learnomy' );
__( 'Unlimited webhooks with automatic retry and delivery logs.', 'learnomy' );
__( 'Site owner creates 10 webhooks (Free limit is 3), each subscribes to specific event types out of the 33-event registry', 'learnomy' );
__( 'Site owner extends the event registry via learnomy_pro_webhooks_event_slugs filter to add custom event slugs without forking', 'learnomy' );
__( 'Webhook delivery to a flaky endpoint retries with exponential backoff (0s, 5m, 30m)', 'learnomy' );
__( 'Site owner clicks Retry on a failed delivery from the Deliveries admin page (new /webhook-deliveries/{id}/retry endpoint)', 'learnomy' );
__( 'Site owner views delivery log to debug why a webhook didn\'t fire', 'learnomy' );
__( 'White Label', 'learnomy' );
__( 'Rebrand the LMS with your own name, logo, and colors.', 'learnomy' );
__( 'Agency replaces \'Learnomy\' admin menu label with their brand name', 'learnomy' );
__( 'Site owner uploads custom admin logo and accent color', 'learnomy' );
__( 'Site owner removes \'Powered by Learnomy\' from frontend templates', 'learnomy' );
__( 'WP Fusion', 'learnomy' );
__( 'Grant course access, auto-enroll, and apply CRM tags through WP Fusion.', 'learnomy' );
__( 'xAPI', 'learnomy' );
__( 'Emit xAPI statements to your Learning Record Store (LRS) as learners enroll, complete lessons, and answer quizzes.', 'learnomy' );
