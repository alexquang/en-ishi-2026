/**
 * Lesson video — Plyr initialization, watch tracking, and completion gating.
 *
 * Depends on Plyr (loaded separately) and learnomyLesson localized data.
 * This module handles YouTube, Vimeo, and self-hosted video uniformly.
 *
 * @package Learnomy
 */
( function() {
	'use strict';

	// Plyr must be handed the element that carries its OWN config, which
	// differs by content type:
	//
	//   html5 video / audio -> #lrn-player IS the <video>/<audio> element.
	//   youtube / vimeo     -> the config lives on the WRAPPER div as
	//                          data-plyr-provider + data-plyr-embed-id, and
	//                          #lrn-player is the server-rendered <iframe>
	//                          INSIDE it (templates/lesson/partials/media.php:86-97).
	//
	// Passing the iframe for an embed does not throw — Plyr returns an instance
	// with provider "none" that silently never fires `timeupdate`. That is why
	// this shipped undetected with zero console errors: watch progress stayed at
	// 0 on every YouTube/Vimeo lesson, and on an `auto_video` lesson the student
	// saw "Watch 80% of the video: 0% / 80%" forever with no way to satisfy it.
	// The media partial's own comment already documented the intent ("Plyr can
	// still attach itself to the wrapper") — it was never implemented here.
	var embedHost = document.querySelector( '.lrn-video-wrapper[data-plyr-provider]' );
	var el        = embedHost || document.getElementById( 'lrn-player' );
	if ( ! el || typeof Plyr === 'undefined' ) {
		return;
	}

	if ( embedHost ) {
		// Progressive enhancement. The server renders a working <iframe> so the
		// video plays with JS off (Basecamp #9925147314). With JS on, Plyr builds
		// its own iframe inside this host, so drop the static one first —
		// otherwise the lesson shows two players.
		embedHost.textContent = '';
	}

	var config   = window.learnomyLesson || {};
	var lessonId = config.lessonId || 0;
	var restUrl  = config.restUrl || '';
	var nonce    = config.nonce || '';
	var requireWatchPercent = parseInt( config.requireWatchPercent, 10 ) || 0;

	// How much of the video the student has actually WATCHED.
	//
	// This used to be a high-water mark of player.currentTime / player.duration,
	// which is a position, not an amount: dragging the scrubber to the end
	// reported 100% with nothing played, and "must watch" lessons unlocked in
	// one gesture (Basecamp 10134140085). We now mark off the whole seconds the
	// player actually plays through and measure coverage of the set, so a seek
	// credits at most the single second it lands on, and re-watching the same
	// stretch does not inflate the total either.
	var watchedSeconds = Object.create( null );
	var watchedCount   = 0;
	var lastSecond     = -1;
	var highestPercent = 0;
	var lastReported   = 0;
	var watchUnlocked  = requireWatchPercent <= 0;

	// A tick is only credited when the position advanced by at most this many
	// seconds — i.e. the media moved on by itself. Anything larger is a jump.
	var MAX_NATURAL_STEP = 2;

	// Expose the running high-water mark on a shared global so the
	// Interactivity store (learnomy/progress) can read it synchronously
	// during its 10-second poll without needing a cross-module import or
	// a server round-trip per tick.
	window.learnomyLessonProgress = window.learnomyLessonProgress || { pct: 0, lessonId: lessonId };
	window.learnomyLessonProgress.lessonId = lessonId;

	var player = new Plyr( el, {
		controls: [
			'play-large', 'play', 'progress', 'current-time',
			'mute', 'volume', 'captions', 'settings',
			'pip', 'airplay', 'fullscreen'
		]
	} );

	// check 9: loading_state — remove skeleton once the player is ready.
	//
	// For html5/audio, `el` is the media element and the wrapper is its
	// ancestor. For an embed, Plyr REPLACES the host div with its own `.plyr`
	// container, so `el.closest('.lrn-video-wrapper')` finds nothing — hence the
	// explicit fallbacks. Without them this handler silently no-ops on embeds
	// and any skeleton added to that branch later would never be cleared.
	player.on( 'ready', function() {
		var wrapper = ( el.closest && el.closest( '.lrn-video-wrapper' ) )
			|| document.querySelector( '.lrn-video-wrapper' )
			|| ( player.elements && player.elements.container );
		if ( wrapper && wrapper.classList ) {
			wrapper.classList.add( 'lrn-video-wrapper--loaded' );
		}
	} );

	// NOTE: this script must never DISABLE the completion button, only relax it
	// early. The gate is owned by the server + Interactivity store:
	// Progress_Service builds a completion_mode-aware `requirements` array
	// (class-progress-service.php:204-231 — the video rule is active only for
	// auto_video / auto_combined, never for `manual`), progress-view.js exposes
	// it as state.completeDisabled, and completion.php binds the button with
	// data-wp-bind--disabled.
	//
	// A previous version wrote `completeBtn.disabled = true` here whenever
	// requireWatchPercent > 0. That was mode-blind and fought the declarative
	// binding, which reasserts state.completeDisabled on every reconcile. Since
	// 1361 of 1363 lessons on a typical install are `manual`, imposing a gate
	// from here risks locking learners out of lessons the owner never gated.
	// Asymmetry is deliberate: the store may impose, this script may only
	// accelerate the release (see enableCompletion() below, which spares the
	// student the store's 10-second poll after they cross the threshold).
	var completeBtn = document.getElementById( 'lrn-mark-complete' );

	// Mark off watched seconds on each timeupdate tick.
	player.on( 'timeupdate', function() {
		if ( ! player.duration || player.duration <= 0 ) {
			return;
		}

		var second = Math.floor( player.currentTime );

		// Only playback counts. Scrubbing while paused also fires timeupdate,
		// and a jump of more than a couple of seconds is a seek however it was
		// triggered — in both cases we re-anchor without crediting the gap.
		if ( player.playing && ( lastSecond < 0 || ( second >= lastSecond && second - lastSecond <= MAX_NATURAL_STEP ) ) ) {
			if ( ! watchedSeconds[ second ] ) {
				watchedSeconds[ second ] = true;
				++watchedCount;
			}
		}
		lastSecond = second;

		var totalSeconds   = Math.max( 1, Math.floor( player.duration ) );
		var currentPercent = Math.min( 100, Math.round( ( watchedCount / totalSeconds ) * 100 ) );
		if ( currentPercent > highestPercent ) {
			highestPercent = currentPercent;
			// Publish to the shared global so the progress strip reflects
			// the live figure on its next poll.
			window.learnomyLessonProgress.pct = highestPercent;
		}

		// Check if the student crossed the watch threshold.
		if ( ! watchUnlocked && highestPercent >= requireWatchPercent ) {
			watchUnlocked = true;
			// Report straight away rather than waiting out the 30s interval:
			// the server's verdict reads the PERSISTED mark, so the student
			// would otherwise see the button open and the click still refused.
			reportProgress( highestPercent );
			lastReported = highestPercent;
			enableCompletion();
		}
	} );

	// Report progress to the REST API every 30 seconds while playing.
	var reportInterval = setInterval( function() {
		if ( player.playing && highestPercent > lastReported ) {
			reportProgress( highestPercent );
			lastReported = highestPercent;
		}
	}, 30000 );

	// Final report when the student stops, the video ends, or they leave. Note
	// `ended` reports the coverage figure like everything else — reaching the
	// end of the timeline is not the same as having watched what came before.
	player.on( 'pause', function() {
		if ( highestPercent > lastReported ) {
			reportProgress( highestPercent );
			lastReported = highestPercent;
		}
	} );

	player.on( 'ended', function() {
		reportProgress( highestPercent );
		lastReported = highestPercent;
	} );

	window.addEventListener( 'beforeunload', function() {
		clearInterval( reportInterval );
		if ( highestPercent > lastReported ) {
			reportProgress( highestPercent, true );
		}
	} );

	/**
	 * Enable the "Mark Complete" button and show a success toast.
	 */
	function enableCompletion() {
		if ( completeBtn ) {
			completeBtn.disabled = false;
		}

		showToast( config.i18n.watchComplete || 'You can now mark this lesson as complete.' );
	}

	/**
	 * Show a lightweight toast notification.
	 *
	 * @param {string} message
	 */
	function showToast( message ) {
		var toast = document.createElement( 'div' );
		toast.className = 'lrn-toast lrn-toast--success';
		toast.setAttribute( 'role', 'status' );
		toast.textContent = message;
		document.body.appendChild( toast );

		// Trigger reflow, then add visible class.
		void toast.offsetWidth;
		toast.classList.add( 'lrn-toast--visible' );

		setTimeout( function() {
			toast.classList.remove( 'lrn-toast--visible' );
			setTimeout( function() {
				toast.remove();
			}, 300 );
		}, 3000 );
	}

	/**
	 * Report video watch progress to the server.
	 *
	 * Uses sendBeacon only on the unload path — it is fire-and-forget with no
	 * ordering guarantee, which is fine for a parting shot but not for the
	 * threshold-crossing report the student is about to act on. The `pct` key is
	 * the canonical field the progress controller reads; `watch_percent` stays
	 * for back-compat.
	 *
	 * @param {number}  percent
	 * @param {boolean} [unloading] True on the beforeunload path.
	 */
	function reportProgress( percent, unloading ) {
		if ( ! lessonId || ! restUrl ) {
			return;
		}

		// Preview viewers save nothing. The server decides (canSaveProgress, set
		// in class-template-loader.php::build_lesson_video_data) rather than the
		// client guessing from a role — an instructor previewing their own course
		// is not enrolled, so POSTing here returned 403 eight or more times per
		// preview, and an admin's preview silently wrote real progress rows even
		// though the preview bar promises "Student data is not affected"
		// (Basecamp 10134676326). Both are the same mistake: firing a write the
		// viewer is not entitled to make. Watch tracking itself still runs, so the
		// player and the completion strip behave normally in preview.
		if ( ! config.canSaveProgress ) {
			return;
		}

		var url  = restUrl + 'lessons/' + lessonId + '/progress';
		var body = JSON.stringify( { pct: percent, watch_percent: percent } );

		if ( unloading && navigator.sendBeacon ) {
			var blob = new Blob( [ body ], { type: 'application/json' } );
			navigator.sendBeacon( url + '?_wpnonce=' + encodeURIComponent( nonce ), blob );
		} else {
			fetch( url, {
				method:  'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce':   nonce
				},
				body: body,
				keepalive: true
			} );
		}
	}
} )();
