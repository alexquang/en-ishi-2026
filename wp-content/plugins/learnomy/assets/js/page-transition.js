/**
 * Page transition store — Plan 09.
 *
 * Adds the `lrn-page-enter` class to `.lrn-app` once the DOM is ready so
 * the CSS keyframe in `learnomy.css` plays a 200ms fade-up. The class is
 * removed automatically once the animation finishes (or after the 250ms
 * perception ceiling, whichever comes first) so it does not interfere with
 * subsequent layout reads.
 *
 * Wiring: this file should be enqueued as a module (see CONTINUATION.md).
 *   wp_enqueue_script_module(
 *     'learnomy-page-transition',
 *     LEARNOMY_URL . 'assets/js/page-transition.js',
 *     [ '@wordpress/interactivity' ],
 *     LEARNOMY_VERSION
 *   );
 *
 * @package Learnomy
 */

import { store, getContext } from '@wordpress/interactivity';

const ANIMATION_DURATION = 250;
const ENTER_CLASS = 'lrn-page-enter';

const playEnterAnimation = () => {
	const targets = document.querySelectorAll( '.lrn-app' );
	if ( ! targets.length ) {
		return;
	}

	// Respect prefers-reduced-motion at the JS layer too — skip class entirely
	// so screen readers do not get an aria-busy region churning.
	const reduceMotion =
		window.matchMedia &&
		window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches;
	if ( reduceMotion ) {
		return;
	}

	targets.forEach( ( el ) => {
		el.classList.add( ENTER_CLASS );
		window.setTimeout( () => {
			el.classList.remove( ENTER_CLASS );
		}, ANIMATION_DURATION );
	} );
};

const { state, actions } = store( 'learnomy/page', {
	state: {
		entered: false,
	},
	actions: {
		enter() {
			if ( state.entered ) {
				return;
			}
			state.entered = true;
			playEnterAnimation();
		},
	},
	callbacks: {
		init() {
			// Avoid re-running across hot navigations within the same page life.
			if ( state.entered ) {
				return;
			}
			state.entered = true;
			playEnterAnimation();
		},
	},
} );

// Auto-fire on first paint when the script lands after DOMContentLoaded
// (typical for footer enqueue) without waiting for an Interactivity directive
// to mount the callback.
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', () => {
		actions.enter();
	} );
} else {
	actions.enter();
}

// Suppress unused-import warnings — getContext is part of the public surface
// future authors may need when extending this store with per-route hooks.
void getContext;
