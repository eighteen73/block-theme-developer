<?php
/**
 * Pattern Manager
 *
 * Handles wp_block pattern metadata and editor assets.
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
		add_action( 'init', [ $this, 'register_meta_fields' ] );
		add_action( 'rest_api_init', [ $this, 'register_rest_fields' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
	}

	/**
	 * Check if the plugin is in file mode
	 *
	 * @return bool True if in file mode, false otherwise
	 */
	public static function is_file_mode(): bool {
		return 'file' === BLOCK_THEME_DEVELOPER_MODE;
	}

	/**
	 * Check if the plugin is in API mode
	 *
	 * @return bool True if in API mode, false otherwise
	 */
	public static function is_api_mode(): bool {
		return 'api' === BLOCK_THEME_DEVELOPER_MODE;
	}

	/**
	 * Handle plugin activation
	 *
	 * @return void
	 */
	public static function on_activation(): void {
		flush_rewrite_rules();
	}

	/**
	 * Register REST fields for wp_block patterns.
	 *
	 * @return void
	 */
	public function register_rest_fields(): void {
		register_rest_field(
			'wp_block',
			'btd_synced_locked',
			[
				'get_callback' => static function ( $object ): bool {
					$post_id = (int) ( $object['id'] ?? 0 );

					return $post_id > 0 && '1' === (string) get_post_meta( $post_id, '_orbit_managed_theme_pattern', true );
				},
				'schema'       => [
					'description' => __( 'Whether the synced theme pattern setting is locked after Orbit has claimed the pattern.', 'block-theme-developer' ),
					'type'        => 'boolean',
					'context'     => [ 'view', 'edit' ],
					'readonly'    => true,
				],
			]
		);
	}

	/**
	 * Register meta fields for wp_block patterns
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
			'_btd_synced' => [
				'type'         => 'boolean',
				'description'  => __( 'Export as a synced theme pattern (Orbit)', 'block-theme-developer' ),
				'single'       => true,
				'default'      => false,
			],
		];

		foreach ( $meta_fields as $meta_key => $args ) {
			$rest_schema = [
				'type'    => $args['type'],
				'default' => $args['default'],
			];

			if ( 'array' === $args['type'] ) {
				$rest_schema['items'] = [
					'type' => 'string',
				];
			}

			register_meta(
				'post',
				$meta_key,
				[
					'object_subtype'    => 'wp_block',
					'type'              => $args['type'],
					'description'       => $args['description'],
					'single'            => $args['single'],
					'default'           => $args['default'],
					'show_in_rest'      => [
						'schema' => $rest_schema,
					],
					'auth_callback'     => static function () {
						return current_user_can( 'edit_posts' );
					},
					'sanitize_callback' => 'array' === $args['type'] ? [ $this, 'sanitize_array_meta' ] : null,
				]
			);
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
	 * Enqueue editor assets for the pattern metadata sidebar.
	 *
	 * Loads for wp_block screens and the Site Editor.
	 *
	 * @return void
	 */
	public function enqueue_editor_assets(): void {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$is_wp_block    = 'wp_block' === $screen->post_type;
		$is_site_editor = 'site-editor' === $screen->id || 'appearance_page_gutenberg-edit-site' === $screen->id;

		if ( ! $is_wp_block && ! $is_site_editor ) {
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

			wp_localize_script(
				'btd-pattern-sidebar',
				'btdData',
				[
					'patternCategories' => $pattern_categories,
					'postTypes'         => $post_types,
					'blockTypes'        => $block_types,
					'templateTypes'     => $template_types,
				]
			);

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
	 * Get pattern metadata fields
	 *
	 * @return array
	 */
	public static function get_metadata_fields(): array {
		return [
			'_btd_description'       => '',
			'_btd_categories'        => [],
			'_btd_keywords'          => [],
			'_btd_viewport_width'    => 1280,
			'_btd_block_types'       => [],
			'_btd_post_types'        => [],
			'_btd_template_types'    => [],
			'_btd_inserter'          => true,
			'_btd_synced'            => false,
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
}
