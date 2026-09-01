<?php
/**
 * Media upload endpoint for the lesson editor's Image tool.
 *
 * Editor.js Image tool posts to two endpoints — both wired to /learnomy/v1/media:
 *   • byFile — multipart/form-data with an `image` field (drag/drop, file picker)
 *   • byUrl  — application/json with a `url` field (paste image URL)
 *
 * Both return the Editor.js response shape `{ success: 1, file: { url } }` so
 * the tool inserts the resulting image inline. Falling back to the WP Media
 * Library's `wp_handle_upload` / `media_sideload_image` keeps the upload
 * routed through the same attachment pipeline admins are used to (resized
 * thumbnails, alt-text editing, cleanup on delete).
 *
 * @package Learnomy
 */

namespace Learnomy\API;

use Learnomy\Permissions\Permission_Engine;

defined( 'ABSPATH' ) || exit;

class Media_Controller extends Base_Controller {

	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/media',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle_upload' ),
				'permission_callback' => array( $this, 'check_upload_permission' ),
			)
		);

		// Author-side embed preview — renders a URL through the SAME
		// \Learnomy\Content\Embed::render() the student player uses, so the lesson
		// editor / front-end builder preview matches the learner view (uniform
		// embeds: one renderer across student, API, and author preview).
		register_rest_route(
			$this->namespace,
			'/oembed-preview',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'preview_embed' ),
				'permission_callback' => array( $this, 'check_upload_permission' ),
				'args'                => array(
					'url' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);

		// Editor.js LinkTool preview. The tool GETs `?url=…` and expects the
		// shape `{ success: 1, meta: { title, description, image: { url } } }`.
		// Wired from editor-tools.js (Free lesson editor) AND the Pro frontend
		// builder (builder-store.js) — both target this same learnomy/v1 route,
		// so registering it here unblocks links in Free and Pro at once.
		register_rest_route(
			$this->namespace,
			'/fetchUrl',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'fetch_link_meta' ),
				'permission_callback' => array( $this, 'check_upload_permission' ),
				'args'                => array(
					'url' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
				),
			)
		);
	}

	/**
	 * Editor.js LinkTool endpoint: build a link-preview card for a URL.
	 *
	 * Returns the tool's exact contract `{ success: 1, meta: {...} }` so the
	 * link ALWAYS inserts (no 404, no "couldn't fetch link" error). Preview
	 * data is best-effort: on an unreachable target, a non-HTML response, or an
	 * SSRF-unsafe host, the card still inserts with the host as its title.
	 *
	 * @param \WP_REST_Request $request `url` query arg.
	 * @return \WP_REST_Response Editor.js LinkTool response.
	 */
	public function fetch_link_meta( \WP_REST_Request $request ): \WP_REST_Response {
		$url = (string) $request->get_param( 'url' );
		return new \WP_REST_Response(
			array(
				'success' => 1,
				'meta'    => $this->link_meta( $url ),
			),
			200
		);
	}

	/**
	 * Fetch + parse Open Graph / title / description / image for a URL, guarded
	 * against SSRF and cached. Always returns a usable meta array (falls back to
	 * the host as the title) so the LinkTool card is never empty.
	 *
	 * @param string $url Author-supplied URL.
	 * @return array{title:string,description:string,image:array{url:string}}
	 */
	private function link_meta( string $url ): array {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );
		$host = '' !== $host ? (string) preg_replace( '/^www\./', '', $host ) : '';
		$meta = array(
			'title'       => $host,
			'description' => '',
			'image'       => array( 'url' => '' ),
		);

		// SSRF guard: never fetch a private/reserved host from an author URL
		// (same validator that gates outgoing webhook URLs). Still inserts the
		// card, just without a fetched preview.
		if ( '' === $url || ! \Learnomy\Security\Webhook_URL_Validator::is_safe( $url ) ) {
			return $meta;
		}

		$cache_key = 'lrn_linkmeta_' . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$resp = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 5,
				'redirection' => 3,
				'user-agent'  => 'Learnomy-LinkPreview/1.0 (+' . home_url( '/' ) . ')',
			)
		);

		$ctype = is_wp_error( $resp ) ? '' : (string) wp_remote_retrieve_header( $resp, 'content-type' );
		if ( is_wp_error( $resp )
			|| 200 !== (int) wp_remote_retrieve_response_code( $resp )
			|| false === stripos( $ctype, 'html' ) ) {
			// Cache the negative result briefly so a bad/paywalled URL is not
			// re-fetched on every keystroke, but recovers within the hour.
			set_transient( $cache_key, $meta, HOUR_IN_SECONDS );
			return $meta;
		}

		// Only the <head> carries the OG tags; cap the parse to keep it cheap.
		$head  = substr( (string) wp_remote_retrieve_body( $resp ), 0, 100000 );
		$title = $this->meta_content( $head, 'og:title' );
		$desc  = $this->meta_content( $head, 'og:description' );
		$image = $this->meta_content( $head, 'og:image' );

		if ( '' === $title && preg_match( '#<title[^>]*>(.*?)</title>#is', $head, $m ) ) {
			$title = trim( html_entity_decode( wp_strip_all_tags( $m[1] ), ENT_QUOTES, 'UTF-8' ) );
		}
		if ( '' === $desc ) {
			$desc = $this->meta_content( $head, 'description' );
		}

		$meta['title']       = '' !== $title ? $title : $host;
		$meta['description'] = $desc;
		if ( '' !== $image ) {
			$meta['image']['url'] = $this->absolute_url( $image, $url );
		}

		set_transient( $cache_key, $meta, DAY_IN_SECONDS );
		return $meta;
	}

	/**
	 * Pull the `content` of the first <meta> tag whose property/name equals
	 * $key (attribute order-independent). Returns '' when absent.
	 *
	 * @param string $html HTML head fragment.
	 * @param string $key  e.g. `og:title`, `description`.
	 */
	private function meta_content( string $html, string $key ): string {
		if ( ! preg_match_all( '#<meta\b[^>]*>#i', $html, $tags ) ) {
			return '';
		}
		foreach ( $tags[0] as $tag ) {
			if ( preg_match( '#(?:property|name)\s*=\s*["\']' . preg_quote( $key, '#' ) . '["\']#i', $tag )
				&& preg_match( '#content\s*=\s*["\'](.*?)["\']#is', $tag, $c ) ) {
				return trim( html_entity_decode( $c[1], ENT_QUOTES, 'UTF-8' ) );
			}
		}
		return '';
	}

	/**
	 * Resolve a (possibly relative) og:image URL against the page URL.
	 *
	 * @param string $candidate og:image value (absolute, //host, /path, or path).
	 * @param string $base      The page URL it was found on.
	 */
	private function absolute_url( string $candidate, string $base ): string {
		if ( preg_match( '#^https?://#i', $candidate ) ) {
			return esc_url_raw( $candidate );
		}
		$parts = wp_parse_url( $base );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return '';
		}
		if ( 0 === strpos( $candidate, '//' ) ) {
			return esc_url_raw( $parts['scheme'] . ':' . $candidate );
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		return esc_url_raw( $origin . '/' . ltrim( $candidate, '/' ) );
	}

	/**
	 * Render an embed URL through the one Embed renderer (oEmbed + premium
	 * component + link-card fallback) so the author preview is identical to the
	 * learner view. Cached via Embed's transient, so repeat previews are cheap.
	 *
	 * @param \WP_REST_Request $request `url` query arg.
	 * @return \WP_REST_Response Response with `{ html }`.
	 */
	public function preview_embed( \WP_REST_Request $request ): \WP_REST_Response {
		$url  = (string) $request->get_param( 'url' );
		$html = \Learnomy\Content\Embed::render( $url );
		return $this->single_response( array( 'html' => $html ) );
	}

	/**
	 * Editor.js Image tool only ships with logged-in instructors / admins who
	 * are already inside the lesson editor — gate on `upload_files` (the same
	 * cap WP core uses for Media Library) so the surface stays consistent.
	 */
	public function check_upload_permission(): bool {
		return current_user_can( 'upload_files' );
	}

	public function handle_upload( \WP_REST_Request $request ): \WP_REST_Response {
		$files = $request->get_file_params();
		$url   = $request->get_param( 'url' );

		// File upload branch (multipart). Field name is `image` per the
		// Editor.js Image tool config; we also accept `file` as a fallback so
		// other tools can reuse the endpoint without a config change.
		$file = $files['image'] ?? $files['file'] ?? null;
		if ( is_array( $file ) && ! empty( $file['tmp_name'] ) ) {
			return $this->ingest_file( $file );
		}

		// URL ingest branch — Editor.js byUrl posts JSON `{ "url": "https://…" }`.
		if ( is_string( $url ) && '' !== trim( $url ) ) {
			return $this->ingest_url( $url );
		}

		return new \WP_REST_Response(
			array(
				'success' => 0,
				'message' => __( 'No image was provided.', 'learnomy' ),
			),
			400
		);
	}

	/**
	 * Sideload an uploaded file into the Media Library. Wraps WP core helpers
	 * that we have to require_once at REST time because they don't auto-load
	 * outside the wp-admin upload screen.
	 *
	 * @param array{tmp_name:string,name:string,type:string,size:int,error:int} $file
	 */
	private function ingest_file( array $file ): \WP_REST_Response {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$overrides = array( 'test_form' => false );
		$result    = wp_handle_upload( $file, $overrides );
		if ( isset( $result['error'] ) ) {
			return new \WP_REST_Response(
				array(
					'success' => 0,
					'message' => (string) $result['error'],
				),
				400
			);
		}

		$attachment_id = wp_insert_attachment(
			array(
				'post_mime_type' => $result['type'],
				'post_title'     => sanitize_file_name( $file['name'] ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			),
			$result['file']
		);

		if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
			return new \WP_REST_Response(
				array(
					'success' => 0,
					'message' => __( 'Upload succeeded but the attachment could not be registered.', 'learnomy' ),
				),
				500
			);
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $result['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );

		return $this->success_response( $result['url'], $attachment_id );
	}

	/**
	 * Sideload an external image URL into the Media Library so the lesson
	 * doesn't depend on a third-party host that may rotate / break. Returns
	 * the local attachment URL.
	 */
	private function ingest_url( string $url ): \WP_REST_Response {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$attachment_id = media_sideload_image( $url, 0, null, 'id' );
		if ( is_wp_error( $attachment_id ) ) {
			return new \WP_REST_Response(
				array(
					'success' => 0,
					'message' => $attachment_id->get_error_message(),
				),
				400
			);
		}

		$attachment_url = wp_get_attachment_url( (int) $attachment_id );
		if ( ! $attachment_url ) {
			return new \WP_REST_Response(
				array(
					'success' => 0,
					'message' => __( 'Image was downloaded but the attachment URL could not be resolved.', 'learnomy' ),
				),
				500
			);
		}

		return $this->success_response( $attachment_url, (int) $attachment_id );
	}

	private function success_response( string $url, int $attachment_id ): \WP_REST_Response {
		return new \WP_REST_Response(
			array(
				'success' => 1,
				'file'    => array(
					'url'           => $url,
					'attachment_id' => $attachment_id,
				),
			),
			201
		);
	}
}
