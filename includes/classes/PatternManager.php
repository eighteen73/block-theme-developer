<?php
/**
 * Pattern Manager
 *
 * Handles CPT and taxonomy registration for block patterns
 *
 * @package Block Theme Developer
 */

namespace Eighteen73\BlockThemeDeveloper;

use WP_Block_Pattern_Categories_Registry;
use WP_Block_Editor_Context;
use WP_Block_Type_Registry;

/**
 * Pattern Manager class
 */
class PatternManager {

	use Singleton;

	/**
	 * Initialize the class
	 */
	public function __construct() {
		add_action( 'init', [ $this, 'register_post_type' ] );
		add_action( 'init', [ $this, 'register_meta_fields' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
		add_action( 'save_post_btd_pattern', [ $this, 'save_pattern' ], 20, 2 );
		add_action( 'rest_after_insert_btd_pattern', [ $this, 'save_pattern_rest' ], 10, 3 );

		add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
		add_action( 'admin_notices', [ $this, 'display_import_notices' ] );
		add_action( 'wp_ajax_btd_import_patterns', [ $this, 'ajax_import_patterns' ] );

		// Auto-import on plugin activation (only in development)
		add_action( 'init', [ $this, 'maybe_auto_import_patterns' ], 20 );
	}

	/**
	 * Register the btd_pattern custom post type
	 *
	 * @return void
	 */
	public function register_post_type(): void {
		$args = [
			'label'               => __( 'Patterns', 'block-theme-developer' ),
			'labels'              => [
				'name'               => __( 'Patterns', 'block-theme-developer' ),
				'singular_name'      => __( 'Pattern', 'block-theme-developer' ),
				'add_new'            => __( 'Add New Pattern', 'block-theme-developer' ),
				'add_new_item'       => __( 'Add New Pattern', 'block-theme-developer' ),
				'edit_item'          => __( 'Edit Pattern', 'block-theme-developer' ),
				'new_item'           => __( 'New Pattern', 'block-theme-developer' ),
				'view_item'          => __( 'View Pattern', 'block-theme-developer' ),
				'search_items'       => __( 'Search Patterns', 'block-theme-developer' ),
				'not_found'          => __( 'No patterns found', 'block-theme-developer' ),
				'not_found_in_trash' => __( 'No patterns found in trash', 'block-theme-developer' ),
			],
			'public'              => false,
			'publicly_queryable'  => false,
			'exclude_from_search' => true,
			'show_ui'             => true,
			'show_in_menu'        => true,
			'show_in_nav_menus'   => false,
			'show_in_admin_bar'   => true,
			'show_in_rest'        => true,
			'rest_base'           => 'btd-patterns',
			'menu_position'       => 25,
			'menu_icon'           => 'dashicons-layout',
			'capability_type'     => [ 'btd_pattern', 'btd_patterns' ],
			'map_meta_cap'        => true,
			'hierarchical'        => false,
			'supports'            => [ 'title', 'editor', 'custom-fields' ],
			'has_archive'         => false,
			'rewrite'             => false,
			'query_var'           => false,
		];

		register_post_type( 'btd_pattern', $args );
	}

	/**
	 * Register meta fields for patterns
	 *
	 * @return void
	 */
	public function register_meta_fields(): void {
		$meta_fields = [
			'_btd_description' => [
				'type'         => 'string',
				'description'  => __( 'Pattern description', 'block-theme-developer' ),
				'single'       => true,
				'default'      => '',
			],
			'_btd_categories' => [
				'type'         => 'array',
				'description'  => __( 'Pattern categories', 'block-theme-developer' ),
				'single'       => true,
				'default'      => [],
			],
			'_btd_keywords' => [
				'type'         => 'array',
				'description'  => __( 'Pattern keywords', 'block-theme-developer' ),
				'single'       => true,
				'default'      => [],
			],
			'_btd_viewport_width' => [
				'type'         => 'integer',
				'description'  => __( 'Pattern viewport width', 'block-theme-developer' ),
				'single'       => true,
				'default'      => 1280,
			],
			'_btd_block_types' => [
				'type'         => 'array',
				'description'  => __( 'Pattern block types', 'block-theme-developer' ),
				'single'       => true,
				'default'      => [],
			],
			'_btd_post_types' => [
				'type'         => 'array',
				'description'  => __( 'Pattern post types', 'block-theme-developer' ),
				'single'       => true,
				'default'      => [],
			],
			'_btd_template_types' => [
				'type'         => 'array',
				'description'  => __( 'Pattern template types', 'block-theme-developer' ),
				'single'       => true,
				'default'      => [],
			],
			'_btd_inserter' => [
				'type'         => 'boolean',
				'description'  => __( 'Show pattern in inserter', 'block-theme-developer' ),
				'single'       => true,
				'default'      => true,
			],
		];

		foreach ( $meta_fields as $meta_key => $args ) {
			$rest_schema = [
				'type'    => $args['type'],
				'default' => $args['default'],
			];

			// For array types, specify items schema
			if ( 'array' === $args['type'] ) {
				$rest_schema['items'] = [
					'type' => 'string',
				];
			}

			register_meta( 'post', $meta_key, [
				'object_subtype'    => 'btd_pattern',
				'type'              => $args['type'],
				'description'       => $args['description'],
				'single'            => $args['single'],
				'default'           => $args['default'],
				'show_in_rest'      => [
					'schema' => $rest_schema,
				],
				'auth_callback'     => function() {
					return current_user_can( 'edit_btd_patterns' );
				},
				'sanitize_callback' => $args['type'] === 'array' ? [ $this, 'sanitize_array_meta' ] : null,
			] );
		}
	}

	/**
	 * Sanitize array meta fields
	 *
	 * @param mixed $meta_value The meta value to sanitize.
	 * @return array Sanitized array.
	 */
	public function sanitize_array_meta( $meta_value ): array {
		if ( ! is_array( $meta_value ) ) {
			return [];
		}

		return array_map( 'sanitize_text_field', $meta_value );
	}

	/**
	 * Enqueue editor assets for the pattern metadata sidebar
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		// Only load on btd_pattern edit screens
		$screen = get_current_screen();
		if ( ! $screen || 'btd_pattern' !== $screen->post_type ) {
			return;
		}

		$sidebar_asset = include BLOCK_THEME_DEVELOPER_PATH . 'build/patterns.asset.php';

		if ( $sidebar_asset ) {
			wp_enqueue_script(
				'btd-pattern-sidebar',
				BLOCK_THEME_DEVELOPER_URL . 'build/patterns.js',
				$sidebar_asset['dependencies'],
				$sidebar_asset['version'],
				true
			);

			$pattern_categories = $this->get_pattern_categories_slugs();
			$post_types         = $this->get_public_post_types();
			$block_types        = $this->get_available_block_types();
			$template_types     = $this->get_available_template_types();

			wp_localize_script( 'btd-pattern-sidebar', 'btdData', [
				'patternCategories' => $pattern_categories,
				'postTypes'         => $post_types,
				'blockTypes'        => $block_types,
				'templateTypes'     => $template_types,
			] );

			// Localize script with environment data
			wp_localize_script(
				'btd-pattern-sidebar',
				'btdPatterns',
				[
					'environment' => wp_get_environment_type(),
					'mode'        => BLOCK_THEME_DEVELOPER_MODE,
				]
			);
		}
	}

	/**
	 * Get pattern categories as slugs
	 *
	 * @return array Array of category slugs.
	 */
	private function get_pattern_categories_slugs(): array {
		$wp_categories = WP_Block_Pattern_Categories_Registry::get_instance()->get_all_registered();

		// If it's a numerically indexed array, extract the 'name' field from each category
		if ( is_array( $wp_categories ) && ! empty( $wp_categories ) ) {
			$first_item = reset( $wp_categories );
			if ( is_array( $first_item ) && isset( $first_item['name'] ) ) {
				// It's an array of category objects, extract the 'name' field
				return array_column( $wp_categories, 'name' );
			} else {
				// It's an associative array, use the keys
				return array_keys( $wp_categories );
			}
		}

		return [];
	}

	/**
	 * Get public post types as slugs
	 *
	 * @return array Array of post type slugs.
	 */
	private function get_public_post_types(): array {
		$post_types = get_post_types( [ 'public' => true ], 'names' );
		unset( $post_types['attachment'] );
		return array_values( $post_types );
	}

	/**
	 * Get all available block types including template part areas
	 *
	 * @return array Array of block type slugs.
	 */
	public function get_available_block_types(): array {
		$block_types = [];

		// Create block editor context for site editor to get all available blocks
		$context = new WP_Block_Editor_Context( [ 'name' => 'core/edit-site' ] );

		// Get all allowed block types for the site editor context
		$allowed_blocks = get_allowed_block_types( $context );

		if ( is_array( $allowed_blocks ) ) {
			// If we get an array, use the keys
			$block_types = array_keys( $allowed_blocks );
		} elseif ( true === $allowed_blocks ) {
			// If all blocks are allowed (returns true), get all registered block types
			$registry    = WP_Block_Type_Registry::get_instance();
			$block_types = array_keys( $registry->get_all_registered() );
		}

		// Add template part areas
		$template_part_areas = get_allowed_block_template_part_areas();

		foreach ( $template_part_areas as $area ) {
			if ( isset( $area['area'] ) ) {
				// Handle "uncategorized" area specially - use just "core/template-part"
				if ( 'uncategorized' === $area['area'] ) {
					$block_types[] = 'core/template-part';
				} else {
					$block_types[] = 'core/template-part/' . $area['area'];
				}
			}
		}

		// Remove duplicates and sort
		$block_types = array_unique( $block_types );
		sort( $block_types );

		return $block_types;
	}

		/**
	 * Get all available block template types
	 *
	 * @return array Array of block template type slugs.
	 */
	public function get_available_template_types(): array {
		$template_types = get_default_block_template_types();

		return $template_types;
	}

	/**
	 * Handle pattern save operations for REST API requests
	 *
	 * @param WP_Post         $post The post object.
	 * @param WP_REST_Request $request The REST request.
	 * @param bool            $creating Whether this is creating a new post.
	 */
	public function save_pattern_rest( $post, $request, $creating ): void {
		if ( 'btd_pattern' !== $post->post_type ) {
			return;
		}

		// Handle file mode
		if ( 'file' === BLOCK_THEME_DEVELOPER_MODE ) {
			FileOperations::instance()->save_pattern_to_file( $post->ID );
		}
	}

	/**
	 * Handle pattern save operations
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post The post object.
	 */
	public function save_pattern( int $post_id, $post ): void {
		// Skip if this is an autosave
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Skip if this is a REST API request (handled by save_pattern_rest)
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		// Skip if user doesn't have permission
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Skip if this is not a pattern
		if ( 'btd_pattern' !== $post->post_type ) {
			return;
		}

		// Skip if this is an auto-draft or draft
		if ( in_array( $post->post_status, [ 'auto-draft', 'draft' ], true ) ) {
			return;
		}

		// Only process published or updated posts
		if ( ! in_array( $post->post_status, [ 'publish', 'private' ], true ) ) {
			return;
		}

		// Handle file mode
		if ( 'file' === BLOCK_THEME_DEVELOPER_MODE ) {
			FileOperations::instance()->save_pattern_to_file( $post_id );
		}
	}

	/**
	 * Get pattern metadata fields
	 *
	 * @return array
	 */
	public static function get_metadata_fields(): array {
		return [
			'_btd_description'     => '',
			'_btd_categories'      => [],
			'_btd_keywords'        => [],
			'_btd_viewport_width'  => 1280,
			'_btd_block_types'     => [],
			'_btd_post_types'      => [],
			'_btd_template_types'  => [],
			'_btd_inserter'        => true,
		];
	}

	/**
	 * Get pattern metadata for a given post ID
	 *
	 * @param int $post_id The post ID.
	 * @return array
	 */
	public static function get_pattern_metadata( int $post_id ): array {
		$defaults = self::get_metadata_fields();
		$metadata = [];

		foreach ( $defaults as $key => $default_value ) {
			$value = get_post_meta( $post_id, $key, true );

			// Handle array fields - use actual value if it exists, even if empty
			if ( in_array( $key, [ '_btd_keywords', '_btd_block_types', '_btd_post_types', '_btd_template_types', '_btd_categories' ], true ) ) {
				$metadata[ $key ] = $value !== '' ? (array) $value : $default_value;
			} else {
				// For non-array fields, use actual value if it exists, even if empty
				$metadata[ $key ] = $value !== '' ? $value : $default_value;
			}
		}

		return $metadata;
	}

	/**
	 * Add admin menu for pattern management
	 *
	 * @return void
	 */
	public function add_admin_menu(): void {
		add_submenu_page(
			'edit.php?post_type=btd_pattern',
			__( 'Import Theme Patterns', 'block-theme-developer' ),
			__( 'Import Patterns', 'block-theme-developer' ),
			'edit_btd_patterns',
			'btd-import-patterns',
			[ $this, 'admin_page_import_patterns' ]
		);
	}

	/**
	 * Display admin notices for import results
	 *
	 * @return void
	 */
	public function display_import_notices(): void {
		// Only show notices on the import page
		$current_screen = get_current_screen();
		if ( ! $current_screen || 'btd_pattern_page_btd-import-patterns' !== $current_screen->id ) {
			return;
		}

		// Check for import results in URL parameters (from redirect)
		if ( isset( $_GET['import_success'] ) ) {
			$count = intval( $_GET['import_success'] );
			echo '<div class="notice notice-success is-dismissible"><p>' .
				sprintf( esc_html__( 'Successfully imported %d patterns', 'block-theme-developer' ), $count ) .
				'</p></div>';
		}

		if ( isset( $_GET['import_errors'] ) ) {
			$errors = explode( '|', sanitize_text_field( $_GET['import_errors'] ) );
			echo '<div class="notice notice-error is-dismissible"><p>' .
				esc_html__( 'Some patterns failed to import:', 'block-theme-developer' ) .
				'<br>' . implode( '<br>', array_map( 'esc_html', $errors ) ) .
				'</p></div>';
		}
	}

	/**
	 * Admin page for pattern import
	 *
	 * @return void
	 */
	public function admin_page_import_patterns(): void {
		$theme_patterns = FileOperations::instance()->get_theme_pattern_files();
		$existing_count = 0;

		// Check which patterns already exist
		foreach ( $theme_patterns as $pattern_file ) {
			$pattern_name  = basename( $pattern_file, '.php' );
			$existing_post = get_page_by_path( $pattern_name, OBJECT, 'btd_pattern' );
			if ( $existing_post ) {
				$existing_count++;
			}
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Import Theme Patterns', 'block-theme-developer' ); ?></h1>

			<div class="notice notice-info">
				<p><?php esc_html_e( 'This tool imports existing pattern files from your active theme into the database for editing.', 'block-theme-developer' ); ?></p>
			</div>

			<?php if ( empty( $theme_patterns ) ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'No pattern files found in your active theme.', 'block-theme-developer' ); ?></p>
				</div>
			<?php else : ?>
				<div class="btd-import-stats">
					<p>
						<strong><?php printf( esc_html__( 'Found %d pattern files in theme', 'block-theme-developer' ), count( $theme_patterns ) ); ?></strong><br>
						<?php if ( $existing_count > 0 ) : ?>
							<?php printf( esc_html__( '%d patterns already imported', 'block-theme-developer' ), $existing_count ); ?>
						<?php endif; ?>
					</p>
				</div>

				<form method="post" action="">
					<?php wp_nonce_field( 'btd_import_patterns', 'btd_import_nonce' ); ?>

					<table class="wp-list-table widefat fixed striped">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Pattern File', 'block-theme-developer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Status', 'block-theme-developer' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Action', 'block-theme-developer' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $theme_patterns as $pattern_file ) : ?>
								<?php
								$pattern_name  = basename( $pattern_file, '.php' );
								$existing_post = get_page_by_path( $pattern_name, OBJECT, 'btd_pattern' );
								?>
								<tr>
									<td><code><?php echo esc_html( basename( $pattern_file ) ); ?></code></td>
									<td>
										<?php if ( $existing_post ) : ?>
											<span class="dashicons dashicons-yes-alt" style="color: green;"></span>
											<?php esc_html_e( 'Imported', 'block-theme-developer' ); ?>
										<?php else : ?>
											<span class="dashicons dashicons-minus" style="color: #ccc;"></span>
											<?php esc_html_e( 'Not imported', 'block-theme-developer' ); ?>
										<?php endif; ?>
									</td>
									<td>
										<label>
											<input type="checkbox" name="import_patterns[]" value="<?php echo esc_attr( $pattern_file ); ?>"
												<?php checked( ! $existing_post ); ?>>
											<?php $existing_post ? esc_html_e( 'Re-import', 'block-theme-developer' ) : esc_html_e( 'Import', 'block-theme-developer' ); ?>
										</label>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<p class="submit">
						<button type="button" id="btd-select-all" class="button"><?php esc_html_e( 'Select All', 'block-theme-developer' ); ?></button>
						<button type="button" id="btd-select-none" class="button"><?php esc_html_e( 'Select None', 'block-theme-developer' ); ?></button>
						<input type="submit" name="import_patterns_submit" class="button button-primary" value="<?php esc_attr_e( 'Import Selected Patterns', 'block-theme-developer' ); ?>">
					</p>
				</form>

				<script>
				document.getElementById('btd-select-all').addEventListener('click', function() {
					document.querySelectorAll('input[name="import_patterns[]"]').forEach(cb => cb.checked = true);
				});
				document.getElementById('btd-select-none').addEventListener('click', function() {
					document.querySelectorAll('input[name="import_patterns[]"]').forEach(cb => cb.checked = false);
				});
				</script>
			<?php endif; ?>
		</div>
		<?php

		// Handle form submission
		if ( isset( $_POST['import_patterns_submit'] ) && wp_verify_nonce( $_POST['btd_import_nonce'], 'btd_import_patterns' ) ) {
			$patterns_to_import = $_POST['import_patterns'] ?? [];
			$import_results     = $this->import_pattern_files( $patterns_to_import );

			// Build redirect URL with results
			$redirect_url = add_query_arg( [
				'post_type' => 'btd_pattern',
				'page'      => 'btd-import-patterns',
			], admin_url( 'edit.php' ) );

			if ( ! empty( $import_results['success'] ) ) {
				$redirect_url = add_query_arg( 'import_success', count( $import_results['success'] ), $redirect_url );
			}

			if ( ! empty( $import_results['errors'] ) ) {
				$redirect_url = add_query_arg( 'import_errors', implode( '|', $import_results['errors'] ), $redirect_url );
			}

			// Redirect to prevent form resubmission
			wp_redirect( $redirect_url );
			exit;
		}
	}

	/**
	 * Maybe auto-import patterns on plugin activation
	 *
	 * @return void
	 */
	public function maybe_auto_import_patterns(): void {
		// Only auto-import in development environments and if no patterns exist yet
		if ( ! in_array( wp_get_environment_type(), [ 'development', 'local' ], true ) ) {
			return;
		}

		// Check if we've already auto-imported
		if ( get_option( 'btd_auto_imported_patterns', false ) ) {
			return;
		}

		// Check if any patterns already exist
		$existing_patterns = get_posts( [
			'post_type'      => 'btd_pattern',
			'posts_per_page' => 1,
			'post_status'    => 'any',
		] );

		if ( ! empty( $existing_patterns ) ) {
			update_option( 'btd_auto_imported_patterns', true );
			return;
		}

		// Get theme pattern files
		$theme_patterns = FileOperations::instance()->get_theme_pattern_files();

		if ( ! empty( $theme_patterns ) ) {
			$this->import_pattern_files( $theme_patterns );
			update_option( 'btd_auto_imported_patterns', true );
		}
	}

	/**
	 * Import pattern files into database
	 *
	 * @param array $pattern_files Array of pattern file paths.
	 * @return array Import results with success and error arrays.
	 */
	public function import_pattern_files( array $pattern_files ): array {
		$results = [
			'success' => [],
			'errors'  => [],
		];

		foreach ( $pattern_files as $pattern_file ) {
			try {
				$pattern_data = $this->parse_pattern_file( $pattern_file );

				if ( ! $pattern_data ) {
					$results['errors'][] = sprintf( 'Could not parse pattern file: %s', basename( $pattern_file ) );
					continue;
				}

				$post_id = $this->create_pattern_from_data( $pattern_data );

				if ( is_wp_error( $post_id ) ) {
					$results['errors'][] = sprintf( 'Failed to create pattern %s: %s', $pattern_data['title'], $post_id->get_error_message() );
				} else {
					$results['success'][] = $pattern_data['title'];
				}
			} catch ( \Exception $e ) {
				$results['errors'][] = sprintf( 'Error importing %s: %s', basename( $pattern_file ), $e->getMessage() );
			}
		}

		return $results;
	}

	/**
	 * Parse a pattern file to extract metadata and content
	 *
	 * @param string $file_path Path to pattern file.
	 * @return array|false Pattern data or false on failure.
	 */
	private function parse_pattern_file( string $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		$file_content = file_get_contents( $file_path );
		if ( false === $file_content ) {
			return false;
		}

		// Extract the header comment
		if ( ! preg_match( '/^<\?php\s*\/\*\*(.*?)\*\//s', $file_content, $matches ) ) {
			return false;
		}

		$header = $matches[1];

		// Parse header fields
		$pattern_data = [
			'title'           => '',
			'description'     => '',
			'categories'      => [],
			'keywords'        => [],
			'viewport_width'  => 1280,
			'block_types'     => [],
			'post_types'      => [],
			'template_types'  => [],
			'inserter'        => true,
			'content'         => '',
		];

		// Extract content (everything after the PHP closing tag)
		if ( preg_match( '/\?>\s*(.*)/s', $file_content, $content_matches ) ) {
			$pattern_data['content'] = trim( $content_matches[1] );
		}

		// Parse header lines
		$header_lines = explode( "\n", $header );
		foreach ( $header_lines as $line ) {
			$line = trim( $line, " \t*" );
			if ( empty( $line ) ) {
				continue;
			}

			if ( strpos( $line, ':' ) === false ) {
				continue;
			}

			list( $key, $value ) = explode( ':', $line, 2 );
			$key = trim( $key );
			$value = trim( $value );


			switch ( strtolower( str_replace( ' ', '', $key ) ) ) {
				case 'title':
					$pattern_data['title'] = $value;
					break;
				case 'description':
					$pattern_data['description'] = $value;
					break;
				case 'categories':
					$pattern_data['categories'] = $this->parse_comma_separated_field( $value );
					break;
				case 'keywords':
					$pattern_data['keywords'] = $this->parse_comma_separated_field( $value );
					break;
				case 'viewportwidth':
					$pattern_data['viewport_width'] = ! empty( $value ) ? (int) $value : 1280;
					break;
				case 'blocktypes':
					$pattern_data['block_types'] = $this->parse_comma_separated_field( $value );
					break;
				case 'posttypes':
					$pattern_data['post_types'] = $this->parse_comma_separated_field( $value );
					break;
				case 'templatetypes':
					$pattern_data['template_types'] = $this->parse_comma_separated_field( $value );
					break;
				case 'inserter':
					$pattern_data['inserter'] = ! in_array( strtolower( $value ), [ 'no', 'false', '0' ], true );
					break;
			}
		}


		return $pattern_data;
	}

	/**
	 * Parse a comma-separated field, handling empty values correctly
	 *
	 * @param string $value The field value.
	 * @return array Array of trimmed values, empty array if value is empty.
	 */
	private function parse_comma_separated_field( string $value ): array {
		$value = trim( $value );
		if ( empty( $value ) ) {
			return [];
		}

		$items = array_map( 'trim', explode( ',', $value ) );
		return array_filter( $items ); // Remove any empty items
	}

	/**
	 * Create a pattern post from parsed data
	 *
	 * @param array $pattern_data Pattern data array.
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	private function create_pattern_from_data( array $pattern_data ) {
		$pattern_name = sanitize_title( $pattern_data['title'] );

		// Check if pattern already exists
		$existing_post = get_page_by_path( $pattern_name, OBJECT, 'btd_pattern' );

		$post_data = [
			'post_title'   => $pattern_data['title'],
			'post_content' => $pattern_data['content'],
			'post_status'  => 'publish',
			'post_type'    => 'btd_pattern',
			'post_name'    => $pattern_name,
		];

		if ( $existing_post ) {
			$post_data['ID'] = $existing_post->ID;
			$post_id = wp_update_post( $post_data );
		} else {
			$post_id = wp_insert_post( $post_data );
		}

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Set metadata - always set all fields for consistency
		update_post_meta( $post_id, '_btd_description', $pattern_data['description'] );
		update_post_meta( $post_id, '_btd_keywords', $pattern_data['keywords'] );
		update_post_meta( $post_id, '_btd_viewport_width', $pattern_data['viewport_width'] );
		update_post_meta( $post_id, '_btd_block_types', $pattern_data['block_types'] );
		update_post_meta( $post_id, '_btd_post_types', $pattern_data['post_types'] );
		update_post_meta( $post_id, '_btd_template_types', $pattern_data['template_types'] );
		update_post_meta( $post_id, '_btd_inserter', $pattern_data['inserter'] );
		update_post_meta( $post_id, '_btd_categories', $pattern_data['categories'] );

		// Generate the pattern file after metadata is saved
		if ( 'file' === BLOCK_THEME_DEVELOPER_MODE ) {
			FileOperations::instance()->save_pattern_to_file( $post_id );
		}

		return $post_id;
	}

	/**
	 * AJAX handler for pattern import
	 *
	 * @return void
	 */
	public function ajax_import_patterns(): void {
		check_ajax_referer( 'btd_import_patterns', 'nonce' );

		if ( ! current_user_can( 'edit_btd_patterns' ) ) {
			wp_die( 'Unauthorized' );
		}

		$pattern_files = $_POST['pattern_files'] ?? [];
		$results = $this->import_pattern_files( $pattern_files );

		wp_send_json_success( $results );
	}
}
