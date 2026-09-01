<?php
/**
 * Lesson media block — the content-type switch (video / audio / pdf / embed /
 * download / live).
 *
 * Extracted verbatim from partials/player-content.php so BOTH the collapsible
 * player (focus + the legacy embedded reflow) AND the new sidebar embedded
 * layout render the exact same media markup — preserving the Plyr player's
 * `data-plyr-*` bindings, the loading skeleton, and the Interactivity hooks
 * so video playback + watch-percent tracking keep working unchanged.
 *
 * Included via `include` from partials/player-content.php and from
 * templates/lesson/embedded.php, inheriting the including scope:
 *   $lesson, $course, $video_provider, $video_embed_id, $is_admin,
 *   $is_instructor.
 *
 * Sets two locals the caller reads after the include: $content_type and
 * $media_url (the player needs them for the body / empty-state branches).
 *
 * @package Learnomy
 */

defined( 'ABSPATH' ) || exit;
?>
		<?php
		// `video_url` is the generic media-URL column reused across every
		// content type — the column was named for the original v1 video-only
		// scope but now also stores audio src, pdf href, embed url, etc.
		// (see Lesson::CONTENT_TYPES). Switch on content_type so audio
		// lessons get an `<audio>` player, pdf lessons get an `<iframe>`,
		// download lessons get a button, etc. (bug card "Audio, PDF Content
		// Types Not Working in Lesson Settings").
		$content_type = $lesson->content_type ?? 'text';
		$media_url    = $lesson->video_url ?? '';
		?>

		<?php
		/*
		 * Render the protected player FIRST, then decide which branch we are in.
		 *
		 * The gate used to be `shortcode_exists( 'mediashield' )`, which asks
		 * whether the PLUGIN is here — not whether this video is. A lesson
		 * pointing at a trashed or deleted video passes that check (the id is
		 * still stored, the plugin is still active), takes the player branch, and
		 * MediaShield returns an empty string because it suppresses its notices
		 * for non-editors. The student got a blank box and no explanation, and
		 * the fallback below was unreachable.
		 *
		 * Asking the OUTPUT covers every reason a video does not resolve —
		 * trashed, deleted, draft, access revoked, a future one we have not met —
		 * without this template having to know anything about MediaShield's post
		 * type or its rules.
		 */
		$lrn_mediashield_html = '';
		if ( 'mediashield' === $content_type && ! empty( $media_url ) && shortcode_exists( 'mediashield' ) ) {
			$lrn_mediashield_html = trim( do_shortcode( '[mediashield id="' . absint( $media_url ) . '"]' ) );
		}
		?>

		<?php if ( 'mediashield' === $content_type && '' !== $lrn_mediashield_html ) : ?>
			<div class="lrn-player-content__video lrn-player-content__video--mediashield">
				<?php
				// Trusted, pre-escaped player markup from MediaShield.
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $lrn_mediashield_html;
				?>
			</div>
		<?php elseif ( 'mediashield' === $content_type ) : ?>
			<div class="lrn-player-content__video">
				<div class="lrn-empty lrn-empty--inline">
					<i data-lucide="video-off" class="lrn-icon"></i>
					<p class="lrn-empty__title"><?php esc_html_e( 'Protected video unavailable', 'learnomy' ); ?></p>
					<p class="lrn-empty__body"><?php esc_html_e( 'This lesson uses a MediaShield protected video that cannot be played right now. The video may have been removed, or MediaShield may not be active. Please tell whoever runs this course.', 'learnomy' ); ?></p>
				</div>
			</div>
		<?php elseif ( 'video' === $content_type && ! empty( $media_url ) && 'loom' === $video_provider && ! empty( $video_embed_url ) ) : ?>
			<?php // Loom has no Plyr integration; render the official embed iframe directly. ?>
			<div class="lrn-player-content__video">
				<div class="lrn-video-wrapper">
					<iframe
						src="<?php echo esc_url( $video_embed_url ); ?>"
						class="lrn-lesson-video"
						title="<?php echo esc_attr( $lesson->title ); ?>"
						loading="lazy"
						frameborder="0"
						allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
						allowfullscreen></iframe>
				</div>
			</div>
		<?php elseif ( 'video' === $content_type && ! empty( $media_url ) && 'html5' !== $video_provider && ! empty( $video_embed_url ) ) : ?>
			<?php
			/*
			 * YouTube / Vimeo render: server-side iframe. The prior version
			 * shipped only a Plyr-dependent placeholder div that depended on
			 * the JS bundle initialising correctly — if Plyr failed to load
			 * (CSP, ad-blocker, slow network, JS error elsewhere on the
			 * page) the lesson rendered with no video at all (Basecamp
			 * #9925147314). The iframe pattern mirrors the Loom branch
			 * above and works without JS. Plyr can still attach itself to
			 * the wrapper via the data-plyr-* attributes for enhanced
			 * controls when it loads, but the video is now functional even
			 * without it.
			 */
			?>
			<div class="lrn-player-content__video">
				<div class="lrn-video-wrapper"
					data-plyr-provider="<?php echo esc_attr( $video_provider ); ?>"
					data-plyr-embed-id="<?php echo esc_attr( $video_embed_id ); ?>">
					<iframe
						id="lrn-player"
						src="<?php echo esc_url( $video_embed_url ); ?>"
						class="lrn-lesson-video"
						title="<?php echo esc_attr( $lesson->title ); ?>"
						loading="lazy"
						frameborder="0"
						allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
						allowfullscreen></iframe>
				</div>
			</div>
		<?php elseif ( 'video' === $content_type && ! empty( $media_url ) && 'html5' === $video_provider ) : ?>
			<div class="lrn-player-content__video">
				<div class="lrn-video-wrapper lrn-video-wrapper--plyr">
					<video id="lrn-player" class="lrn-lesson-video" playsinline controls>
						<source src="<?php echo esc_url( $media_url ); ?>" type="video/mp4">
						<?php /* check 11: error_state — browser-native fallback when src is unresolvable */ ?>
					</video>
				</div>
			</div>
		<?php elseif ( 'video' === $content_type && empty( $media_url ) ) : ?>
			<?php // check 11: error_state — video type but no URL set by instructor. ?>
			<div class="lrn-player-content__video">
				<div class="lrn-lesson-media-error lrn-empty-state">
					<svg class="lrn-empty-state__icon" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
					<p class="lrn-empty-state__desc"><?php esc_html_e( 'Video not available. Contact your instructor.', 'learnomy' ); ?></p>
				</div>
			</div>
		<?php elseif ( 'audio' === $content_type && ! empty( $media_url ) ) : ?>
			<div class="lrn-player-content__audio">
				<audio id="lrn-player" class="lrn-lesson-audio" controls preload="metadata">
					<source src="<?php echo esc_url( $media_url ); ?>">
					<?php esc_html_e( 'Your browser does not support the audio element.', 'learnomy' ); ?>
				</audio>
			</div>
		<?php elseif ( 'audio' === $content_type && empty( $media_url ) ) : ?>
			<?php // check 11: error_state — audio type but no URL set. ?>
			<div class="lrn-player-content__audio">
				<div class="lrn-lesson-media-error lrn-empty-state">
					<svg class="lrn-empty-state__icon" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
					<p class="lrn-empty-state__desc"><?php esc_html_e( 'Audio not available. Contact your instructor.', 'learnomy' ); ?></p>
				</div>
			</div>
		<?php elseif ( 'pdf' === $content_type && ! empty( $media_url ) ) : ?>
			<div class="lrn-player-content__pdf">
				<iframe
					src="<?php echo esc_url( $media_url ); ?>"
					title="<?php echo esc_attr( $lesson->title ); ?>"
					class="lrn-lesson-pdf"
					loading="lazy"
				></iframe>
				<p class="lrn-lesson-pdf__fallback">
					<a href="<?php echo esc_url( $media_url ); ?>" target="_blank" rel="noopener">
						<?php esc_html_e( 'Open PDF in a new tab', 'learnomy' ); ?>
					</a>
				</p>
			</div>
		<?php elseif ( 'pdf' === $content_type && empty( $media_url ) ) : ?>
			<?php // check 11: error_state — PDF type but no URL set. ?>
			<div class="lrn-player-content__pdf">
				<div class="lrn-lesson-media-error lrn-empty-state">
					<svg class="lrn-empty-state__icon" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
					<p class="lrn-empty-state__desc"><?php esc_html_e( 'PDF not available. Contact your instructor.', 'learnomy' ); ?></p>
				</div>
			</div>
		<?php elseif ( 'embed' === $content_type && ! empty( $media_url ) ) : ?>
			<div class="lrn-player-content__embed">
				<?php
				// One canonical embed renderer (WP oEmbed + premium link-card
				// fallback) so the embed lesson type matches in-body embeds and
				// every surface — admin / instructor / student (ADR D8,
				// plan/2026-06-28-uniform-embeds). Replaces the old raw <iframe>
				// that broke on a normal watch/page URL (X-Frame-Options).
				echo \Learnomy\Content\Embed::render( (string) $media_url ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Embed::render sanitizes provider HTML via Content_HTML::sanitize + esc_url.
				?>
			</div>
		<?php elseif ( 'embed' === $content_type && empty( $media_url ) ) : ?>
			<?php // check 11: error_state — embed type but no URL set. ?>
			<div class="lrn-player-content__embed">
				<div class="lrn-lesson-media-error lrn-empty-state">
					<svg class="lrn-empty-state__icon" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
					<p class="lrn-empty-state__desc"><?php esc_html_e( 'Embedded content not available. Contact your instructor.', 'learnomy' ); ?></p>
				</div>
			</div>
		<?php elseif ( 'download' === $content_type && ! empty( $media_url ) ) : ?>
			<div class="lrn-player-content__download">
				<a
					href="<?php echo esc_url( $media_url ); ?>"
					class="lrn-btn lrn-btn--primary lrn-btn--lg"
					download
					target="_blank"
					rel="noopener"
				>
					<?php esc_html_e( 'Download file', 'learnomy' ); ?>
				</a>
			</div>
		<?php elseif ( 'download' === $content_type && empty( $media_url ) ) : ?>
			<?php // check 12: error_state — download type but no file URL set. ?>
			<div class="lrn-player-content__download">
				<div class="lrn-lesson-media-error lrn-empty-state">
					<svg class="lrn-empty-state__icon" xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/></svg>
					<p class="lrn-empty-state__desc"><?php esc_html_e( 'Download file not available. Contact your instructor.', 'learnomy' ); ?></p>
				</div>
			</div>

		<?php elseif ( 'live' === $content_type ) : ?>
			<?php
			/*
			 * Live session content-type branch.
			 *
			 * The start comes from `scheduled_start_at` (3.18.0), NOT `available_date`.
			 * Those columns answer different questions — when the session HAPPENS vs
			 * when the lesson UNLOCKS — and answering both with the drip column meant
			 * every scheduled session was locked to every student until the instant it
			 * began, so this entire pre-session UI was unreachable for the people it
			 * exists for. If you ever find yourself reaching for `available_date` here
			 * again, that is the bug coming back.
			 *
			 * Join URL: stored in `video_url` — the generic media-URL column, reused
			 * across content types (same as embed and download). That reuse is fine:
			 * a lesson has exactly one media URL and `content_type` says how to read
			 * it. The drip collision was different in kind — drip applies to every
			 * lesson regardless of type, so it can't share a column with anything.
			 */
			$live_join_url = $media_url; // video_url column — the Zoom/Meet/Teams join link.

			// One shared computation for template + REST (see live_session_state()
			// in functions.php — extracted 2026-07-16 so the app and this player
			// cannot drift on what "pre/live/post" means).
			$live          = \Learnomy\live_session_state( $lesson );
			$live_state    = $live['state'];
			$minutes_until = $live['minutes_until'];
			$live_start    = null !== $live['starts_at'] ? (int) strtotime( $live['starts_at'] ) : 0;

			// Human-readable start time for the pre-time state.
			$live_start_formatted = $live_start > 0
				? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $live_start )
				: '';
			?>
			<div class="lrn-player-content__live lrn-lesson-live">

				<?php if ( 'pre' === $live_state ) : ?>
					<div class="lrn-lesson-live__card lrn-lesson-live__card--pre">
						<div class="lrn-lesson-live__icon" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
						</div>
						<h2 class="lrn-lesson-live__heading">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: lesson title */
									__( 'Live session: %s', 'learnomy' ),
									$lesson->title
								)
							);
							?>
						</h2>
						<?php if ( $minutes_until > 0 ) : ?>
							<p class="lrn-lesson-live__meta">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %d: minutes until the session starts */
										_n( 'Starts in %d minute', 'Starts in %d minutes', $minutes_until, 'learnomy' ),
										$minutes_until
									)
								);
								?>
							</p>
						<?php endif; ?>
						<?php if ( $live_start_formatted ) : ?>
							<p class="lrn-lesson-live__time">
								<?php
								echo esc_html(
									sprintf(
										/* translators: %s: formatted date and time */
										__( 'Starts at %s', 'learnomy' ),
										$live_start_formatted
									)
								);
								?>
							</p>
						<?php endif; ?>
						<?php if ( ! empty( $lesson->content ) ) : ?>
							<p class="lrn-lesson-live__note lrn-lesson-live__note--instructor">
								<?php
								// Plain-text render via the canonical helper so
								// code-block newlines and list bullets survive
								// the inline context. The prior pattern stripped
								// HTML after-the-fact, which destroyed both
								// (Basecamp #9925230195).
								echo nl2br( esc_html( \Learnomy\render_editorjs_content( $lesson->content, $lesson->title, 'plain' ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_html wraps the helper output; nl2br only emits <br> tags.
								?>
							</p>
						<?php endif; ?>
						<button type="button" class="lrn-btn lrn-btn--primary lrn-btn--lg lrn-lesson-live__cta" disabled aria-disabled="true">
							<?php esc_html_e( 'Join now', 'learnomy' ); ?>
						</button>
					</div>

				<?php elseif ( 'live' === $live_state ) : ?>
					<div class="lrn-lesson-live__card lrn-lesson-live__card--live">
						<div class="lrn-lesson-live__header">
							<span class="lrn-badge lrn-badge--red lrn-lesson-live__badge">
								<span class="lrn-lesson-live__pulse" aria-hidden="true"></span>
								<?php esc_html_e( 'Live now', 'learnomy' ); ?>
							</span>
						</div>
						<h2 class="lrn-lesson-live__heading">
							<?php
							echo esc_html(
								sprintf(
									/* translators: %s: lesson title */
									__( 'Live session: %s', 'learnomy' ),
									$lesson->title
								)
							);
							?>
						</h2>
						<?php if ( ! empty( $live_join_url ) ) : ?>
							<a
								href="<?php echo esc_url( $live_join_url ); ?>"
								class="lrn-btn lrn-btn--primary lrn-btn--lg lrn-lesson-live__cta"
								target="_blank"
								rel="noopener noreferrer"
							>
								<?php esc_html_e( 'Join now', 'learnomy' ); ?>
							</a>
							<p class="lrn-lesson-live__note">
								<?php esc_html_e( 'Opens in a new tab.', 'learnomy' ); ?>
							</p>
						<?php else : ?>
							<p class="lrn-lesson-live__note">
								<?php esc_html_e( 'No join link has been set for this session. Please contact your instructor.', 'learnomy' ); ?>
							</p>
						<?php endif; ?>
					</div>

				<?php else : // post ?>
					<div class="lrn-lesson-live__card lrn-lesson-live__card--post">
						<div class="lrn-lesson-live__icon" aria-hidden="true">
							<svg xmlns="http://www.w3.org/2000/svg" width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>
						</div>
						<h2 class="lrn-lesson-live__heading">
							<?php esc_html_e( 'Live session ended', 'learnomy' ); ?>
						</h2>
						<p class="lrn-lesson-live__meta">
							<?php esc_html_e( 'This session has finished. No recording is available yet.', 'learnomy' ); ?>
						</p>
						<button type="button" class="lrn-btn lrn-btn--primary lrn-btn--lg lrn-lesson-live__cta" disabled aria-disabled="true">
							<?php esc_html_e( 'Session ended', 'learnomy' ); ?>
						</button>
						<?php
						// Allow Pro or theme to inject a recording URL or contact-instructor link.
						do_action( 'learnomy_lesson_live_post_actions', $lesson, $course );
						?>
					</div>
				<?php endif; ?>

			</div><!-- .lrn-lesson-live -->

		<?php endif; ?>

		<?php
		// Render seam for content-types registered by a Pro module (e.g. SCORM)
		// that the built-in chain above does not handle. Fires ONLY for
		// non-built-in types, so 'text' (no media) and every handled type are
		// untouched. The Pro module hooks this to render its player.
		if ( ! in_array( $content_type, \Learnomy\Models\Lesson::CONTENT_TYPES, true ) ) {
			/**
			 * Render a Pro-registered lesson content-type's front-end player.
			 *
			 * @since 1.3.1
			 *
			 * @param string $content_type The lesson content-type slug.
			 * @param object $lesson       The lesson row being rendered.
			 * @param string $media_url    The resolved media URL (may be empty).
			 */
			ob_start();
			do_action( 'learnomy_lesson_render_content', $content_type, $lesson, $media_url );
			$lrn_pro_content = trim( (string) ob_get_clean() );

			if ( '' !== $lrn_pro_content ) {
				// A Pro module handled this content-type — echo its player verbatim
				// (the handler is responsible for escaping its own output).
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				echo $lrn_pro_content;
			} else {
				// No handler produced output — the module for this content-type is
				// inactive, or Pro is deactivated. Degrade gracefully with an
				// empty-state instead of a blank player. [card 10062744620]
				?>
				<div class="lrn-player-content__video">
					<div class="lrn-empty lrn-empty--inline">
						<i data-lucide="file-question" class="lrn-icon"></i>
						<p class="lrn-empty__title"><?php esc_html_e( 'This content is unavailable', 'learnomy' ); ?></p>
						<p class="lrn-empty__body"><?php esc_html_e( 'This lesson uses a content type that is not currently available. Please contact your instructor.', 'learnomy' ); ?></p>
					</div>
				</div>
				<?php
			}
		}
		?>
