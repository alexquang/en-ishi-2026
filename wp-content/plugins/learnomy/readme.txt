=== Learnomy - Simple LMS ===
Contributors: vapvarun, wbcomdesigns
Tags: lms, online courses, elearning, quiz, membership
Requires at least: 6.7
Tested up to: 6.9
Requires PHP: 8.1
Stable tag: 1.9.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

The simplest LMS for WordPress: courses, quizzes, certificates, built-in Stripe and PayPal checkout, memberships, and a full REST API. No add-ons.

== Description ==

Learnomy is the LMS WordPress should have shipped with. You don't need MemberPress to protect courses, WooCommerce to take payments, or a separate quiz plugin to test your students. Everything is built in, and you can have your first course live in five minutes.

Most WordPress LMS plugins started as simple course tools and bolted on payments, memberships, and quizzes as expensive addons over the years. Learnomy was designed from the ground up to include all of that in the free version. These are the same features you'd pay $300+ per year for elsewhere.

**Built for solo creators and growing academies alike.** Whether you're a coach launching your first course or a business running a multi-instructor marketplace, Learnomy scales without forcing you to upgrade. The instructor revenue sharing system follows the Udemy model: instructors earn their share automatically, and payouts are tracked through the admin.

**App-ready from day one.** Learnomy ships a full REST API with JWT authentication, a batch endpoint to reduce round trips, offline progress sync, and device registration hooks for push notifications. If you're building a mobile app to complement your courses, the API is waiting for you.

**Why Learnomy?**

* Create a course in 5 minutes. No page builders, no shortcode puzzles
* Built-in membership plans. No $179/yr MemberPress required
* Stripe and PayPal payments work out of the box
* Instructor revenue sharing with automatic commission tracking
* 199 REST API route registrations with JWT, API-key, and Application Password auth
* 41 custom tables. No CPT overhead, no meta bloat
* Works with any WordPress theme
* Follows your theme's light or dark colour scheme
* GDPR compliant: data export and erase hooks included

**Free includes everything you need:**

* Unlimited courses, sections, lessons, and topics
* 6 quiz question types: multiple choice (single), multiple choice (multi with partial credit), true/false, short answer, fill-in-the-blank, and essay
* 3 quiz modes: graded, practice, and survey
* 5 quiz layouts, each labelled for its use case: Focused (timed exams), Classic (class quizzes), Sectioned (SAT/GRE-style), Conversational (knowledge checks), and Review board (long certification exams)
* Multi-subject exams: group questions by category with a subject-wise score breakdown in the results
* Large-exam wayfinding: clickable question map, flag-for-review, per-answer autosave, and resume to the exact question and remaining time
* Proctoring: track tab switches, flag them for graders, and optionally auto-submit after a configurable number of switches
* Rich question stems: a formatted passage or scenario above the answers
* Closed course pricing: sell a course on any external platform and grant access here by email, CSV upload, or a signed enrollment webhook
* Solo Academy mode: one switch turns Learnomy into a single-instructor academy, hiding every marketplace surface
* Standalone quizzes: attach to a course or use independently
* Certificate generation with public verification URLs and QR codes
* Ed25519 digital signatures on certificates
* LinkedIn sharing for certificates
* Unlimited paid membership plans (plus up to 3 free plans) with monthly/yearly billing, free trials, and one-click cancel
* Stripe and PayPal direct checkout. No WooCommerce needed
* WooCommerce adapter if you already use it (payment gateway only, no course/product catalog sync)
* 2-party commission system: platform and instructor earnings tracked automatically
* Coupon system with percentage and fixed discounts, usage limits, and expiry dates
* Instructor application flow with admin review
* Push notifications: Expo (zero-config) plus optional Firebase/FCM
* 12 Gutenberg blocks, all server-side rendered
* 6 sidebar widgets
* 10 shortcodes
* 199 REST API route registrations under `learnomy/v1`
* JWT authentication for headless integrations (15-minute access tokens, 30-day refresh); mobile app uses WordPress Application Passwords
* Batch API: execute up to 25 requests in one call
* Offline progress sync with server-side conflict resolution
* FULLTEXT search across courses and lessons
* JSON-LD Course schema markup for SEO
* XML sitemap integration
* HTML email templates for lifecycle events
* 13 WP-CLI command registrations under `wp learnomy`
* GDPR data export and erase compliance
* WordPress Abilities API integration: 54 LMS actions across 8 categories are AI-discoverable
* BuddyNext and Jetonomy ecosystem hooks
* Follows your theme's colour scheme, built on a CSS token system
* Setup wizard: first course in 5 minutes

**Pro adds:**

* Frontend course creation and editing (no admin needed)
* Weighted gradebook from quizzes, assignments, and SCORM, with manual overrides and CSV export
* Assignment reporting (submissions, graded, average, late) in instructor analytics
* Membership upgrades/downgrades with Stripe proration, pause/resume, dunning retries, and gift codes
* Automatic payout via Stripe Connect
* Advanced quiz types: matching, ordering, numeric, symbolic math, and calculated (randomized-variable) questions
* Content standards: import SCORM 1.2/2004 packages (with a completion gate that blocks Mark Complete until the package reports complete), send xAPI statements to any LRS, act as an LTI 1.3 tool provider with AGS grade passback (beta), import QTI assessments, and embed H5P content
* Certificate trust network: Open Badges 3.0, Credly export, and a tamper-evident local hash registry (public-chain anchoring planned)
* Content drip by days, prerequisites, or fixed dates
* Learning paths with curated sequences and path certificates
* Spaces: B2B seat licensing, owner dashboards, bulk invite/CSV, and team reports
* Cohorts, notes and bookmarks, skills/milestones (User Journey), white-label, AI authoring, WP Fusion, and advanced analytics
* Unlimited outbound webhooks (34 events) with retry logic and delivery logs

== Installation ==

1. Upload the `learnomy` folder to `/wp-content/plugins/`, or install directly from the WordPress Plugins screen.
2. Activate the plugin through the **Plugins** screen.
3. The setup wizard launches automatically. Follow the 3 steps to configure your first course in 5 minutes.

That's it. No configuration required before you can create a course. Payments, memberships, and certificates are optional and can be enabled from **Learnomy > Settings** at any time.

== Frequently Asked Questions ==

= Do I need WooCommerce to take payments? =

No. Learnomy includes direct Stripe and PayPal integrations. Students can check out without WooCommerce. A WooCommerce adapter is available if you already use it and prefer to keep payments there.

= Do I need MemberPress or Paid Memberships Pro? =

No. Learnomy includes a full membership system in the free version: unlimited paid plans plus up to 3 free plans, with monthly or yearly billing, free trials, one-click cancel-at-period-end, invoice history, and Stripe/PayPal recurring. Students can subscribe to a plan to unlock a group of courses. Pro adds proration on plan changes, pause/resume, and dunning retries.

= Can I have multiple instructors sharing revenue? =

Yes. Each course can have a primary instructor plus co-instructors, each assigned a percentage of the instructor's earnings share. The platform rate (default 30%) goes to the site owner; the remainder is split among instructors based on their configured percentages.

= Does it work with block themes? =

Yes. Learnomy works with any WordPress theme, classic or block. The frontend templates can be overridden by your theme at `theme/learnomy/*.php`, following the same pattern as WooCommerce.

= Can students use it on a mobile app? =

Yes. The REST API (`learnomy/v1`) is app-ready: WordPress Application Passwords for the companion app, JWT for headless integrations, a batch endpoint for reduced round trips, offline progress sync, and device token registration for Expo (and optional Firebase) push. The native companion app is a Pro-license benefit.

= How are certificates verified? =

Every certificate gets a UUID and a QR code. Anyone can visit `/verify/{uuid}` without logging in to confirm the certificate is legitimate. Certificates are signed with Ed25519 (PHP libsodium) so the signature can be verified independently.

= What quiz types are included in the free version? =

Multiple choice (single answer), multiple choice (multi-answer with partial credit), true/false, short answer, fill-in-the-blank, and essay. Essay questions go to a manual grading queue where instructors can leave inline feedback.

= Can I import courses from LearnDash or TutorLMS? =

Yes. Learnomy 1.2.0 adds a built-in migration wizard (Learnomy Settings > Import) that imports courses, lessons, quizzes, and questions from LearnDash, Tutor LMS, and LifterLMS, with an optional tier that also brings across enrollments and progress. You can also run it from WP-CLI with `wp learnomy migrate`. Certificates, grades, and payments are not migrated.

= Does it support GDPR? =

Yes. Learnomy hooks into WordPress core's personal data exporter and eraser. Student data (enrollments, progress, quiz attempts, certificates) is included in the standard WordPress privacy export and can be erased on request. Consent is logged with timestamps.

= Will it slow down my site? =

Learnomy uses custom MySQL tables instead of post meta, which avoids the performance issues common in CPT-based LMS plugins. Catalog queries use covering indexes, denormalized counters keep course stats fast, and the object cache is used throughout. FULLTEXT indexes handle search without `%LIKE%` queries.

== Screenshots ==

1. Course catalog with grid/list toggle and instant category filtering.
2. Single course page: curriculum tree, instructor bio, reviews, and enrollment CTA.
3. Course player with sidebar navigation and lesson progress tracking.
4. Quiz interface: one question at a time with instant feedback.
5. Certificate with QR code, Ed25519 signature, and LinkedIn sharing.
6. Student dashboard showing enrolled courses, progress, and quiz scores.
7. Instructor dashboard with earnings, completion funnel, and engagement alerts.
8. Admin course builder: sections, lessons, and topics in a Notion-like editor.
9. Membership pricing page with plan comparison table.
10. Admin settings: payments, commissions, membership, and email configuration.

== Changelog ==

= 1.9.3 - August 2026 =

Sign-up is yours to shape: add your own fields, switch on an anti-spam question, and rename Student, Instructor and Space to whatever your academy calls them. Every ISO 4217 currency is now selectable, and the curriculum finally locks and describes itself honestly.

* New      - Add your own fields to the student and instructor sign-up forms through three hooks, target one form or both, and mark a field required on the server and in the browser.
* New      - A built-in anti-spam question for sign-up, off by default, with a seam any third-party CAPTCHA can plug into instead.
* New      - Rename Student, Instructor and Space across the whole product, not just their content, from Settings or the learnomy_entity_labels filter.
* New      - Every ISO 4217 currency is now offered, so a site can charge in Swiss francs, Kuwaiti dinars or anything else its gateway supports.
* New      - Suspend and resume an instructor, instead of a Remove button that could not do what it said.
* New      - Every curriculum row shows how long it takes, and a topic shows the total of what is inside it.
* New      - The catalog tells a member which courses their membership already covers, before they reach checkout.
* New      - A learner can list their own quiz attempts over the API, and the wishlist returns the courses rather than only their ids.
* New      - Report state and announcement history are readable over the API.
* New      - One instructor grading inbox, with a seam extensions can join rather than opening a second one.
* New      - Extensions can contribute their own integrity repairs to the maintenance sweep.
* Improve  - The curriculum locks to real access rather than a price check, and says which of the three reasons applies.
* Improve  - A locked lesson remembers the course it sent you to, through logging in as well as signing up.
* Improve  - The sidebar stops printing a section heading over nothing, and a protected video says when it cannot play instead of showing an empty frame.
* Improve  - The instructor navigation hides the links a person would only 404 on.
* Improve  - A long option list on sign-up becomes a pick-and-add control instead of a wall of tick boxes.
* Improve  - Save confirmations use the real toast and survive the page reload that follows.
* Improve  - Recommended course cards fit inside their card on a phone.
* Improve  - The lesson drawer dims the page behind it, so it reads as a drawer.
* Fix      - A currency code now has a space before its amount, so a price reads CHF 49.90 rather than CHF49.90.
* Fix      - Bahraini, Iraqi, Jordanian, Kuwaiti, Libyan, Omani and Tunisian dinars are stored in thousandths, as their standard requires.
* Fix      - A customer on a site with two-factor login is no longer told their password is wrong. The response now says a site login is required and why.
* Fix      - The notification panel closes through its store rather than by writing to the page, so the bell and the panel stop disagreeing.
* Fix      - The instructor roster stops counting courses that no longer exist.
* Fix      - When a person has more than one enrollment row for a course, the row that got furthest represents the pair.
* Fix      - A course with no sections renders through the same path as one with them, and a curriculum card no longer invents a title.
* Fix      - The instructor suspension check tests the person acting, so an administrator is not blocked by somebody else's suspension.
* Fix      - Certificate names stay on one line, and the font the instructor picked reaches the PDF.
* Fix      - The lesson editor says when the fixed Available date is not the one actually in force.
* Fix      - The instructor landing page explains itself again instead of returning 404.
* Fix      - One resolver decides what the course call to action says, so the enrollment card and the button stop contradicting each other.
* Fix      - The lesson-comments toggle renders on the course form again, and the form stops saving settings it does not show.
* Fix      - An instructor's grade override now beats the auto-grader instead of being overwritten by it.
* Fix      - Checkout prices come from one resolver, and the WooCommerce order summary shows the price it charged.
* Fix      - The catalog Paid and Free filter asks what the learner actually pays.
* Fix      - The grading API refuses a score above the question's maximum instead of echoing it back.
* Fix      - A reported item keeps its Reported state across a reload, and the modules toggle stops reporting a network error over a save that succeeded.
* Fix      - Money in templates and notifications carries its currency, formatted one way throughout.
* Fix      - Commission rules can be deleted, as the documentation already promised, and a refunded commission stops counting as earned.
* Fix      - Three controls that looked active and did nothing now do what they say.
* Fix      - Instructor announcements report the real reason Send failed, in the three cases where it could.
* Fix      - A learning path with an unavailable required step is no longer sold.
* Fix      - Creating a course derives its slug, as creating a quiz already did.
* Security - The sign-up rate limiter now runs before the anti-spam challenge, so a flood cannot force repeated challenge work.
* Security - The instructor status route is registered and permission-gated rather than reachable unannounced.
* Dev      - New hooks: learnomy_account_fields, learnomy_account_fields_validate, learnomy_account_fields_save, learnomy_registration_challenge, learnomy_registration_challenge_verify, learnomy_entity_labels and learnomy_three_decimal_currencies.
* Dev      - Entity relations are declared once, with cascade, detection and repair derived from that declaration.
* Dev      - 140 lifecycle events are published, and publishing an event is now gated at zero tolerance rather than left to habit.
* Dev      - The manifest lists the 37 REST routes it was missing, and a gate keeps it honest.
* Dev      - Every functionality group shipped this release has a browser journey.
* Dev      - Removed: Learnomy no longer edits your site's navigation menu. It used to hide menu items - instructor sections from students, "Become an Instructor" from instructors, student signup from anyone signed in - and it could remove the wrong item, including Home. Every one of those pages now explains itself instead, so nothing needs hiding. The `learnomy_hidden_nav_routes` filter added in 1.9.1 is gone with it; if you hooked it, your callback is simply no longer called and menu items you were hiding will reappear.
* Dev      - New filter `learnomy_route_access_gates` (route name => template partial) so a route can answer a refused visitor with an explanation instead of a 404. The five instructor section routes now return 200 with an access gate rather than 404 for a signed-in non-instructor.

= 1.9.2 - August 2026 =

Quiz start screens and review you can actually read, clearer admin lists, and a set of corrections to what the product tells you about email, answers and access.

* New      - Quiz start screens come in three layouts and you pick the one you want per quiz.
* New      - The quiz attempt review exports as a printable PDF.
* New      - A long review reads by section: 300 questions collapse into groups and filter down to just the mistakes.
* New      - The quiz review shows which answer the student picked, beside the correct one.
* New      - Instructors set how many questions a sectioned quiz draws from each section.
* New      - Lessons can be detached to an orphan pool on purpose, and attaching a lesson that belongs elsewhere asks whether to Copy or Move it.
* New      - The curriculum can return sub-lessons.
* Improve  - Bulk actions sit above the list on every admin list screen, and row actions collapse into an overflow menu instead of crowding the table.
* Improve  - The curriculum says WHY a lesson is locked, not only that it is.
* Improve  - Settings are grouped by what they do rather than by which plugin adds them, and no settings row is left outside a group.
* Improve  - Pro Tools groups its tools under Teaching, Skills and progress, and Standards and import instead of one flat list.
* Improve  - The seat product is called Learning Spaces everywhere it is named, so it is not confused with the Spaces feature other plugins add to the same site.
* Improve  - Switching a module off shows a screen naming the module to enable, instead of a permission error that reads like a fault.
* Improve  - Learnomy routes present as pages, so theme builders render their headers and footers on them.
* Improve  - Lesson comments go through normal WordPress moderation, and a comment made during a lesson can be deleted.
* Improve  - "In progress" means enrolled and not finished, and one progress label is used across the screens.
* Improve  - Certificate field labels say what each field actually takes.
* Fix      - Outgoing email keeps the WordPress sender address when you have not set one. Learnomy replaced it with the site admin address, which is often on another domain and sent transactional mail to spam.
* Fix      - "Show quiz answers: only after the student passes" now does exactly that. It was treated as "after each attempt", which handed the answer key to anyone who failed once.
* Fix      - A course that requires a membership no longer offers a Join link when memberships are switched off, because that link led to a page which does not exist on those sites.
* Fix      - A quiz that belongs to a course opens correctly from a learning path step instead of a Not Found page.
* Fix      - A lesson quiz sends the student back to the lesson it belongs to.
* Fix      - A quiz is only as open as the lesson it belongs to.
* Fix      - Instructors can reach Assignments, Content Drip and Learning Paths from the admin menu again.
* Fix      - White Label no longer renames all three Learnomy menus to the same word, which made Commerce and Settings impossible to tell apart.
* Fix      - Reported Content opens a page headed Reported Content, and no page heading carries the pending count from its menu row.
* Fix      - Analytics shows a dash where nothing has been graded yet and a real 0% where a group genuinely scored zero. The two were indistinguishable.
* Fix      - A learner at zero percent reads as in progress, not as missing.
* Fix      - A student who passed is no longer shown a cross on their result.
* Fix      - Saving a course never changes its price.
* Fix      - A zero-total order completes without a payment gateway configured.
* Fix      - Subscription trials reach Stripe, and a trial checkout still mounts.
* Fix      - A mistyped payout email is refused instead of silently paying the previous address.
* Fix      - Dates the site does not have render as a dash, and are no longer written in the first place.
* Fix      - Coupon and money-back wording appears only where a purchase can actually happen.
* Fix      - Course pages no longer advertise "0 lessons" as a feature.
* Fix      - An instructor sees their own course's content.
* Fix      - A seeded membership plan opens the courses it advertises.
* Fix      - Course payloads carry the price exactly as the site prints it.
* Fix      - A member whose plan includes a course can open its lessons. The course page offered the course and the lesson then asked them to enrol.
* Fix      - Instructor pages ask whether you may see them, not merely whether you are signed in. A student who typed the address was shown the instructor console.
* Fix      - The Self-Publish Courses setting works in the course editor. Instructors it applied to were still offered Draft and Pending Review only.
* Fix      - My Courses leaves out trashed courses, and its Next and Previous buttons work on the My Courses page.
* Fix      - Review and Retake on the Quiz Scores panel open the quiz instead of a Not Found page, and a course quiz opened at its short address goes to the right place.
* Fix      - Rejecting an instructor application asks for a reason, and the applicant reads it. Approve and Reject also work on the instructor's own page, where neither did anything.
* Fix      - Applying to teach again after a rejection says what happened. The form also explains a missing field, an over-long answer and too many attempts, none of which reached the applicant before.
* Fix      - A course page's breadcrumb leads back to Courses. Signing in re-pointed it at your account.
* Fix      - The notifications panel closes. On some themes it painted over the page and stayed there.
* Fix      - The account menu on a phone closes when you tap outside it or press Escape.
* Fix      - Learnomy blocks appear under Learnomy in the block inserter instead of Uncategorized.
* Fix      - Buttons that carry a course or space name wrap inside their border instead of running past it.
* Fix      - The quiz buttons fit on a phone, at one height and one shape, and nothing floats over them.
* Fix      - Previous and Next lesson cards line up when stacked on a phone, and a page heading's icon sits with its text at every size.
* Fix      - A withdrawal of a single earning reads "1 earning".
* Dev      - Right-to-left support: physical left/right CSS converted to logical properties, with a gate to keep it that way.
* Dev      - Quiz URLs are built through one helper, so a course quiz and a standalone quiz always resolve to the route that serves them.
* Dev      - New filters: learnomy_sender_email, learnomy_sender_name, learnomy_membership_on_offer, learnomy_admin_settings_nav_groups, learnomy_pro_tools_card_groups.
* Dev      - A Pro content type can register what a client needs to play it, and content-type labels come from one place.
* Dev      - Buttons whose label is content opt into wrapping with one modifier, and instructor routes gate on a named ability rather than a hardcoded capability.
* Compat   - Requires Learnomy Pro 1.9.2. Install both updates together.

= 1.9.1 - August 2026 =

Corrections across the learning loop, the catalog and the instructor screens, plus one reporting fix that will change numbers you have already seen.

* New      - The quiz result screen offers View Certificate when you hold one for that course, including the certificate that pass just earned.
* New      - Catalog cards carry an Enroll or Buy action, so a course you have not bought invites you in like one you have.
* New      - Instructor My Courses has a Create Course action on the list itself, not only on the dashboard.
* New      - Members can edit their own billing address from the account area.
* Improve  - Reports Completion counts everyone who finished the course, not only learners who enrolled inside the reporting window. This CHANGES figures you have seen: a course that read 0% may now read its real rate.
* Improve  - Revenue totals count each refund once. Earnings and analytics figures will move, and the new numbers are the correct ones.
* Improve  - Students list shows how many courses a learner is in; the student page still lists which.
* Improve  - Instructor My Courses and Reports drop columns that duplicated the Earnings and reviews screens, so both tables fit without sideways scrolling.
* Improve  - The account navigation is shorter, so pages are no longer as tall as the menu and the footer is easier to reach.
* Improve  - Dashboard stat tiles share one baseline instead of sitting at four different heights.
* Fix      - The quiz shortcode's Start Quiz button opens the quiz, instead of a missing page, when the quiz belongs to a course.
* Fix      - A linear sectioned quiz opens on its first question instead of a second Start gate, so a timed exam no longer runs the clock on a screen you cannot answer.
* Fix      - Registration links no longer appear to people who already hold the role, and an instructor can no longer take the student role by accident.
* Fix      - Nav menu filtering and the registration security check now work on block themes, where neither applied before.
* Fix      - The demo academy's fill-in-the-blank question can be answered.
* Dev      - New filters learnomy_hidden_nav_routes and learnomy_instructor_routes; new helper \Learnomy\instructor_routes().
* Dev      - Quiz submit responses carry a certificate object when the student holds one for that course.
* Dev      - Documented the certificate visibility contract in docs/standards/certificate-visibility.md.
* Compat   - Aligned with Learnomy Pro 1.9.1. Install both updates together.

= 1.9.0 - August 2026 =

Invite-only courses, per-course SEO control, and a large payments correctness sweep. Every PayPal one-time purchase now completes, and delayed bank payments enrol the student when the money lands.

* New      - Invite-only courses: you add the students, nothing is shown for sale, and checkout refuses the course.
* New      - Per-course SEO overrides for title, meta description, social image and robots.
* New      - Member billing address, kept on the account and frozen onto each order.
* New      - Delayed payment methods (SEPA, Bacs, BECS, boleto, OXXO, Konbini) fulfil the enrolment when the funds clear.
* New      - Filter learnomy_instructor_profile_url points an instructor's name at any page you choose, or none.
* Improve  - Account and instructor navigation collapses to a labelled disclosure on phones instead of a clipped rail.
* Improve  - Nav menus now offer nine account and instructor pages that could not be added before.
* Improve  - One purchase recorder across every gateway, so tax, fees and idempotency behave the same everywhere.
* Improve  - A refused action can explain itself instead of always reading as a permissions problem.
* Fix      - PayPal one-time purchases redirect to checkout again; every one previously failed silently.
* Fix      - Stripe subscriptions charge, enrol and record again after Stripe relocated three fields.
* Fix      - Renewals extend to the next period instead of expiring the member on the day they paid.
* Fix      - Refunding a membership updates the order instead of leaving it marked paid.
* Fix      - Tax is recorded on Stripe orders instead of counting as zero.
* Fix      - A private academy hides its categories and tags, not only its courses.
* Fix      - Lessons and quizzes can no longer be created under a trashed course.
* Fix      - The catalog search box keeps what you typed while suggestions load.
* Fix      - Success messages appear fully on screen instead of clipped at the corner.
* Fix      - Exported analytics reports no longer contain a Print button.
* Fix      - Draft courses stay available in every content picker; only trashed ones are withheld.
* Fix      - Sixteen style rules that silently rendered nothing now apply, including headings and a warning border.
* Fix      - The thank-you page stops refreshing forever and states the position if fulfilment is slow.
* Security - Private-academy taxonomy endpoints no longer expose category and tag names to signed-out visitors.
* Dev      - wp learnomy test no longer deletes the current month's commission rows while reporting success.
* Dev      - New gates: undefined CSS tokens, unresolvable journey references and hedged test assertions now fail the build.
* Compat   - Requires Learnomy Pro 1.9.0. Install both updates together.

= 1.8.0 - July 2026 =

A SCORM completion gate, search and social tags for every course page, a reorganized admin menu that fits on one screen, and a large stabilization sweep.

* New      - Lessons can require a SCORM or standards package to report completion before the student can mark the lesson complete, so a "mark complete" trigger inside the package is what advances the course.
* New      - Every course, catalog, lesson, membership and instructor page now writes its own meta description and social sharing tags, so a shared link shows a real title and summary instead of navigation text.
* Improve  - The Learnomy admin menu fits on one screen: Pro tool pages moved into a single Pro Tools launcher, every row sits under a labelled section, and with all extensions enabled the menu dropped from 33 rows to 22 so nothing is cut off below the fold.
* Improve  - Menu labels no longer collide: the moderation queue is "Reported Content", the payment log is "Payment Webhook Log" beside "Outgoing Webhooks", the companion installer is "Companion Plugins", the Pro catalog is "What's in Pro", and the top-level menus are "LMS Commerce" and "LMS Settings" so they fit one sidebar row. Every page address is unchanged, so existing bookmarks keep working.
* Improve  - The Modules screen groups and explains each module instead of showing one long list, so a site owner can see what each one does before enabling it.
* Improve  - A large course curriculum loads without a per-lesson query, so the page stays fast as the lesson count grows.
* Improve  - "My Account" and "My Courses" can be added to any menu from Appearance, Menus, so members can reach their grades, notes, quiz attempts and billing without a hand-written link.
* Improve  - Students no longer see the WordPress admin bar on the site; it stays for admins and instructors, and a filter turns it back on.
* Improve  - On phones the account navigation labels are readable at 12px, and the floating notification bell reserves its own space so it never covers the last row of content.
* Fix      - A draft lesson no longer counts toward course progress, so a student who finished every published lesson reaches 100% and receives their certificate instead of being stuck below the line.
* Fix      - Draft lessons and topics are hidden from the curriculum, the lesson list API, resume links and next-lesson navigation for students, while instructors still preview them.
* Fix      - Draft quizzes are hidden from the course curriculum and from the course content counts for students and visitors, matching the existing behaviour for draft lessons, while instructors still preview them.
* Fix      - A quiz with no questions can no longer be started or submitted; students see a clear "check back later" notice and instructors are pointed to the quiz editor.
* Fix      - A course whose date was never set no longer shows "Last updated November 30, -0001" on its page.
* Fix      - Typing in the catalog search no longer wipes the box after two characters when the suggestion dropdown opens.
* Fix      - The "Remove Demo Content" button in the dashboard alert bar works; it previously did nothing while the same button inside the onboarding card worked.
* Fix      - The setup wizard launches on first activation instead of never appearing.
* Fix      - The [learnomy_my_courses] shortcode shows the same enrolled courses as the account page; it previously claimed "no courses" for a student whose course was set back to draft.
* Fix      - Publishing a course without publish permission now submits it for review instead of silently staying a draft nobody ever saw.
* Fix      - The Enrollment Mode setting was removed until approval enrollment is actually built; it saved a choice that nothing enforced, so "Approval" behaved exactly like "Open".
* Fix      - The course Q&A and course-comments toggles only appear when a feature is installed to power them, so enabling one can no longer produce an empty section on the course page.
* Fix      - A quiz shown under a section now displays its pass percentage, matching quizzes outside sections.
* Fix      - Instructor student and report lists could not reach page two; the page link led to a "not found". The pagination rule is now generated so every page is reachable.
* Fix      - A shared breadcrumb was overwriting the row total on list pages, which silently hid the pager and left later pages unreachable on large rosters.
* Fix      - Text, PDF and download lessons no longer load the video player, and a video URL removed from a lesson no longer stays in the page source.
* Fix      - The "require login for all course content" setting now covers every public course surface, not only the lesson template.
* Fix      - Currency symbols follow each locale's placement instead of a fixed prefix.
* Fix      - Non-enrolled visitors no longer see the course intro video twice, tables no longer show a phantom scrollbar at tablet widths, and engagement alerts no longer wrap one word per line on narrow screens.
* Fix      - Certificate PDF downloads open correctly. Warnings from the PDF library were being written into the file ahead of the document itself, which left it unreadable. The same fix covers the Pro analytics report exports.
* Security - Draft, pending and archived courses are hidden from the public: the course page returns not-found, enrollment is refused, and no search-engine data is emitted for them. Students already enrolled keep full access when a course is unpublished, and new enrollments require a published course.
* Dev      - Quiz attempts can be read as one best-percentage-per-student map for a course in a single query, and front-end URLs go through one route-slug filter with a completeness gate that rejects hardcoded paths.
* Dev      - Lesson model reads default to published status with an explicit every-status opt-in for editorial callers, and the hidden-submenu filter tolerates the value-append shape instead of emitting a broken selector.
* Compat   - Pairs with Learnomy Pro 1.8.0, which adds the weighted gradebook, the SCORM completion gate answer, and assignment reporting. Install both updates together.

= 1.7.0 - July 2026 =

Live sessions you can actually schedule, image sizing in your content, correct certificate printing, tax on PayPal, partial refunds, two security fixes, and instructors no longer see admin screens they cannot open.

* New      - Live sessions have a Join link, a start time, and a duration. Students see a countdown before it starts, a Join button while it runs, and a clear "session has ended" afterwards.
* New      - Images in lessons and course content can be set to a width (25%, 50%, 75%, 100%) and aligned left, center, or right, with text flowing around them. On phones they go full width automatically.
* New      - Courses can be duplicated from the Courses list. The copy is created as a draft with the full curriculum.
* New      - Refunds can be partial, and a sale stays refundable until it is refunded in full. Refund 25.00 of a 100.00 order today and the Refund button offers the remaining 75.00 later. Only a full refund revokes access and reverses commission.
* New      - The grace period for a failed subscription payment is configurable under Settings, Payments (0 to 90 days, default 7). It was previously fixed at 7 days with nothing in the interface to show it.
* New      - Mobile push notifications. Device tokens have been collected since 1.0 and nothing ever sent to them. Add your Firebase service account under Settings, Emails and members get the notifications they already receive in-app and by email on their phone as well. Off by default; a site without a mobile app needs nothing.
* New      - Instructor withdrawals now capture where the payout should go. When an instructor requests a PayPal payout they enter the PayPal email, and the site owner sees it on the withdrawal so they know exactly where to send the money. An "arrange by email" option is available for any other method, and it stores no account details. No bank numbers are ever stored; those stay with your payment gateway.
* New      - Members can block another member. The blocked member's comments and reviews no longer appear to them, and they can unblock anyone from a list on their account. Reporting content to a moderator was already available.
* Improve  - The Analytics page now shows completion rate and average score for each course. The site owner previously saw less about their own academy than each instructor saw about their courses.
* Fix      - Pausing a subscription now records when it was paused, and resuming clears it. A resumed subscription used to look permanently paused to reports and exports.
* Improve  - Learnomy follows your theme's colour scheme instead of showing its own dark-mode switch on top of it. Site owners on a theme with no dark mode can turn the switch back on with the learnomy_standalone_dark_mode filter.
* Improve  - Instructors can reach rubric grading from the grading queue when a quiz has a rubric. The screen existed but nothing linked to it.
* Fix      - Scheduled live sessions were locked to students until the moment they began, so nobody could see the countdown or the join link. The start time is now stored separately from the drip schedule, and existing live lessons are unlocked automatically on update.
* Fix      - Tax is applied to PayPal payments, not only Stripe.
* Fix      - A course's price is charged in the currency set on its plan, instead of always using the site's default currency.
* Fix      - Zero-decimal currencies such as Japanese Yen and Korean Won are charged the exact amount, not one hundred times too much, on both Stripe and PayPal.
* Fix      - A failed subscription renewal now marks the subscription past due and begins the dunning emails on PayPal, and on Stripe for sites without Pro, matching what WooCommerce already did.
* Fix      - A course a member joined through more than one route (for example free and then a membership) no longer appears twice in My Courses, and the "Courses Enrolled" total counts it once.
* Fix      - Course prerequisites saved from the front-end course builder no longer lose the first prerequisite in the list.
* Fix      - The editor's border, background, and stretch options for an image are now saved. They were previously discarded, so the toggle appeared to do nothing and reverted on reload.
* Fix      - Certificates print correctly: the QR code no longer overlaps the signature, a long course title no longer prints through the completion date, the Modern design's signature is no longer cut off the edge of the page, and the Elegant designs now draw the border they are named for.
* Fix      - Instructors no longer see the site owner's dashboard, or links to Certificates, Settings, Modules, Categories, and Earnings that answered "Sorry, you are not allowed to access this page". They now get their own dashboard, and are told plainly when an administrator has to do something for them.
* Fix      - Licensing assets no longer fail to load on hosts where the document root does not match the plugin path (symlinked hosts, some managed hosts, local dev).
* Fix      - Partial refunds are no longer double-counted when the payment gateway's confirmation webhook arrives after an admin refund. A 25.00 refund is recorded as 25.00, not 50.00, so revenue, access, and instructor commission stay correct.
* Fix      - A sale can never be refunded past the amount charged, even if the Refund button is double-clicked or two refunds land at the same moment.
* Fix      - Uninstalling Learnomy Free no longer deletes Learnomy Pro user data - the instructor's connected payout account, LTI identity, and saved notes and bookmarks are preserved.
* Fix      - Mobile push notifications are delivered in the background instead of inside the payment webhook, so a member with several devices can no longer slow a checkout or cause a gateway to retry.
* Fix      - Image width and alignment labels in the content editor are now translatable, and the front-end course builder's live-session fields no longer emit duplicate element ids.
* Fix      - The Instructors admin page lists every instructor who teaches a course, including course authors and co-instructors. It used to show "No instructors yet" next to courses that had real instructors, because being assigned to a course did not create the instructor record the list is built from.
* Fix      - Center-aligned certificate text placed near the left or right edge no longer clips off the page in the download. It stays within the certificate and wraps if needed.
* Fix      - Right-aligned certificate text prints where it sits in the designer. It was placed from an estimate of the text width that drifted from the real width, more so for longer lines.
* Fix      - Course-access-expiry and membership-renewal reminders are written in each member's own language, not the site's.
* Fix      - With "Require login for all course content" turned on, the course grid and course catalog blocks and the XML sitemap no longer show the course list to logged-out visitors. Only the single-course pages and the REST API were covered before.
* Dev      - New Notification_Service::send_many() queues a large recipient fan-out (bulk enrolment, announcements) through Action Scheduler. Default async; filter learnomy_notifications_async to force inline.
* Dev      - Member blocking stores a lrn_blocked_users table (database version 3.26.0) with routes POST and DELETE /users/{id}/block and GET /users/me/blocks; a blocked member is filtered from comment and review reads on every surface, and both directions are cleared on account erasure.
* Dev      - wp learnomy repair student-count counts distinct people, so a course a member joined through more than one route is no longer reported as needing repair on every run.
* Security - A course's title, full description, status, and price could be read by anyone, including for draft, pending, and private courses, through the Abilities REST surface. It now applies the same visibility rules as the rest of the API.
* Security - A member's in-progress courses and completion percentages could be read by anyone. That data is now visible only to the member themselves and to staff.
* Dev      - New shared gate \Learnomy\can_view_course( $course, $user_id ): every door that serves a course by ID applies the same two rules (unpublished is owner/admin only; private is owner/admin or enrolled).
* Dev      - New lesson columns scheduled_start_at and live_duration_minutes (database version 3.18.0). A live session's start no longer shares the drip column. New filter: learnomy_live_session_duration.
* Dev      - New withdrawal column destination (database version 3.19.0) holds the payout payee (the PayPal email), or stays empty for a manual arrangement. The POST /earnings/withdraw endpoint takes an optional payout_email, required when method is paypal. Readable, low-sensitivity; never a bank number.
* Dev      - New single readers for values that were previously derived in several places: tax_rate(), tax_amount(), charge_currency(), subscription_grace_days(), Course::prerequisite_ids(), datetime_local_input().
* Dev      - New filter learnomy_grading_row_actions on the grading queue, so an extension can add a grading route without forking the view.
* Dev      - Image layout is stored on the block as { width, align } and rendered as classes on the figure. Both directions are enforced by the content round-trip gate, so a layout that cannot be read back fails the build instead of being silently erased.
* Dev      - New action learnomy_webhook_deleted fires when a webhook is deleted, so an extension can clean up rows that hang off it. Learnomy Pro uses it to purge the deleted webhook's delivery log immediately instead of waiting for retention.
* Dev      - Database version 3.20.0 backfills the instructor record for existing course assignments, so course authors and co-instructors appear in the Instructors list on upgrade.
* Compat   - Requires Learnomy Pro 1.7.0 for the Pro modules. Install both updates together.

= 1.6.0 - July 2026 =

A ready-to-use certificate library with instructor controls, honest test-mode payments, safer instructor deletion, correct standards labels, and proper account-page titles.

* New      - Certificate library: four ready-made certificate designs (two Course Completion, two Quiz Completion) are seeded and available to every instructor out of the box, so a course can award a polished certificate with no design work.
* New      - Instructor availability: admin-designed certificates stay private to admins by default, and you can share any template with all instructors or specific instructors from the certificate designer.
* New      - Private academy: a "Require login for all course content" toggle under Settings, General puts every course, lesson, quiz, and the catalog itself behind a login.
* Improve  - Instructors now pick a certificate from a clear, labeled list (Course Completion or Quiz Completion) scoped to what they are allowed to use, instead of a long list of unlabeled templates.
* Improve  - Test payment mode is now clear and honest: one "test payment mode is on" notice instead of a misleading "customers cannot purchase" warning, and simulated Test-gateway orders are recorded at zero so they never inflate your revenue, earnings, or payouts.
* Fix      - Deleting an instructor now reassigns their courses to the site admin with a confirmation notice, instead of leaving those courses showing "Unknown".
* Fix      - The lessons list now labels standards content types correctly (SCORM, xAPI, LTI, QTI, H5P), while learners no longer see the underlying technical type on course cards.
* Fix      - Member account pages (Certificates, Notes, Spaces, Learning Paths, Cohorts, Assignments, Billing, Details) now carry proper browser and search titles instead of just the site name.
* Fix      - A certificate whose course was later deleted now verifies as "Course no longer available" and stays valid, instead of showing "Unknown Course".
* Dev      - New extension point learnomy_route_title lets add-ons title their own front-end routes; new filters learnomy_unknown_instructor_label and learnomy_technical_content_types.
* Dev      - Certificate library is an extensible registry: add designs on the learnomy_certificate_designs filter (seeded idempotently), and tune the specific-instructor picker cap with learnomy_certificate_instructor_choice_limit.
* Compat   - Requires Learnomy Pro 1.6.0 for the Pro modules. Install both updates together.

= 1.5.0 - July 2026 =

A control-and-trust release: hold instructor courses for admin approval, moderate reported content from a new queue, edit every notification email without code, and hand SEO tags to Yoast or Rank Math when they are active.

* New      - Course-approval gate. Require admin approval before an instructor's course goes live, with a global toggle, a per-instructor override, and Approve or Reject actions on the Pending Review tab.
* New      - Content moderation. Members can report a review, comment, or course, and admins action those reports from a new Reports queue.
* New      - Per-template email editor. Edit the subject and body of any Learnomy notification from Settings using merge tags, with no code.
* New      - SEO plugin handoff. When Yoast or Rank Math is active, Learnomy defers its canonical and Open Graph tags to that plugin while keeping its own Course structured data.
* Improve  - The Email Log now records and shows the failure reason for a failed email, adds a one-click resend, and lets you search by recipient and date.
* Improve  - Admin usability sweep: a single Learning Standards hub, a per-module surface map with Pro toggles locked when Pro is not installed, and clearer setup-wizard signposts.
* Improve  - Coupons can be restricted to specific courses with a searchable picker, and the enrollment card now reprices live when a coupon is applied.
* Improve  - The wp-admin course builder can now add a topic under a lesson without leaving the builder.
* Improve  - The course builder and lesson drip scheduling batch their database work instead of running per-row queries, so large courses load and schedule faster.
* Improve  - Removed two dead settings surfaces - the site-wide lesson-body search and the API Keys screen - that were wired to nothing.
* Fix      - Catalog search is now fully keyboard-navigable and announces the active suggestion to screen readers.
* Fix      - The instructor dashboard now shows the Awaiting Grading widget so essay and manual attempts are not missed.
* Fix      - The data-integrity CLI check now flags only genuine orphaned progress rows instead of false positives.
* Fix      - In the wp-admin course builder, a section's Rename, Move, and Delete now work the moment you add it, without reloading the page.
* Fix      - Deleting a parent course category no longer hides its sub-categories; they move up to the parent's level instead of disappearing.
* Fix      - Attempting to refund an already-refunded transaction now reports "already refunded" instead of a misleading "not completed" message.
* Security - Closed access-control gaps found in a security sweep: the public subscribe endpoint no longer lets a paid plan be joined for free, and REST API keys are accepted only in the request header, not the URL.
* Dev      - New extension points: learnomy_lesson_unlock_date, learnomy_membership_plan_after_form, a course sidebar-card footer seam, and public per-member credential REST endpoints for certifications and teaching.
* Compat   - Requires Learnomy Pro 1.5.0 for the Pro modules. Install both updates together.

= 1.4.0 - July 2026 =

A major release: a full exam-grade quiz engine (five layouts, subject grouping, rich question stems, large-exam wayfinding, and proctoring), a "sell anywhere, fulfil here" Closed pricing model, a single-instructor Solo Academy mode, native Fediverse video, and the Free foundation for SCORM, xAPI, LTI, QTI and H5P.

* New      - Closed course pricing. Sell a course on any external platform (your own checkout, Gumroad, a CRM) and grant access here by email, CSV bulk upload, or an automatic signed webhook; refunds can auto-revoke access. Includes a copy-paste connection recipe on the Webhooks screen.
* New      - Solo Academy mode. One switch turns Learnomy into a single-instructor academy, hiding every instructor-marketplace surface (instructor registration, commissions, earnings, become-instructor links) and forcing the monetization module off.
* New      - Large-exam mode for the Focused and Review board layouts. A clickable question map, flag-for-review, per-answer autosave, and resume to the exact question and remaining time, verified at 150 questions.
* New      - Five quiz layouts, each labelled with its best use case: Focused (timed exams), Classic (class quizzes), Sectioned (SAT/GRE-style), Conversational (knowledge checks), and Review board (long certification exams).
* New      - Group questions by subject or category across all five quiz layouts, with a per-category score breakdown in the results so a raw percentage becomes a subject-wise diagnostic.
* New      - Free section navigation for the Sectioned layout. Candidates choose which section to attempt first and switch between sections until they submit.
* New      - Rich question stems. Author a formatted passage or scenario above a question's answers, stored round-trip-safe and rendered across every quiz layout.
* New      - Quiz proctoring. Track when a student switches browser tabs, flag it for graders, and optionally auto-submit the quiz after a configurable number of switches; a monitored notice on the start screen tells candidates the rules before they begin.
* New      - Native Fediverse video. Embed PeerTube and other Fediverse videos through SSRF-guarded oEmbed discovery, with no per-domain allowlist to maintain.
* New      - Course search autocomplete. A typeahead dropdown of matching course names appears as you type on the catalog.
* New      - Content-standard foundation. New lesson content-type, first-login provisioning, and quiz hooks let the Pro SCORM, xAPI, LTI, QTI and H5P modules plug straight into Learnomy's own progress, grading and reporting.
* New      - The demo installer now seeds a categorized certification exam so a fresh install showcases grouped display, one-click navigation, and subject-wise scoring out of the box.
* Improve  - Add questions from the Question Bank while creating a quiz, without having to save and reopen it first.
* Improve  - The per-attempt exam-integrity report for graders now shows the submission IP address alongside the tab-switch count and late-submission signals.
* Improve  - Every front-end and admin string is translatable, including the Interactivity-API quiz, catalog and membership modules, with professional copy and refreshed language templates.
* Improve  - The quiz layout picker in the editor now describes the best use case for each of the five formats, so instructors choose the right one without guidance.
* Improve  - The membership FAQ editor is now an add/remove repeater instead of a fixed grid of blank rows.
* Improve  - Admin polish sweep: uniform on/off toggles, consistent status badges and action buttons, clearer Email Log and Settings, a shared CSV-import component, and self-contained settings cards across screens.
* Improve  - The demo installer seeds a complete, non-empty dataset on every screen (real courses, lessons, reviews, webhooks, cohorts, spaces, and a categorized certification exam) so the product demonstrates fully out of the box.
* Improve  - AI-assisted translations now ship at full coverage for 9 Free locales (German, French, Spanish, Italian, Dutch, Brazilian Portuguese, Polish, Swedish, Russian).
* Fix      - Multiple-choice answer options no longer re-shuffle when a student resumes an in-progress quiz.
* Fix      - Flag-for-review marks on the Review board layout now survive a mid-exam refresh.
* Fix      - The lesson-player sidebar now shows lessons that are not inside a section.
* Fix      - Shortcodes in lesson content and course descriptions (contact forms, WP Fusion, galleries, or any third-party shortcode) now save as the shortcode and render on the front end. They previously corrupted the saved content and could show raw data on the page. The shortcode stays visible as a shortcode in the editor and renders only in the view, exactly like a WordPress post.
* Fix      - Removed orphaned enrollment, progress and commission rows left by earlier deletes, and routed demo-data teardown through the full delete cascade.
* Security - The incoming enrollment webhook now requires a valid HMAC signature. It grants paid access, so an unsigned or unverifiable request is rejected instead of processed.
* Dev      - New extension points: learnomy_closed_cta, learnomy_incoming_webhook_payload, learnomy_bulk_enroll_max, learnomy_section_navigation, learnomy_lesson_content_types, learnomy_provision_role, plus single-source Course pricing constants and question-type/stem seams.
* Dev      - cmi5 support is planned with a code-verified implementation plan and will land in a later release.
* Compat   - Aligned with Learnomy Pro 1.4.0. Install both updates together.

= 1.3.0 - June 2026 =

Uniform embeds on every screen, one editor toolset on backend and frontend, and full translation readiness with ready-to-use language files.

* New      - Uniform embeds. The lesson Embed type, embeds inside lesson and course content, and migrated content all render through one WordPress oEmbed pipeline as a premium responsive player, with a clean link-card fallback for anything WordPress cannot embed. Covers all built-in oEmbed providers (YouTube, Vimeo, TikTok, Spotify and ~40 more).
* New      - Author embed preview now matches the student view in the lesson editor and the front-end course builder, via a new REST endpoint GET /learnomy/v1/oembed-preview.
* New      - Translation ready. Every front-end string is translatable, and the plugin ships AI-assisted translations for 12 locales (German, French, Spanish, Italian, Brazilian Portuguese, Dutch, Polish, Swedish, Russian, Japanese, Korean, Simplified Chinese).
* Improve  - Classic-layout quizzes now reliably record short-answer, fill-in-the-blank and essay responses.
* Improve  - The lesson progress bar is calculated live from completed lessons so it always matches the X of N count.
* Security - Embeds resolve only through WordPress's built-in oEmbed provider list, closing a server-side request risk on the author preview endpoint.
* Dev      - New filters learnomy_embed_html and learnomy_embed_oembed_args, and a new Learnomy\Content\Embed engine. Front-end account, registration and progress scripts are now i18n-wired.

= 1.2.1 - June 2026 =

* New      - Course visibility is now a three-way choice - Public, Unlisted (hidden from the catalog but open to anyone with the direct link), or Private (enrolled students and admins only) - replacing the single Private hide-from-catalog checkbox so a course can be shared by link without being public.
* Fix      - Restored the Payment Provider Mapping fields on the membership plan editor (Stripe Test/Live Price ID, PayPal Sandbox/Live Plan ID) so plans can be mapped to provider price IDs again.

= 1.2.0 - June 2026 =

Migrate an existing course library into Learnomy, build custom account fields without code, and gate lessons on your own rules.

* New      - Import courses, lessons, quizzes, and questions from LearnDash, Tutor LMS, and LifterLMS through a guided wizard, with an optional people tier that brings across enrollments and progress.
* New      - Migrations run in resumable background batches with a dry-run preview, and state up front which data is not migrated (certificates, grades, and payments).
* New      - Build custom account fields for registration and the account profile from a no-code builder, managed entirely by the site owner.
* New      - Set per-lesson access requirements through a filterable access gate, including lessons embedded outside the course player.
* New      - Sections are now optional in the course builder, so lessons and quizzes can sit directly under a course.
* New      - Reassign a course's lead author and add co-instructors from the course editor.
* Improve  - Instructor roster data can now be anonymized through dedicated privacy hooks for export and erasure requests.
* Improve  - Promoted the Abilities API from a single modules-table row to a linked, first-class integrations doc.
* Fix      - Removed per-row queries in the instructor Q&A inbox, students-at-risk, and recent-transactions widgets so large dashboards load in a constant query count.
* Fix      - The grading detail screen no longer fatals on a malformed format string, and editing a lesson no longer mangles its media IDs.
* Fix      - Corrected a malformed placeholder in the revenue-trend widget that could fatal on PHP 8.
* Dev      - Added a wp learnomy migrate WP-CLI command and REST endpoints that drive the import wizard.
* Dev      - Added editor-field and integration hook seams so third-party fields round-trip through the course and account editors.
* Dev      - Added an AI Agents and Abilities API integration guide covering the 34 AI-discoverable LMS actions across 6 categories.
* Dev      - Added a Dutch (nl_NL) translation and regenerated the .pot.
* Compat   - Aligned with Learnomy Pro 1.2.0. Install both updates together.

= 1.1.1 - June 2026 =

Bug-sweep, full translation readiness, and a category-delete fix on top of 1.1.0.

* Improve  - Reworded user-facing strings to plain English across admin and front-end (replaced stylistic dashes with natural punctuation).
* Fix      - Deleting a course category that still has courses now moves them to Uncategorized and removes the category, instead of appearing to do nothing; the confirm explains the move and the result reports how many courses moved.
* Fix      - Checkout could subscribe a member to the same membership plan twice and double-bill; a second active subscription to the same plan is now rejected, and a different plan is directed to billing to change plans.
* Fix      - All 12 Gutenberg blocks now load their styles on any page, not only on Learnomy routes; they previously rendered unstyled elsewhere.
* Fix      - The membership pricing block now ships its own styles and shows the currency symbol on the amount.
* Fix      - The become-an-instructor page now sends anonymous visitors to the public registration form instead of bouncing them to wp-login and losing the applicant.
* Fix      - An unknown course URL now renders the theme 404 page and title instead of a "Course" title on a not-found body.
* Fix      - Course and catalog grids no longer leave blank trailing columns when there are fewer cards than columns.
* Fix      - The PWA manifest is now served dynamically with valid icons and a non-redirecting start URL, fixing manifest and icon 404s.
* Fix      - Documentation and marketing copy corrected against the shipped product: REST auth endpoint (/auth/token) and endpoint count (166), the five Pro quiz question types, the Pro module count (15), lesson content types, settings navigation, and analytics docs.
* Dev      - Full translation readiness: regenerated the .pot, added translator comments to every placeholder string, and registered front-end script strings (modals, course builder, quiz editor, toasts) for translation. The WordPress.WP.I18n check is clean.
* Compat   - Aligned with Learnomy Pro 1.1.1. Install both updates together.

= 1.1.0 - June 2026 =

First public release. Bundles the async backbone so the plugin runs standalone, adds BuddyX theme support, and clears the launch bug queue.

* New      - Action Scheduler is now bundled, so recurring jobs (membership renewals, reminders, dunning, webhook delivery, large broadcasts) run on a standalone install with no WooCommerce required.
* New      - BuddyX and BuddyX Pro theme bridge: Learnomy adopts the theme palette and follows its dark mode automatically.
* Fix      - Bearer token authentication now works for REST and mobile clients; tokens were issued but never read, returning 401.
* Fix      - The certificate designer pre-loads the Classic template when creating a new certificate instead of opening to a blank canvas.
* Fix      - Cancelling a membership now unenrolls the member from the courses that membership granted, even on enrollments created without a stored plan id.
* Compat   - Aligned with Learnomy Pro 1.1.0. Install both updates together.

= 1.0.0 =
* Initial release.
* 37 custom MySQL tables: courses, quizzes, certificates, memberships, revenue, and more.
* Course builder with sections, lessons, and topics.
* 6 quiz question types with auto-grading and manual essay grading.
* 5 quiz display templates: Focused, Classic, Sectioned, Conversational, Review Board.
* Certificate generation with UUID verification, QR codes, and Ed25519 signatures.
* Built-in membership plans with Stripe and PayPal recurring billing.
* 2-party commission system with instructor payout tracking.
* Coupon system with percentage and fixed discounts.
* 145 REST API endpoints under `learnomy/v1` with JWT auth and batch support.
* 12 Gutenberg blocks, 6 sidebar widgets, 10 shortcodes.
* 5 WP-CLI top-level commands with sub-commands for inspect, test, and selfcheck.
* WordPress Interactivity API frontend: no React, no jQuery, no build step.
* WordPress Abilities API integration: 34 AI-discoverable LMS actions across 6 categories.
* FULLTEXT search, JSON-LD schema markup, and XML sitemap.
* 8 HTML email templates.
* GDPR data export and erase compliance.
* Follows your theme's light or dark colour scheme.
* BuddyNext and Jetonomy ecosystem hooks.

== Upgrade Notice ==

= 1.4.0 =
Major release: an exam-grade quiz engine (five layouts, subject grouping, large-exam wayfinding, proctoring), Closed course pricing (sell anywhere, fulfil here), Solo Academy mode, and Pro support for SCORM, xAPI, LTI, QTI, and H5P. This release adds database migrations; update Learnomy and Learnomy Pro together.

= 1.3.0 =
Uniform embeds on every screen, one editor toolset on backend and front-end, and full translation readiness with ready-to-use language files for 12 locales. Update Learnomy and Learnomy Pro together.

= 1.0.0 =
Initial release of Learnomy LMS.
