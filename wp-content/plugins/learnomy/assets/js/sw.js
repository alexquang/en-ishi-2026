/**
 * Learnomy Service Worker
 *
 * Caching strategies:
 *   - App shell (CSS, JS, fonts, images): cache-first
 *   - Lesson pages: network-first with cache fallback
 *   - REST API responses: network-first with cache fallback
 *   - Course catalog: stale-while-revalidate
 *
 * Offline progress sync:
 *   Lesson completion requests are queued in IndexedDB when offline and
 *   replayed via Background Sync (or on next page load as fallback).
 *
 * @package Learnomy
 */

const CACHE_VERSION = 'v3';
const CACHE_NAME    = 'learnomy-' + CACHE_VERSION;

/**
 * Static assets that form the app shell — cached on install so the LMS
 * UI renders even when the network is unavailable.
 */
const APP_SHELL = [
	// Stylesheets
	'/wp-content/plugins/learnomy/assets/css/learnomy.css',
	// Core modules
	'/wp-content/plugins/learnomy/assets/js/view.js',
	'/wp-content/plugins/learnomy/assets/js/progress-view.js',
	// Vendor libraries
	'/wp-content/plugins/learnomy/assets/js/vendor/plyr.min.js',
	'/wp-content/plugins/learnomy/assets/css/vendor/plyr.css',
];

/**
 * Route to the offline fallback page — served by the plugin via a
 * WordPress rewrite rule so it has the correct theme header/footer.
 */
const OFFLINE_PAGE = '/learnomy-offline/';

// ---------------------------------------------------------------------------
// IndexedDB helpers — offline progress queue
// ---------------------------------------------------------------------------

const DB_NAME    = 'learnomy-offline';
const DB_VERSION = 1;
const STORE_NAME = 'progress-queue';

/**
 * Open (or create) the IndexedDB database used for queuing progress
 * requests that failed while the user was offline.
 *
 * @return {Promise<IDBDatabase>}
 */
function openDB() {
	return new Promise( ( resolve, reject ) => {
		const request = indexedDB.open( DB_NAME, DB_VERSION );

		request.onupgradeneeded = ( event ) => {
			const db = event.target.result;
			if ( ! db.objectStoreNames.contains( STORE_NAME ) ) {
				db.createObjectStore( STORE_NAME, { keyPath: 'id', autoIncrement: true } );
			}
		};

		request.onsuccess = () => resolve( request.result );
		request.onerror   = () => reject( request.error );
	} );
}

/**
 * Add a failed progress request to the offline queue.
 *
 * @param {Object} entry Serialized request data (url, method, body, timestamp).
 * @return {Promise<void>}
 */
function enqueueProgress( entry ) {
	return openDB().then( ( db ) => {
		return new Promise( ( resolve, reject ) => {
			const tx    = db.transaction( STORE_NAME, 'readwrite' );
			const store = tx.objectStore( STORE_NAME );
			store.add( entry );
			tx.oncomplete = () => resolve();
			tx.onerror    = () => reject( tx.error );
		} );
	} );
}

/**
 * Read and delete all queued progress entries so they can be replayed.
 *
 * @return {Promise<Array>}
 */
function drainQueue() {
	return openDB().then( ( db ) => {
		return new Promise( ( resolve, reject ) => {
			const tx      = db.transaction( STORE_NAME, 'readwrite' );
			const store   = tx.objectStore( STORE_NAME );
			const request = store.getAll();

			request.onsuccess = () => {
				const entries = request.result;
				// Clear the store after reading.
				store.clear();
				resolve( entries );
			};

			request.onerror = () => reject( request.error );
		} );
	} );
}

// ---------------------------------------------------------------------------
// Caching strategies
// ---------------------------------------------------------------------------

/**
 * Cache-first: serve from cache if available, otherwise fetch and cache.
 * Best for versioned static assets that rarely change mid-session.
 *
 * @param {Request} request
 * @return {Promise<Response>}
 */
async function cacheFirst( request ) {
	const cached = await caches.match( request );
	if ( cached ) {
		return cached;
	}

	try {
		const response = await fetch( request );
		if ( response.ok ) {
			const cache = await caches.open( CACHE_NAME );
			cache.put( request, response.clone() );
		}
		return response;
	} catch ( err ) {
		// Static asset unavailable and not cached — return a minimal response
		// so the browser doesn't show a raw network error.
		return new Response( '', { status: 503, statusText: 'Offline' } );
	}
}

/**
 * Network-first: try the network, fall back to cache, then offline page.
 * Used for lesson pages and API responses that should be as fresh as
 * possible but still available offline.
 *
 * @param {Request} request
 * @return {Promise<Response>}
 */
async function networkFirst( request ) {
	try {
		const response = await fetch( request );
		if ( response.ok ) {
			const cache = await caches.open( CACHE_NAME );
			cache.put( request, response.clone() );
		}
		return response;
	} catch ( err ) {
		const cached = await caches.match( request );
		if ( cached ) {
			return cached;
		}

		// HTML navigation requests get the offline fallback page.
		if ( request.mode === 'navigate' ) {
			const offlinePage = await caches.match( OFFLINE_PAGE );
			if ( offlinePage ) {
				return offlinePage;
			}
		}

		return new Response( 'Offline', { status: 503, statusText: 'Offline' } );
	}
}

// ---------------------------------------------------------------------------
// Install — pre-cache the app shell and offline fallback page
// ---------------------------------------------------------------------------

self.addEventListener( 'install', ( event ) => {
	event.waitUntil(
		caches.open( CACHE_NAME ).then( ( cache ) => {
			return cache.addAll( [ ...APP_SHELL, OFFLINE_PAGE ] );
		} ).then( () => {
			// Activate immediately — don't wait for existing tabs to close.
			return self.skipWaiting();
		} )
	);
} );

// ---------------------------------------------------------------------------
// Activate — clean up old cache versions
// ---------------------------------------------------------------------------

self.addEventListener( 'activate', ( event ) => {
	event.waitUntil(
		caches.keys().then( ( keys ) => {
			return Promise.all(
				keys.filter( ( key ) => key.startsWith( 'learnomy-' ) && key !== CACHE_NAME )
					.map( ( key ) => caches.delete( key ) )
			);
		} ).then( () => {
			// Claim all open tabs so the new SW takes effect immediately.
			return self.clients.claim();
		} )
	);
} );

// ---------------------------------------------------------------------------
// Fetch — route requests to the appropriate caching strategy
// ---------------------------------------------------------------------------

self.addEventListener( 'fetch', ( event ) => {
	const url    = new URL( event.request.url );
	const method = event.request.method;

	// Only handle GET requests in the caching layer. POST/PUT/DELETE for
	// progress are handled in the message listener and sync event.
	if ( method !== 'GET' ) {
		return;
	}

	// NEVER intercept HTML page loads. Document navigations always go straight
	// to the network so the buyer/student sees the real, current page — a
	// service worker serving a stale or half-built HTML response is exactly how
	// a "white screen on every URL" happens. The SW only accelerates static
	// assets + buffers REST for offline; it must not own page HTML.
	if ( event.request.mode === 'navigate' || event.request.destination === 'document' ) {
		return;
	}

	// REST API responses: network-first (so offline reads still work).
	if ( url.pathname.includes( '/wp-json/learnomy/' ) ) {
		event.respondWith( networkFirst( event.request ) );
		return;
	}

	// Plugin static assets: cache-first (versioned via filemtime query string).
	if ( url.pathname.includes( '/plugins/learnomy/assets/' ) ) {
		event.respondWith( cacheFirst( event.request ) );
		return;
	}
} );

// ---------------------------------------------------------------------------
// Background Sync — replay queued progress completions
// ---------------------------------------------------------------------------

self.addEventListener( 'sync', ( event ) => {
	if ( event.tag === 'learnomy-progress-sync' ) {
		event.waitUntil( replayProgressQueue() );
	}
} );

/**
 * Drain the offline queue and POST each entry to the bulk sync endpoint.
 * If the sync endpoint is unavailable the entries stay in IndexedDB and
 * the next sync event will pick them up.
 *
 * @return {Promise<void>}
 */
async function replayProgressQueue() {
	const entries = await drainQueue();
	if ( ! entries.length ) {
		return;
	}

	// Collect queued events into the shape the /progress/sync endpoint expects.
	const events = entries.map( ( entry ) => entry.body );

	try {
		const response = await fetch( '/wp-json/learnomy/v1/progress/sync', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   entries[0].nonce || '',
			},
			body: JSON.stringify( { events } ),
		} );

		if ( ! response.ok ) {
			// Re-queue so we don't lose data.
			for ( const entry of entries ) {
				await enqueueProgress( entry );
			}
		}
	} catch ( err ) {
		// Network still down — put everything back.
		for ( const entry of entries ) {
			await enqueueProgress( entry );
		}
	}
}

// ---------------------------------------------------------------------------
// Message listener — the page can ask the SW to queue progress or sync
// ---------------------------------------------------------------------------

self.addEventListener( 'message', ( event ) => {
	if ( ! event.data || ! event.data.type ) {
		return;
	}

	switch ( event.data.type ) {
		case 'QUEUE_PROGRESS':
			// Page detected it's offline and wants to queue a completion.
			enqueueProgress( {
				body:      event.data.payload,
				nonce:     event.data.nonce || '',
				timestamp: Date.now(),
			} );
			break;

		case 'FORCE_SYNC':
			// Page came back online and wants to flush the queue immediately.
			replayProgressQueue().then( () => {
				// Notify all clients that sync is done.
				self.clients.matchAll().then( ( clients ) => {
					clients.forEach( ( client ) => {
						client.postMessage( { type: 'SYNC_COMPLETE' } );
					} );
				} );
			} );
			break;
	}
} );
