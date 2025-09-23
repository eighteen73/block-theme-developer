<?php
/**
 * REST API
 *
 * Handles REST API endpoints for pattern management
 *
 * @package Block Theme Developer
 */

namespace Eighteen73\BlockThemeDeveloper;

/**
 * REST API class
 */
class RestApi {

	use Singleton;

	/**
	 * API namespace
	 */
	const NAMESPACE = 'btd/v1';

	/**
	 * Initialize the class
	 */
	public function __construct() {
		// Only register routes and setup capabilities when in API mode
		if ( 'api' === BLOCK_THEME_DEVELOPER_MODE ) {
			add_action( 'rest_api_init', [ $this, 'register_routes' ] );
			add_action( 'init', [ $this, 'setup_api_capabilities' ] );
		}
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes(): void {
		// Patterns endpoint - exposes all pattern data for external access
		register_rest_route(
			self::NAMESPACE,
			'/patterns',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_patterns' ],
				'permission_callback' => [ $this, 'check_permissions' ],
				'args'                => [
					'page'     => [
						'default'           => 1,
						'sanitize_callback' => 'absint',
					],
					'per_page' => [
						'default'           => 100, // Higher default for external access
						'sanitize_callback' => 'absint',
					],
					'search'   => [
						'sanitize_callback' => 'sanitize_text_field',
					],
					'category' => [
						'sanitize_callback' => 'sanitize_text_field',
					],
				],
			]
		);

		// Application Passwords info endpoint (no auth required for this one)
		register_rest_route(
			self::NAMESPACE,
			'/auth-info',
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_auth_info' ],
				'permission_callback' => '__return_true', // Public endpoint for auth info
			]
		);
	}

	/**
	 * Setup API capabilities and role when in API mode
	 *
	 * @return void
	 */
	public function setup_api_capabilities(): void {
		$capability = 'btd_api_access';
		$admin_role = get_role( 'administrator' );

		if ( $admin_role ) {
			$admin_role->add_cap( $capability );
		}

		$api_user_role = get_role( 'api_user' );

		if ( ! $api_user_role ) {
			add_role(
				'api_user',
				__( 'API User', 'block-theme-developer' ),
				[
					'read' => true,
					$capability => true,
				]
			);
		} else {
			$api_user_role->add_cap( $capability );
		}
	}

	/**
	 * Check permissions for API access using Application Passwords
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return bool|WP_Error True if user has permission, false or WP_Error otherwise.
	 */
	public function check_permissions( $request ) {
		// Only allow API access when in API mode
		if ( 'api' !== BLOCK_THEME_DEVELOPER_MODE ) {
			return new \WP_Error(
				'rest_api_disabled',
				__( 'REST API is only available in API mode.', 'block-theme-developer' ),
				[ 'status' => 403 ]
			);
		}

		// Check if Application Passwords are available
		if ( ! function_exists( 'wp_is_application_passwords_available' ) || ! wp_is_application_passwords_available() ) {
			return new \WP_Error(
				'application_passwords_unavailable',
				__( 'Application Passwords are not available on this site.', 'block-theme-developer' ),
				[ 'status' => 501 ]
			);
		}

		// Get the current user from the request
		$user = wp_get_current_user();

		// If no user is authenticated, return error
		if ( ! $user || ! $user->exists() ) {
			return new \WP_Error(
				'rest_not_authenticated',
				__( 'Authentication required. Please use WordPress Application Passwords.', 'block-theme-developer' ),
				[ 'status' => 401 ]
			);
		}

		// Check if user has API access capability
		if ( ! current_user_can( 'btd_api_access' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'Insufficient permissions. User must have btd_api_access capability.', 'block-theme-developer' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Get information about Application Passwords setup
	 *
	 * @return array Information about Application Passwords.
	 */
	public function get_application_passwords_info(): array {
		return [
			'available' => function_exists( 'wp_is_application_passwords_available' ) && wp_is_application_passwords_available(),
			'admin_url' => admin_url( 'profile.php#application-passwords-section' ),
			'instructions' => [
				__( '1. Go to Users > Profile in your WordPress admin', 'block-theme-developer' ),
				__( '2. Scroll down to the "Application Passwords" section', 'block-theme-developer' ),
				__( '3. Enter a name for your application (e.g., "Client Site")', 'block-theme-developer' ),
				__( '4. Click "Add New Application Password"', 'block-theme-developer' ),
				__( '5. Copy the generated username and password', 'block-theme-developer' ),
				__( '6. Use these credentials for HTTP Basic Authentication', 'block-theme-developer' ),
			],
		];
	}

	/**
	 * Get authentication information
	 *
	 * @return WP_REST_Response Response object.
	 */
	public function get_auth_info() {
		$info = $this->get_application_passwords_info();
		return rest_ensure_response( $info );
	}

	/**
	 * Get patterns
	 *
	 * @param WP_REST_Request $request The request object.
	 * @return WP_REST_Response|WP_Error Response object or WP_Error.
	 */
	public function get_patterns( $request ) {
		$page     = $request->get_param( 'page' );
		$per_page = $request->get_param( 'per_page' );
		$search   = $request->get_param( 'search' );
		$category = $request->get_param( 'category' );

		// Build query args
		$args = [
			'post_type'      => 'btd_pattern',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'title',
			'order'          => 'ASC',
		];

		// Add search if provided
		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		// Add category filter if provided
		if ( ! empty( $category ) ) {
			$args['meta_query'] = [
				[
					'key'     => '_btd_categories',
					'value'   => $category,
					'compare' => 'LIKE',
				],
			];
		}

		$query = new \WP_Query( $args );
		$patterns = [];

		foreach ( $query->posts as $post ) {
			$patterns[] = $this->format_pattern_data( $post );
		}

		$response = rest_ensure_response( $patterns );
		$response->header( 'X-WP-Total', $query->found_posts );
		$response->header( 'X-WP-TotalPages', $query->max_num_pages );

		return $response;
	}

	/**
	 * Format pattern data for API response
	 *
	 * @param WP_Post $post The post object.
	 * @return array Formatted pattern data.
	 */
	private function format_pattern_data( $post ): array {
		$metadata = PatternManager::get_pattern_metadata( $post->ID );

		// Format the response with all metadata for external access
		$pattern_data = [
			'id'            => $post->ID,
			'name'          => $post->post_name,
			'title'         => $post->post_title,
			'content'       => $post->post_content,
			'description'   => $metadata['_btd_description'],
			'categories'    => $this->ensure_array( $metadata['_btd_categories'] ),
			'keywords'      => $this->ensure_array( $metadata['_btd_keywords'] ),
			'viewportWidth' => (int) $metadata['_btd_viewport_width'],
			'blockTypes'    => $this->ensure_array( $metadata['_btd_block_types'] ),
			'postTypes'     => $this->ensure_array( $metadata['_btd_post_types'] ),
			'templateTypes' => $this->ensure_array( $metadata['_btd_template_types'] ),
			'inserter'      => (bool) $metadata['_btd_inserter'],
			'lastUpdated'   => $post->post_modified,
			'created'       => $post->post_date,
		];

		return $pattern_data;
	}

	/**
	 * Ensure value is an array
	 *
	 * @param mixed $value The value to check.
	 * @return array The value as an array.
	 */
	private function ensure_array( $value ): array {
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( empty( $value ) ) {
			return [];
		}

		// Handle comma-separated strings
		if ( is_string( $value ) ) {
			return array_filter( array_map( 'trim', explode( ',', $value ) ) );
		}

		return [ $value ];
	}
}
