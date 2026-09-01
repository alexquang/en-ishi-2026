<?php
/**
 * Lesson_Attachment — per-lesson downloadable resources.
 *
 * Maps to `lrn_lesson_attachments`. Each row references a WP media attachment
 * and denormalises filename / mime / size so listing views can render without
 * another trip through `get_post()`.
 *
 * @package Learnomy
 */

namespace Learnomy\Models;

defined( 'ABSPATH' ) || exit;

use function Learnomy\now;

class Lesson_Attachment extends Model {

	/**
	 * @var array<string, string|array{column:string,on_delete:string}>
	 */
	protected static array $references = array(
		'lesson' => 'lesson_id',
	);

	protected static function table_name(): string {
		return 'lesson_attachments';
	}

	/**
	 * Fetch every attachment for a lesson, ordered by sort_order ASC then id.
	 *
	 * @param int $lesson_id Lesson row id.
	 * @return object[] Rows from lrn_lesson_attachments.
	 */
	public static function get_for_lesson( int $lesson_id ): array {
		if ( $lesson_id <= 0 ) {
			return [];
		}
		return static::db()->get_results(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE lesson_id = %d ORDER BY sort_order ASC, id ASC',
				$lesson_id
			)
		) ?: [];
	}

	/**
	 * Create a new attachment row. Caller passes a media_id (WP attachment) plus
	 * a lesson_id; filename / mime / size are resolved from the attachment post
	 * when the caller does not supply them explicitly.
	 *
	 * @param array $data Column map. `lesson_id` and `media_id` are required.
	 * @return int Inserted row id, 0 on failure.
	 */
	public static function create( array $data ): int {
		if ( empty( $data['lesson_id'] ) || empty( $data['media_id'] ) ) {
			return 0;
		}

		$data['lesson_id'] = (int) $data['lesson_id'];
		$data['media_id']  = (int) $data['media_id'];

		// Taking ownership of the bytes is part of creating the row, not a step
		// a caller can forget. Every path into this table — REST, the seeder,
		// Pro, a future importer — goes through here, so putting the guard at
		// the model boundary is what makes "no attachment row ever points at a
		// publicly-fetchable file" true by construction. Failing to protect
		// fails the create; we never store a row whose file is still public.
		if ( ! \Learnomy\Services\Protected_Uploads::is_protected( $data['media_id'] )
			&& is_wp_error( \Learnomy\Services\Protected_Uploads::protect_media( $data['media_id'] ) ) ) {
			return 0;
		}

		// Backfill media metadata from the WP attachment when omitted.
		if ( empty( $data['filename'] ) || ! isset( $data['mime_type'] ) || ! isset( $data['size_bytes'] ) ) {
			$meta = self::read_attachment_meta( $data['media_id'] );
			if ( empty( $data['filename'] ) ) {
				$data['filename'] = $meta['filename'];
			}
			if ( ! isset( $data['mime_type'] ) ) {
				$data['mime_type'] = $meta['mime_type'];
			}
			if ( ! isset( $data['size_bytes'] ) ) {
				$data['size_bytes'] = $meta['size_bytes'];
			}
		}

		// Append after the highest existing sort_order for this lesson when no
		// explicit position was provided.
		if ( ! isset( $data['sort_order'] ) ) {
			$data['sort_order'] = self::next_sort_order( $data['lesson_id'] );
		}

		$defaults = [
			'filename'   => '',
			'mime_type'  => '',
			'size_bytes' => 0,
			'sort_order' => 0,
			'created_at' => now(),
		];

		return static::insert( array_merge( $defaults, $data ) );
	}

	/**
	 * Apply a new sort position to every row in `$order`. Rows not referenced
	 * are left untouched — callers pass the full ordered id list.
	 *
	 * @param int   $lesson_id Lesson whose attachments are being reordered.
	 * @param int[] $order     Attachment ids in the desired order.
	 */
	public static function reorder( int $lesson_id, array $order ): void {
		$table = static::table();
		foreach ( array_values( $order ) as $position => $attachment_id ) {
			static::db()->update(
				$table,
				[ 'sort_order' => (int) $position ],
				[
					'id'        => (int) $attachment_id,
					'lesson_id' => $lesson_id,
				],
				[ '%d' ],
				[ '%d', '%d' ]
			);
		}
		// Raw write bypasses base insert/update/delete — bump the cache group.
		static::invalidate_cache();
	}

	/**
	 * Delete a single attachment row. Does NOT remove the underlying WP media
	 * file — that stays in the library in case it's used elsewhere.
	 */
	public static function detach( int $attachment_id ): bool {
		return static::delete( $attachment_id );
	}

	/**
	 * Delete every attachment row for a lesson in one statement. Called by the
	 * {@see Lesson::delete()} cascade so a deleted lesson never leaves orphaned
	 * `lrn_lesson_attachments` rows. As with {@see detach()}, the underlying WP
	 * media files are intentionally left in the library.
	 *
	 * @param int $lesson_id Lesson row id.
	 * @return int Number of attachment rows removed.
	 */
	public static function delete_for_lesson( int $lesson_id ): int {
		if ( $lesson_id <= 0 ) {
			return 0;
		}
		$deleted = (int) static::db()->delete( static::table(), [ 'lesson_id' => $lesson_id ], [ '%d' ] );
		static::invalidate_cache();
		return $deleted;
	}

	/**
	 * The download URL for a stored attachment row.
	 *
	 * ALWAYS the gated `lesson-attachment` route, never the media item's own
	 * uploads URL. This method is the single chokepoint every surface goes
	 * through (REST, the player, the embedded player, Pro), which is what makes
	 * "the raw path is never published anywhere" a property of the codebase
	 * rather than a promise. Returning `wp_get_attachment_url()` here is what
	 * shipped a paid, drip-locked handout to anonymous curl (Basecamp 10148249510).
	 */
	public static function get_url( object $row ): string {
		$id = (int) ( $row->id ?? 0 );
		if ( $id <= 0 ) {
			return '';
		}

		// Cosmetic segment: it becomes the browser's default "save as" name.
		// Lookup is by id, so a rename never breaks an existing link.
		$filename = sanitize_file_name( (string) ( $row->filename ?? '' ) );
		if ( '' === $filename ) {
			$filename = 'file';
		}

		return \Learnomy\route_url(
			'lesson-attachment',
			[
				'attachment' => $id,
				'file'       => $filename,
			]
		);
	}

	/**
	 * Absolute server path to the stored bytes, for the delivery route.
	 *
	 * Reads through `get_attached_file()` so it follows the media item wherever
	 * {@see \Learnomy\Services\Protected_Uploads::protect_media()} moved it —
	 * no second copy of the path in our own table to drift.
	 */
	public static function get_path( object $row ): string {
		$media_id = (int) ( $row->media_id ?? 0 );
		if ( $media_id <= 0 ) {
			return '';
		}

		$path = get_attached_file( $media_id );

		return is_string( $path ) ? $path : '';
	}

	/**
	 * The first attachment row pointing at a given media item.
	 *
	 * Used to answer "what is the gated URL for this file?" when all a caller
	 * has is the media id — WordPress core and third-party code ask for an
	 * attachment URL, not for a Learnomy row. Memoised per request because the
	 * `wp_get_attachment_url` filter can fire many times on one media library
	 * screen.
	 */
	public static function find_by_media( int $media_id ): ?object {
		static $memo = [];

		if ( $media_id <= 0 ) {
			return null;
		}

		if ( array_key_exists( $media_id, $memo ) ) {
			return $memo[ $media_id ];
		}

		$row = static::db()->get_row(
			static::db()->prepare(
				'SELECT * FROM ' . static::table() . ' WHERE media_id = %d ORDER BY id ASC LIMIT 1',
				$media_id
			)
		);

		$memo[ $media_id ] = $row ?: null;

		return $memo[ $media_id ];
	}

	/**
	 * Every distinct media item referenced by an attachment row.
	 *
	 * Feeds the one-time backfill that moves handouts uploaded before the
	 * protected store existed. DISTINCT because one media item may be attached
	 * to several lessons and must only be relocated once.
	 *
	 * @return int[]
	 */
	public static function all_media_ids(): array {
		$ids = static::db()->get_col(
			'SELECT DISTINCT media_id FROM ' . static::table() . ' WHERE media_id > 0 ORDER BY media_id ASC'
		);

		return array_map( 'intval', (array) $ids );
	}

	// -----------------------------------------------------------------------
	// Internal helpers
	// -----------------------------------------------------------------------

	/**
	 * Read filename / mime / size from a WP media attachment.
	 *
	 * @param int $media_id Attachment post id.
	 * @return array{filename:string, mime_type:string, size_bytes:int}
	 */
	private static function read_attachment_meta( int $media_id ): array {
		$post = $media_id > 0 ? get_post( $media_id ) : null;
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return [
				'filename'   => '',
				'mime_type'  => '',
				'size_bytes' => 0,
			];
		}

		$file_path = get_attached_file( $media_id );
		$filename  = $file_path ? wp_basename( $file_path ) : $post->post_title;
		$size      = ( $file_path && file_exists( $file_path ) ) ? (int) filesize( $file_path ) : 0;

		return [
			'filename'   => (string) $filename,
			'mime_type'  => (string) ( $post->post_mime_type ?? '' ),
			'size_bytes' => $size,
		];
	}

	/**
	 * Return the next sort_order value for a lesson (current MAX + 1).
	 */
	private static function next_sort_order( int $lesson_id ): int {
		$current = (int) static::db()->get_var(
			static::db()->prepare(
				'SELECT MAX(sort_order) FROM ' . static::table() . ' WHERE lesson_id = %d',
				$lesson_id
			)
		);
		return $current + 1;
	}
}
