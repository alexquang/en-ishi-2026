<?php
/**
 * Lesson comments REST controller — CRUD for wp_comments with type 'learnomy'.
 *
 * Uses the WordPress comments system. The storage contract — the comment type
 * and the lesson meta key — is owned by Lesson_Comment and referenced through
 * its constants; see the note there for what happens when a producer spells it
 * from memory instead. Supports 2-level threading
 * max: top-level comments + one level of replies.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Learnomy\Models\Course;
use Learnomy\Models\Lesson;
use Learnomy\Models\Enrollment;
use Learnomy\Models\Blocked_User;
use Learnomy\Models\Lesson_Comment;

class Comments_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'comments';
	}

	public function register_routes(): void {
		// List comments for a lesson.
		register_rest_route(
			$this->namespace,
			'/lessons/(?P<lesson_id>[\d]+)/comments',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_comments' ),
					'permission_callback' => array( $this, 'can_view_lesson_comments' ),
					'args'                => array(
						'lesson_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_comment' ),
					'permission_callback' => array( $this, 'can_comment_on_lesson' ),
					'args'                => array(
						'lesson_id' => array(
							'type'     => 'integer',
							'required' => true,
						),
						'content'   => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_textarea_field',
						),
					),
				),
			)
		);

		// Reply to a comment (1 level only).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)/reply',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'create_reply' ),
				'permission_callback' => array( $this, 'can_reply_to_comment' ),
				'args'                => array(
					'id'      => array(
						'type'     => 'integer',
						'required' => true,
					),
					'content' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);

		// Delete own comment.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_comment' ),
				'permission_callback' => array( $this, 'can_delete_comment' ),
				'args'                => array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Callbacks
	// -------------------------------------------------------------------------

	/**
	 * Lists all top-level comments for a lesson with their replies.
	 */
	public function get_comments( WP_REST_Request $request ): WP_REST_Response {
		$lesson_id = (int) $request->get_param( 'lesson_id' );
		$lesson    = Lesson::find( $lesson_id );

		if ( ! $lesson ) {
			return $this->error_response( $this->not_found( 'Lesson' ) );
		}

		// Paginate top-level comments (big-site checklist #1). An unpaginated
		// thread on a busy lesson pulls every comment row; bound it with
		// limit/offset and report the real total via a separate COUNT so the
		// client can page. Response shape is unchanged — same data[]/meta
		// envelope, each item still carries its replies[].
		$pagination = $this->get_pagination( $request );
		$limit      = max( 1, min( 100, (int) $pagination['limit'] ) );
		$offset     = max( 0, (int) $pagination['offset'] );

		$meta_query = array(
			array(
				'key'   => Lesson_Comment::LESSON_META_KEY,
				'value' => $lesson_id,
				'type'  => 'NUMERIC',
			),
		);

		// App Store 1.2: hide comments authored by anyone the viewer has blocked,
		// from both the count and the page, so pagination stays honest. Empty for
		// the common case (nobody blocked) → author__not_in is simply omitted.
		$blocked = Blocked_User::blocked_ids_for( get_current_user_id() );

		// Real total of top-level comments for this lesson (one COUNT query),
		// so meta.total / meta.has_more reflect the whole thread, not the page.
		$total = (int) get_comments(
			array(
				'type'           => Lesson_Comment::COMMENT_TYPE,
				'parent'         => 0,
				'status'         => 'approve',
				'meta_query'     => $meta_query,
				'author__not_in' => $blocked,
				'count'          => true,
			)
		);

		$top_comments = get_comments(
			array(
				'type'           => Lesson_Comment::COMMENT_TYPE,
				'parent'         => 0,
				'status'         => 'approve',
				'orderby'        => 'comment_date_gmt',
				'order'          => 'ASC',
				'number'         => $limit,
				'offset'         => $offset,
				'meta_query'     => $meta_query,
				'author__not_in' => $blocked,
			)
		);

		$course         = Course::find( (int) $lesson->course_id );
		$instructor_ids = $this->get_instructor_ids( $course );

		// Batch-fetch every reply for this page's comments in ONE query, then
		// group by parent in PHP (big-site checklist #3 — no per-comment reply
		// query). Replies stay ordered by comment_date_gmt ASC, same as before.
		$parent_ids        = array_map( static fn( $c ) => (int) $c->comment_ID, $top_comments );
		$replies_by_parent = array();
		if ( $parent_ids ) {
			$all_replies = get_comments(
				array(
					'type'           => Lesson_Comment::COMMENT_TYPE,
					'status'         => 'approve',
					'orderby'        => 'comment_date_gmt',
					'order'          => 'ASC',
					'parent__in'     => $parent_ids,
					'author__not_in' => $blocked,
				)
			);
			foreach ( $all_replies as $reply ) {
				$replies_by_parent[ (int) $reply->comment_parent ][] = $reply;
			}
		}

		$items = array();
		foreach ( $top_comments as $comment ) {
			$replies    = $replies_by_parent[ (int) $comment->comment_ID ] ?? array();
			$reply_data = array_map(
				function ( $reply ) use ( $instructor_ids ) {
					return $this->format_comment( $reply, $instructor_ids );
				},
				$replies
			);

			$formatted            = $this->format_comment( $comment, $instructor_ids );
			$formatted['replies'] = $reply_data;
			$items[]              = $formatted;
		}

		return $this->paginated_response( $items, $total, $offset );
	}

	/**
	 * Most-recent identical comment by the same user in the same thread, posted
	 * within the dedupe window — our equivalent of WordPress core's
	 * wp_check_comment_duplicate(). Core keys the dupe check on comment_post_ID,
	 * but Learnomy lesson comments use comment_post_ID = 0 (lessons aren't WP
	 * posts — the lesson lives in the _learnomy_lesson_id meta), so we scope by
	 * user + thread + lesson + content instead. This is what actually stops a
	 * double-submit (two identical posts a second or two apart) from creating
	 * duplicate rows, regardless of the client.
	 *
	 * @param int    $user_id   Author.
	 * @param string $content   Sanitized comment content to match.
	 * @param int    $parent_id 0 for a top-level comment, else the parent id.
	 * @param int    $lesson_id Lesson (only used to scope top-level comments).
	 * @return int Existing duplicate comment id, or 0 when none.
	 */
	/**
	 * Insert a lesson comment THROUGH WordPress moderation.
	 *
	 * The old inserts hardcoded comment_approved = 1, which silently skipped
	 * everything a site owner configures under Settings > Discussion:
	 * hold-for-moderation, disallowed keys, the flood check, and any
	 * anti-spam plugin hooked on preprocess_comment (Akismet included).
	 * Learnomy's report-driven queue is a deliberate stance, but when the
	 * owner HAS configured WordPress moderation, dropping it without a word
	 * is a bug (Basecamp 10180490950).
	 *
	 * Mirrors the parts of wp_new_comment() that decide a verdict: full
	 * commentdata (author email/IP/agent matter to the disallowed and
	 * previously-approved checks), the preprocess_comment filter (the
	 * anti-spam seam), then wp_allow_comment() for the verdict. The insert
	 * itself stays wp_insert_comment so the caller's advisory-lock dedupe
	 * flow is untouched.
	 *
	 * @param array $fields comment_content, comment_parent, user_id.
	 * @return array{id:int, approved:int|string}|\WP_Error WP_Error on the
	 *         flood check; otherwise the new id and the verdict
	 *         (1 | 0 | 'spam' | 'trash').
	 */
	private function insert_moderated_comment( array $fields ) {
		$user_id = (int) ( $fields['user_id'] ?? 0 );
		$user    = get_user_by( 'ID', $user_id );

		$commentdata = array(
			'comment_post_ID'      => 0,
			'comment_author'       => $user ? $user->display_name : '',
			'comment_author_email' => $user ? $user->user_email : '',
			'comment_author_url'   => '',
			'comment_author_IP'    => isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '',
			'comment_agent'        => isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 254 ) : '',
			'comment_content'      => (string) ( $fields['comment_content'] ?? '' ),
			'comment_type'         => Lesson_Comment::COMMENT_TYPE,
			'comment_parent'       => (int) ( $fields['comment_parent'] ?? 0 ),
			'user_id'              => $user_id,
			'user_ID'              => $user_id,
			'comment_date'         => current_time( 'mysql' ), // tz-local-ok: WP comments store the local/GMT PAIR, exactly as wp_new_comment() does.
			'comment_date_gmt'     => current_time( 'mysql', 1 ),
		);

		/** This filter is documented in wp-includes/comment.php. */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core's own filter, deliberately unprefixed: prefixing it would fire a hook nothing listens to and skip the moderation/flood pipeline wp_new_comment() runs.
		$commentdata = apply_filters( 'preprocess_comment', $commentdata );

		$approved = wp_allow_comment( $commentdata, true );
		if ( is_wp_error( $approved ) ) {
			// The flood check. Its message is already human-readable, but core
			// stores the status as bare int data, which the error envelope
			// cannot read - normalise to the array shape so the client gets a
			// 429 instead of a default 500.
			$status = is_numeric( $approved->get_error_data() ) ? (int) $approved->get_error_data() : 429;
			return new \WP_Error( $approved->get_error_code(), $approved->get_error_message(), array( 'status' => $status ) );
		}

		$commentdata['comment_approved'] = $approved;

		$new_id = wp_insert_comment( $commentdata );

		return array(
			'id'       => (int) $new_id,
			'approved' => $approved,
		);
	}

	private function find_recent_duplicate( int $user_id, string $content, int $parent_id, int $lesson_id ): int {
		if ( $user_id <= 0 || '' === $content ) {
			return 0;
		}

		$args = array(
			'type'       => Lesson_Comment::COMMENT_TYPE,
			'user_id'    => $user_id,
			'parent'     => $parent_id,
			// 'all' = approved + hold: a double-post while the first copy is
			// awaiting moderation must dedupe too, or two pending rows land.
			'status'     => 'all',
			'number'     => 10,
			'orderby'    => 'comment_date_gmt',
			'order'      => 'DESC',
			'date_query' => array(
				array(
					'column'    => 'comment_date_gmt',
					'after'     => gmdate( 'Y-m-d H:i:s', time() - MINUTE_IN_SECONDS ),
					'inclusive' => true,
				),
			),
		);

		// Top-level comments share comment_parent = 0 across every lesson, so
		// scope them to this lesson via the meta; replies are already scoped by
		// their parent (and thus their lesson).
		if ( 0 === $parent_id ) {
			$args['meta_key']   = Lesson_Comment::LESSON_META_KEY; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			$args['meta_value'] = (string) $lesson_id;   // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		}

		foreach ( get_comments( $args ) as $existing ) {
			if ( $existing->comment_content === $content ) {
				return (int) $existing->comment_ID;
			}
		}

		return 0;
	}

	/**
	 * Creates a new top-level comment on a lesson.
	 */
	public function create_comment( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = get_current_user_id();
		$lesson_id = (int) $request->get_param( 'lesson_id' );
		$content   = $request->get_param( 'content' );

		if ( empty( trim( $content ) ) ) {
			return $this->error_response(
				$this->validation_error( __( 'Comment content cannot be empty.', 'learnomy' ) )
			);
		}

		$user          = get_user_by( 'ID', $user_id );
		$clean_content = sanitize_textarea_field( $content );

		// Dedupe + insert run under one advisory lock keyed on user + lesson +
		// content, so two requests fired by a single double-click serialise: the
		// first inserts, the second then SEES that row and returns it instead of
		// creating a duplicate. Without the lock both requests passed the dupe
		// check before either committed, so both inserted.
		$is_new     = false;
		$moderation = 1;
		$comment_id = \Learnomy\DB\Lock::with_lock(
			'comment:' . $user_id . ':L' . $lesson_id . ':' . md5( $clean_content ),
			function () use ( $user_id, $clean_content, $lesson_id, $user, &$is_new, &$moderation ) {
				$dupe_id = $this->find_recent_duplicate( $user_id, $clean_content, 0, $lesson_id );
				if ( $dupe_id ) {
					return $dupe_id;
				}

				$inserted = $this->insert_moderated_comment(
					array(
						'comment_content' => $clean_content,
						'comment_parent'  => 0,
						'user_id'         => $user_id,
					)
				);
				if ( is_wp_error( $inserted ) ) {
					return $inserted;
				}
				$new_id     = $inserted['id'];
				$moderation = $inserted['approved'];

				if ( $new_id ) {
					// Set the lesson meta INSIDE the lock so a concurrent request
					// waiting on the lock can match it via find_recent_duplicate.
					update_comment_meta( $new_id, Lesson_Comment::LESSON_META_KEY, $lesson_id );
					$is_new = true;
				}

				return (int) $new_id;
			}
		);

		if ( is_wp_error( $comment_id ) ) {
			// The flood check's own message ("posting too quickly") is the
			// most useful thing to show.
			return $this->error_response( $comment_id );
		}
		if ( ! $comment_id ) {
			return $this->error_response(
				$this->validation_error( __( 'Could not post comment. Please try again.', 'learnomy' ) )
			);
		}

		$lesson = Lesson::find( $lesson_id );
		$course = $lesson ? Course::find( (int) $lesson->course_id ) : null;

		// Side effects (notifications etc.) only fire for a genuinely new comment
		// that is actually LIVE - notifying people about a comment held for
		// moderation would link them to something they cannot see. A held
		// comment that later gets approved goes live through wp-admin, where
		// core's comment_unapproved_to_approved transition is the seam.
		if ( $is_new && 1 === (int) $moderation ) {
			do_action( 'learnomy_lesson_comment_posted', $comment_id, $lesson_id, $user_id );
		}

		$instructor_ids       = $this->get_instructor_ids( $course );
		$comment_obj          = get_comment( $comment_id );
		$formatted            = $this->format_comment( $comment_obj, $instructor_ids );
		$formatted['replies'] = array();
		// 'pending' covers every non-live verdict (hold, disallowed-key trash,
		// spam) - the author is told their comment awaits review, never its
		// spam classification.
		$formatted['moderation'] = ( 1 === (int) $moderation ) ? 'approved' : 'pending';

		$response = $this->single_response( $formatted );
		// 201 for a newly created comment; 200 when we returned an existing one
		// (idempotent dedupe of a double-submit).
		$response->set_status( $is_new ? 201 : 200 );

		return $response;
	}

	/**
	 * Creates a reply to an existing comment (1 level max).
	 */
	public function create_reply( WP_REST_Request $request ): WP_REST_Response {
		$user_id   = get_current_user_id();
		$parent_id = (int) $request->get_param( 'id' );
		$content   = $request->get_param( 'content' );

		$parent = get_comment( $parent_id );
		if ( ! $parent || Lesson_Comment::COMMENT_TYPE !== $parent->comment_type ) {
			return $this->error_response( $this->not_found( 'Comment' ) );
		}

		// Enforce 2-level threading: do not allow replies to replies.
		if ( (int) $parent->comment_parent > 0 ) {
			return $this->error_response(
				$this->validation_error( __( 'Replies to replies are not allowed. Please reply to the top-level comment.', 'learnomy' ) )
			);
		}

		if ( empty( trim( $content ) ) ) {
			return $this->error_response(
				$this->validation_error( __( 'Reply content cannot be empty.', 'learnomy' ) )
			);
		}

		$user          = get_user_by( 'ID', $user_id );
		$clean_content = sanitize_textarea_field( $content );
		$lesson_id     = (int) get_comment_meta( $parent_id, Lesson_Comment::LESSON_META_KEY, true );

		// Dedupe + insert under one advisory lock (same as create_comment) so a
		// double-clicked reply can't race past the duplicate check.
		$is_new     = false;
		$moderation = 1;
		$comment_id = \Learnomy\DB\Lock::with_lock(
			'reply:' . $user_id . ':P' . $parent_id . ':' . md5( $clean_content ),
			function () use ( $user_id, $clean_content, $parent_id, $lesson_id, $user, &$is_new, &$moderation ) {
				$dupe_id = $this->find_recent_duplicate( $user_id, $clean_content, $parent_id, 0 );
				if ( $dupe_id ) {
					return $dupe_id;
				}

				$inserted = $this->insert_moderated_comment(
					array(
						'comment_content' => $clean_content,
						'comment_parent'  => $parent_id,
						'user_id'         => $user_id,
					)
				);
				if ( is_wp_error( $inserted ) ) {
					return $inserted;
				}
				$new_id     = $inserted['id'];
				$moderation = $inserted['approved'];

				if ( $new_id ) {
					update_comment_meta( $new_id, Lesson_Comment::LESSON_META_KEY, $lesson_id );
					$is_new = true;
				}

				return (int) $new_id;
			}
		);

		if ( is_wp_error( $comment_id ) ) {
			return $this->error_response( $comment_id );
		}
		if ( ! $comment_id ) {
			return $this->error_response(
				$this->validation_error( __( 'Could not post reply. Please try again.', 'learnomy' ) )
			);
		}

		if ( $is_new && 1 === (int) $moderation ) {
			do_action( 'learnomy_lesson_comment_replied', $comment_id, $parent_id, $lesson_id, $user_id );
		}

		$lesson         = Lesson::find( $lesson_id );
		$course         = $lesson ? Course::find( (int) $lesson->course_id ) : null;
		$instructor_ids = $this->get_instructor_ids( $course );
		$comment_obj    = get_comment( $comment_id );

		$formatted = $this->format_comment( $comment_obj, $instructor_ids );
		// Same 'pending' umbrella as create_comment: never disclose a spam
		// classification to the author.
		$formatted['moderation'] = ( 1 === (int) $moderation ) ? 'approved' : 'pending';

		$response = $this->single_response( $formatted );
		$response->set_status( $is_new ? 201 : 200 );

		return $response;
	}

	/**
	 * Deletes a comment (own comments only, or admin).
	 */
	public function delete_comment( WP_REST_Request $request ): WP_REST_Response {
		$comment_id = (int) $request->get_param( 'id' );
		$comment    = get_comment( $comment_id );

		if ( ! $comment || Lesson_Comment::COMMENT_TYPE !== $comment->comment_type ) {
			return $this->error_response( $this->not_found( 'Comment' ) );
		}

		// Also delete child replies when deleting a top-level comment.
		if ( 0 === (int) $comment->comment_parent ) {
			$replies = get_comments(
				array(
					'parent' => $comment_id,
					'type'   => Lesson_Comment::COMMENT_TYPE,
					'status' => 'any',
				)
			);
			foreach ( $replies as $reply ) {
				wp_delete_comment( $reply->comment_ID, true );
			}
		}

		wp_delete_comment( $comment_id, true );

		return $this->single_response(
			array(
				'deleted' => true,
				'id'      => $comment_id,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	/**
	 * Anyone enrolled in the course (or instructor/admin) can view comments.
	 */
	public function can_view_lesson_comments( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$lesson_id = (int) $request->get_param( 'lesson_id' );
		$lesson    = Lesson::find( $lesson_id );

		if ( ! $lesson ) {
			return $this->permission_error();
		}

		if ( ! $this->check_permission( 'access_course', (int) $lesson->course_id ) ) {
			return $this->permission_error();
		}

		return true;
	}

	/**
	 * Only enrolled students and course instructor can post comments.
	 */
	public function can_comment_on_lesson( WP_REST_Request $request ): bool|WP_Error {
		$can_view = $this->can_view_lesson_comments( $request );
		if ( true !== $can_view ) {
			return $can_view;
		}

		// Posting must also respect the effective comments setting (global
		// kill-switch + course/lesson override). Viewing existing comments
		// stays allowed; only new writes are blocked when comments are off.
		$lesson_id = (int) $request->get_param( 'lesson_id' );
		if ( ! Lesson::get_effective_comments_enabled( $lesson_id ) ) {
			return $this->permission_error();
		}

		return true;
	}

	/**
	 * Must be enrolled in the parent comment's lesson course.
	 */
	public function can_reply_to_comment( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$parent_id = (int) $request->get_param( 'id' );
		$parent    = get_comment( $parent_id );

		if ( ! $parent || Lesson_Comment::COMMENT_TYPE !== $parent->comment_type ) {
			return $this->permission_error();
		}

		$lesson_id = (int) get_comment_meta( $parent_id, Lesson_Comment::LESSON_META_KEY, true );
		$lesson    = Lesson::find( $lesson_id );

		if ( ! $lesson ) {
			return $this->permission_error();
		}

		if ( ! $this->check_permission( 'access_course', (int) $lesson->course_id ) ) {
			return $this->permission_error();
		}

		// Replies are writes too — block them when comments are disabled.
		if ( ! Lesson::get_effective_comments_enabled( $lesson_id ) ) {
			return $this->permission_error();
		}

		return true;
	}

	/**
	 * Users can delete their own comments; admins can delete any.
	 */
	public function can_delete_comment( WP_REST_Request $request ): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		$comment_id = (int) $request->get_param( 'id' );
		$comment    = get_comment( $comment_id );

		if ( ! $comment || Lesson_Comment::COMMENT_TYPE !== $comment->comment_type ) {
			return $this->permission_error();
		}

		// Permission_Engine bypass — ownership predicate only, not a standalone gate.
		// The OR expression binds ownership (user's own comment) with admin status.
		// Permission_Engine is invoked separately below for the instructor path.
		// Extracting this into check_permission() would require a new per-comment
		// context ability that has no other consumers; the ownership arm is a data
		// predicate, not a capability check. See audit/abilities-coverage.md.
		if ( (int) $comment->user_id === $user_id || current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Course instructor can also moderate.
		$lesson_id = (int) get_comment_meta( $comment_id, Lesson_Comment::LESSON_META_KEY, true );
		$lesson    = Lesson::find( $lesson_id );

		if ( $lesson && $this->check_permission( 'manage_course', (int) $lesson->course_id ) ) {
			return true;
		}

		return $this->permission_error();
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Format a WP_Comment into a consistent array for the API response.
	 */
	private function format_comment( \WP_Comment $comment, array $instructor_ids = array() ): array {
		$user = get_user_by( 'ID', $comment->user_id );

		return array(
			'id'            => (int) $comment->comment_ID,
			'parent_id'     => (int) $comment->comment_parent,
			'user_id'       => (int) $comment->user_id,
			'author_name'   => $user ? $user->display_name : __( 'Anonymous', 'learnomy' ),
			'author_avatar' => $user ? get_avatar_url( $user->ID, array( 'size' => 64 ) ) : '',
			'is_instructor' => in_array( (int) $comment->user_id, $instructor_ids, true ),
			'content'       => $comment->comment_content,
			'date'          => $comment->comment_date_gmt,
			'date_relative' => sprintf(
				/* translators: %s: human-readable time difference */
				__( '%s ago', 'learnomy' ),
				human_time_diff( strtotime( $comment->comment_date_gmt ), time() )
			),
		);
	}

	/**
	 * Collects all instructor user IDs for a course.
	 */
	private function get_instructor_ids( ?object $course ): array {
		if ( ! $course ) {
			return array();
		}

		$ids   = array( (int) $course->author_id );
		$extra = Course::get_instructors( (int) $course->id );

		foreach ( $extra as $instructor ) {
			$ids[] = (int) $instructor->user_id;
		}

		return array_unique( array_filter( $ids ) );
	}
}
