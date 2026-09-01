<?php
/**
 * Catalog facet registry.
 *
 * Lets site owners and Pro extensions add filter facets to /courses with one
 * register() call — no template hacks, no manual SQL plumbing. Each registered
 * facet contributes:
 *
 *   1. A sidebar UI block (rendered by Facet_Renderer on the
 *      `learnomy_catalog_filter_facets` hook).
 *   2. A `$_GET` key that flows through Catalog_View_Data into
 *      Course::search_catalog() args.
 *   3. WHERE clauses on the catalog SQL via the
 *      `learnomy_catalog_where_clauses` filter.
 *   4. Optional badges on each course card via
 *      `learnomy_course_card_meta`.
 *
 * Out-of-the-box source kinds:
 *
 *   tags:<type>     — flat tags from lrn_tags where type = <type>
 *                     (matches via lrn_tag_map.object_type='course').
 *   access_type     — built-in lrn_courses.access_type column.
 *   callable        — provide `clauses` + `options` callables for full control.
 *
 * Usage:
 *
 *   add_action( 'init', function () {
 *       \Learnomy\Catalog\Facets::register( 'industry', [
 *           'label'  => __( 'Industry', 'learnomy' ),
 *           'source' => 'tags:industry',
 *       ] );
 *   } );
 *
 * @package Learnomy\Catalog
 */

namespace Learnomy\Catalog;

defined( 'ABSPATH' ) || exit;

class Facets {

	/**
	 * Registered facet configs, keyed by slug.
	 *
	 * @var array<string, array<string,mixed>>
	 */
	private static array $registry = array();

	/**
	 * Register a facet.
	 *
	 * Required config keys: `label`, `source`.
	 *
	 * Optional:
	 *   `param`         (string) URL query var. Defaults to the slug.
	 *   `ui`            (string) 'checkbox-list' (default) | 'pills'.
	 *   `multi`         (bool)   Allow multi-select. Default true for `tags:*`,
	 *                            false for single-pick sources.
	 *   `show_counts`   (bool)   Show per-option count. Default true.
	 *   `options`       (callable|array) Override option list. Receives `$args`.
	 *   `clauses`       (callable) Return [ $where_sql_fragments[], $values[] ]
	 *                              when source is 'callable'.
	 *   `badge_label`   (callable|string) Per-course badge text. Receives `$course`.
	 *
	 * @param string              $slug   Unique facet identifier (sanitize_key).
	 * @param array<string,mixed> $config Facet config.
	 */
	public static function register( string $slug, array $config ): void {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return;
		}

		$defaults = array(
			'label'       => ucfirst( str_replace( array( '-', '_' ), ' ', $slug ) ),
			'source'      => '',
			'param'       => $slug,
			'ui'          => 'checkbox-list',
			'show_counts' => true,
			'multi'       => true,
			'options'     => null,
			'clauses'     => null,
			'badge_label' => null,
		);

		self::$registry[ $slug ] = array_merge( $defaults, $config );
	}

	/**
	 * Register the built-in facet for every course taxonomy.
	 *
	 * {@see \Learnomy\course_taxonomies()} is the one registry of course tag
	 * vocabularies — the course editor already renders a term picker per entry,
	 * and its help text promises "Tags help students find this course in the
	 * catalog". Nothing kept that promise: tags were written to `lrn_tag_map`
	 * and never surfaced anywhere a student could see, because no facet was
	 * ever registered against them (Basecamp #10143794593). Driving the facets
	 * off the same registry means a taxonomy added through
	 * `learnomy_course_taxonomies` gets its catalog filter for free, which is
	 * what both docblocks always said would happen.
	 *
	 * Runs on `init` at priority 5 so a site owner or Pro can `deregister()` or
	 * re-`register()` at the default priority 10 and win. A facet whose slug is
	 * already taken is left alone for the same reason.
	 *
	 * Sites with no tagged courses see no change: the option query filters to
	 * terms with at least one published course, and the renderer skips a facet
	 * with no options.
	 */
	public static function register_defaults(): void {
		foreach ( \Learnomy\course_taxonomies() as $taxonomy ) {
			$type = sanitize_key( (string) ( $taxonomy['type'] ?? '' ) );
			if ( '' === $type ) {
				continue;
			}

			// The built-in vocabulary's type is `course`; expose it as `tags`
			// so the catalog URL reads ?tags=beginner rather than ?course=,
			// which would read like a single-course parameter.
			$slug = 'course' === $type ? 'tags' : $type;

			if ( null !== self::get( $slug ) ) {
				continue;
			}

			self::register(
				$slug,
				array(
					'label'  => (string) ( $taxonomy['label'] ?? $type ),
					'source' => 'tags:' . $type,
				)
			);
		}
	}

	/**
	 * Remove a facet — useful when a Pro extension overrides a Free default.
	 */
	public static function deregister( string $slug ): void {
		unset( self::$registry[ sanitize_key( $slug ) ] );
	}

	/** @return array<string, array<string,mixed>> */
	public static function all(): array {
		return self::$registry;
	}

	public static function get( string $slug ): ?array {
		return self::$registry[ sanitize_key( $slug ) ] ?? null;
	}

	/**
	 * Read the active selection for every registered facet from $_GET.
	 *
	 * Returns a map of slug → array of sanitized values (always wrapped in
	 * an array so the rest of the pipeline doesn't branch on multi vs single).
	 *
	 * @return array<string, string[]>
	 */
	public static function resolve_active_from_request(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- catalog filters are GET-only nav.
		$active = array();
		foreach ( self::$registry as $slug => $facet ) {
			$param = (string) $facet['param'];
			if ( ! isset( $_GET[ $param ] ) ) {
				continue;
			}
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- every element is unslashed + sanitize_key()'d in the array_map below, which is the only place the value is read.
			$raw    = $_GET[ $param ];
			$values = is_array( $raw ) ? $raw : array( $raw );
			$values = array_values(
				array_filter(
					array_map(
						static fn( $v ): string => sanitize_key( wp_unslash( (string) $v ) ),
						$values
					)
				)
			);
			if ( ! empty( $values ) ) {
				$active[ $slug ] = $values;
			}
		}
		return $active;
		// phpcs:enable
	}

	/**
	 * Produce WHERE clauses for every active facet, joined into a single
	 * `[ $sql_fragments, $values ]` tuple ready for build_catalog_filters() to
	 * append.
	 *
	 * @param array<string, string[]> $active Slug → selected values.
	 * @return array{0: string[], 1: array<int,mixed>}
	 */
	public static function build_clauses( array $active ): array {
		$where_sql    = array();
		$where_values = array();
		global $wpdb;
		$p = $wpdb->prefix;

		foreach ( $active as $slug => $values ) {
			$facet = self::get( $slug );
			if ( ! $facet || empty( $values ) ) {
				continue;
			}
			$source = (string) $facet['source'];

			if ( str_starts_with( $source, 'tags:' ) ) {
				$tag_type = sanitize_key( substr( $source, 5 ) );
				if ( '' === $tag_type ) {
					continue;
				}
				$placeholders   = implode( ',', array_fill( 0, count( $values ), '%s' ) );
				$where_sql[]    = "id IN (
					SELECT tm.object_id
					FROM {$p}lrn_tag_map AS tm
					INNER JOIN {$p}lrn_tags AS t ON t.id = tm.tag_id
					WHERE tm.object_type = 'course'
					  AND t.type = %s
					  AND t.slug IN ({$placeholders})
				)";
				$where_values[] = $tag_type;
				foreach ( $values as $v ) {
					$where_values[] = $v;
				}
				continue;
			}

			if ( 'access_type' === $source ) {
				$allowed = array_intersect( $values, \Learnomy\Models\Course::ACCESS_TYPES );
				if ( empty( $allowed ) ) {
					continue;
				}
				$placeholders = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
				$where_sql[]  = "access_type IN ({$placeholders})";
				foreach ( $allowed as $v ) {
					$where_values[] = $v;
				}
				continue;
			}

			if ( 'callable' === $source && is_callable( $facet['clauses'] ) ) {
				$result = call_user_func( $facet['clauses'], $values, $facet );
				if ( is_array( $result ) && count( $result ) === 2 ) {
					foreach ( (array) $result[0] as $frag ) {
						$where_sql[] = (string) $frag;
					}
					foreach ( (array) $result[1] as $v ) {
						$where_values[] = $v;
					}
				}
			}
		}

		return array( $where_sql, $where_values );
	}

	/**
	 * Resolve the option list (label + slug + count) for a facet.
	 *
	 * @param array<string,mixed> $facet  Registered facet config.
	 * @param array<string,mixed> $args   Current catalog args (so counts can reflect the active filter set).
	 * @return array<int, array{slug:string,label:string,count:int}>
	 */
	public static function resolve_options( array $facet, array $args = array() ): array {
		if ( is_callable( $facet['options'] ) ) {
			$out = call_user_func( $facet['options'], $args, $facet );
			return is_array( $out ) ? $out : array();
		}
		if ( is_array( $facet['options'] ) ) {
			return $facet['options'];
		}

		// C4: the source-driven branches are GROUP BY count aggregates over the
		// published catalog — hot + shared on every /courses render, recomputed
		// each time, and expensive at catalog scale. Cache the option list, keyed
		// by the source + active-filter args + a composite of the course / tag /
		// tag-map / category group incrementors, so any relevant write (Rule 14
		// bumps every group) orphans it. Short TTL is a backstop; the incrementor
		// is the freshness mechanism. Callable/array facets (Pro-registered,
		// possibly dynamic) returned above and are NOT cached here.
		$cache_group = 'learnomy_facets';
		$cache_key   = 'facet_' . md5(
			(string) $facet['source'] . '|' . (string) wp_json_encode( $args ) . '|'
			. wp_cache_get_last_changed( 'learnomy_courses' )
			. wp_cache_get_last_changed( 'learnomy_tags' )
			. wp_cache_get_last_changed( 'learnomy_tag_map' )
			. wp_cache_get_last_changed( 'learnomy_categories' )
		);
		$found       = false;
		$cached      = wp_cache_get( $cache_key, $cache_group, false, $found );
		if ( $found ) {
			return is_array( $cached ) ? $cached : array();
		}

		$options = self::compute_source_options( $facet, $args );
		wp_cache_set( $cache_key, $options, $cache_group, 300 );
		return $options;
	}

	/**
	 * The source-driven facet option list (label + slug + count). Extracted from
	 * resolve_options() so the GROUP BY aggregates can be cached around it (C4).
	 *
	 * @param array<string,mixed> $facet Registered facet config.
	 * @param array<string,mixed> $args  Current catalog args.
	 * @return array<int, array{slug:string,label:string,count:int}>
	 */
	private static function compute_source_options( array $facet, array $args ): array {
		$source = (string) $facet['source'];

		if ( str_starts_with( $source, 'tags:' ) ) {
			$tag_type = sanitize_key( substr( $source, 5 ) );
			if ( '' === $tag_type ) {
				return array();
			}
			global $wpdb;
			$p = $wpdb->prefix;
			// Count only courses a visitor can actually reach. Counting every
			// mapped row offers a term whose result page comes back empty
			// because the courses behind it are drafts — the access_type
			// branch below already scopes to published for the same reason.
			$sql = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT t.slug, t.name, COUNT(c.id) AS cnt
				FROM {$p}lrn_tags AS t
				LEFT JOIN {$p}lrn_tag_map AS tm
				  ON tm.tag_id = t.id AND tm.object_type = 'course'
				LEFT JOIN {$p}lrn_courses AS c
				  ON c.id = tm.object_id AND c.status = 'published'
				WHERE t.type = %s
				GROUP BY t.id
				HAVING cnt > 0
				ORDER BY t.name ASC",
				$tag_type
			);
			$rows = $wpdb->get_results( $sql );
			return array_map(
				static fn( object $r ): array => array(
					'slug'  => (string) $r->slug,
					'label' => (string) $r->name,
					'count' => (int) $r->cnt,
				),
				(array) $rows
			);
		}

		if ( 'access_type' === $source ) {
			global $wpdb;
			$p      = $wpdb->prefix;
			$sql    = "SELECT access_type AS slug, COUNT(*) AS cnt
				FROM {$p}lrn_courses
				WHERE status = 'published'
				GROUP BY access_type";
			$rows   = $wpdb->get_results( $sql );
			$labels = array(
				'free'               => __( 'Free', 'learnomy' ),
				'paid'               => __( 'Paid', 'learnomy' ),
				'membership'         => __( 'Membership', 'learnomy' ),
				'paid_or_membership' => __( 'Paid or membership', 'learnomy' ),
				'closed'             => __( 'External / closed', 'learnomy' ),
			);
			return array_map(
				static fn( object $r ): array => array(
					'slug'  => (string) $r->slug,
					'label' => $labels[ (string) $r->slug ] ?? (string) $r->slug,
					'count' => (int) $r->cnt,
				),
				(array) $rows
			);
		}

		return array();
	}
}
