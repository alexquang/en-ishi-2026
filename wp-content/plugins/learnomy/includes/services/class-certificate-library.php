<?php
/**
 * Curated certificate library — a small set of ready-to-use certificate
 * designs, seeded once and offered to every instructor with zero setup.
 *
 * This is the extensible "design registry": designs are exposed on the
 * `learnomy_certificate_designs` filter so we — or any future add-on — can drop
 * in more designs with no core edit, then seeded idempotently keyed by
 * `design_key`. Curated templates ship available to all instructors; a site
 * owner can restrict or edit them like any other template afterwards.
 *
 * The design_json shape mirrors the designer's own pre-built templates
 * (`assets/js/admin/certificate-designer.js` PREBUILT) so a curated template
 * opens and edits in the designer exactly like a hand-built one.
 *
 * @package Learnomy
 */

namespace Learnomy\Services;

use Learnomy\Models\CertificateTemplate;

defined( 'ABSPATH' ) || exit;

class Certificate_Library {

	/**
	 * Bump when the curated design SET grows (a new design is added). A site then
	 * re-seeds only the designs it is missing — templates an owner deleted are
	 * never resurrected, and existing ones are never duplicated.
	 */
	private const DESIGN_SET_VERSION = '1';

	private const SEED_OPTION = 'learnomy_certificate_library_version';

	/** Register the design filter + the one-time seeding hook. */
	public static function register(): void {
		add_filter( 'learnomy_certificate_designs', [ self::class, 'default_designs' ] );
		add_action( 'admin_init', [ self::class, 'maybe_seed' ] );
	}

	/** All registered curated designs (core defaults + anything an add-on appended). */
	public static function designs(): array {
		return (array) apply_filters( 'learnomy_certificate_designs', [] );
	}

	/**
	 * Seed missing curated templates once per design-set version. Cheap on the
	 * common path — a single autoloaded-option read short-circuits when the
	 * current set has already been seeded.
	 */
	public static function maybe_seed(): void {
		if ( self::DESIGN_SET_VERSION === get_option( self::SEED_OPTION ) ) {
			return;
		}
		if ( ! CertificateTemplate::table_exists() ) {
			return;
		}

		self::seed();
		update_option( self::SEED_OPTION, self::DESIGN_SET_VERSION, false );
	}

	/**
	 * Create any curated design not already present. Idempotent: a design whose
	 * `design_key` already has a template is skipped, so re-running never dups.
	 *
	 * @return int Number of templates created this run.
	 */
	public static function seed(): int {
		$created = 0;

		foreach ( self::designs() as $design ) {
			$key = isset( $design['design_key'] ) ? (string) $design['design_key'] : '';
			if ( '' === $key || CertificateTemplate::find_by_design_key( $key ) ) {
				continue;
			}

			$id = CertificateTemplate::create(
				[
					'title'         => (string) $design['title'],
					'orientation'   => (string) ( $design['orientation'] ?? 'landscape' ),
					'status'        => 'active',
					'author_id'     => 0,
					'design_json'   => (string) wp_json_encode( $design['design_json'] ),
					'triggers_json' => CertificateTemplate::encode_meta(
						[
							'kind'         => (string) ( $design['kind'] ?? 'course_completion' ),
							'design_key'   => $key,
							'source'       => 'curated',
							'availability' => CertificateTemplate::AVAILABILITY_ALL,
							'instructors'  => [],
						]
					),
				]
			);

			if ( $id ) {
				++$created;
			}
		}

		return $created;
	}

	/** Core curated designs: 2 course-completion + 2 quiz-completion variants. */
	public static function default_designs( array $designs ): array {
		$designs[] = self::design_course_elegant();
		$designs[] = self::design_course_modern();
		$designs[] = self::design_quiz_elegant();
		$designs[] = self::design_quiz_modern();

		return $designs;
	}

	/* ── Course Completion - Elegant (serif, bordered) ─────────────────────── */
	private static function design_course_elegant(): array {
		return [
			'design_key'  => 'course-elegant',
			'title'       => __( 'Course Completion - Elegant', 'learnomy' ),
			'kind'        => 'course_completion',
			'orientation' => 'landscape',
			'design_json' => [
				'orientation' => 'landscape',
				'background'  => [
					'color' => '#fffef5',
					'image' => null,
				],
				'elements'    => [
					[
						'id'        => 'el_1',
						'type'      => 'line',
						'x'         => 3,
						'y'         => 3,
						'width'     => 94,
						'height'    => 0.4,
						'color'     => '#c8a951',
						'lineStyle' => 'solid',
					],
					[
						'id'        => 'el_2',
						'type'      => 'line',
						'x'         => 3,
						'y'         => 96,
						'width'     => 94,
						'height'    => 0.4,
						'color'     => '#c8a951',
						'lineStyle' => 'solid',
					],
					[
						'id'        => 'el_3',
						'type'      => 'line',
						'x'         => 3,
						'y'         => 3,
						'width'     => 0.3,
						'height'    => 93,
						'color'     => '#c8a951',
						'lineStyle' => 'solid',
					],
					[
						'id'        => 'el_4',
						'type'      => 'line',
						'x'         => 96.7,
						'y'         => 3,
						'width'     => 0.3,
						'height'    => 93,
						'color'     => '#c8a951',
						'lineStyle' => 'solid',
					],
					[
						'id'    => 'el_5',
						'type'  => 'text',
						'text'  => __( 'Certificate of Completion', 'learnomy' ),
						'x'     => 50,
						'y'     => 18,
						'font'  => 'Georgia',
						'size'  => 32,
						'color' => '#1a1a1a',
						'bold'  => false,
						'align' => 'center',
					],
					[
						'id'    => 'el_6',
						'type'  => 'text',
						'text'  => __( 'This is to certify that', 'learnomy' ),
						'x'     => 50,
						'y'     => 32,
						'font'  => 'Georgia',
						'size'  => 14,
						'color' => '#666666',
						'bold'  => false,
						'align' => 'center',
					],
					[
						'id'    => 'el_7',
						'type'  => 'merge_tag',
						'tag'   => '{student_name}',
						'x'     => 50,
						'y'     => 42,
						'font'  => 'Georgia',
						'size'  => 36,
						'color' => '#1a1a1a',
						'bold'  => true,
						'align' => 'center',
					],
					[
						'id'    => 'el_8',
						'type'  => 'text',
						'text'  => __( 'has successfully completed the course', 'learnomy' ),
						'x'     => 50,
						'y'     => 54,
						'font'  => 'Georgia',
						'size'  => 14,
						'color' => '#666666',
						'bold'  => false,
						'align' => 'center',
					],
					[
						'id'    => 'el_9',
						'type'  => 'merge_tag',
						'tag'   => '{course_title}',
						'x'     => 50,
						'y'     => 64,
						'font'  => 'Georgia',
						'size'  => 22,
						'color' => '#333333',
						'bold'  => true,
						'align' => 'center',
					],
					[
						'id'    => 'el_10',
						'type'  => 'merge_tag',
						'tag'   => '{completion_date}',
						'x'     => 50,
						'y'     => 78,
						'font'  => 'Georgia',
						'size'  => 14,
						'color' => '#666666',
						'bold'  => false,
						'align' => 'center',
					],
					[
						'id'    => 'el_11',
						'type'  => 'signature',
						'label' => __( 'Instructor', 'learnomy' ),
						'x'     => 25,
						'y'     => 85,
						'font'  => 'Georgia',
						'size'  => 12,
						'color' => '#333333',
					],
					[
						'id'    => 'el_12',
						'type'  => 'signature',
						'label' => __( 'Director', 'learnomy' ),
						'x'     => 75,
						'y'     => 85,
						'font'  => 'Georgia',
						'size'  => 12,
						'color' => '#333333',
					],
					[
						'id'   => 'el_13',
						'type' => 'qr_code',
						'x'    => 88,
						'y'    => 78,
						'size' => 10,
					],
				],
			],
		];
	}

	/* ── Course Completion - Modern (sans, left-aligned accent bar) ────────── */
	private static function design_course_modern(): array {
		return [
			'design_key'  => 'course-modern',
			'title'       => __( 'Course Completion - Modern', 'learnomy' ),
			'kind'        => 'course_completion',
			'orientation' => 'landscape',
			'design_json' => [
				'orientation' => 'landscape',
				'background'  => [
					'color' => '#ffffff',
					'image' => null,
				],
				'elements'    => [
					[
						'id'        => 'el_1',
						'type'      => 'line',
						'x'         => 0,
						'y'         => 0,
						'width'     => 100,
						'height'    => 2.5,
						'color'     => '#2383e2',
						'lineStyle' => 'solid',
					],
					[
						'id'    => 'el_2',
						'type'  => 'text',
						'text'  => __( 'CERTIFICATE', 'learnomy' ),
						'x'     => 8,
						'y'     => 14,
						'font'  => 'Helvetica',
						'size'  => 14,
						'color' => '#2383e2',
						'bold'  => true,
						'align' => 'left',
					],
					[
						'id'    => 'el_3',
						'type'  => 'text',
						'text'  => __( 'OF COMPLETION', 'learnomy' ),
						'x'     => 8,
						'y'     => 20,
						'font'  => 'Helvetica',
						'size'  => 14,
						'color' => '#999999',
						'bold'  => false,
						'align' => 'left',
					],
					[
						'id'    => 'el_4',
						'type'  => 'merge_tag',
						'tag'   => '{student_name}',
						'x'     => 8,
						'y'     => 38,
						'font'  => 'Helvetica',
						'size'  => 40,
						'color' => '#1a1a1a',
						'bold'  => true,
						'align' => 'left',
					],
					[
						'id'    => 'el_5',
						'type'  => 'text',
						'text'  => __( 'Has successfully completed', 'learnomy' ),
						'x'     => 8,
						'y'     => 52,
						'font'  => 'Helvetica',
						'size'  => 14,
						'color' => '#666666',
						'bold'  => false,
						'align' => 'left',
					],
					[
						'id'    => 'el_6',
						'type'  => 'merge_tag',
						'tag'   => '{course_title}',
						'x'     => 8,
						'y'     => 60,
						'font'  => 'Helvetica',
						'size'  => 22,
						'color' => '#333333',
						'bold'  => true,
						'align' => 'left',
					],
					[
						'id'    => 'el_7',
						'type'  => 'merge_tag',
						'tag'   => '{completion_date}',
						'x'     => 8,
						'y'     => 76,
						'font'  => 'Helvetica',
						'size'  => 13,
						'color' => '#999999',
						'bold'  => false,
						'align' => 'left',
					],
					[
						'id'    => 'el_8',
						'type'  => 'signature',
						'label' => __( 'Instructor', 'learnomy' ),
						'x'     => 8,
						'y'     => 86,
						'font'  => 'Helvetica',
						'size'  => 12,
						'color' => '#333333',
					],
					[
						'id'   => 'el_9',
						'type' => 'qr_code',
						'x'    => 85,
						'y'    => 75,
						'size' => 12,
					],
				],
			],
		];
	}

	/* ── Quiz Completion - Elegant (achievement wording + score) ───────────── */
	private static function design_quiz_elegant(): array {
		return [
			'design_key'  => 'quiz-elegant',
			'title'       => __( 'Quiz Completion - Elegant', 'learnomy' ),
			'kind'        => 'quiz_completion',
			'orientation' => 'landscape',
			'design_json' => [
				'orientation' => 'landscape',
				'background'  => [
					'color' => '#f7fbff',
					'image' => null,
				],
				'elements'    => [
					[
						'id'        => 'el_1',
						'type'      => 'line',
						'x'         => 3,
						'y'         => 3,
						'width'     => 94,
						'height'    => 0.4,
						'color'     => '#2383e2',
						'lineStyle' => 'solid',
					],
					[
						'id'        => 'el_2',
						'type'      => 'line',
						'x'         => 3,
						'y'         => 96,
						'width'     => 94,
						'height'    => 0.4,
						'color'     => '#2383e2',
						'lineStyle' => 'solid',
					],
					[
						'id'    => 'el_3',
						'type'  => 'text',
						'text'  => __( 'Certificate of Achievement', 'learnomy' ),
						'x'     => 50,
						'y'     => 18,
						'font'  => 'Georgia',
						'size'  => 32,
						'color' => '#1a1a1a',
						'bold'  => false,
						'align' => 'center',
					],
					[
						'id'    => 'el_4',
						'type'  => 'text',
						'text'  => __( 'This is to certify that', 'learnomy' ),
						'x'     => 50,
						'y'     => 31,
						'font'  => 'Georgia',
						'size'  => 14,
						'color' => '#666666',
						'bold'  => false,
						'align' => 'center',
					],
					[
						'id'    => 'el_5',
						'type'  => 'merge_tag',
						'tag'   => '{student_name}',
						'x'     => 50,
						'y'     => 41,
						'font'  => 'Georgia',
						'size'  => 36,
						'color' => '#1a1a1a',
						'bold'  => true,
						'align' => 'center',
					],
					[
						'id'    => 'el_6',
						'type'  => 'text',
						'text'  => __( 'has passed the assessment', 'learnomy' ),
						'x'     => 50,
						'y'     => 53,
						'font'  => 'Georgia',
						'size'  => 14,
						'color' => '#666666',
						'bold'  => false,
						'align' => 'center',
					],
					[
						'id'    => 'el_7',
						'type'  => 'merge_tag',
						'tag'   => '{course_title}',
						'x'     => 50,
						'y'     => 62,
						'font'  => 'Georgia',
						'size'  => 22,
						'color' => '#333333',
						'bold'  => true,
						'align' => 'center',
					],
					[
						'id'    => 'el_8',
						'type'  => 'text',
						'text'  => __( 'with a score of', 'learnomy' ),
						'x'     => 50,
						'y'     => 72,
						'font'  => 'Georgia',
						'size'  => 13,
						'color' => '#666666',
						'bold'  => false,
						'align' => 'center',
					],
					[
						'id'    => 'el_9',
						'type'  => 'merge_tag',
						'tag'   => '{score}',
						'x'     => 50,
						'y'     => 79,
						'font'  => 'Georgia',
						'size'  => 20,
						'color' => '#2383e2',
						'bold'  => true,
						'align' => 'center',
					],
					[
						'id'    => 'el_10',
						'type'  => 'merge_tag',
						'tag'   => '{completion_date}',
						'x'     => 25,
						'y'     => 88,
						'font'  => 'Georgia',
						'size'  => 12,
						'color' => '#666666',
						'bold'  => false,
						'align' => 'center',
					],
					[
						'id'    => 'el_11',
						'type'  => 'signature',
						'label' => __( 'Instructor', 'learnomy' ),
						'x'     => 75,
						'y'     => 86,
						'font'  => 'Georgia',
						'size'  => 12,
						'color' => '#333333',
					],
					[
						'id'   => 'el_12',
						'type' => 'qr_code',
						'x'    => 88,
						'y'    => 79,
						'size' => 9,
					],
				],
			],
		];
	}

	/* ── Quiz Completion - Modern (sans, accent bar + score) ───────────────── */
	private static function design_quiz_modern(): array {
		return [
			'design_key'  => 'quiz-modern',
			'title'       => __( 'Quiz Completion - Modern', 'learnomy' ),
			'kind'        => 'quiz_completion',
			'orientation' => 'landscape',
			'design_json' => [
				'orientation' => 'landscape',
				'background'  => [
					'color' => '#ffffff',
					'image' => null,
				],
				'elements'    => [
					[
						'id'        => 'el_1',
						'type'      => 'line',
						'x'         => 0,
						'y'         => 0,
						'width'     => 100,
						'height'    => 2.5,
						'color'     => '#16a34a',
						'lineStyle' => 'solid',
					],
					[
						'id'    => 'el_2',
						'type'  => 'text',
						'text'  => __( 'CERTIFICATE', 'learnomy' ),
						'x'     => 8,
						'y'     => 14,
						'font'  => 'Helvetica',
						'size'  => 14,
						'color' => '#16a34a',
						'bold'  => true,
						'align' => 'left',
					],
					[
						'id'    => 'el_3',
						'type'  => 'text',
						'text'  => __( 'OF ACHIEVEMENT', 'learnomy' ),
						'x'     => 8,
						'y'     => 20,
						'font'  => 'Helvetica',
						'size'  => 14,
						'color' => '#999999',
						'bold'  => false,
						'align' => 'left',
					],
					[
						'id'    => 'el_4',
						'type'  => 'merge_tag',
						'tag'   => '{student_name}',
						'x'     => 8,
						'y'     => 37,
						'font'  => 'Helvetica',
						'size'  => 40,
						'color' => '#1a1a1a',
						'bold'  => true,
						'align' => 'left',
					],
					[
						'id'    => 'el_5',
						'type'  => 'text',
						'text'  => __( 'Passed the assessment', 'learnomy' ),
						'x'     => 8,
						'y'     => 50,
						'font'  => 'Helvetica',
						'size'  => 14,
						'color' => '#666666',
						'bold'  => false,
						'align' => 'left',
					],
					[
						'id'    => 'el_6',
						'type'  => 'merge_tag',
						'tag'   => '{course_title}',
						'x'     => 8,
						'y'     => 58,
						'font'  => 'Helvetica',
						'size'  => 22,
						'color' => '#333333',
						'bold'  => true,
						'align' => 'left',
					],
					[
						'id'    => 'el_7',
						'type'  => 'text',
						'text'  => __( 'Score', 'learnomy' ),
						'x'     => 8,
						'y'     => 70,
						'font'  => 'Helvetica',
						'size'  => 12,
						'color' => '#999999',
						'bold'  => false,
						'align' => 'left',
					],
					[
						'id'    => 'el_8',
						'type'  => 'merge_tag',
						'tag'   => '{score}',
						'x'     => 8,
						'y'     => 75,
						'font'  => 'Helvetica',
						'size'  => 20,
						'color' => '#16a34a',
						'bold'  => true,
						'align' => 'left',
					],
					[
						'id'    => 'el_9',
						'type'  => 'merge_tag',
						'tag'   => '{completion_date}',
						'x'     => 8,
						'y'     => 86,
						'font'  => 'Helvetica',
						'size'  => 12,
						'color' => '#999999',
						'bold'  => false,
						'align' => 'left',
					],
					[
						'id'    => 'el_10',
						'type'  => 'signature',
						'label' => __( 'Instructor', 'learnomy' ),
						'x'     => 60,
						'y'     => 86,
						'font'  => 'Helvetica',
						'size'  => 12,
						'color' => '#333333',
					],
					[
						'id'   => 'el_11',
						'type' => 'qr_code',
						'x'    => 85,
						'y'    => 74,
						'size' => 12,
					],
				],
			],
		];
	}
}
