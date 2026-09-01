import { store, getContext } from '@wordpress/interactivity';

/**
 * learnomy/progress — Interactivity store for the lesson player.
 *
 * Owns two responsibilities:
 *   1. Time-on-page heartbeat (writes `time_spent_seconds` back to the
 *      progress row via POST /lessons/:id/progress).
 *   2. Completion-rule evaluation — GETs /lessons/:id/completion-status
 *      on mount and every 10s while the tab is visible, then decides
 *      whether the Mark Complete button is click-enabled, should auto-fire,
 *      or should surface a "X of Y met" toast when the student clicks early.
 *
 * The video high-water mark (`highestPercent`) is read synchronously from
 * `window.learnomyLessonProgress.pct` — populated by `lesson-video.js` on
 * each Plyr timeupdate tick — so the strip's video line reflects the live
 * session without a round-trip per tick.
 */

let timeStart = null;
let timeInterval = null;
let statusInterval = null;
let localTickInterval = null;
let autoFireTriggered = false;

// Module-scope fallback for `getContext()`. When an action is invoked from
// inside a setInterval / setTimeout callback (instead of a directive-fired
// event), the WP Interactivity runtime's context binding is gone and
// `getContext()` returns undefined — every action that read it threw
// `Cannot read properties of undefined (reading 'context')` and silently
// dropped its server call. onInit captures the live ctx here so the heart-
// beat, status poll, scroll listener and auto-fire keep working off the
// originally-bound ctx. See Basecamp #9925208873 reopen.
let storedContext = null;

function ctxOrStored() {
	try {
		const live = getContext();
		if ( live ) {
			return live;
		}
	} catch ( e ) {
		// getContext() throws when called outside a directive-fired event —
		// that's our cue to fall back to the captured-at-onInit context.
	}
	return storedContext;
}

const AUTO_MODES = [ 'auto_time', 'auto_video', 'auto_scroll', 'auto_quiz_pass' ];

/**
 * Hand a failed completion/progress write to the Service Worker so it can
 * replay when the device comes back online. Returns true when the payload
 * was queued (caller should show a "saved offline" message instead of an
 * error toast), false otherwise.
 */
function queueIfOffline( payload ) {
	if ( typeof window.learnomyQueueProgress !== 'function' ) {
		return false;
	}
	if ( navigator.onLine ) {
		return false;
	}
	const nonce = ( window.wpApiSettings && window.wpApiSettings.nonce ) || '';
	window.learnomyQueueProgress( payload, nonce );
	return true;
}

/**
 * Raw "have every rule actually been met" check. Used by the auto-fire
 * trigger so completion doesn't fire on lesson-load just because the
 * enforcement mode would have let the button click through. Auto-fire
 * should still wait for the student to actually clear the rules.
 */
function rulesActuallyMet( ctx ) {
	if ( ! ctx || ! Array.isArray( ctx.requirements ) ) {
		return true;
	}
	if ( 'manual' === ctx.completionMode ) {
		return true;
	}
	return ctx.requirements.every( ( req ) => !! req.met );
}

/**
 * "Is the Complete button allowed to be pressed right now" check. Layers
 * the server-resolved enforcement mode (see
 * Progress_Service::resolve_enforcement — site + course + lesson + role
 * bypass + filter) on top of the raw rules check. 'advisory' / 'off' let
 * the click through immediately; 'strict' (default) waits for every rule
 * to clear.
 */
function allRequirementsMet( ctx ) {
	if ( ! ctx || ! Array.isArray( ctx.requirements ) ) {
		return true;
	}
	if ( 'manual' === ctx.completionMode ) {
		return true;
	}
	if ( 'advisory' === ctx.enforcement || 'off' === ctx.enforcement ) {
		return true;
	}
	return ctx.requirements.every( ( req ) => !! req.met );
}

/**
 * Build the requirement-progress percentage for the <progress> element on
 * each strip row. Time and video have numeric targets; scroll and quiz are
 * pass/fail so they render as 0 or 100.
 */
function requirementPercent( req ) {
	if ( ! req ) {
		return 0;
	}
	if ( req.met ) {
		return 100;
	}
	if ( 'time' === req.kind && req.min_seconds > 0 ) {
		return Math.min( 100, Math.round( ( ( req.current_seconds || 0 ) / req.min_seconds ) * 100 ) );
	}
	if ( 'video' === req.kind && req.min_pct > 0 ) {
		return Math.min( 100, Math.round( ( ( req.current_pct || 0 ) / req.min_pct ) * 100 ) );
	}
	return 0;
}

const { state, actions } = store( 'learnomy/progress', {
	state: {
		completing: false,
		toastMessage: '',
		toastVisible: false,

		get progressPercent() {
			const ctx = getContext();
			const completed = ctx.completedCount || 0;
			const total = ctx.totalCount || 1;
			return Math.round( ( completed / total ) * 100 );
		},

		get allMet() {
			const ctx = getContext();
			return allRequirementsMet( ctx );
		},

		get isManual() {
			const ctx = getContext();
			return ! ctx.completionMode || 'manual' === ctx.completionMode;
		},

		get stripVisible() {
			const ctx = getContext();
			if ( ! ctx.completionMode || 'manual' === ctx.completionMode ) {
				return false;
			}
			return Array.isArray( ctx.requirements ) && ctx.requirements.length > 0;
		},

		get completeDisabled() {
			const ctx = getContext();
			if ( state.completing ) {
				return true;
			}
			if ( ctx.lessonCompleted ) {
				return true;
			}
			return ! allRequirementsMet( ctx );
		},

		get requirementsCount() {
			const ctx = getContext();
			return Array.isArray( ctx.requirements ) ? ctx.requirements.length : 0;
		},

		get requirementsMetCount() {
			const ctx = getContext();
			if ( ! Array.isArray( ctx.requirements ) ) {
				return 0;
			}
			return ctx.requirements.filter( ( r ) => !! r.met ).length;
		},
	},

	actions: {
		async completeLesson() {
			if ( state.completing ) {
				return;
			}

			const ctx = ctxOrStored();
			if ( ! ctx || ! ctx.lessonId ) {
				return;
			}

			if ( ! allRequirementsMet( ctx ) ) {
				actions.showToast(
					/* translators: 1: met count, 2: total count */
					`${ state.requirementsMetCount } of ${ state.requirementsCount } requirements met. Keep going.`
				);
				return;
			}

			state.completing = true;

			// Include client-side completion signals so the server can pass
			// the scroll-to-end check (which it cannot observe directly).
			const scrollReqMet = Array.isArray( ctx.requirements ) &&
				ctx.requirements.some( ( r ) => 'scroll' === r.kind && r.met );

			// Live watch % at click time, sourced the same way the
			// completion-status poll does, so the auto_video rule is satisfied
			// even if the persisted high-water mark has not caught up yet.
			const completeVideoPct =
				window.learnomyLessonProgress &&
				window.learnomyLessonProgress.lessonId === ctx.lessonId
					? ( window.learnomyLessonProgress.pct || 0 )
					: 0;

			try {
				const response = await wp.apiFetch( {
					path: `/learnomy/v1/lessons/${ ctx.lessonId }/complete`,
					method: 'POST',
					data: {
						scrolled_to_end: scrollReqMet,
						video_pct: completeVideoPct,
					},
				} );

				// REST base-controller envelope — response.data carries the payload.
				const data = response && response.data ? response.data : response;

				ctx.completedCount = data.completed_count || ctx.completedCount;
				ctx.totalCount = data.total_count || ctx.totalCount;
				ctx.lessonCompleted = true;

				actions.showToast(
					( data && data.message ) || ( state.i18n && state.i18n.lessonMarkedComplete ) || 'Lesson marked complete.'
				);

				if ( data && data.next_lesson_url ) {
					setTimeout( () => {
						window.location.href = data.next_lesson_url;
					}, 1200 );
				} else {
					// No next lesson — reload so the Completed badge renders.
					setTimeout( () => {
						window.location.reload();
					}, 1200 );
				}
			} catch ( err ) {
				// 422 with structured details — sync the strip so the next click
				// reflects whatever the server says is missing.
				if ( err && err.code === 'completion_rules_not_met' && err.details ) {
					ctx.requirements = err.details.requirements || [];
					ctx.completionMode = err.details.completion_mode || ctx.completionMode;
					actions.showToast(
						`${ state.requirementsMetCount } of ${ state.requirementsCount } requirements met. Keep going.`
					);
				} else if ( queueIfOffline( {
					item_type:        'lesson',
					item_id:          ctx.lessonId,
					status:           'completed',
					client_timestamp: new Date().toISOString(),
				} ) ) {
					ctx.lessonCompleted = true;
					actions.showToast( ( state.i18n && state.i18n.savedOffline ) || 'Saved offline. Will sync when you reconnect.' );
				}
			} finally {
				state.completing = false;
			}
		},

		async completeTopic() {
			if ( state.completing ) {
				return;
			}

			const ctx = getContext();
			state.completing = true;

			try {
				const response = await wp.apiFetch( {
					path: `/learnomy/v1/topics/${ ctx.topicId }/complete`,
					method: 'POST',
				} );

				const data = response && response.data ? response.data : response;

				ctx.completedCount = data.completed_count;
				ctx.totalCount = data.total_count;
				ctx.topicCompleted = true;

				actions.showToast( ( data && data.message ) || ( state.i18n && state.i18n.topicMarkedComplete ) || 'Topic marked complete.' );
			} catch ( err ) {
				if ( queueIfOffline( {
					item_type:        'topic',
					item_id:          ctx.topicId,
					status:           'completed',
					client_timestamp: new Date().toISOString(),
				} ) ) {
					ctx.topicCompleted = true;
					actions.showToast( ( state.i18n && state.i18n.savedOffline ) || 'Saved offline. Will sync when you reconnect.' );
				}
			} finally {
				state.completing = false;
			}
		},

		async recordTime() {
			const ctx = ctxOrStored();

			if ( ! timeStart || ! ctx || ! ctx.lessonId ) {
				return;
			}

			// A preview viewer saves nothing. The server answers this
			// (canSaveProgress, seeded in class-template-loader.php) rather than
			// the client inferring it from a role: an instructor previewing their
			// own course is not enrolled, so this heartbeat 403'd on every tick,
			// and an admin's preview wrote real progress rows even though the
			// preview bar promises "Student data is not affected"
			// (Basecamp 10134676326). Time still accumulates locally, so nothing
			// else in the strip changes behaviour.
			if ( ! state.canSaveProgress ) {
				return;
			}

			const seconds = Math.round( ( Date.now() - timeStart ) / 1000 );

			if ( seconds < 3 ) {
				return;
			}

			await wp.apiFetch( {
				path: `/learnomy/v1/lessons/${ ctx.lessonId }/progress`,
				method: 'POST',
				data: { seconds, time_seconds: seconds },
			} );

			timeStart = Date.now();
		},

		/**
		 * Poll the completion-status endpoint and refresh the strip. Reads
		 * the live video high-water mark from the shared global so the
		 * response reflects the current session (not just the last 30s
		 * checkpoint written by lesson-video.js).
		 */
		async refreshCompletionStatus() {
			const ctx = ctxOrStored();
			if ( ! ctx || ! ctx.lessonId ) {
				return;
			}

			const videoPct =
				window.learnomyLessonProgress &&
				window.learnomyLessonProgress.lessonId === ctx.lessonId
					? ( window.learnomyLessonProgress.pct || 0 )
					: 0;

			try {
				const response = await wp.apiFetch( {
					path: `/learnomy/v1/lessons/${ ctx.lessonId }/completion-status?pct=${ videoPct }`,
				} );
				const data = response && response.data ? response.data : response;
				if ( ! data ) {
					return;
				}

				ctx.completionMode = data.completion_mode || ctx.completionMode;
				ctx.requirements = data.requirements || [];
				ctx.allMet = !! data.all_met;
				ctx.locksNext = !! data.locks_next;

				if ( data.is_completed ) {
					ctx.lessonCompleted = true;
				}

				// Decorate each requirement with a UI-ready percent so the
				// <progress> element inside the strip has a single bound value.
				ctx.requirements = ctx.requirements.map( ( r ) => ( {
					...r,
					uiPercent: requirementPercent( r ),
				} ) );

				// Auto-fire for pure-auto modes when everything just flipped
				// to met. Only fires once per page load. In advisory / off
				// enforcement the student still has to click — auto-fire is
				// reserved for strict mode where reaching the rule IS the
				// signal that completion should happen.
				if (
					data.all_met &&
					! data.is_completed &&
					! autoFireTriggered &&
					AUTO_MODES.includes( data.completion_mode ) &&
					( ! data.enforcement || 'strict' === data.enforcement )
				) {
					autoFireTriggered = true;
					actions.showToast( ( state.i18n && state.i18n.lessonComplete ) || 'Lesson complete!' );
					actions.completeLesson();
				}
			} catch ( err ) {
				// Silent failure — the next poll retries. The student's UI
				// just stays on the last known state.
			}
		},

		showToast( message ) {
			state.toastMessage = message;
			state.toastVisible = true;

			setTimeout( () => {
				state.toastVisible = false;
			}, 3000 );
		},
	},

	callbacks: {
		onInit() {
			const ctx = getContext();

			if ( ! ctx.lessonId ) {
				return;
			}

			// Capture ctx for setInterval / setTimeout callbacks below — see
			// the module-level `storedContext` comment.
			storedContext = ctx;

			// Hydrate strip defaults so the manual-mode guard works even
			// before the first fetch lands.
			ctx.completionMode = ctx.completionMode || 'manual';
			ctx.requirements = ctx.requirements || [];
			ctx.allMet = ctx.allMet !== undefined ? ctx.allMet : true;
			ctx.lessonCompleted = ctx.lessonCompleted || false;

			// Initial decoration of server-rendered requirements.
			if ( Array.isArray( ctx.requirements ) ) {
				ctx.requirements = ctx.requirements.map( ( r ) => ( {
					...r,
					uiPercent: requirementPercent( r ),
				} ) );
			}

			timeStart = Date.now();

			timeInterval = setInterval( () => {
				if ( document.visibilityState === 'visible' ) {
					actions.recordTime();
				}
			}, 60000 );

			// Completion-status poll — only needed for auto modes. Manual
			// lessons skip the network chatter entirely.
			if ( 'manual' !== ctx.completionMode ) {
				// Refresh immediately so the initial server-rendered state
				// gets reconciled with whatever time-on-page / video pct
				// exists right now.
				actions.refreshCompletionStatus();

				statusInterval = setInterval( () => {
					if ( document.visibilityState === 'visible' ) {
						actions.refreshCompletionStatus();
					}
				}, 10000 );

				// Local 1-second ticker. Increments the 'time' requirement's
				// current_seconds so the strip countdown feels continuous
				// instead of jumping in 10s chunks each server poll. The 10s
				// poll reconciles any drift.
				localTickInterval = setInterval( () => {
					if ( document.visibilityState !== 'visible' ) {
						return;
					}
					if ( ! Array.isArray( ctx.requirements ) || ctx.requirements.length === 0 ) {
						return;
					}
					let mutated = false;
					ctx.requirements = ctx.requirements.map( ( r ) => {
						if ( 'time' === r.kind && ! r.met && r.min_seconds > 0 ) {
							const nextSeconds = Math.min( r.min_seconds, ( r.current_seconds || 0 ) + 1 );
							mutated = nextSeconds !== r.current_seconds;
							const remaining = Math.max( 0, r.min_seconds - nextSeconds );
							const uiPercent = Math.min( 100, Math.round( ( nextSeconds / r.min_seconds ) * 100 ) );
							// Mirror the change onto the DOM strip — the server-
							// rendered list doesn't carry per-row Interactivity
							// bindings, so without this manual update the user
							// sees a frozen bar and assumes the rule is broken.
							// See Basecamp #9925208873.
							const row = document.querySelector( '.lrn-completion-strip__line[data-req-kind="time"]' );
							if ( row ) {
								const bar = row.querySelector( '.lrn-completion-strip__bar' );
								if ( bar ) {
									bar.value = uiPercent;
								}
								const rem = row.querySelector( '[data-req-remaining]' );
								if ( rem ) {
									const mm = Math.floor( remaining / 60 );
									const ss = remaining % 60;
									rem.textContent = ( mm + ':' + ( ss < 10 ? '0' + ss : ss ) ) + ' left';
								}
								if ( nextSeconds >= r.min_seconds ) {
									row.classList.add( 'lrn-completion-strip__line--met' );
								}
							}
							return {
								...r,
								current_seconds: nextSeconds,
								remaining,
								met: nextSeconds >= r.min_seconds,
								uiPercent,
							};
						}
						return r;
					} );
					// Trigger auto-fire when this tick just met everything.
					//
					// Race fix: the local ticker reaches `min_time_seconds`
					// 1–60 seconds BEFORE the next heartbeat posts the
					// accumulated time to the DB. Without flushing time
					// first, `mark_lesson_complete()` re-evaluates rules
					// against a stale `time_spent_seconds`, throws
					// CompletionRulesNotMet, the REST endpoint returns 422,
					// and the auto-progression silently fails — exactly the
					// "next lesson does not open automatically" report.
					//
					// Force `recordTime()` to flush the local elapsed time
					// before the complete call so the server sees a fresh
					// `time_spent_seconds` row when it validates the rules.
					if ( mutated && rulesActuallyMet( ctx ) && ! autoFireTriggered ) {
						autoFireTriggered = true;
						Promise.resolve()
							.then( () => actions.recordTime() )
							.catch( () => {} ) // swallow heartbeat errors — completeLesson has its own offline queue
							.then( () => actions.completeLesson() );
					}
				}, 1000 );
			}

			// Scroll-to-end completion. When the lesson uses a `scroll`
			// requirement (auto_scroll mode or a combined mode that includes
			// it) we flip the requirement to met as soon as the student
			// reaches the bottom of the document, then auto-fire if every
			// other requirement is already satisfied. The server-side check
			// in Progress_Service is keyed off `completed_at` (a state that
			// only exists AFTER completion), so without this listener the
			// rule could never satisfy itself. See Basecamp #9925249672.
			const onScroll = () => {
				if ( ctx.lessonCompleted ) {
					return;
				}
				if ( ! Array.isArray( ctx.requirements ) || ctx.requirements.length === 0 ) {
					return;
				}
				const scrollPos    = window.scrollY + window.innerHeight;
				const docHeight    = Math.max(
					document.documentElement.scrollHeight,
					document.body ? document.body.scrollHeight : 0
				);
				if ( docHeight <= 0 ) {
					return;
				}
				// 92% of the way down counts as "reached the end" — gives a
				// small buffer for footers / sticky bars without forcing a
				// pixel-perfect scroll to the very last line.
				if ( ( scrollPos / docHeight ) < 0.92 ) {
					return;
				}
				let mutated = false;
				ctx.requirements = ctx.requirements.map( ( r ) => {
					if ( 'scroll' === r.kind && ! r.met ) {
						mutated = true;
						return { ...r, met: true, uiPercent: 100 };
					}
					return r;
				} );
				if ( ! mutated ) {
					return;
				}
				// Mirror the flip onto the DOM strip.
				const row = document.querySelector( '.lrn-completion-strip__line[data-req-kind="scroll"]' );
				if ( row ) {
					row.classList.add( 'lrn-completion-strip__line--met' );
					const bar = row.querySelector( '.lrn-completion-strip__bar' );
					if ( bar ) {
						bar.value = 100;
					}
				}
				if ( rulesActuallyMet( ctx ) && ! autoFireTriggered ) {
					autoFireTriggered = true;
					actions.showToast( ( state.i18n && state.i18n.lessonComplete ) || 'Lesson complete!' );
					Promise.resolve()
						.then( () => actions.recordTime() )
						.catch( () => {} )
						.then( () => actions.completeLesson() );
				}
			};
			window.addEventListener( 'scroll', onScroll, { passive: true } );
			// Run once on init in case the lesson body is shorter than the viewport.
			onScroll();

			document.addEventListener( 'visibilitychange', () => {
				if ( document.visibilityState === 'hidden' ) {
					actions.recordTime();
				}

				if ( document.visibilityState === 'visible' ) {
					timeStart = Date.now();
					if ( 'manual' !== ctx.completionMode ) {
						actions.refreshCompletionStatus();
					}
				}
			} );

			window.addEventListener( 'beforeunload', () => {
				actions.recordTime();
				clearInterval( timeInterval );
				if ( statusInterval ) {
					clearInterval( statusInterval );
				}
				if ( localTickInterval ) {
					clearInterval( localTickInterval );
				}
			} );
		},
	},
} );
