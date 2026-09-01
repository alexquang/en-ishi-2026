<?php
/**
 * Protected uploads store — where bytes that must obey a Learnomy permission
 * check are kept.
 *
 * WHY THIS EXISTS
 * ---------------
 * A lesson handout used to be an ordinary WordPress media item, so its bytes
 * sat at `wp-content/uploads/2026/07/handout.pdf` and were served by nginx or
 * Apache before PHP ever loaded. Gating the REST listing closed discovery and
 * nothing else: anyone holding the URL — a refunded student, a forwarded link,
 * a crawler that indexed the uploads directory — kept permanent access to a
 * paid, drip-locked file (Basecamp 10148249510).
 *
 * The fix has two legs, and they fail differently on purpose.
 *
 * LEG 1 — relocation (portable, always available, fails closed)
 *   The file moves into `uploads/learnomy-protected/<site-key>/YYYY/MM/` with a
 *   random 16-hex filename prefix. `<site-key>` is 32 hex characters generated
 *   once per site. That is ~192 bits of path entropy, and — this is the part
 *   that matters — the resulting URL is never printed anywhere: not in a
 *   template, not in a REST body, not in the sitemap. Every published link is
 *   the `lesson-attachment` route, which runs the permission check.
 *   If the store cannot be created, or the file cannot be moved into it, the
 *   caller gets a WP_Error and the attachment is NOT created. We never fall
 *   back to leaving bytes on the public path.
 *
 * LEG 2 — server-level deny (defence in depth, verified, never assumed)
 *   `.htaccess` + `web.config` + `index.php` guards are written at the store
 *   root. Apache and IIS honour them. **nginx does not read `.htaccess`**, so on
 *   a large share of installs leg 2 is inert. Rather than shipping a rewrite the
 *   host silently strips and calling the bug closed, we PROVE it: {@see probe()}
 *   fetches a canary file over HTTP and records whether the server actually
 *   denied it. `blocking_status()` drives the Site Health test and the admin
 *   notice, so an owner on nginx is told the exact `location` block to add
 *   instead of being told a comforting lie.
 *
 * Why not the alternatives:
 *   - *Move uploads out of the web root.* Nothing outside `wp-content` is
 *     portable-writable, and every backup/migration tool copies `wp-content`
 *     only. Course handouts would vanish on the first host move. Losing the
 *     files is worse than the bug.
 *   - *Signed, expiring URLs pointing at the raw file.* The raw path stays
 *     readable; a signature on one link does not stop the unsigned one.
 *   - *Wrapping bytes behind a `<?php exit;` header so nginx hands them to
 *     PHP-FPM.* Two independent killers. It fails OPEN on any host that hardens
 *     uploads with `php_flag engine off` (a common recipe — we ship it ourselves
 *     for SCORM), serving the whole file verbatim. And every handout would then
 *     be a `.php` file inside uploads, which Wordfence, Sucuri and most host
 *     scanners flag as malware and may quarantine or delete: we would trade a
 *     disclosure bug for silent customer data loss.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use WP_Error;
use Learnomy\Models\Course;
use Learnomy\Models\Lesson;

class Protected_Uploads {

	/** Directory created under wp-content/uploads/. */
	public const DIR_NAME = 'learnomy-protected';

	/** Option holding the per-site random path segment. */
	public const OPTION_KEY = 'learnomy_protected_uploads_key';

	/** Option holding the last canary probe result. */
	public const OPTION_PROBE = 'learnomy_protected_uploads_probe';

	/** Re-probe the server at most this often. */
	private const PROBE_TTL = DAY_IN_SECONDS;

	/** Option flag: the one-time backfill of pre-fix handouts has been queued. */
	private const OPTION_BACKFILL = 'learnomy_protected_uploads_backfilled';

	/** Action Scheduler hook running the backfill. */
	public const BACKFILL_HOOK = 'learnomy_protect_lesson_attachments';

	// -------------------------------------------------------------------------
	// Wiring
	// -------------------------------------------------------------------------

	/**
	 * Hook the backfill and the owner-facing status surfaces.
	 *
	 * Registered unconditionally (not behind is_admin) because the backfill runs
	 * from Action Scheduler, which has no admin context.
	 */
	public static function register(): void {
		add_action( self::BACKFILL_HOOK, [ self::class, 'run_backfill' ] );
		add_action( 'admin_init', [ self::class, 'maybe_schedule_backfill' ] );
		add_action( 'admin_notices', [ self::class, 'render_exposure_notice' ] );
		add_filter( 'site_status_tests', [ self::class, 'register_site_health_test' ] );
		add_filter( 'wp_get_attachment_url', [ self::class, 'filter_attachment_url' ], 10, 2 );
	}

	/**
	 * Point `wp_get_attachment_url()` at the gated route for a relocated handout.
	 *
	 * Two jobs. It keeps the raw storage path out of every caller we do not own
	 * — a theme, a Pro view, a future controller — instead of relying on each of
	 * them remembering to ask `Lesson_Attachment::get_url()`. And it keeps the
	 * media library working on Apache, where the deny rule is live and an
	 * unfiltered library link to the storage path would simply 403 for the
	 * administrator who uploaded it.
	 *
	 * Generated image sizes are resolved by core from the metadata + base URL,
	 * not through this filter, so a thumbnail of an image handout still points
	 * into the denied folder. Cosmetic and admin-only; the file itself is always
	 * reachable through the route.
	 *
	 * @param string $url      Attachment URL core resolved.
	 * @param int    $media_id Attachment post ID.
	 * @return string
	 */
	public static function filter_attachment_url( $url, $media_id ): string {
		$media_id = (int) $media_id;
		if ( $media_id <= 0 || ! self::is_protected( $media_id ) ) {
			return (string) $url;
		}

		$row = \Learnomy\Models\Lesson_Attachment::find_by_media( $media_id );
		if ( ! $row ) {
			return (string) $url;
		}

		return \Learnomy\Models\Lesson_Attachment::get_url( $row );
	}

	/**
	 * Queue the one-time relocation of handouts uploaded before the store
	 * existed. Deferred to Action Scheduler rather than run inline: a site with
	 * thousands of attachments must not move them all during someone's page load.
	 */
	public static function maybe_schedule_backfill(): void {
		if ( get_option( self::OPTION_BACKFILL ) ) {
			return;
		}

		if ( ! function_exists( 'as_enqueue_async_action' ) ) {
			return;
		}

		update_option( self::OPTION_BACKFILL, time(), false );
		as_enqueue_async_action( self::BACKFILL_HOOK, [], 'learnomy' );
	}

	/**
	 * Action Scheduler target — one job that makes every pre-fix file private.
	 *
	 * Covers both known cases of "Learnomy wrote owner content to a public
	 * path": lesson handouts, and the pre-migration content snapshot (a single
	 * JSON file holding every lesson body on the site).
	 */
	public static function run_backfill(): void {
		self::sweep( false );
		\Learnomy\Content\Content_Migrator::relocate_backup_to_protected();
	}

	// -------------------------------------------------------------------------
	// Owner-facing status
	// -------------------------------------------------------------------------

	/**
	 * Warn on Learnomy admin screens when the server did not confirm the deny.
	 *
	 * Deliberately not dismissible and deliberately honest: the handouts ARE
	 * protected by the route and by an unguessable path, and the one remaining
	 * gap is a server rule only the owner can add. Telling them "protected" and
	 * staying quiet would be the failure mode this whole card is about.
	 */
	public static function render_exposure_notice(): void {
		if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		// Learnomy screens only — a storage warning has no business on someone
		// else's plugin page. Matched by suffix because white-label renames the
		// screen-hook prefix.
		$screen = get_current_screen();
		$id     = $screen ? (string) $screen->id : '';
		if ( '' === $id || ( ! str_contains( $id, 'learnomy' ) && ! str_contains( $id, 'site-health' ) ) ) {
			return;
		}

		if ( 'blocked' === self::blocking_status() ) {
			return;
		}

		if ( empty( \Learnomy\Models\Lesson_Attachment::all_media_ids() ) ) {
			return;
		}

		// ONE LINE and a way to act. This notice is advisory — nothing is
		// broken for a student today — so it has no business dominating the
		// dashboard. It previously printed the whole host message inline: a
		// ten-row monospace textarea plus a copy button, on every Learnomy
		// screen, permanently. A big notice for a non-urgent thing trains
		// owners to scroll past every notice we ever show, including the
		// urgent ones. The detail lives one click away, in Settings > Advanced
		// > Tools, next to the other diagnostics.
		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Learnomy: student files need one server rule.', 'learnomy' ),
			esc_html__( 'Handouts are private and permission-checked, but this server still answers a direct request to their folder. You do not need to change anything yourself.', 'learnomy' ),
			esc_url( admin_url( 'admin.php?page=learnomy-settings#advanced' ) ),
			esc_html__( 'Get the message to send your host', 'learnomy' )
		);
	}

	/**
	 * The diagnostic panel — the notice's destination, in Settings > Advanced
	 * > Tools beside Send Test Email and Flush Rewrite Rules.
	 *
	 * This is where the detail belongs: an owner who has come here has decided
	 * to deal with it, so the full message, the copy button and the plain
	 * explanation are all wanted. Renders nothing at all when the server is
	 * already refusing direct requests — a tool that only ever says "fine" is
	 * noise on a page of tools.
	 */
	public static function render_tools_panel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( 'blocked' === self::blocking_status() ) {
			return;
		}

		if ( empty( \Learnomy\Models\Lesson_Attachment::all_media_ids() ) ) {
			return;
		}

		// Dependency-free and enqueued only where the panel renders.
		wp_enqueue_script(
			'learnomy-copy-to-clipboard',
			LEARNOMY_URL . 'assets/js/admin/copy-to-clipboard.js',
			array(),
			LEARNOMY_VERSION,
			true
		);

		printf(
			'<div class="lrn-settings-card">'
			. '<div class="lrn-settings-card__head">'
			. '<h2 class="lrn-settings-card__title">%1$s</h2>'
			. '<p class="lrn-settings-card__desc">%2$s</p>'
			. '</div>'
			. '<div class="lrn-settings-card__body-pad">'
			. '<p><button type="button" class="lrn-admin-btn" data-lrn-copy-target="lrn-protected-host-message">'
			. '<i data-lucide="clipboard-copy" aria-hidden="true"></i>%3$s</button> '
			. '<span class="lrn-protected-copied" aria-live="polite" hidden>%4$s</span></p>'
			. '<textarea id="lrn-protected-host-message" readonly rows="10" style="width:100%%;font-family:monospace;font-size:12px;">%5$s</textarea>'
			. '<p class="description">%6$s</p>'
			. '</div></div>',
			esc_html__( 'Student file protection', 'learnomy' ),
			esc_html__( 'Course handouts are stored on a private path and are only served after a permission check. One layer is missing: this server does not refuse a direct request to the storage folder, which is normal on nginx because it ignores the .htaccess file Learnomy already wrote.', 'learnomy' ),
			esc_html__( 'Copy message for my host', 'learnomy' ),
			esc_html__( 'Copied.', 'learnomy' ),
			esc_textarea( self::host_request_message() ),
			esc_html__( 'Send this to your hosting support. Nothing else needs to change, and this check clears on its own once the rule is in place.', 'learnomy' )
		);
	}

	/**
	 * Add the Site Health test. This is the permanent home for the status; the
	 * admin notice is the nudge that points here.
	 *
	 * @param array<string, array<string, mixed>> $tests Registered tests.
	 * @return array<string, array<string, mixed>>
	 */
	public static function register_site_health_test( array $tests ): array {
		$tests['direct']['learnomy_protected_uploads'] = [
			'label' => __( 'Learnomy private course files', 'learnomy' ),
			'test'  => [ self::class, 'site_health_result' ],
		];

		return $tests;
	}

	/**
	 * @return array<string, mixed>
	 */
	public static function site_health_result(): array {
		$status = self::blocking_status();

		$result = [
			'label'       => __( 'Course handouts are stored privately', 'learnomy' ),
			'status'      => 'good',
			'badge'       => [
				'label' => __( 'Security', 'learnomy' ),
				'color' => 'blue',
			],
			'description' => '<p>' . esc_html__( 'Lesson handouts are stored outside the public media path and are only delivered after Learnomy checks that the reader may open the lesson. Your web server also refuses direct requests to the storage folder.', 'learnomy' ) . '</p>',
			'test'        => 'learnomy_protected_uploads',
		];

		if ( 'blocked' !== $status ) {
			$result['label']          = __( 'Your host needs to add one rule for student course files', 'learnomy' );
			$result['status']         = 'recommended';
			$result['badge']['color'] = 'orange';
			// Same audience as the admin notice: an owner, not an engineer.
			// Lead with what is and is not at risk, then with the one thing
			// they can actually do about it — send the message to their host.
			$result['description'] = '<p>'
				. esc_html__( 'Lesson handouts are stored on a private, unguessable path and are only linked through a permission-checked URL, so a shared or indexed link no longer works. One layer is missing: this server did not refuse a direct request to the storage folder. That is normal on nginx, which ignores the .htaccess file Learnomy already wrote.', 'learnomy' )
				. '</p><p>'
				. esc_html__( 'You do not need to change anything yourself. Copy the message below into a ticket to your hosting support - it explains what to add and why. This check turns green on its own once the rule is in place.', 'learnomy' )
				. '</p><textarea readonly rows="10" style="width:100%;font-family:monospace;font-size:12px;">'
				. esc_textarea( self::host_request_message() )
				. '</textarea>';
		}

		return $result;
	}

	// -------------------------------------------------------------------------
	// Locations
	// -------------------------------------------------------------------------

	/**
	 * Absolute path to the guarded root (`uploads/learnomy-protected`). The
	 * guard files live here so they cover every per-site key directory beneath.
	 */
	public static function root_dir(): string {
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . self::DIR_NAME;
	}

	/** URL of the guarded root. Used only by the canary probe — never printed. */
	public static function root_url(): string {
		$uploads = wp_get_upload_dir();
		return trailingslashit( $uploads['baseurl'] ) . self::DIR_NAME;
	}

	/**
	 * The per-site random path segment. Generated once and stored; regenerating
	 * it would orphan every file already relocated, so it is written only when
	 * absent.
	 */
	public static function site_key(): string {
		$key = (string) get_option( self::OPTION_KEY, '' );
		if ( 32 === strlen( $key ) && ctype_xdigit( $key ) ) {
			return $key;
		}

		$key = bin2hex( random_bytes( 16 ) );
		update_option( self::OPTION_KEY, $key, false );

		return $key;
	}

	/** Store path relative to the uploads basedir, e.g. `learnomy-protected/ab12…`. */
	public static function relative_root(): string {
		return self::DIR_NAME . '/' . self::site_key();
	}

	/** Absolute path to the per-site store directory. */
	public static function store_dir(): string {
		return self::root_dir() . '/' . self::site_key();
	}

	/**
	 * Is this media item's file already inside the protected store?
	 *
	 * Derived from `_wp_attached_file` rather than a new column, so there is one
	 * source of truth and no migration to keep in sync.
	 */
	public static function is_protected( int $media_id ): bool {
		$relative = (string) get_post_meta( $media_id, '_wp_attached_file', true );
		return '' !== $relative && str_starts_with( $relative, self::DIR_NAME . '/' );
	}

	// -------------------------------------------------------------------------
	// Store creation
	// -------------------------------------------------------------------------

	/**
	 * Create the store and (re)write its guard files.
	 *
	 * @return bool True when the directory exists and is writable. False is the
	 *              fail-closed signal — callers must abort rather than store the
	 *              bytes somewhere public.
	 */
	public static function ensure_store(): bool {
		$root  = self::root_dir();
		$store = self::store_dir();

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- a writability probe, not a write; WP_Filesystem would need credentials we do not have on an upload request.
		if ( ! wp_mkdir_p( $store ) || ! is_writable( $store ) ) {
			return false;
		}

		self::write_guards( $root );

		return true;
	}

	/**
	 * Write `.htaccess`, `web.config` and `index.php` at the store root.
	 *
	 * Rewritten whenever missing so a host that wipes the uploads directory (or
	 * a customer who deletes the file) gets them back on the next upload. The
	 * `.htaccess` denies EVERYTHING, not just script execution — this directory
	 * has no legitimate direct-fetch use.
	 */
	private static function write_guards( string $root ): void {
		$files = [
			'.htaccess'  => "# Learnomy protected uploads — every byte here is served through\n"
				. "# /learnomy-file/{id}/ after a permission check. Direct access is never valid.\n"
				. "<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
				. "<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n"
				. "Options -Indexes\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
				. '<configuration><system.webServer><authorization>'
				. '<deny users="*" />'
				. "</authorization></system.webServer></configuration>\n",
			'index.php'  => "<?php\n// Silence is golden.\n",
		];

		foreach ( $files as $name => $contents ) {
			$path = $root . '/' . $name;
			if ( file_exists( $path ) ) {
				continue;
			}
			// Guard files must land before the first byte is stored, and a
			// read-only uploads dir is reported by ensure_store() rather than by
			// a warning here.
			@file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	// -------------------------------------------------------------------------
	// Relocation
	// -------------------------------------------------------------------------

	/**
	 * Move a WordPress media item's bytes into the protected store.
	 *
	 * The media item keeps its post ID, its title and its library entry — only
	 * the file moves, and `_wp_attached_file` follows it, so `get_attached_file()`
	 * and the media library still resolve. Generated image sizes move with it
	 * (they are separate public files; leaving them behind would leave a
	 * readable copy of the handout).
	 *
	 * TAKING OWNERSHIP. Relocating a file that something else also links to would
	 * break that other thing, so a media item used as a featured image, a course
	 * thumbnail, a lesson video or an embed inside course/lesson content is
	 * REFUSED rather than moved. The instructor is told to upload a dedicated
	 * copy. Refusing is the fail-closed answer: the alternative — copying the
	 * bytes and leaving the public original — would report success while the
	 * handout stayed downloadable.
	 *
	 * @param int $media_id WordPress attachment post ID.
	 * @return true|WP_Error
	 */
	public static function protect_media( int $media_id ): bool|WP_Error {
		$post = $media_id > 0 ? get_post( $media_id ) : null;
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return new WP_Error( 'learnomy_media_missing', __( 'That media item no longer exists.', 'learnomy' ) );
		}

		if ( self::is_protected( $media_id ) ) {
			return true;
		}

		$source = get_attached_file( $media_id );
		if ( ! $source || ! file_exists( $source ) ) {
			return new WP_Error( 'learnomy_media_file_missing', __( 'The uploaded file is missing from the server.', 'learnomy' ) );
		}

		$used = self::used_elsewhere( $media_id );
		if ( '' !== $used ) {
			return new WP_Error(
				'learnomy_media_shared',
				sprintf(
					/* translators: %s: where else the file is used, e.g. "a course thumbnail". */
					__( 'This file cannot be made private because it is also used as %s. Upload a separate copy to attach it to this lesson.', 'learnomy' ),
					$used
				)
			);
		}

		if ( ! self::ensure_store() ) {
			return new WP_Error(
				'learnomy_protected_store_unavailable',
				__( 'The private file store could not be created, so the file was not attached. Check that wp-content/uploads is writable.', 'learnomy' )
			);
		}

		$sub_dir    = gmdate( 'Y/m' );
		$target_dir = self::store_dir() . '/' . $sub_dir;
		if ( ! wp_mkdir_p( $target_dir ) ) {
			return new WP_Error(
				'learnomy_protected_store_unavailable',
				__( 'The private file store could not be created, so the file was not attached. Check that wp-content/uploads is writable.', 'learnomy' )
			);
		}

		$basename = bin2hex( random_bytes( 8 ) ) . '-' . sanitize_file_name( wp_basename( $source ) );
		$target   = $target_dir . '/' . $basename;

		if ( ! self::move( $source, $target ) ) {
			return new WP_Error(
				'learnomy_protected_move_failed',
				__( 'The file could not be moved into the private store, so it was not attached.', 'learnomy' )
			);
		}

		// Generated sizes live beside the original and are separately fetchable.
		// Their metadata filenames are relative to the main file's directory, so
		// moving them under the same basename keeps the metadata valid as-is.
		$metadata  = wp_get_attachment_metadata( $media_id );
		$source_dr = dirname( $source );
		if ( is_array( $metadata ) && ! empty( $metadata['sizes'] ) ) {
			foreach ( $metadata['sizes'] as $size ) {
				$size_file = isset( $size['file'] ) ? wp_basename( (string) $size['file'] ) : '';
				if ( '' === $size_file || ! file_exists( $source_dr . '/' . $size_file ) ) {
					continue;
				}
				self::move( $source_dr . '/' . $size_file, $target_dir . '/' . $size_file );
			}
		}

		$relative = self::relative_root() . '/' . $sub_dir . '/' . $basename;
		update_post_meta( $media_id, '_wp_attached_file', $relative );

		if ( is_array( $metadata ) ) {
			$metadata['file'] = $relative;
			wp_update_attachment_metadata( $media_id, $metadata );
		}

		/**
		 * Fires after a media item's bytes have moved into the protected store.
		 *
		 * @param int    $media_id Attachment post ID.
		 * @param string $relative New path relative to the uploads basedir.
		 */
		do_action( 'learnomy_media_protected', $media_id, $relative );

		return true;
	}

	/**
	 * Where else is this media item referenced? Empty string means nowhere, and
	 * only then may we move it.
	 *
	 * @return string Human-readable location, or '' when unreferenced.
	 */
	public static function used_elsewhere( int $media_id ): string {
		$url = (string) wp_get_attachment_url( $media_id );

		$featured = get_posts(
			[
				'post_type'        => 'any',
				'post_status'      => 'any',
				'numberposts'      => 1,
				'fields'           => 'ids',
				'meta_key'         => '_thumbnail_id', // phpcs:ignore WordPress.DB.SlowMetaQuery.SlowMetaQuery -- single indexed lookup, not a listing query.
				'meta_value'       => $media_id,       // phpcs:ignore WordPress.DB.SlowMetaQuery.SlowMetaQuery -- ditto.
				'suppress_filters' => false,
			]
		);
		if ( ! empty( $featured ) ) {
			return __( 'a post featured image', 'learnomy' );
		}

		if ( Course::media_reference_count( $media_id, $url ) > 0 ) {
			return __( 'a course thumbnail, promo video or course content', 'learnomy' );
		}

		if ( Lesson::media_reference_count( $media_id, $url ) > 0 ) {
			return __( 'a lesson video or lesson content', 'learnomy' );
		}

		return '';
	}

	/** rename() where possible, copy+unlink across filesystems. */
	private static function move( string $from, string $to ): bool {
		// A failed rename is a return value here, not a warning, and
		// WP_Filesystem cannot be initialised without credentials mid-upload.
		if ( @rename( $from, $to ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename, WordPress.PHP.NoSilencedErrors.Discouraged
			return true;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy, WordPress.PHP.NoSilencedErrors.Discouraged -- cross-device fallback; WP_Filesystem cannot be initialised without credentials during an upload request.
		if ( ! @copy( $from, $to ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- removing the public copy is the whole point; a failure is reported by the caller's re-check.
		@unlink( $from );

		return ! file_exists( $from );
	}

	// -------------------------------------------------------------------------
	// Server verification
	// -------------------------------------------------------------------------

	/**
	 * Did the web server actually refuse a direct fetch from the store?
	 *
	 * @return string 'blocked' | 'open' | 'unknown'
	 */
	public static function blocking_status(): string {
		$stored = get_option( self::OPTION_PROBE, [] );
		if ( is_array( $stored ) && isset( $stored['status'], $stored['checked'] )
			&& ( time() - (int) $stored['checked'] ) < self::PROBE_TTL ) {
			return (string) $stored['status'];
		}

		return self::probe();
	}

	/**
	 * Drop a canary file in the store root and try to fetch it over HTTP.
	 *
	 * The canary holds nothing but a random token, so the probe itself never
	 * exposes customer data. A 200 that returns the token means the deny rule is
	 * not in force (nginx, or a host that strips `.htaccess`); anything else
	 * means the server refused. A transport failure is recorded as 'unknown',
	 * which the UI treats exactly like 'open' — we do not report protection we
	 * could not observe.
	 */
	public static function probe(): string {
		$status = 'unknown';

		if ( ! self::ensure_store() ) {
			update_option(
				self::OPTION_PROBE,
				[
					'status'  => 'unknown',
					'checked' => time(),
				],
				false
			);
			return 'unknown';
		}

		$token = bin2hex( random_bytes( 12 ) );
		$name  = 'canary-' . $token . '.txt';
		$path  = self::root_dir() . '/' . $name;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.NoSilencedErrors.Discouraged -- transient probe artifact, removed below.
		if ( false === @file_put_contents( $path, $token ) ) {
			update_option(
				self::OPTION_PROBE,
				[
					'status'  => 'unknown',
					'checked' => time(),
				],
				false
			);
			return 'unknown';
		}

		$response = wp_remote_get(
			self::root_url() . '/' . $name,
			[
				'timeout'   => 8,
				'sslverify' => false,
				'headers'   => [ 'Cache-Control' => 'no-cache' ],
			]
		);

		if ( ! is_wp_error( $response ) ) {
			$code = (int) wp_remote_retrieve_response_code( $response );
			$body = (string) wp_remote_retrieve_body( $response );
			if ( 200 === $code && str_contains( $body, $token ) ) {
				$status = 'open';
			} elseif ( $code >= 300 ) {
				$status = 'blocked';
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup of our own probe file.
		@unlink( $path );

		update_option(
			self::OPTION_PROBE,
			[
				'status'  => $status,
				'checked' => time(),
			],
			false
		);

		return $status;
	}

	/**
	 * The nginx snippet an owner needs when {@see blocking_status()} is not
	 * 'blocked'. Kept here so the Site Health test, the admin notice and the
	 * docs all print the same text.
	 */
	public static function nginx_snippet(): string {
		$uploads = wp_get_upload_dir();
		$path    = wp_parse_url( trailingslashit( $uploads['baseurl'] ) . self::DIR_NAME, PHP_URL_PATH );

		return 'location ^~ ' . $path . "/ {\n\tdeny all;\n\treturn 403;\n}";
	}

	/**
	 * The message an owner sends to their host, ready to paste.
	 *
	 * The rule itself is a two-line nginx block, and we used to print exactly
	 * that and stop — at an audience who mostly cannot reach a server at all.
	 * A site owner on shared or managed hosting has no nginx access, no shell,
	 * and no idea what a server block is, so "add this to your nginx
	 * configuration" is a dead end dressed as an instruction: correct, and
	 * unactionable.
	 *
	 * What almost every one of them CAN do is open a support ticket. So the
	 * deliverable is not the config, it is the message that carries the config
	 * — addressed to the host, naming the site, explaining what is wanted and
	 * why, with the snippet at the bottom for the engineer who reads it. The
	 * owner's job becomes copy, paste, send.
	 *
	 * Deliberately plain text and deliberately self-contained: it gets pasted
	 * into a ticket form or an email that knows nothing about this site.
	 */
	public static function host_request_message(): string {
		$uploads = wp_get_upload_dir();
		$path    = (string) wp_parse_url( trailingslashit( $uploads['baseurl'] ) . self::DIR_NAME, PHP_URL_PATH );

		$lines = array(
			sprintf(
				/* translators: %s: the site's home URL */
				__( 'Hello, I need one web server rule added for my website: %s', 'learnomy' ),
				home_url( '/' )
			),
			'',
			sprintf(
				/* translators: %s: URL path of the protected uploads folder */
				__( 'My learning management plugin stores student course files in this folder: %s', 'learnomy' ),
				$path . '/'
			),
			'',
			__( 'These files are meant to be private. The plugin already checks permissions before serving them, but the folder itself can still be opened directly by anyone who has the link, because this server does not refuse direct requests to it. On Apache a .htaccess file handles this, and the plugin has already written one. On nginx that file is ignored, so the rule has to be added to the server configuration.', 'learnomy' ),
			'',
			__( 'Please add the following to the nginx server block for my site and reload nginx:', 'learnomy' ),
			'',
			self::nginx_snippet(),
			'',
			__( 'After that, opening a file in that folder directly should return 403 Forbidden. Nothing else needs to change, and this does not affect how the files are delivered to my students. Thank you.', 'learnomy' ),
		);

		return implode( "\n", $lines );
	}

	// -------------------------------------------------------------------------
	// Backfill
	// -------------------------------------------------------------------------

	/**
	 * Relocate every lesson attachment that is still on a public path.
	 *
	 * Existing installs are the larger half of this bug: handouts uploaded
	 * before the fix are already at public URLs that may have been shared or
	 * indexed. Protecting only new uploads would leave every current handout
	 * exposed, so the sweep moves them. Any link someone already holds to the
	 * old path stops working — that broken link IS the vulnerability, and the
	 * lesson page's own link is rebuilt from the route, so a legitimate reader
	 * never notices.
	 *
	 * Rows whose media is shared with a thumbnail/video/embed are reported, not
	 * moved; the owner resolves those by uploading a dedicated copy.
	 *
	 * @param bool $dry_run When true, report what would move without moving it.
	 * @return array{total:int, protected:int, already:int, skipped:array<int, string>}
	 */
	public static function sweep( bool $dry_run = false ): array {
		$result = [
			'total'     => 0,
			'protected' => 0,
			'already'   => 0,
			'skipped'   => [],
		];

		foreach ( \Learnomy\Models\Lesson_Attachment::all_media_ids() as $media_id ) {
			++$result['total'];
			$media_id = (int) $media_id;

			if ( self::is_protected( $media_id ) ) {
				++$result['already'];
				continue;
			}

			if ( $dry_run ) {
				$used = self::used_elsewhere( $media_id );
				if ( '' !== $used ) {
					$result['skipped'][ $media_id ] = $used;
					continue;
				}
				++$result['protected'];
				continue;
			}

			$done = self::protect_media( $media_id );
			if ( is_wp_error( $done ) ) {
				$result['skipped'][ $media_id ] = $done->get_error_message();
				continue;
			}
			++$result['protected'];
		}

		return $result;
	}
}
