<?php
/**
 * Question CSV service — import + export round-trip for the question bank.
 *
 * The sprint requires one authoritative CSV format that survives both
 * directions without data loss. Every row carries: `title`, `type`,
 * `difficulty`, `category_slug`, `status`, `hint`, `body_text`, and a JSON
 * blob in `answers_json` which captures each answer's content, correctness,
 * fraction, feedback, and group_id. JSON sidesteps the "quote inside quote"
 * headaches that happen when answers contain commas or newlines.
 *
 * On import, an unknown `category_slug` is created on first sight (rather than
 * dumped as uncategorised) so a bulk import that introduces new subjects needs
 * no manual pre-creation. An optional `category_name` column supplies a nicer
 * display name; when absent the name is humanised from the slug.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

defined( 'ABSPATH' ) || exit;

use Learnomy\QuestionTypes;
use Learnomy\Models\Answer;
use Learnomy\Models\Question;
use Learnomy\Models\QuestionCategory;

final class Question_CSV {

	/**
	 * Header row used by both export() and import() — keep in lockstep.
	 *
	 * @var string[]
	 */
	public const COLUMNS = [
		'title',
		'type',
		'difficulty',
		'category_slug',
		'status',
		'hint',
		'body_text',
		'answers_json',
	];

	/**
	 * Serializes the current question bank (optionally narrowed) to CSV text.
	 *
	 * @param array<string,scalar> $filters Same shape as Model::where() filters.
	 *                                      Accepts `category_id`, `type`,
	 *                                      `difficulty`, `status`, `author_id`.
	 * @return string                       CSV text ready for `Content-Disposition: attachment`.
	 */
	public static function export( array $filters = [] ): string {
		$questions = Question::where( $filters, 'id', 'ASC', 0, 0 );

		// fopen('php://temp') gives us a streamable buffer so fputcsv() handles
		// escaping quotes/newlines for us, then we snapshot the contents at the
		// end. Writing manually would re-implement half of RFC 4180 poorly.
		// php://temp is an in-memory stream — no disk IO, so WP_Filesystem does not apply.
		$handle = fopen( 'php://temp', 'w+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return '';
		}

		fputcsv( $handle, self::COLUMNS, ',', '"', '\\' );

		// Category slugs are looked up in a single pass to keep the export
		// O(n + m) rather than O(n * m) — n rows, m categories.
		$category_slugs = self::category_slug_map();

		foreach ( $questions as $question ) {
			$answers = Answer::get_for_question( (int) $question->id );
			$row     = [
				$question->title ?? '',
				$question->type ?? 'mc_single',
				(int) ( $question->difficulty ?? 3 ),
				$category_slugs[ (int) ( $question->category_id ?? 0 ) ] ?? '',
				$question->status ?? 'published',
				$question->hint ?? '',
				$question->content ?? '',
				self::encode_answers( $answers ),
			];
			fputcsv( $handle, $row, ',', '"', '\\' );
		}

		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory php://temp stream.

		return $csv;
	}

	/**
	 * Parses a CSV text blob and inserts rows into `lrn_questions` + `lrn_question_answers`.
	 *
	 * Malformed rows (missing title/type, invalid JSON) are counted as skipped
	 * and reported back — we do NOT abort the whole import on a single bad row.
	 * That would make partial imports impossible to retry.
	 *
	 * @param string $csv_text Full CSV payload (header row + data rows).
	 * @return array{imported:int,skipped:int,errors:array<int,string>}
	 */
	public static function import( string $csv_text ): array {
		$result = [
			'imported' => 0,
			'skipped'  => 0,
			'errors'   => [],
		];

		if ( '' === trim( $csv_text ) ) {
			$result['errors'][] = __( 'The uploaded CSV is empty.', 'learnomy' );
			return $result;
		}

		// php://temp is an in-memory stream — no disk IO, so WP_Filesystem does not apply.
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			$result['errors'][] = __( 'Could not open a buffer to parse the CSV.', 'learnomy' );
			return $result;
		}
		fwrite( $handle, $csv_text ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- In-memory php://temp stream.
		rewind( $handle );

		$header = fgetcsv( $handle, null, ',', '"', '\\' );
		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory php://temp stream.
			$result['errors'][] = __( 'The CSV header row is missing.', 'learnomy' );
			return $result;
		}

		$header = array_map( 'trim', $header );
		// Resolve column indexes by name so CSVs with extra/reordered columns
		// still import correctly as long as the required columns are present.
		$col_index = array_flip( $header );
		foreach ( [ 'title', 'type' ] as $required ) {
			if ( ! isset( $col_index[ $required ] ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory php://temp stream.
				$result['errors'][] = sprintf(
					/* translators: %s: CSV column name */
					__( 'Missing required column "%s" in the CSV header.', 'learnomy' ),
					$required
				);
				return $result;
			}
		}

		$category_ids = array_flip( self::category_slug_map() );
		$slugs        = QuestionTypes::slugs();
		$row_number   = 1; // Header is row 1; data starts at row 2.

		while ( false !== ( $row = fgetcsv( $handle, null, ',', '"', '\\' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition
			++$row_number;

			if ( ! is_array( $row ) || [] === array_filter( $row, 'strlen' ) ) {
				continue; // blank row
			}

			$get = static function ( string $col ) use ( $row, $col_index ) {
				return isset( $col_index[ $col ], $row[ $col_index[ $col ] ] ) ? (string) $row[ $col_index[ $col ] ] : '';
			};

			$title = trim( $get( 'title' ) );
			$type  = QuestionTypes::normalize( trim( $get( 'type' ) ) );

			if ( '' === $title || ! in_array( $type, $slugs, true ) ) {
				++$result['skipped'];
				$result['errors'][] = sprintf(
					/* translators: %d: 1-based CSV row number */
					__( 'Row %d skipped: missing title or unknown type.', 'learnomy' ),
					$row_number
				);
				continue;
			}

			// Slug is normalised the same way categories are slugged on creation,
			// so a human-typed "My Topic" matches the stored "my-topic". An
			// unknown slug is created on first sight (see resolve_category_id)
			// instead of leaving the row uncategorised.
			$category_slug = sanitize_title( trim( $get( 'category_slug' ) ) );
			$category_name = sanitize_text_field( trim( $get( 'category_name' ) ) );
			$category_id   = self::resolve_category_id( $category_slug, $category_name, $category_ids );

			$data = [
				'title'       => sanitize_text_field( $title ),
				'type'        => $type,
				// Same normaliser as REST. `(int) $x ?: 3` used to send a literal
				// 0 to 3 (0 is falsy) while the identical 0 through REST became
				// 1 -- the same CSV imported two ways produced two banks.
				'difficulty'  => \Learnomy\Models\Question::normalize_difficulty( $get( 'difficulty' ) ),
				'status'      => sanitize_key( $get( 'status' ) ) ?: 'published',
				'hint'        => sanitize_text_field( $get( 'hint' ) ),
				'content'     => wp_kses_post( $get( 'body_text' ) ),
				'category_id' => $category_id,
				'author_id'   => get_current_user_id(),
			];

			$question_id = Question::create( $data );
			if ( ! $question_id ) {
				++$result['skipped'];
				$result['errors'][] = sprintf(
					/* translators: %d: 1-based CSV row number */
					__( 'Row %d skipped: database insert failed.', 'learnomy' ),
					$row_number
				);
				continue;
			}

			// Keep the denormalised per-category count honest so the auto-created
			// (and existing) categories show the right totals right after import.
			if ( null !== $category_id ) {
				QuestionCategory::increment_question_count( $category_id );
			}

			$answers = self::decode_answers( $get( 'answers_json' ) );
			foreach ( $answers as $i => $answer ) {
				if ( ! is_array( $answer ) || ! isset( $answer['content'] ) ) {
					continue;
				}
				Answer::create(
					[
						'question_id' => (int) $question_id,
						'content'     => wp_kses_post( (string) $answer['content'] ),
						'is_correct'  => ! empty( $answer['is_correct'] ) ? 1 : 0,
						'fraction'    => isset( $answer['fraction'] ) ? (float) $answer['fraction'] : ( ! empty( $answer['is_correct'] ) ? 1.0 : 0.0 ),
						'feedback'    => isset( $answer['feedback'] ) ? sanitize_text_field( (string) $answer['feedback'] ) : '',
						'group_id'    => isset( $answer['group_id'] ) ? sanitize_text_field( (string) $answer['group_id'] ) : null,
						'sort_order'  => isset( $answer['sort_order'] ) ? (int) $answer['sort_order'] : $i,
					]
				);
			}

			++$result['imported'];
		}

		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- In-memory php://temp stream.

		return $result;
	}

	// ---------------------------------------------------------------------
	// Helpers
	// ---------------------------------------------------------------------

	/**
	 * Resolve a category id from its slug, creating the category the first time
	 * an unknown slug is seen so a bulk import never silently dumps rows as
	 * uncategorised. Newly resolved ids are cached into $known (slug => id) for
	 * the rest of the run, so a slug repeated across rows creates one category.
	 *
	 * @param string            $slug  Sanitised category slug ('' = uncategorised).
	 * @param string            $name  Optional display name; humanised from the slug when empty.
	 * @param array<string,int> $known Slug => id map for this import, updated by reference.
	 * @return int|null Category id, or null when no slug was supplied.
	 */
	private static function resolve_category_id( string $slug, string $name, array &$known ): ?int {
		if ( '' === $slug ) {
			return null;
		}
		if ( isset( $known[ $slug ] ) ) {
			return (int) $known[ $slug ];
		}

		// Guard against a slug already in the bank but absent from the map built
		// at the start of this run (e.g. created by a concurrent import). The
		// slug column is UNIQUE, so a blind create would otherwise fail.
		$existing = QuestionCategory::find_by_slug( $slug );
		if ( $existing ) {
			$known[ $slug ] = (int) $existing->id;
			return (int) $existing->id;
		}

		$display = '' !== $name ? $name : ucwords( str_replace( [ '-', '_' ], ' ', $slug ) );
		$new_id  = QuestionCategory::create(
			[
				'name' => $display,
				'slug' => $slug,
			]
		);
		if ( $new_id > 0 ) {
			$known[ $slug ] = $new_id;
			return $new_id;
		}

		return null;
	}

	/**
	 * Map of category id => slug for the current bank.
	 *
	 * @return array<int,string>
	 */
	private static function category_slug_map(): array {
		$map  = [];
		$rows = QuestionCategory::where( [], 'name', 'ASC', 0, 0 );
		foreach ( $rows as $row ) {
			$map[ (int) $row->id ] = (string) $row->slug;
		}
		return $map;
	}

	/**
	 * Serializes an answer list to a JSON string suitable for a CSV cell.
	 *
	 * @param object[] $answers Answer rows from Answer::get_for_question().
	 * @return string
	 */
	private static function encode_answers( array $answers ): string {
		$payload = [];
		foreach ( $answers as $a ) {
			$payload[] = [
				'content'    => (string) ( $a->content ?? '' ),
				'is_correct' => (int) ( $a->is_correct ?? 0 ),
				'fraction'   => (float) ( $a->fraction ?? 0.0 ),
				'feedback'   => (string) ( $a->feedback ?? '' ),
				'group_id'   => (string) ( $a->group_id ?? '' ),
				'sort_order' => (int) ( $a->sort_order ?? 0 ),
			];
		}
		return (string) wp_json_encode( $payload );
	}

	/**
	 * Safely decodes a JSON-encoded answer blob from the CSV.
	 *
	 * @param string $json CSV cell contents.
	 * @return array<int,array<string,mixed>>
	 */
	private static function decode_answers( string $json ): array {
		$json = trim( $json );
		if ( '' === $json ) {
			return [];
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return [];
		}
		return $decoded;
	}
}
