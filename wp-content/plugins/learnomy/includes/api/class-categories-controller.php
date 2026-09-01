<?php
/**
 * Categories REST controller — course categories and tags.
 *
 * @package Learnomy
 */

namespace Learnomy\API;

defined( 'ABSPATH' ) || exit;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Learnomy\Models\Category;
use Learnomy\Models\Course;
use Learnomy\Models\Tag;
use Learnomy\Models\TagMap;
use Learnomy\Permissions\Permission_Engine;

class Categories_Controller extends Base_Controller {

	public function __construct() {
		parent::__construct();
		$this->rest_base = 'categories';
	}

	public function register_routes(): void {
		// Public category routes — list (GET) is open, create (POST) is gated to course creators.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_categories' ],
					'permission_callback' => '__return_true',
					'args'                => array_merge(
						$this->get_collection_params(),
						[
							'parent_id' => [
								'type'              => 'integer',
								'sanitize_callback' => 'absint',
								'description'       => __( 'Filter to children of this category ID. Omit for top-level categories.', 'learnomy' ),
							],
						]
					),
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_category' ],
					'permission_callback' => [ $this, 'can_create_taxonomy' ],
					'args'                => $this->category_args(),
				],
			]
		);

		// Public tag routes — list (GET) is open, create (POST) is gated to course creators.
		register_rest_route(
			$this->namespace,
			'/tags',
			[
				[
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_tags' ],
					'permission_callback' => '__return_true',
					'args'                => array_merge(
						$this->get_collection_params(),
						[
							'type' => [
								'type'              => 'string',
								'description'       => __( 'Tag vocabulary to list (course / lesson / quiz / question). Defaults to course.', 'learnomy' ),
								'sanitize_callback' => 'sanitize_key',
							],
						]
					),
				],
				[
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_tag' ],
					'permission_callback' => [ $this, 'can_create_taxonomy' ],
					'args'                => $this->tag_args(),
				],
			]
		);

		// Admin category management: create.
		register_rest_route(
			$this->namespace,
			'/admin/' . $this->rest_base,
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_category' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => $this->category_args(),
			]
		);

		// Admin category management: update and delete.
		register_rest_route(
			$this->namespace,
			'/admin/' . $this->rest_base . '/(?P<id>[\d]+)',
			[
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_category' ],
					'permission_callback' => [ $this, 'is_admin' ],
					'args'                => array_merge(
						[
							'id' => [
								'type'     => 'integer',
								'required' => true,
							],
						],
						$this->category_args( false )
					),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_category' ],
					'permission_callback' => [ $this, 'is_admin' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
			]
		);

		// Admin tag management: create.
		register_rest_route(
			$this->namespace,
			'/admin/tags',
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'create_tag' ],
				'permission_callback' => [ $this, 'is_admin' ],
				'args'                => $this->tag_args(),
			]
		);

		// Admin tag management: update and delete.
		register_rest_route(
			$this->namespace,
			'/admin/tags/(?P<id>[\d]+)',
			[
				[
					'methods'             => 'PATCH',
					'callback'            => [ $this, 'update_tag' ],
					'permission_callback' => [ $this, 'is_admin' ],
					'args'                => array_merge(
						[
							'id' => [
								'type'     => 'integer',
								'required' => true,
							],
						],
						$this->tag_args( false )
					),
				],
				[
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_tag' ],
					'permission_callback' => [ $this, 'is_admin' ],
					'args'                => [
						'id' => [
							'type'     => 'integer',
							'required' => true,
						],
					],
				],
			]
		);
	}

	// -------------------------------------------------------------------------
	// Category callbacks
	// -------------------------------------------------------------------------

	public function get_categories( WP_REST_Request $request ): WP_REST_Response {
		// A private academy hides what it teaches, and a category name says
		// exactly that. `/courses` already returns nothing here; returning the
		// taxonomy that describes those courses would undo it.
		if ( $this->private_academy_blocks_anonymous() ) {
			return $this->paginated_response( array(), 0, 0, false );
		}

		$parent_id = absint( $request->get_param( 'parent_id' ) );

		if ( $parent_id > 0 ) {
			$categories = Category::list_children( $parent_id );
		} else {
			$categories = Category::list_top_level( 'course' );
		}

		$etag         = $this->generate_etag( $categories );
		$not_modified = $this->maybe_not_modified( $etag, $request );
		if ( $not_modified ) {
			return $not_modified;
		}

		// Honour limit/page so meta.total is the real category count and
		// has_more is accurate (it previously returned every row with
		// total = count(returned)). The category list is a small, deduped set,
		// so slicing the in-memory result is correct and cheap.
		$total    = count( $categories );
		$per_page = (int) $request->get_param( 'per_page' );
		if ( $per_page <= 0 ) {
			$per_page = (int) $request->get_param( 'limit' );
		}
		$per_page = $per_page > 0 ? min( 100, $per_page ) : $total;
		$offset   = $this->resolve_offset( $request, (int) $per_page );
		$page_set = $per_page > 0 ? array_slice( $categories, $offset, $per_page ) : $categories;

		$response = $this->paginated_response( $page_set, $total, $offset );

		return $this->add_etag( $response, $etag );
	}

	public function create_category( WP_REST_Request $request ): WP_REST_Response {
		$data = $this->extract_category_fields( $request );
		if ( empty( $data['name'] ) ) {
			return $this->error_response( $this->validation_error( __( 'Category name is required.', 'learnomy' ) ) );
		}

		// Auto-generate a slug from the name if one wasn't supplied.
		if ( empty( $data['slug'] ) ) {
			$data['slug'] = sanitize_title( $data['name'] );
		}

		$category_id = Category::create( $data );
		if ( ! $category_id ) {
			return $this->error_response( $this->validation_error( __( 'Could not create the category. Please try again.', 'learnomy' ) ) );
		}

		$response = $this->single_response( Category::to_typed_array( Category::find( $category_id ) ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_category( WP_REST_Request $request ): WP_REST_Response {
		$category = Category::find( (int) $request->get_param( 'id' ) );
		if ( ! $category ) {
			return $this->error_response( $this->not_found( 'Category' ) );
		}

		$data = $this->extract_category_fields( $request );
		if ( empty( $data ) ) {
			return $this->error_response( $this->validation_error( __( 'No valid fields were provided for update.', 'learnomy' ) ) );
		}

		Category::update( (int) $category->id, $data );

		return $this->single_response( Category::to_typed_array( Category::find( (int) $category->id ) ) );
	}

	public function delete_category( WP_REST_Request $request ): WP_REST_Response {
		$category = Category::find( (int) $request->get_param( 'id' ) );
		if ( ! $category ) {
			return $this->error_response( $this->not_found( 'Category' ) );
		}

		// Deleting a category must never orphan a course (leave
		// `lrn_courses.category_id` pointing at a row that no longer exists).
		// So, like WordPress moving posts to the default category, move any
		// attached courses to Uncategorized (category_id = 0) first, then delete.
		// The UI confirms this move up front, so it is never a surprise — this
		// replaces the old hard refusal that read to users as "delete does
		// nothing" when the example courses were still in the category.
		$moved = Course::move_category( (int) $category->id, 0 );

		// ...and never orphan a CHILD category either. Lift any children to this
		// category's own parent (grandparent), or to the top level when deleting
		// a top-level category — otherwise they keep a parent_id pointing at the
		// deleted row and vanish from every listing.
		$to_parent      = (int) ( $category->parent_id ?? 0 );
		$children_moved = Category::reparent_children( (int) $category->id, $to_parent );

		if ( ! Category::delete( (int) $category->id ) ) {
			return $this->error_response( $this->validation_error( __( 'The category could not be deleted. Please try again.', 'learnomy' ) ) );
		}

		return $this->single_response(
			[
				'deleted'        => true,
				'id'             => (int) $category->id,
				'courses_moved'  => $moved,
				'children_moved' => $children_moved,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Tag callbacks
	// -------------------------------------------------------------------------

	public function get_tags( WP_REST_Request $request ): WP_REST_Response {
		// Same rule as categories above - a tag names a topic the academy
		// covers, so it is course-describing content.
		if ( $this->private_academy_blocks_anonymous() ) {
			return $this->paginated_response( array(), 0, 0, false );
		}

		$pagination = $this->get_pagination( $request );

		// Vocabulary filter — course / lesson / quiz / question. Validated
		// against the registered taxonomy types; defaults to course so existing
		// clients (which never sent `type`) keep their behaviour.
		$type = sanitize_key( (string) $request->get_param( 'type' ) );
		if ( ! in_array( $type, \Learnomy\registered_tag_types(), true ) ) {
			$type = 'course';
		}

		$tags = Tag::where( [ 'type' => $type ], 'usage_count', 'DESC', $pagination['limit'], $pagination['offset'] );

		$etag         = $this->generate_etag( $tags );
		$not_modified = $this->maybe_not_modified( $etag, $request );
		if ( $not_modified ) {
			return $not_modified;
		}

		$response = $this->paginated_response( $tags, Tag::count( [ 'type' => $type ] ), (int) $pagination['offset'] );

		return $this->add_etag( $response, $etag );
	}

	public function create_tag( WP_REST_Request $request ): WP_REST_Response {
		$data = $this->extract_tag_fields( $request );
		if ( empty( $data['name'] ) ) {
			return $this->error_response( $this->validation_error( __( 'Tag name is required.', 'learnomy' ) ) );
		}

		if ( empty( $data['slug'] ) ) {
			$data['slug'] = sanitize_title( $data['name'] );
		}

		// Prevent duplicate slugs WITHIN the same vocabulary. Tags are unique per
		// (slug, type), so the same name can exist as e.g. a Course tag and a
		// Lesson tag — a global slug check would wrongly block the second.
		$tag_type = $data['type'] ?? 'course';
		$existing = Tag::find_by_slug_and_type( $data['slug'], $tag_type );
		if ( $existing ) {
			return $this->error_response( $this->validation_error( __( 'A tag with this name already exists.', 'learnomy' ) ) );
		}

		$tag_id = Tag::create( $data );
		if ( ! $tag_id ) {
			return $this->error_response( $this->validation_error( __( 'Could not create the tag. Please try again.', 'learnomy' ) ) );
		}

		$response = $this->single_response( Tag::to_typed_array( Tag::find( $tag_id ) ) );
		$response->set_status( 201 );

		return $response;
	}

	public function update_tag( WP_REST_Request $request ): WP_REST_Response {
		$tag = Tag::find( (int) $request->get_param( 'id' ) );
		if ( ! $tag ) {
			return $this->error_response( $this->not_found( 'Tag' ) );
		}

		$data = $this->extract_tag_fields( $request );
		if ( empty( $data ) ) {
			return $this->error_response( $this->validation_error( __( 'No valid fields were provided for update.', 'learnomy' ) ) );
		}

		Tag::update( (int) $tag->id, $data );

		return $this->single_response( Tag::to_typed_array( Tag::find( (int) $tag->id ) ) );
	}

	public function delete_tag( WP_REST_Request $request ): WP_REST_Response {
		$tag = Tag::find( (int) $request->get_param( 'id' ) );
		if ( ! $tag ) {
			return $this->error_response( $this->not_found( 'Tag' ) );
		}

		if ( ! Tag::delete( (int) $tag->id ) ) {
			return $this->error_response( $this->validation_error( __( 'The tag could not be deleted. Please try again.', 'learnomy' ) ) );
		}

		// Cascade the junction rows so a deleted tag never leaves orphan
		// associations in lrn_tag_map (tags are many-to-many, unlike a category
		// which is a single FK on the course row).
		TagMap::delete_for_tag( (int) $tag->id );

		return $this->single_response(
			[
				'deleted' => true,
				'id'      => (int) $tag->id,
			]
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	public function is_admin(): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		// Delegate to Permission_Engine — the canonical entry point.
		// 'manage_categories' is admin-only; Permission_Engine short-circuits on
		// manage_options so WP admins always pass through automatically.
		if ( ! $this->check_permission( 'manage_categories' ) ) {
			return $this->permission_error();
		}

		return true;
	}

	/**
	 * Anyone who can create a course can also create the categories and tags
	 * the course needs — instructors shouldn't have to ask an admin to seed
	 * a taxonomy term they're about to use. Admins inherit this through
	 * Permission_Engine's manage_options short-circuit.
	 */
	public function can_create_taxonomy(): bool|WP_Error {
		$user_id = $this->require_auth();
		if ( is_wp_error( $user_id ) ) {
			return $user_id;
		}

		if ( ! Permission_Engine::can( $user_id, 'create_course' ) ) {
			return $this->permission_error();
		}

		return true;
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	private function extract_category_fields( WP_REST_Request $request ): array {
		$data = [];

		$name = $request->get_param( 'name' );
		if ( null !== $name ) {
			$data['name'] = sanitize_text_field( wp_unslash( $name ) );
		}

		$slug = $request->get_param( 'slug' );
		if ( null !== $slug ) {
			$data['slug'] = sanitize_title( wp_unslash( $slug ) );
		}

		$description = $request->get_param( 'description' );
		if ( null !== $description ) {
			$data['description'] = sanitize_text_field( wp_unslash( $description ) );
		}

		$parent_id = $request->get_param( 'parent_id' );
		if ( null !== $parent_id ) {
			// `parent_id` column is NOT NULL with default 0. Top-level
			// categories arrive as `0`; the previous `?: null` coerced 0 to
			// null and the INSERT was rejected as "Column 'parent_id' cannot
			// be null".
			$data['parent_id'] = absint( $parent_id );
		}

		$thumbnail_url = $request->get_param( 'thumbnail_url' );
		if ( null !== $thumbnail_url ) {
			$data['thumbnail_url'] = esc_url_raw( wp_unslash( $thumbnail_url ) );
		}

		$sort_order = $request->get_param( 'sort_order' );
		if ( null !== $sort_order ) {
			$data['sort_order'] = (int) $sort_order;
		}

		return $data;
	}

	private function extract_tag_fields( WP_REST_Request $request ): array {
		$data = [];

		$name = $request->get_param( 'name' );
		if ( null !== $name ) {
			$data['name'] = sanitize_text_field( wp_unslash( $name ) );
		}

		$slug = $request->get_param( 'slug' );
		if ( null !== $slug ) {
			$data['slug'] = sanitize_title( wp_unslash( $slug ) );
		}

		// Per-type vocabulary (course / lesson / question / …). Validated against
		// the registered taxonomy types so a tag can only land in a real
		// vocabulary; anything else is dropped and Tag::create defaults it.
		$type = $request->get_param( 'type' );
		if ( null !== $type ) {
			$type = sanitize_key( wp_unslash( (string) $type ) );
			if ( in_array( $type, \Learnomy\registered_tag_types(), true ) ) {
				$data['type'] = $type;
			}
		}

		return $data;
	}

	private function category_args( bool $name_required = true ): array {
		return [
			'name'          => [
				'type'              => 'string',
				'required'          => $name_required,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'slug'          => [
				// sanitize_title() is a multi-arg WP function — REST passes
				// (value, request, param) to sanitize_callback, which makes
				// the 2nd arg become the WP_REST_Request and crashes inside
				// remove_accents() when slug is empty. Controllers sanitize
				// slugs internally, so omit the callback here.
				'type' => 'string',
			],
			'description'   => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			],
			'parent_id'     => [
				'type'              => 'integer',
				'sanitize_callback' => 'absint',
			],
			'thumbnail_url' => [
				'type'   => 'string',
				'format' => 'uri',
			],
			'sort_order'    => [ 'type' => 'integer' ],
		];
	}

	private function tag_args( bool $name_required = true ): array {
		return [
			'name' => [
				'type'              => 'string',
				'required'          => $name_required,
				'sanitize_callback' => 'sanitize_text_field',
			],
			'slug' => [
				// See note above on the categories slug arg — same crash risk
				// when sanitize_title() is used as a REST sanitize_callback.
				'type' => 'string',
			],
			// Which content-type vocabulary the tag belongs to. Validated in
			// extract_tag_fields() against the registered taxonomy types.
			'type' => [
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_key',
			],
		];
	}
}
