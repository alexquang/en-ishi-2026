<?php
/**
 * BuddyNext template part: profile-tab-panel.
 *
 * Renders the tab-content container for the profile-view page. The
 * existing behavior renders all known panel shells (`posts`, `replies`,
 * `media`, `likes`, `discussions`) and lets the Interactivity API show
 * the active one via the `hidden` attribute on `[data-tab-panel]` nodes.
 * The seam for bridge/Pro additions is `buddynext_part_profile_tab_panel_after`.
 *
 * @package BuddyNext
 * @since   1.1.0
 *
 * @var string $active_tab        Required. Slug of the active tab. Default `posts`.
 * @var int    $profile_user_id   Required. ID of the profile being viewed.
 * @var int    $viewer_id         Optional. ID of the current viewer.
 * @var bool   $is_owner          Optional. Whether viewer owns this profile.
 * @var string $display_name      Optional. Profile display name (used in empty states).
 * @var array  $recent_posts      Optional. Rows for the Posts panel.
 * @var array  $user_replies      Optional. Rows for the Replies panel.
 * @var array  $user_media        Optional. Rows for the Media panel.
 * @var array  $user_likes        Optional. Rows for the Likes panel.
 * @var array  $jt_discussions    Optional. Rows for the Jetonomy Discussions panel.
 * @var bool   $show_discussions  Optional. Whether the Discussions panel is rendered.
 * @var array  $classes           Optional. Extra CSS classes appended to `.bn-pf-tab-content`.
 *
 * Fires:
 *   - do_action( 'buddynext_part_profile_tab_panel_before', $args )
 *   - do_action( 'buddynext_part_profile_tab_panel_after',  $args )
 *
 * Filters:
 *   - apply_filters( 'buddynext_part_profile_tab_panel_args',    array $args )
 *   - apply_filters( 'buddynext_part_profile_tab_panel_classes', array $classes, array $args )
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

$args = array(
	'active_tab'               => isset( $active_tab ) ? (string) $active_tab : 'posts',
	'about_html'               => isset( $about_html ) ? (string) $about_html : '',
	'profile_user_id'          => isset( $profile_user_id ) ? (int) $profile_user_id : 0,
	'viewer_id'                => isset( $viewer_id ) ? (int) $viewer_id : 0,
	'is_owner'                 => isset( $is_owner ) ? (bool) $is_owner : false,
	'display_name'             => isset( $display_name ) ? (string) $display_name : '',
	'recent_posts'             => isset( $recent_posts ) && is_array( $recent_posts ) ? $recent_posts : array(),
	'scheduled_posts'          => isset( $scheduled_posts ) && is_array( $scheduled_posts ) ? $scheduled_posts : array(),
	'user_replies'             => isset( $user_replies ) && is_array( $user_replies ) ? $user_replies : array(),
	'user_media'               => isset( $user_media ) && is_array( $user_media ) ? $user_media : array(),
	'user_likes'               => isset( $user_likes ) && is_array( $user_likes ) ? $user_likes : array(),
	'jt_discussions'           => isset( $jt_discussions ) && is_array( $jt_discussions ) ? $jt_discussions : array(),
	'show_discussions'         => isset( $show_discussions ) ? (bool) $show_discussions : false,
	'follower_users'           => isset( $follower_users ) && is_array( $follower_users ) ? $follower_users : array(),
	'following_users'          => isset( $following_users ) && is_array( $following_users ) ? $following_users : array(),
	'connection_users'         => isset( $connection_users ) && is_array( $connection_users ) ? $connection_users : array(),
	'pending_follow_users'     => isset( $pending_follow_users ) && is_array( $pending_follow_users ) ? $pending_follow_users : array(),
	'pending_connection_users' => isset( $pending_connection_users ) && is_array( $pending_connection_users ) ? $pending_connection_users : array(),
	'classes'                  => isset( $classes ) ? (array) $classes : array(),
);

/** Sanitized partial arguments. @var array<string,mixed> $args */
$args = (array) apply_filters( 'buddynext_part_profile_tab_panel_args', $args );

$bn_classes = array_merge( array( 'bn-pf-tab-content' ), array_filter( (array) $args['classes'], 'is_string' ) );
/** Computed root-class list. @var array<int,string> $bn_classes */
$bn_classes = (array) apply_filters( 'buddynext_part_profile_tab_panel_classes', $bn_classes, $args );
$bn_class   = trim(
	implode(
		' ',
		array_unique(
			array_filter(
				$bn_classes,
				static function ( $c ) {
					return is_string( $c ) && '' !== $c;
				}
			)
		)
	)
);

$bn_pf_uid              = (int) $args['profile_user_id'];
$bn_pf_viewer           = (int) $args['viewer_id'];
$bn_pf_is_owner         = (bool) $args['is_owner'];
$bn_pf_name             = (string) $args['display_name'];
$bn_pf_about_html       = (string) $args['about_html'];
$bn_recent_posts        = (array) $args['recent_posts'];
$bn_scheduled_posts     = (array) $args['scheduled_posts'];
$bn_user_replies        = (array) $args['user_replies'];
$bn_user_media          = (array) $args['user_media'];
$bn_user_likes          = (array) $args['user_likes'];
$bn_jt_disc             = (array) $args['jt_discussions'];
$bn_followers           = (array) $args['follower_users'];
$bn_following           = (array) $args['following_users'];
$bn_connections         = (array) $args['connection_users'];
$bn_pending_follows     = (array) $args['pending_follow_users'];
$bn_pending_connections = (array) $args['pending_connection_users'];

// Reactive single-source visibility: each panel carries its own context
// (tabSlug) and inherits the profile region's context.activeTab (seeded
// server-side from the route action). The Interactivity getter
// state.isActiveTab (c.activeTab === c.tabSlug) reveals exactly the active
// panel via data-wp-bind--hidden="!state.isActiveTab" — deep links work on the
// initial server render (activeTab is seeded), and tab clicks repaint reactively
// without any DOM toggling. $bn_pf_panel_ctx builds the per-panel context JSON.
$bn_pf_active    = (string) $args['active_tab'];
$bn_pf_panel_ctx = static fn( string $bn_slug ): string => (string) wp_json_encode( array( 'tabSlug' => $bn_slug ) );

do_action( 'buddynext_part_profile_tab_panel_before', $args );
?>
		<!-- Tab panels container -->
		<div class="<?php echo esc_attr( $bn_class ); ?>">

			<!-- Posts list (default tab) -->
			<div class="bn-profile-posts-panel" id="<?php echo esc_attr( buddynext_nav_panel_id( 'posts' ) ); ?>" data-tab-panel="posts" data-wp-context='<?php echo esc_attr( $bn_pf_panel_ctx( 'posts' ) ); ?>' data-wp-bind--hidden="!state.isActiveTab" <?php echo 'posts' === $bn_pf_active ? '' : 'hidden'; ?>>
			<?php if ( $bn_pf_is_owner ) : ?>
				<?php
				// Profile owner can post directly from their activity tab — the same
				// composer the home feed uses (self-contained Interactivity context).
				buddynext_get_template(
					'partials/composer.php',
					array(
						'space_id'        => null,
						'current_user_id' => $bn_pf_viewer,
					)
				);
				?>
			<?php endif; ?>
			<?php if ( $bn_recent_posts ) : ?>
				<?php
				foreach ( $bn_recent_posts as $post_arr ) {
					// Decode media_ids JSON string for the post-card partial.
					if ( isset( $post_arr['media_ids'] ) && is_string( $post_arr['media_ids'] ) ) {
						$post_arr['media_ids'] = json_decode( $post_arr['media_ids'], true );
					}
					buddynext_get_template(
						'partials/post-card.php',
						array(
							'post'            => $post_arr,
							'current_user_id' => $bn_pf_viewer,
							'context'         => 'profile',
						)
					);
				}
				?>
			<?php else : ?>
				<div class="bn-empty-state">
					<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'edit' ); ?></div>
					<div class="bn-empty-title">
						<?php
						echo esc_html(
							$bn_pf_is_owner
								? __( 'You have not posted anything yet.', 'buddynext' )
								: sprintf(
									/* translators: %s: member display name */
									__( '%s has not posted anything yet.', 'buddynext' ),
									$bn_pf_name
								)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
			</div><!-- /.bn-profile-posts-panel -->

			<!-- Scheduled tab content — owner-only; the member's queued future posts. -->
			<?php if ( $bn_pf_is_owner ) : ?>
			<div class="bn-profile-tab-panel bn-profile-scheduled-panel" id="<?php echo esc_attr( buddynext_nav_panel_id( 'scheduled' ) ); ?>" data-tab-panel="scheduled" data-wp-context='<?php echo esc_attr( $bn_pf_panel_ctx( 'scheduled' ) ); ?>' data-wp-bind--hidden="!state.isActiveTab" <?php echo 'scheduled' === $bn_pf_active ? '' : 'hidden'; ?>>
				<?php if ( $bn_scheduled_posts ) : ?>
					<?php
					foreach ( $bn_scheduled_posts as $bn_sched_post ) {
						if ( isset( $bn_sched_post['media_ids'] ) && is_string( $bn_sched_post['media_ids'] ) ) {
							$bn_sched_post['media_ids'] = json_decode( $bn_sched_post['media_ids'], true );
						}
						buddynext_get_template(
							'partials/post-card.php',
							array(
								'post'            => $bn_sched_post,
								'current_user_id' => $bn_pf_viewer,
								'context'         => 'profile',
							)
						);
					}
					?>
				<?php else : ?>
					<div class="bn-empty-state">
						<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'clock' ); ?></div>
						<div class="bn-empty-title"><?php esc_html_e( 'You have no scheduled posts.', 'buddynext' ); ?></div>
					</div>
				<?php endif; ?>
			</div><!-- /.bn-profile-scheduled-panel -->
			<?php endif; ?>

			<!-- About tab content — profile details, moved out of the always-on column. -->
			<?php if ( '' !== $bn_pf_about_html ) : ?>
			<div class="bn-profile-tab-panel bn-pf-about-panel" id="<?php echo esc_attr( buddynext_nav_panel_id( 'about' ) ); ?>" data-tab-panel="about" data-wp-context='<?php echo esc_attr( $bn_pf_panel_ctx( 'about' ) ); ?>' data-wp-bind--hidden="!state.isActiveTab" <?php echo 'about' === $bn_pf_active ? '' : 'hidden'; ?>>
				<?php echo $bn_pf_about_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-rendered about-cards markup; escaped at source by the FieldType engine + esc_html(). ?>
			</div>
			<?php endif; ?>

			<!-- Replies tab content -->
			<div class="bn-profile-tab-panel" id="<?php echo esc_attr( buddynext_nav_panel_id( 'replies' ) ); ?>" data-tab-panel="replies" data-wp-context='<?php echo esc_attr( $bn_pf_panel_ctx( 'replies' ) ); ?>' data-wp-bind--hidden="!state.isActiveTab" <?php echo 'replies' === $bn_pf_active ? '' : 'hidden'; ?>>
				<?php if ( $bn_user_replies ) : ?>
					<?php
					foreach ( $bn_user_replies as $reply ) :
						// Link each reply back to the activity it was posted on
						// (/p/{id}/ renders the post + its comment thread), so the
						// Replies tab is a navigable history, not a dead list.
						$bn_reply_url = \BuddyNext\Core\PageRouter::post_url( (int) $reply->object_id );
						?>
					<a class="bn-reply-card bn-reply-card--link" href="<?php echo esc_url( $bn_reply_url ); ?>">
						<div class="bn-reply-card__meta">
							<?php buddynext_icon( 'message-circle' ); ?>
							<span><?php echo esc_html( sprintf( /* translators: %s: author name */ __( 'Replied to %s', 'buddynext' ), $reply->post_author_name ) ); ?></span>
							<span class="bn-reply-card__time"><?php echo buddynext_time_ago( (string) $reply->created_at ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- buddynext_time_ago() returns esc_html()'d output. ?></span>
						</div>
						<div class="bn-reply-card__content"><?php echo wp_kses_post( wp_trim_words( $reply->content, 30 ) ); ?></div>
						<div class="bn-reply-card__context"><?php echo wp_kses_post( wp_trim_words( $reply->post_content, 15 ) ); ?></div>
					</a>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="bn-empty-state">
						<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'message-circle' ); ?></div>
						<div class="bn-empty-title"><?php esc_html_e( 'No replies yet.', 'buddynext' ); ?></div>
					</div>
				<?php endif; ?>
			</div>

			<!-- Media tab content -->
			<div class="bn-profile-tab-panel" id="<?php echo esc_attr( buddynext_nav_panel_id( 'media' ) ); ?>" data-tab-panel="media" data-wp-context='<?php echo esc_attr( $bn_pf_panel_ctx( 'media' ) ); ?>' data-wp-bind--hidden="!state.isActiveTab" <?php echo 'media' === $bn_pf_active ? '' : 'hidden'; ?>>
				<?php
				// Full media surface: Media | Albums sub-nav, the owner upload
				// composer + gallery, and the albums UI (cards/create/detail/picker).
				// BuddyNext owns the whole experience; no WPMediaVerse markup/JS.
				buddynext_get_template(
					'partials/media-tab.php',
					array(
						'bn_mt_owner_id'  => $bn_pf_uid,
						'bn_mt_is_owner'  => $bn_pf_is_owner,
						'bn_mt_media_ids' => (array) $bn_user_media,
					)
				);
				?>
			</div>

			<!-- Likes tab content -->
			<div class="bn-profile-tab-panel" id="<?php echo esc_attr( buddynext_nav_panel_id( 'likes' ) ); ?>" data-tab-panel="likes" data-wp-context='<?php echo esc_attr( $bn_pf_panel_ctx( 'likes' ) ); ?>' data-wp-bind--hidden="!state.isActiveTab" <?php echo 'likes' === $bn_pf_active ? '' : 'hidden'; ?>>
				<?php if ( $bn_user_likes ) : ?>
					<?php
					// Render each liked post through the full post-card partial so the
					// Likes tab shows it exactly as in the feed — media, link previews,
					// reactions and all — instead of a text-only summary card.
					foreach ( $bn_user_likes as $liked_arr ) {
						$liked_arr = (array) $liked_arr;
						if ( isset( $liked_arr['media_ids'] ) && is_string( $liked_arr['media_ids'] ) ) {
							$liked_arr['media_ids'] = json_decode( $liked_arr['media_ids'], true );
						}
						buddynext_get_template(
							'partials/post-card.php',
							array(
								'post'            => $liked_arr,
								'current_user_id' => $bn_pf_viewer,
								'context'         => 'profile',
							)
						);
					}
					?>
				<?php else : ?>
					<div class="bn-empty-state">
						<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'heart' ); ?></div>
						<div class="bn-empty-title"><?php esc_html_e( 'No liked posts yet.', 'buddynext' ); ?></div>
					</div>
				<?php endif; ?>
			</div>

			<!-- Discussions tab content (Jetonomy) -->
			<?php if ( (bool) $args['show_discussions'] ) : ?>
			<div class="bn-profile-tab-panel" id="<?php echo esc_attr( buddynext_nav_panel_id( 'discussions' ) ); ?>" data-tab-panel="discussions" data-wp-context='<?php echo esc_attr( $bn_pf_panel_ctx( 'discussions' ) ); ?>' data-wp-bind--hidden="!state.isActiveTab" <?php echo 'discussions' === $bn_pf_active ? '' : 'hidden'; ?>>
				<?php if ( $bn_jt_disc ) : ?>
					<?php
					foreach ( $bn_jt_disc as $disc ) :
						$disc_space_slug = '' !== (string) $disc->space_slug ? (string) $disc->space_slug : 'general';
						$disc_space_name = '' !== (string) $disc->space_name ? (string) $disc->space_name : __( 'General', 'buddynext' );
						?>
					<a href="<?php echo esc_url( home_url( '/community/s/' . $disc_space_slug . '/t/' . $disc->slug . '/' ) ); ?>" class="bn-reply-card bn-reply-card--link bn-reply-card--avatar">
						<span class="bn-reply-card__avatar" aria-hidden="true">
							<img src="<?php echo esc_url( get_avatar_url( $bn_pf_uid, array( 'size' => 80 ) ) ); ?>" alt="" loading="lazy" width="40" height="40" />
						</span>
						<div class="bn-reply-card__meta">
							<span><?php echo esc_html( $disc_space_name ); ?></span>
							<span class="bn-reply-card__time"><?php echo esc_html( sprintf( /* translators: %s: human-readable time difference, e.g. "3 hours" */ __( '%s ago', 'buddynext' ), human_time_diff( strtotime( $disc->created_at ) ) ) ); ?></span>
						</div>
						<div class="bn-reply-card__content bn-reply-card__content--strong"><?php echo esc_html( $disc->title ); ?></div>
						<div class="bn-reply-card__context">
							<?php
							$bn_ptp_rc = (int) $disc->reply_count;
							/* translators: %d: number of replies */ printf( esc_html( _n( '%d reply', '%d replies', $bn_ptp_rc, 'buddynext' ) ), (int) $bn_ptp_rc );
							?>
							<span aria-hidden="true">&middot;</span>
							<?php
							$bn_ptp_vc = (int) $disc->vote_score;
							/* translators: %d: number of votes */ printf( esc_html( _n( '%d vote', '%d votes', $bn_ptp_vc, 'buddynext' ) ), (int) $bn_ptp_vc );
							?>
						</div>
					</a>
					<?php endforeach; ?>
				<?php else : ?>
					<div class="bn-empty-state">
						<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'messages-square' ); ?></div>
						<div class="bn-empty-title"><?php esc_html_e( 'No discussions yet.', 'buddynext' ); ?></div>
					</div>
				<?php endif; ?>
			</div>
			<?php endif; ?>

			<!-- Followers tab content -->
			<div class="bn-profile-tab-panel bn-pf-people-panel" id="<?php echo esc_attr( buddynext_nav_panel_id( 'followers' ) ); ?>" data-tab-panel="followers" data-wp-context='<?php echo esc_attr( $bn_pf_panel_ctx( 'followers' ) ); ?>' data-wp-bind--hidden="!state.isActiveTab" <?php echo 'followers' === $bn_pf_active ? '' : 'hidden'; ?>>
				<?php if ( ! empty( $bn_pending_follows ) ) : ?>
					<section class="bn-follow-requests" aria-label="<?php esc_attr_e( 'Pending follow requests', 'buddynext' ); ?>">
						<header class="bn-follow-requests__head">
							<h3 class="bn-follow-requests__title">
								<?php
								printf(
									/* translators: %d: number of pending follow requests */
									esc_html( _n( 'Pending request', 'Pending requests', count( $bn_pending_follows ), 'buddynext' ) ) . ' <span class="bn-follow-requests__count">%d</span>',
									(int) count( $bn_pending_follows )
								);
								?>
							</h3>
							<p class="bn-follow-requests__sub"><?php esc_html_e( 'Your account is private. Approve who can follow you.', 'buddynext' ); ?></p>
						</header>
						<ul class="bn-follow-requests__list" role="list">
							<?php
							foreach ( $bn_pending_follows as $bn_req_user ) :
								if ( ! $bn_req_user instanceof WP_User ) {
									continue;
								}
								$bn_req_id  = (int) $bn_req_user->ID;
								$bn_req_url = \BuddyNext\Core\PageRouter::profile_url( $bn_req_id );
								$bn_req_ctx = wp_json_encode(
									array(
										'followerId' => $bn_req_id,
										'targetName' => $bn_req_user->user_nicename,
										'hidden'     => false,
										'busy'       => false,
										'restUrl'    => rest_url( 'buddynext/v1' ),
										'nonce'      => wp_create_nonce( 'wp_rest' ),
									)
								);
								?>
								<li class="bn-follow-requests__row" data-wp-interactive="buddynext/follow-requests" data-wp-context='<?php echo esc_attr( (string) $bn_req_ctx ); ?>' data-wp-bind--hidden="state.rowHidden">
									<a href="<?php echo esc_url( $bn_req_url ); ?>" class="bn-follow-requests__avatar" aria-hidden="true" tabindex="-1">
										<img src="<?php echo esc_url( get_avatar_url( $bn_req_id, array( 'size' => 96 ) ) ); ?>" alt="" loading="lazy" />
									</a>
									<div class="bn-follow-requests__id">
										<a href="<?php echo esc_url( $bn_req_url ); ?>" class="bn-follow-requests__name"><?php echo esc_html( $bn_req_user->display_name ); ?></a>
										<span class="bn-follow-requests__handle">@<?php echo esc_html( $bn_req_user->user_nicename ); ?></span>
									</div>
									<div class="bn-follow-requests__actions">
										<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-wp-on--click="actions.reject"><?php esc_html_e( 'Decline', 'buddynext' ); ?></button>
										<button type="button" class="bn-btn" data-variant="primary" data-size="sm" data-wp-on--click="actions.approve"><?php esc_html_e( 'Approve', 'buddynext' ); ?></button>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endif; ?>
				<?php if ( ! empty( $bn_followers ) ) : ?>
					<?php
					buddynext_get_template(
						'parts/member-grid.php',
						array(
							'members'   => $bn_followers,
							'viewer_id' => $bn_pf_viewer,
						)
					);
					?>
				<?php else : ?>
					<div class="bn-empty-state">
						<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></div>
						<div class="bn-empty-title">
							<?php
							echo esc_html(
								$bn_pf_is_owner
									? __( 'No followers yet', 'buddynext' )
									: sprintf( /* translators: %s: member name */ __( '%s has no followers yet.', 'buddynext' ), $bn_pf_name )
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<!-- Following tab content -->
			<div class="bn-profile-tab-panel bn-pf-people-panel" id="<?php echo esc_attr( buddynext_nav_panel_id( 'following' ) ); ?>" data-tab-panel="following" data-wp-context='<?php echo esc_attr( $bn_pf_panel_ctx( 'following' ) ); ?>' data-wp-bind--hidden="!state.isActiveTab" <?php echo 'following' === $bn_pf_active ? '' : 'hidden'; ?>>
				<?php if ( ! empty( $bn_following ) ) : ?>
					<?php
					buddynext_get_template(
						'parts/member-grid.php',
						array(
							'members'   => $bn_following,
							'viewer_id' => $bn_pf_viewer,
						)
					);
					?>
				<?php else : ?>
					<div class="bn-empty-state">
						<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'user-plus' ); ?></div>
						<div class="bn-empty-title">
							<?php
							echo esc_html(
								$bn_pf_is_owner
									? __( 'You are not following anyone yet', 'buddynext' )
									: sprintf( /* translators: %s: member name */ __( '%s is not following anyone yet.', 'buddynext' ), $bn_pf_name )
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<!-- Connections tab content -->
			<div class="bn-profile-tab-panel bn-pf-people-panel" id="<?php echo esc_attr( buddynext_nav_panel_id( 'connections' ) ); ?>" data-tab-panel="connections" data-wp-context='<?php echo esc_attr( $bn_pf_panel_ctx( 'connections' ) ); ?>' data-wp-bind--hidden="!state.isActiveTab" <?php echo 'connections' === $bn_pf_active ? '' : 'hidden'; ?>>
				<?php if ( ! empty( $bn_pending_connections ) ) : ?>
					<section class="bn-follow-requests" aria-label="<?php esc_attr_e( 'Pending connection requests', 'buddynext' ); ?>">
						<header class="bn-follow-requests__head">
							<h3 class="bn-follow-requests__title">
								<?php
								printf(
									/* translators: %d: number of pending connection requests */
									esc_html( _n( 'Connection request', 'Connection requests', count( $bn_pending_connections ), 'buddynext' ) ) . ' <span class="bn-follow-requests__count">%d</span>',
									(int) count( $bn_pending_connections )
								);
								?>
							</h3>
							<p class="bn-follow-requests__sub"><?php esc_html_e( 'People who want to connect with you. Accept to connect.', 'buddynext' ); ?></p>
						</header>
						<ul class="bn-follow-requests__list" role="list">
							<?php
							foreach ( $bn_pending_connections as $bn_creq_user ) :
								if ( ! $bn_creq_user instanceof WP_User ) {
									continue;
								}
								$bn_creq_id  = (int) $bn_creq_user->ID;
								$bn_creq_url = \BuddyNext\Core\PageRouter::profile_url( $bn_creq_id );
								$bn_creq_ctx = wp_json_encode(
									array(
										'requesterId' => $bn_creq_id,
										'targetName'  => $bn_creq_user->user_nicename,
										'hidden'      => false,
										'busy'        => false,
										'restUrl'     => rest_url( 'buddynext/v1' ),
										'nonce'       => wp_create_nonce( 'wp_rest' ),
									)
								);
								?>
								<li class="bn-follow-requests__row" data-wp-interactive="buddynext/connection-requests" data-wp-context='<?php echo esc_attr( (string) $bn_creq_ctx ); ?>' data-wp-bind--hidden="state.rowHidden">
									<a href="<?php echo esc_url( $bn_creq_url ); ?>" class="bn-follow-requests__avatar" aria-hidden="true" tabindex="-1">
										<img src="<?php echo esc_url( get_avatar_url( $bn_creq_id, array( 'size' => 96 ) ) ); ?>" alt="" loading="lazy" />
									</a>
									<div class="bn-follow-requests__id">
										<a href="<?php echo esc_url( $bn_creq_url ); ?>" class="bn-follow-requests__name"><?php echo esc_html( $bn_creq_user->display_name ); ?></a>
										<span class="bn-follow-requests__handle">@<?php echo esc_html( $bn_creq_user->user_nicename ); ?></span>
									</div>
									<div class="bn-follow-requests__actions">
										<button type="button" class="bn-btn" data-variant="ghost" data-size="sm" data-wp-on--click="actions.decline"><?php esc_html_e( 'Decline', 'buddynext' ); ?></button>
										<button type="button" class="bn-btn" data-variant="primary" data-size="sm" data-wp-on--click="actions.accept"><?php esc_html_e( 'Accept', 'buddynext' ); ?></button>
									</div>
								</li>
							<?php endforeach; ?>
						</ul>
					</section>
				<?php endif; ?>
				<?php if ( ! empty( $bn_connections ) ) : ?>
					<?php
					buddynext_get_template(
						'parts/member-grid.php',
						array(
							'members'   => $bn_connections,
							'viewer_id' => $bn_pf_viewer,
						)
					);
					?>
				<?php else : ?>
					<div class="bn-empty-state">
						<div class="bn-empty-icon" aria-hidden="true"><?php buddynext_icon( 'users' ); ?></div>
						<div class="bn-empty-title">
							<?php
							echo esc_html(
								$bn_pf_is_owner
									? __( 'No connections yet', 'buddynext' )
									: sprintf( /* translators: %s: member name */ __( '%s has no connections yet.', 'buddynext' ), $bn_pf_name )
							);
							?>
						</div>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( $bn_pf_is_owner ) : ?>
			<div class="bn-pf-tab-pane<?php echo 'messages' === $bn_pf_active ? ' is-active' : ''; ?>" data-tab-panel="messages" role="tabpanel" style="<?php echo 'messages' === $bn_pf_active ? '' : 'display:none;'; ?>"<?php echo 'messages' === $bn_pf_active ? '' : ' hidden'; ?>>
				<?php 
				$follower_chat_template = get_stylesheet_directory() . '/template-parts/follower-messages-list.php';
				if ( file_exists( $follower_chat_template ) ) {
					include $follower_chat_template;
				}
				?>
				<div id="pt-profile-inbox-list" style="display:flex;flex-direction:column;gap:12px;margin-top:20px;">
				<?php
				if ( function_exists( 'pt_get_place_inbox_for_viewer' ) ) {
					$pt_inbox_data = pt_get_place_inbox_for_viewer( $bn_pf_uid );
				} else {
					$pt_raw        = get_user_meta( $bn_pf_uid, 'pt_place_inbox_messages', true );
					$pt_inbox_data = array( 'messages' => is_array( $pt_raw ) ? $pt_raw : array(), 'is_paid' => true, 'upgrade_url' => '' );
				}
				$pt_inbox       = $pt_inbox_data['messages'];
				$pt_upgrade_url = (string) $pt_inbox_data['upgrade_url'];
				if ( ! empty( $pt_inbox ) ) :
					foreach ( $pt_inbox as $pt_msg ) :
						$pt_locked = ! empty( $pt_msg['locked'] );
				?>
						<div class="bn-card" style="padding:14px 16px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;">
							<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px;">
								<strong><?php echo esc_html( (string) ( $pt_msg['sender_name'] ?? 'User' ) ); ?></strong>
								<span style="font-size:12px;color:#64748b;"><?php echo esc_html( (string) ( $pt_msg['created_at'] ?? '' ) ); ?></span>
							</div>
							<?php if ( ! empty( $pt_msg['place_name'] ) ) : ?>
								<div style="font-size:12px;color:#ef4444;margin-bottom:4px;">📍 <?php echo esc_html( (string) $pt_msg['place_name'] ); ?></div>
							<?php endif; ?>
							<?php if ( $pt_locked ) : ?>
								<div style="position:relative;padding:10px 12px;border-radius:8px;background:#f1f5f9;">
									<div style="filter:blur(4px);user-select:none;color:#64748b;">██████ ████ ███████ ██████ ████</div>
									<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:8px;font-size:13px;font-weight:600;color:#334155;">
										🔒 Nâng cấp gói PMP Pro trả phí để đọc tin nhắn này
										<?php if ( $pt_upgrade_url ) : ?>
											<a href="<?php echo esc_url( $pt_upgrade_url ); ?>" style="background:#ef4444;color:#fff;padding:3px 10px;border-radius:6px;text-decoration:none;">Nâng cấp</a>
										<?php endif; ?>
									</div>
								</div>
							<?php else : ?>
								<?php if ( ! empty( $pt_msg['subject'] ) ) : ?>
									<div style="font-weight:600;margin-bottom:4px;"><?php echo esc_html( (string) $pt_msg['subject'] ); ?></div>
								<?php endif; ?>
								<div style="font-size:14px;color:#334155;white-space:pre-line;"><?php echo esc_html( (string) ( $pt_msg['message'] ?? '' ) ); ?></div>
							<?php endif; ?>
						</div>
				<?php
					endforeach;
				else :
				?>
					<div class="bn-empty-state">
						<div class="bn-empty-title"><?php esc_html_e( 'No messages received yet', 'buddynext' ); ?></div>
					</div>
				<?php endif; ?>
				</div>
				<script>
				(function () {
					const listEl = document.getElementById('pt-profile-inbox-list');
					const paneEl = listEl ? listEl.closest('[data-tab-panel="messages"]') : null;
					if (!listEl || !paneEl) return;
					const ajaxUrl = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
					const esc = (s) => String(s || '').replace(/[&<>"']/g, (c) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
					const emojis = ['😀','😂','😍','🥰','👍','❤️','🙏','🎉','🔥','👏','😢','😮'];
					let lastSig = '';
					let activeReplyId = '';

					async function refreshInbox(force) {
						try {
							if (!force && activeReplyId) return;
							const res = await fetch(ajaxUrl + '?action=pt_get_place_inbox_messages', { credentials: 'same-origin' });
							const json = await res.json();
							if (!json || !json.success || !json.data || !Array.isArray(json.data.messages)) return;
							const msgs = json.data.messages;
							const upgradeUrl = json.data.upgrade_url || '';
							const sig = msgs.map((m) => (m.id || '') + (m.replied ? '1' : '0')).join('|') + ':' + (json.data.is_paid ? 1 : 0);
							if (!force && sig === lastSig) return;
							lastSig = sig;
							if (!msgs.length) {
								listEl.innerHTML = '<div class="bn-empty-state"><div class="bn-empty-title">No messages received yet</div></div>';
								return;
							}
							const lockedHtml =
								'<div style="position:relative;padding:10px 12px;border-radius:8px;background:#f1f5f9;">' +
									'<div style="filter:blur(4px);user-select:none;color:#64748b;">██████ ████ ███████ ██████ ████</div>' +
									'<div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;gap:8px;font-size:13px;font-weight:600;color:#334155;">' +
										'🔒 Nâng cấp gói PMP Pro trả phí để đọc tin nhắn này' +
										(upgradeUrl ? ' <a href="' + esc(upgradeUrl) + '" style="background:#ef4444;color:#fff;padding:3px 10px;border-radius:6px;text-decoration:none;">Nâng cấp</a>' : '') +
									'</div>' +
								'</div>';
							const emojiButtonsHtml = emojis.map((em) =>
								'<button type="button" class="pt-msg-emoji-item" data-emoji="' + em + '" style="background:none;border:none;font-size:18px;cursor:pointer;padding:2px 4px;border-radius:4px;">' + em + '</button>'
							).join('');

							listEl.innerHTML = msgs.map((m) => (
								'<div class="bn-card pt-msg-card" data-msg-id="' + esc(m.id || '') + '" style="padding:14px 16px;border:1px solid #e2e8f0;border-radius:10px;background:#fff;">' +
									'<div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:6px;">' +
										'<strong>' + esc(m.sender_name || 'User') + '</strong>' +
										'<span style="font-size:12px;color:#64748b;">' + esc(m.created_at || '') + '</span>' +
									'</div>' +
									(m.place_name ? '<div style="font-size:12px;color:#ef4444;margin-bottom:4px;">📍 ' + esc(m.place_name) + '</div>' : '') +
									(m.locked ? lockedHtml : (
										(m.subject ? '<div style="font-weight:600;margin-bottom:4px;">' + esc(m.subject) + '</div>' : '') +
										'<div style="font-size:14px;color:#334155;white-space:pre-line;">' + esc(m.message || '') + '</div>' +
										(m.replied && m.reply_text ? '<div style="margin-top:8px;padding:6px 10px;background:#f0fdf4;border-left:3px solid #22c55e;border-radius:6px;font-size:12px;color:#166534;">✓ Đã trả lời: ' + esc(m.reply_text) + '</div>' : '') +
										'<div style="margin-top:10px;display:flex;align-items:center;gap:8px;">' +
											'<button type="button" class="pt-msg-reply-toggle" style="background:#f1f5f9;border:1px solid #cbd5e1;color:#334155;padding:4px 12px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">↩ Trả lời</button>' +
										'</div>' +
										'<div class="pt-msg-reply-box" style="display:none;margin-top:10px;padding:10px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">' +
											'<textarea class="pt-msg-reply-input" rows="2" placeholder="Nhập nội dung trả lời..." style="width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:13px;resize:vertical;box-sizing:border-box;"></textarea>' +
											'<div class="pt-msg-emoji-bar" style="display:none;flex-wrap:wrap;gap:4px;margin-top:6px;padding:6px;background:#fff;border:1px solid #e2e8f0;border-radius:6px;">' + emojiButtonsHtml + '</div>' +
											'<div style="display:flex;justify-content:space-between;align-items:center;margin-top:8px;">' +
												'<button type="button" class="pt-msg-emoji-toggle" title="Chèn emoji" style="background:#fff;border:1px solid #cbd5e1;border-radius:6px;padding:4px 10px;font-size:14px;cursor:pointer;">😊 Emoji</button>' +
												'<div style="display:flex;gap:6px;">' +
													'<button type="button" class="pt-msg-reply-cancel" style="background:#fff;border:1px solid #cbd5e1;color:#475569;padding:5px 12px;border-radius:6px;font-size:12px;cursor:pointer;">Hủy</button>' +
													'<button type="button" class="pt-msg-reply-send" style="background:#2563eb;border:none;color:#fff;padding:5px 14px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;">Gửi trả lời</button>' +
												'</div>' +
											'</div>' +
										'</div>'
									)) +
								'</div>'
							)).join('');
						} catch (e) {}
					}

					listEl.addEventListener('click', async (e) => {
						const card = e.target.closest('.pt-msg-card');
						if (!card) return;
						const msgId = card.getAttribute('data-msg-id') || '';
						const box = card.querySelector('.pt-msg-reply-box');
						const input = card.querySelector('.pt-msg-reply-input');
						const emojiBar = card.querySelector('.pt-msg-emoji-bar');

						if (e.target.closest('.pt-msg-reply-toggle')) {
							const isOpen = box && box.style.display !== 'none';
							listEl.querySelectorAll('.pt-msg-reply-box').forEach((b) => { b.style.display = 'none'; });
							if (!isOpen && box) {
								box.style.display = 'block';
								activeReplyId = msgId;
								if (input) input.focus();
							} else {
								activeReplyId = '';
							}
							return;
						}

						if (e.target.closest('.pt-msg-reply-cancel')) {
							if (box) box.style.display = 'none';
							activeReplyId = '';
							return;
						}

						if (e.target.closest('.pt-msg-emoji-toggle')) {
							if (emojiBar) {
								emojiBar.style.display = emojiBar.style.display === 'none' ? 'flex' : 'none';
							}
							return;
						}

						const emojiBtn = e.target.closest('.pt-msg-emoji-item');
						if (emojiBtn && input) {
							const em = emojiBtn.getAttribute('data-emoji') || '';
							const start = input.selectionStart || input.value.length;
							const end = input.selectionEnd || input.value.length;
							input.value = input.value.slice(0, start) + em + input.value.slice(end);
							input.focus();
							input.selectionStart = input.selectionEnd = start + em.length;
							return;
						}

						const sendBtn = e.target.closest('.pt-msg-reply-send');
						if (sendBtn && input) {
							const text = (input.value || '').trim();
							if (!text) {
								alert('Vui lòng nhập nội dung trả lời.');
								return;
							}
							sendBtn.disabled = true;
							sendBtn.textContent = 'Đang gửi...';
							try {
								const fd = new FormData();
								fd.append('action', 'pt_reply_place_inbox_message');
								fd.append('message_id', msgId);
								fd.append('reply_text', text);
								const res = await fetch(ajaxUrl, { method: 'POST', credentials: 'same-origin', body: fd });
								const json = await res.json();
								if (json && json.success) {
									activeReplyId = '';
									if (box) box.style.display = 'none';
									await refreshInbox(true);
								} else {
									alert((json && json.data && json.data.message) || 'Không thể gửi trả lời.');
								}
							} catch (err) {
								alert('Lỗi kết nối, vui lòng thử lại.');
							} finally {
								sendBtn.disabled = false;
								sendBtn.textContent = 'Gửi trả lời';
							}
						}
					});

					refreshInbox(true);
					document.addEventListener('click', (e) => {
						const tabBtn = e.target.closest('[data-tab]');
						if (!tabBtn) return;
						const targetTab = tabBtn.getAttribute('data-tab');
						if (targetTab === 'messages') {
							paneEl.style.display = 'block';
							paneEl.removeAttribute('hidden');
							paneEl.classList.add('is-active');
							setTimeout(refreshInbox, 80);
						} else {
							paneEl.style.display = 'none';
							paneEl.setAttribute('hidden', '');
							paneEl.classList.remove('is-active');
						}
					});
					setInterval(() => {
						if (document.visibilityState === 'hidden' || paneEl.hasAttribute('hidden')) return;
						refreshInbox();
					}, 3000);
				})();
				</script>
			</div>
			<?php endif; ?>

		</div><!-- /.bn-pf-tab-content -->
<?php
do_action( 'buddynext_part_profile_tab_panel_after', $args );
