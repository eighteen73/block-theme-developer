<?php
/**
 * File Operations
 *
 * Handles file operations for pattern management
 *
 * @package Block Theme Developer
 */

namespace Eighteen73\BlockThemeDeveloper;

/**
 * File Operations class
 */
class FileOperations {

	use Singleton;

	/**
	 * Post meta: theme-namespaced slug for a BTD-authored pattern file.
	 */
	public const META_THEME_SLUG = '_btd_theme_pattern_slug';

	/**
	 * Post meta: md5 of the last theme pattern file contents written or imported.
	 */
	public const META_FILE_HASH = '_btd_pattern_file_hash';

	/**
	 * Cached theme package tag from functions.php.
	 *
	 * @var string|null|false Null when uncached, false when not found, string when found.
	 */
	private static $theme_package_tag = null;

	/**
	 * Whether theme files are currently being imported into wp_block posts.
	 *
	 * @var bool
	 */
	private static bool $syncing_from_files = false;

	/**
	 * Initialize the class
	 */
	public function __construct() {
		// Constructor intentionally empty
	}

	/**
	 * Save a wp_block pattern to a theme PHP file.
	 *
	 * Uses a stable slug-based filename. When $synced is true, emits Synced: true
	 * and claims the post for Orbit. When false, omits the Synced header.
	 *
	 * @param int  $post_id The wp_block post ID.
	 * @param bool $synced  Whether to export as a synced theme pattern.
	 * @return bool True on success, false on failure.
	 */
	public function save_wp_block_pattern_to_file( int $post_id, bool $synced = true ): bool {
		$post = get_post( $post_id );
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return false;
		}

		$patterns_dir = $this->get_theme_patterns_directory();
		if ( ! $this->ensure_directory_exists( $patterns_dir ) ) {
			return false;
		}

		$full_slug  = $this->resolve_wp_block_pattern_slug( $post );
		$short_slug = $this->normalize_pattern_slug( $full_slug );
		$file_name  = $short_slug !== '' ? $short_slug : ( 'pattern-' . $post_id );
		$file_path  = $patterns_dir . $file_name . '.php';

		$this->hydrate_wp_block_meta_from_theme_file( $post_id );

		$metadata   = PatternManager::get_pattern_metadata( $post_id );
		$categories = $this->resolve_wp_block_categories( $post_id, $metadata );

		$metadata['_btd_synced'] = $synced;

		$post_for_export            = clone $post;
		$post_for_export->post_name = $this->normalize_pattern_slug( $full_slug );

		$file_content = $this->generate_pattern_file_content( $post_for_export, $metadata, $categories, $full_slug );

		$written = $this->write_file( $file_path, $file_content );

		if ( $written ) {
			update_post_meta( $post_id, self::META_THEME_SLUG, $full_slug );
			update_post_meta( $post_id, self::META_FILE_HASH, md5( $file_content ) );
		}

		if ( $written && $synced ) {
			$this->claim_wp_block_for_orbit( $post_id, $full_slug, (string) $post->post_content );
		}

		return $written;
	}

	/**
	 * Whether wp_block posts are being created/updated from theme pattern files.
	 *
	 * @return bool
	 */
	public function is_syncing_from_files(): bool {
		return self::$syncing_from_files;
	}

	/**
	 * Keep unsynced theme pattern files editable as wp_block posts.
	 *
	 * Creates missing posts after a database import, updates posts when the
	 * theme file changes, and unregisters the locked theme-registry copy.
	 *
	 * @return void
	 */
	public function sync_unsynced_theme_patterns(): void {
		if ( self::$syncing_from_files ) {
			return;
		}

		$registry = \WP_Block_Patterns_Registry::get_instance();

		foreach ( $this->get_theme_pattern_files() as $file ) {
			$pattern = $this->parse_theme_pattern_file( $file );

			if ( ! $pattern ) {
				continue;
			}

			$post_id = $this->ensure_unsynced_pattern_post( $pattern );

			if ( $post_id && $registry->is_registered( $pattern['slug'] ) ) {
				$registry->unregister( $pattern['slug'] );
			}
		}
	}

	/**
	 * Parse an unsynced theme pattern PHP file.
	 *
	 * Synced files are left to Orbit.
	 *
	 * @param string $file Absolute path.
	 * @return array<string, mixed>|null
	 */
	public function parse_theme_pattern_file( string $file ): ?array {
		$headers = get_file_data(
			$file,
			[
				'title'         => 'Title',
				'slug'          => 'Slug',
				'description'   => 'Description',
				'viewportWidth' => 'Viewport Width',
				'inserter'      => 'Inserter',
				'categories'    => 'Categories',
				'keywords'      => 'Keywords',
				'blockTypes'    => 'Block Types',
				'postTypes'     => 'Post Types',
				'templateTypes' => 'Template Types',
				'synced'        => 'Synced',
			]
		);

		if ( $this->is_header_truthy( $headers['synced'] ?? '' ) ) {
			return null;
		}

		if ( empty( $headers['slug'] ) || empty( $headers['title'] ) ) {
			return null;
		}

		$content = $this->render_pattern_file( $file );

		if ( '' === trim( $content ) ) {
			return null;
		}

		$pattern = [
			'title'   => $headers['title'],
			'slug'    => $headers['slug'],
			'content' => $content,
			'file'    => $file,
		];

		if ( ! empty( $headers['description'] ) ) {
			$pattern['description'] = $headers['description'];
		}

		if ( ! empty( $headers['viewportWidth'] ) ) {
			$pattern['viewportWidth'] = (int) $headers['viewportWidth'];
		}

		$pattern['inserter'] = ! in_array( strtolower( (string) $headers['inserter'] ), [ 'no', 'false', '0' ], true );

		foreach ( [ 'categories', 'keywords', 'blockTypes', 'postTypes', 'templateTypes' ] as $list_key ) {
			if ( empty( $headers[ $list_key ] ) ) {
				continue;
			}

			$pattern[ $list_key ] = array_values(
				array_filter( array_map( 'trim', explode( ',', $headers[ $list_key ] ) ) )
			);
		}

		return $pattern;
	}

	/**
	 * Create or update the wp_block for an unsynced theme pattern file.
	 *
	 * @param array<string, mixed> $pattern Parsed pattern data.
	 * @return int Post ID, or 0 on failure.
	 */
	public function ensure_unsynced_pattern_post( array $pattern ): int {
		$post      = $this->find_unsynced_pattern_post( (string) $pattern['slug'] );
		$file_hash = is_readable( $pattern['file'] ) ? (string) md5_file( $pattern['file'] ) : '';

		self::$syncing_from_files = true;

		try {
			if ( $post instanceof \WP_Post ) {
				if ( $this->is_orbit_managed_post( (int) $post->ID ) ) {
					$this->unclaim_orbit_managed_post( (int) $post->ID );
				}

				$stored_hash = (string) get_post_meta( $post->ID, self::META_FILE_HASH, true );

				if ( '' !== $file_hash && $stored_hash === $file_hash ) {
					update_post_meta( $post->ID, self::META_THEME_SLUG, $pattern['slug'] );
					update_post_meta( $post->ID, 'wp_pattern_sync_status', 'unsynced' );
					update_post_meta( $post->ID, '_btd_synced', false );
					delete_post_meta( $post->ID, '_orbit_managed_theme_pattern' );
					delete_post_meta( $post->ID, '_orbit_pattern_content_hash' );
					$this->hydrate_wp_block_meta_from_theme_file( (int) $post->ID );
					$this->trash_duplicate_unsynced_posts( (string) $pattern['slug'], (int) $post->ID );
					return (int) $post->ID;
				}

				$updated = wp_update_post(
					[
						'ID'           => $post->ID,
						'post_title'   => $pattern['title'],
						'post_content' => $pattern['content'],
						'post_status'  => 'publish',
					],
					true
				);

				if ( is_wp_error( $updated ) ) {
					return 0;
				}

				$this->apply_unsynced_pattern_meta( (int) $post->ID, $pattern, $file_hash );
				$this->trash_duplicate_unsynced_posts( (string) $pattern['slug'], (int) $post->ID );

				return (int) $post->ID;
			}

			$post_id = wp_insert_post(
				[
					'post_title'     => $pattern['title'],
					'post_name'      => $this->normalize_pattern_slug( (string) $pattern['slug'] ),
					'post_content'   => $pattern['content'],
					'post_type'      => 'wp_block',
					'post_status'    => 'publish',
					'comment_status' => 'closed',
					'ping_status'    => 'closed',
					'meta_input'     => [
						'wp_pattern_sync_status' => 'unsynced',
						'_btd_synced'            => false,
						self::META_THEME_SLUG    => $pattern['slug'],
						self::META_FILE_HASH     => $file_hash,
					],
				],
				true
			);

			if ( is_wp_error( $post_id ) || ! $post_id ) {
				return 0;
			}

			$this->apply_unsynced_pattern_meta( (int) $post_id, $pattern, $file_hash );
			$this->trash_duplicate_unsynced_posts( (string) $pattern['slug'], (int) $post_id );

			return (int) $post_id;
		} finally {
			self::$syncing_from_files = false;
		}
	}

	/**
	 * Delete the theme pattern file for a wp_block.
	 *
	 * @param int $post_id wp_block post ID.
	 * @return bool True when a file was deleted.
	 */
	public function delete_pattern_file_for_post( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return false;
		}

		$slug = $this->resolve_wp_block_pattern_slug( $post );
		$file = $this->get_pattern_file_path_for_slug( $slug );

		return $this->delete_pattern_file( $file );
	}

	/**
	 * Delete a pattern PHP file when it belongs to the active theme patterns directory.
	 *
	 * @param string|null $file_path Absolute file path.
	 * @return bool True when a file was deleted.
	 */
	public function delete_pattern_file( ?string $file_path ): bool {
		if ( ! $file_path || ! is_file( $file_path ) ) {
			return false;
		}

		$patterns_dir = realpath( $this->get_theme_patterns_directory() );
		$real_file    = realpath( $file_path );

		if ( ! $patterns_dir || ! $real_file || ! str_starts_with( $real_file, $patterns_dir . DIRECTORY_SEPARATOR ) ) {
			return false;
		}

		wp_delete_file( $real_file );

		$deleted = ! file_exists( $real_file );

		if ( $deleted ) {
			$this->log( sprintf( 'Deleted theme pattern file %s.', $real_file ) );
		}

		return $deleted;
	}

	/**
	 * Find an existing unsynced wp_block for a theme pattern slug.
	 *
	 * @param string $slug Theme pattern slug.
	 * @return \WP_Post|null
	 */
	private function find_unsynced_pattern_post( string $slug ): ?\WP_Post {
		$by_meta = get_posts(
			[
				'post_type'              => 'wp_block',
				'post_status'            => [ 'publish', 'draft', 'private' ],
				'posts_per_page'         => 1,
				'no_found_rows'          => true,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
				'meta_key'               => self::META_THEME_SLUG,
				'meta_value'             => $slug,
			]
		);

		if ( ! empty( $by_meta[0] ) && $by_meta[0] instanceof \WP_Post ) {
			return $by_meta[0];
		}

		$short = $this->normalize_pattern_slug( $slug );
		$names = array_values(
			array_unique(
				array_filter(
					[
						$short,
						$this->get_theme_slug() . '-' . $short,
						sanitize_title( str_replace( '/', '-', $slug ) ),
					]
				)
			)
		);

		foreach ( $names as $post_name ) {
			$by_name = get_posts(
				[
					'post_type'              => 'wp_block',
					'post_status'            => [ 'publish', 'draft', 'private' ],
					'name'                   => $post_name,
					'posts_per_page'         => 1,
					'no_found_rows'          => true,
					'ignore_sticky_posts'    => true,
					'update_post_term_cache' => false,
				]
			);

			if ( empty( $by_name[0] ) || ! $by_name[0] instanceof \WP_Post ) {
				continue;
			}

			$existing_slug = (string) get_post_meta( $by_name[0]->ID, self::META_THEME_SLUG, true );
			if ( '' !== $existing_slug && $existing_slug !== $slug ) {
				continue;
			}

			return $by_name[0];
		}

		return null;
	}

	/**
	 * Trash extra wp_block posts for the same unsynced theme pattern.
	 *
	 * @param string $slug    Theme pattern slug.
	 * @param int    $keep_id Post ID to keep.
	 * @return void
	 */
	private function trash_duplicate_unsynced_posts( string $slug, int $keep_id ): void {
		$short = $this->normalize_pattern_slug( $slug );
		$theme = $this->get_theme_slug();
		$ids   = [];

		$queries = [
			[
				'meta_key'   => self::META_THEME_SLUG,
				'meta_value' => $slug,
			],
			[
				'meta_key'   => self::META_THEME_SLUG,
				'meta_value' => $theme . '/' . $short,
			],
			[
				'name' => $short,
			],
			[
				'name' => $theme . '-' . $short,
			],
			[
				'name' => sanitize_title( str_replace( '/', '-', $slug ) ),
			],
		];

		foreach ( $queries as $query ) {
			$found = get_posts(
				array_merge(
					[
						'post_type'              => 'wp_block',
						'post_status'            => [ 'publish', 'draft', 'private' ],
						'posts_per_page'         => 20,
						'post__not_in'           => [ $keep_id ],
						'fields'                 => 'ids',
						'no_found_rows'          => true,
						'ignore_sticky_posts'    => true,
						'update_post_meta_cache' => false,
						'update_post_term_cache' => false,
					],
					$query
				)
			);

			$ids = array_merge( $ids, $found );
		}

		foreach ( array_unique( array_map( 'intval', $ids ) ) as $duplicate_id ) {
			if ( $duplicate_id === $keep_id ) {
				continue;
			}

			wp_trash_post( $duplicate_id );
		}
	}

	/**
	 * Store BTD meta for an unsynced theme pattern wp_block.
	 *
	 * @param int                  $post_id   Post ID.
	 * @param array<string, mixed> $pattern   Parsed pattern data.
	 * @param string               $file_hash md5 of the theme file.
	 * @return void
	 */
	private function apply_unsynced_pattern_meta( int $post_id, array $pattern, string $file_hash ): void {
		update_post_meta( $post_id, self::META_THEME_SLUG, $pattern['slug'] );
		update_post_meta( $post_id, 'wp_pattern_sync_status', 'unsynced' );
		update_post_meta( $post_id, '_btd_synced', false );
		delete_post_meta( $post_id, '_orbit_managed_theme_pattern' );
		delete_post_meta( $post_id, '_orbit_pattern_content_hash' );

		if ( '' !== $file_hash ) {
			update_post_meta( $post_id, self::META_FILE_HASH, $file_hash );
		}

		if ( isset( $pattern['inserter'] ) ) {
			update_post_meta( $post_id, '_btd_inserter', (bool) $pattern['inserter'] );
		}

		if ( ! empty( $pattern['description'] ) ) {
			update_post_meta( $post_id, '_btd_description', $pattern['description'] );
		}

		if ( ! empty( $pattern['viewportWidth'] ) ) {
			update_post_meta( $post_id, '_btd_viewport_width', (int) $pattern['viewportWidth'] );
		}

		if ( ! empty( $pattern['keywords'] ) ) {
			update_post_meta( $post_id, '_btd_keywords', $pattern['keywords'] );
		}

		if ( ! empty( $pattern['blockTypes'] ) ) {
			update_post_meta( $post_id, '_btd_block_types', $pattern['blockTypes'] );
		}

		if ( ! empty( $pattern['postTypes'] ) ) {
			update_post_meta( $post_id, '_btd_post_types', $pattern['postTypes'] );
		}

		if ( ! empty( $pattern['templateTypes'] ) ) {
			update_post_meta( $post_id, '_btd_template_types', $pattern['templateTypes'] );
		}

		if ( ! empty( $pattern['categories'] ) && is_array( $pattern['categories'] ) ) {
			$this->assign_wp_pattern_categories( $post_id, $pattern['categories'] );
		}
	}

	/**
	 * Whether a wp_block is owned by Orbit as a synced theme pattern.
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private function is_orbit_managed_post( int $post_id ): bool {
		return '1' === (string) get_post_meta( $post_id, '_orbit_managed_theme_pattern', true );
	}

	/**
	 * Drop an accidental Orbit claim so an unsynced theme file stays editable.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function unclaim_orbit_managed_post( int $post_id ): void {
		delete_post_meta( $post_id, '_orbit_managed_theme_pattern' );
		delete_post_meta( $post_id, '_orbit_pattern_content_hash' );
	}

	/**
	 * Render pattern file content (supports PHP in pattern files).
	 *
	 * @param string $file Absolute path.
	 * @return string
	 */
	private function render_pattern_file( string $file ): string {
		ob_start();
		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- Theme pattern path from stylesheet directory.
		include $file;
		return (string) ob_get_clean();
	}

	/**
	 * Whether a pattern file header value is truthy.
	 *
	 * @param string $value Header value.
	 * @return bool
	 */
	private function is_header_truthy( string $value ): bool {
		return in_array( strtolower( trim( $value ) ), [ '1', 'true', 'yes' ], true );
	}

	/**
	 * Mark a wp_block as the Orbit-managed post for a theme pattern slug.
	 *
	 * Prevents Orbit from inserting a second wp_block after BTD writes the file.
	 *
	 * @param int    $post_id      Pattern post ID.
	 * @param string $full_slug    Theme-namespaced slug.
	 * @param string $post_content Pattern block markup.
	 * @return void
	 */
	public function claim_wp_block_for_orbit( int $post_id, string $full_slug, string $post_content ): void {
		$desired_name = sanitize_title( str_replace( '/', '-', $full_slug ) );
		$post         = get_post( $post_id );

		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return;
		}

		update_post_meta( $post_id, '_orbit_theme_pattern_slug', $full_slug );
		update_post_meta( $post_id, '_orbit_pattern_content_hash', md5( $post_content ) );
		update_post_meta( $post_id, '_orbit_managed_theme_pattern', '1' );
		delete_post_meta( $post_id, 'wp_pattern_sync_status' );

		// Trash Orbit duplicates first so renaming to ollie-{slug} does not conflict.
		$duplicates = get_posts(
			[
				'post_type'      => 'wp_block',
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'posts_per_page' => 20,
				'post__not_in'   => [ $post_id ],
				'meta_query'     => [
					'relation' => 'AND',
					[
						'key'   => '_orbit_managed_theme_pattern',
						'value' => '1',
					],
					[
						'key'   => '_orbit_theme_pattern_slug',
						'value' => $full_slug,
					],
				],
			]
		);

		foreach ( $duplicates as $duplicate ) {
			wp_trash_post( (int) $duplicate->ID );
		}

		// Also remove an unmanaged collision on the desired post_name.
		$name_collision = get_posts(
			[
				'post_type'      => 'wp_block',
				'post_status'    => [ 'publish', 'draft', 'private' ],
				'name'           => $desired_name,
				'posts_per_page' => 20,
				'post__not_in'   => [ $post_id ],
			]
		);

		foreach ( $name_collision as $collision ) {
			$collision_slug = (string) get_post_meta( $collision->ID, '_orbit_theme_pattern_slug', true );
			if ( '' === $collision_slug || $collision_slug === $full_slug ) {
				wp_trash_post( (int) $collision->ID );
			}
		}

		$post = get_post( $post_id );
		if ( $post && $post->post_name !== $desired_name ) {
			wp_update_post(
				[
					'ID'        => $post_id,
					'post_name' => $desired_name,
				]
			);
		}
	}

	/**
	 * Resolve the theme-namespaced slug for a wp_block pattern.
	 *
	 * @param \WP_Post $post Pattern post.
	 * @return string
	 */
	public function resolve_wp_block_pattern_slug( $post ): string {
		$orbit_slug = (string) get_post_meta( $post->ID, '_orbit_theme_pattern_slug', true );
		if ( '' !== $orbit_slug ) {
			return $orbit_slug;
		}

		$btd_slug = (string) get_post_meta( $post->ID, self::META_THEME_SLUG, true );
		if ( '' !== $btd_slug ) {
			return $btd_slug;
		}

		$post_name = ! empty( $post->post_name ) ? (string) $post->post_name : sanitize_title( $post->post_title );

		// Orbit uses ollie-example-synced-pattern style post_names for theme/slug.
		if ( str_contains( $post_name, '-' ) && ! str_contains( $post_name, '/' ) ) {
			$theme_slug = $this->get_theme_slug();
			$prefix     = $theme_slug . '-';
			if ( str_starts_with( $post_name, $prefix ) ) {
				$short = substr( $post_name, strlen( $prefix ) );
				return $theme_slug . '/' . $short;
			}
		}

		return $this->build_pattern_slug( $post_name );
	}

	/**
	 * Absolute path for a pattern file given a (possibly namespaced) slug.
	 *
	 * @param string $slug Pattern slug.
	 * @return string|null
	 */
	public function get_pattern_file_path_for_slug( string $slug ): ?string {
		$short = $this->normalize_pattern_slug( $slug );
		if ( '' === $short ) {
			return null;
		}

		return $this->get_theme_patterns_directory() . $short . '.php';
	}

	/**
	 * Fill empty BTD meta on a wp_block from its theme pattern file headers.
	 *
	 * @param int $post_id wp_block post ID.
	 * @return void
	 */
	public function hydrate_wp_block_meta_from_theme_file( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return;
		}

		$slug = $this->resolve_wp_block_pattern_slug( $post );
		$file = $this->get_pattern_file_path_for_slug( $slug );

		if ( ! $file || ! file_exists( $file ) ) {
			// Orbit-managed patterns should still show as synced in the UI.
			if ( '1' === (string) get_post_meta( $post_id, '_orbit_managed_theme_pattern', true ) ) {
				update_post_meta( $post_id, '_btd_synced', true );
			}
			return;
		}

		$headers = get_file_data(
			$file,
			[
				'description'    => 'Description',
				'categories'     => 'Categories',
				'keywords'       => 'Keywords',
				'viewportWidth'  => 'Viewport Width',
				'blockTypes'     => 'Block Types',
				'postTypes'      => 'Post Types',
				'templateTypes'  => 'Template Types',
				'inserter'       => 'Inserter',
				'synced'         => 'Synced',
			]
		);

		$parse_list = static function ( string $value ): array {
			$value = trim( $value );
			if ( '' === $value ) {
				return [];
			}
			return array_values( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
		};

		$is_truthy = static function ( $value ): bool {
			return in_array( strtolower( trim( (string) $value ) ), [ '1', 'true', 'yes' ], true );
		};

		$current = PatternManager::get_pattern_metadata( $post_id );

		if ( '' === (string) ( $current['_btd_description'] ?? '' ) && '' !== ( $headers['description'] ?? '' ) ) {
			update_post_meta( $post_id, '_btd_description', $headers['description'] );
		}

		// Prefer core's Pattern Categories taxonomy for wp_block; seed from file when empty.
		if ( '' !== ( $headers['categories'] ?? '' ) && taxonomy_exists( 'wp_pattern_category' ) ) {
			$existing_terms = wp_get_object_terms( $post_id, 'wp_pattern_category', [ 'fields' => 'ids' ] );
			if ( empty( $existing_terms ) || is_wp_error( $existing_terms ) ) {
				$this->assign_wp_pattern_categories( $post_id, $parse_list( $headers['categories'] ) );
			}
		}

		if ( empty( $current['_btd_keywords'] ) && '' !== ( $headers['keywords'] ?? '' ) ) {
			update_post_meta( $post_id, '_btd_keywords', $parse_list( $headers['keywords'] ) );
		}

		if ( empty( $current['_btd_block_types'] ) && '' !== ( $headers['blockTypes'] ?? '' ) ) {
			update_post_meta( $post_id, '_btd_block_types', $parse_list( $headers['blockTypes'] ) );
		}

		if ( empty( $current['_btd_post_types'] ) && '' !== ( $headers['postTypes'] ?? '' ) ) {
			update_post_meta( $post_id, '_btd_post_types', $parse_list( $headers['postTypes'] ) );
		}

		if ( empty( $current['_btd_template_types'] ) && '' !== ( $headers['templateTypes'] ?? '' ) ) {
			update_post_meta( $post_id, '_btd_template_types', $parse_list( $headers['templateTypes'] ) );
		}

		if ( '' !== ( $headers['viewportWidth'] ?? '' ) && ( empty( $current['_btd_viewport_width'] ) || 1280 === (int) $current['_btd_viewport_width'] ) ) {
			update_post_meta( $post_id, '_btd_viewport_width', (int) $headers['viewportWidth'] );
		}

		if ( '' !== ( $headers['inserter'] ?? '' ) ) {
			update_post_meta( $post_id, '_btd_inserter', ! in_array( strtolower( $headers['inserter'] ), [ 'no', 'false', '0' ], true ) );
		}

		if ( $is_truthy( $headers['synced'] ?? '' ) || '1' === (string) get_post_meta( $post_id, '_orbit_managed_theme_pattern', true ) ) {
			if ( ! $is_truthy( $current['_btd_synced'] ?? false ) ) {
				update_post_meta( $post_id, '_btd_synced', true );
			}
		}
	}

	/**
	 * Resolve categories for wp_block export (taxonomy first, then legacy BTD meta).
	 *
	 * Native pattern editing uses core's Pattern Categories panel (`wp_pattern_category`).
	 *
	 * @param int   $post_id  Post ID.
	 * @param array $metadata Pattern metadata.
	 * @return array
	 */
	private function resolve_wp_block_categories( int $post_id, array $metadata ): array {
		if ( taxonomy_exists( 'wp_pattern_category' ) ) {
			$terms = wp_get_object_terms( $post_id, 'wp_pattern_category', [ 'fields' => 'slugs' ] );
			if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
				return array_values( $terms );
			}
		}

		if ( ! empty( $metadata['_btd_categories'] ) && is_array( $metadata['_btd_categories'] ) ) {
			return $metadata['_btd_categories'];
		}

		return [];
	}

	/**
	 * Assign wp_pattern_category terms by slug (creating terms when needed).
	 *
	 * @param int   $post_id Post ID.
	 * @param array $slugs   Category slugs from the theme file header.
	 * @return void
	 */
	private function assign_wp_pattern_categories( int $post_id, array $slugs ): void {
		if ( empty( $slugs ) || ! taxonomy_exists( 'wp_pattern_category' ) ) {
			return;
		}

		$term_ids = [];

		foreach ( $slugs as $slug ) {
			$slug = sanitize_title( $slug );
			if ( '' === $slug ) {
				continue;
			}

			$term = get_term_by( 'slug', $slug, 'wp_pattern_category' );
			if ( ! $term ) {
				// Theme headers sometimes use namespaced slugs (e.g. ollie/features).
				$label = $slug;
				if ( str_contains( $slug, '/' ) ) {
					$parts = explode( '/', $slug );
					$label = (string) end( $parts );
				}

				$inserted = wp_insert_term(
					ucwords( str_replace( '-', ' ', $label ) ),
					'wp_pattern_category',
					[ 'slug' => $slug ]
				);

				if ( is_wp_error( $inserted ) ) {
					continue;
				}

				$term_ids[] = (int) $inserted['term_id'];
				continue;
			}

			$term_ids[] = (int) $term->term_id;
		}

		if ( ! empty( $term_ids ) ) {
			wp_set_object_terms( $post_id, $term_ids, 'wp_pattern_category', false );
		}
	}

	/**
	 * Save template or template part to a theme file.
	 *
	 * @param int $post_id The post ID.
	 * @return bool True on success, false on failure.
	 */
	public function save_template_to_file( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, [ 'wp_template', 'wp_template_part' ], true ) ) {
			return false;
		}

		$file_path = $this->get_template_file_path( $post );
		if ( ! $file_path ) {
			return false;
		}

		if ( ! $this->ensure_directory_exists( dirname( $file_path ) ) ) {
			return false;
		}

		$content = $this->format_exported_block_content( (string) $post->post_content );
		if ( '' !== $content && ! str_ends_with( $content, "\n" ) ) {
			$content .= "\n";
		}

		return $this->write_file( $file_path, $content );
	}

	/**
	 * Ensure directory exists
	 *
	 * @param string $dir_path The directory path.
	 * @return bool True if directory exists or was created, false otherwise.
	 */
	private function ensure_directory_exists( string $dir_path ): bool {
		if ( is_dir( $dir_path ) ) {
			return true;
		}

		// Try to create directory
		if ( wp_mkdir_p( $dir_path ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Build an absolute theme file path for template exports.
	 *
	 * @param \WP_Post $post The template post object.
	 * @return string|false Absolute file path or false on failure.
	 */
	public function get_template_file_path( $post ) {
		if ( ! $post || ! in_array( $post->post_type, [ 'wp_template', 'wp_template_part' ], true ) ) {
			return false;
		}

		$directory = $this->get_theme_template_directory( $post->post_type );
		if ( ! $directory ) {
			return false;
		}

		$slug = $this->normalize_template_slug( (string) $post->post_name );
		if ( '' === $slug ) {
			return false;
		}

		return trailingslashit( $directory ) . $slug . '.html';
	}

	/**
	 * Get the active stylesheet template directory by template post type.
	 *
	 * @param string $post_type The post type.
	 * @return string|false Directory path or false for unsupported type.
	 */
	public function get_theme_template_directory( string $post_type ) {
		$theme_dir = get_stylesheet_directory();

		if ( 'wp_template' === $post_type ) {
			return trailingslashit( $theme_dir . '/templates' );
		}

		if ( 'wp_template_part' === $post_type ) {
			return trailingslashit( $theme_dir . '/parts' );
		}

		return false;
	}

	/**
	 * Normalize a template post_name into a safe theme file slug.
	 *
	 * @param string $post_name The template post_name.
	 * @return string Normalized slug relative to the templates/parts directory.
	 */
	public function normalize_template_slug( string $post_name ): string {
		$slug = trim( $post_name );

		// Core template post names include `theme-slug//template-slug`.
		if ( false !== strpos( $slug, '//' ) ) {
			$parts = explode( '//', $slug, 2 );
			$slug  = $parts[1];
		}

		$slug     = str_replace( '\\', '/', $slug );
		$segments = array_filter(
			explode( '/', $slug ),
			static function ( string $segment ): bool {
				return '' !== $segment && '.' !== $segment && '..' !== $segment;
			}
		);

		$segments = array_map(
			static function ( string $segment ): string {
				return sanitize_title( $segment );
			},
			$segments
		);

		$segments = array_values( array_filter( $segments ) );

		return implode( '/', $segments );
	}


	/**
	 * Get patterns directory path for the active stylesheet theme.
	 *
	 * @return string The patterns directory path.
	 */
	public function get_theme_patterns_directory() {
		$theme_dir = get_stylesheet_directory();
		return trailingslashit( $theme_dir . '/patterns' );
	}

	/**
	 * Get the active stylesheet theme slug (child theme when present).
	 *
	 * @return string Theme slug.
	 */
	public function get_theme_slug(): string {
		return (string) get_stylesheet();
	}

	/**
	 * Get the @package tag from the active theme functions.php, if present.
	 *
	 * @return string|null Package tag value or null when not declared.
	 */
	public function get_theme_package_tag(): ?string {
		if ( null !== self::$theme_package_tag ) {
			return false === self::$theme_package_tag ? null : self::$theme_package_tag;
		}

		$functions_file = get_template_directory() . '/functions.php';
		if ( ! is_readable( $functions_file ) ) {
			self::$theme_package_tag = false;
			return null;
		}

		$contents = file_get_contents( $functions_file );
		if ( false === $contents || ! preg_match( '/@package\s+(\S+)/', $contents, $matches ) ) {
			self::$theme_package_tag = false;
			return null;
		}

		self::$theme_package_tag = $matches[1];

		return self::$theme_package_tag;
	}

	/**
	 * Normalize a pattern slug to the short form used by the CPT post_name.
	 *
	 * @param string $pattern_slug Pattern slug, with or without theme prefix.
	 * @return string Sanitized short slug.
	 */
	public function normalize_pattern_slug( string $pattern_slug ): string {
		$theme_slug = $this->get_theme_slug();
		$prefix     = $theme_slug . '/';

		if ( str_starts_with( $pattern_slug, $prefix ) ) {
			$pattern_slug = substr( $pattern_slug, strlen( $prefix ) );
		} elseif ( false !== strpos( $pattern_slug, '/' ) ) {
			$parts        = explode( '/', $pattern_slug );
			$pattern_slug = (string) end( $parts );
		}

		return sanitize_title( $pattern_slug );
	}

	/**
	 * Build a theme-namespaced pattern slug for exported pattern files.
	 *
	 * @param string $pattern_slug Short pattern slug or already-namespaced slug.
	 * @return string Namespaced slug, e.g. pulsar/founders.
	 */
	public function build_pattern_slug( string $pattern_slug ): string {
		$pattern_slug = $this->normalize_pattern_slug( $pattern_slug );

		if ( '' === $pattern_slug ) {
			return $this->get_theme_slug();
		}

		return $this->get_theme_slug() . '/' . $pattern_slug;
	}

	/**
	 * Generate pattern file content
	 *
	 * @param \WP_Post    $post        The post object.
	 * @param array       $metadata    Pattern metadata.
	 * @param array       $categories  Pattern categories.
	 * @param string|null $forced_slug Optional full theme/slug override for the Slug header.
	 * @return string The file content.
	 */
	private function generate_pattern_file_content( $post, array $metadata, array $categories, ?string $forced_slug = null ): string {
		$pattern_slug = $forced_slug;
		if ( null === $pattern_slug || '' === $pattern_slug ) {
			$pattern_slug = ! empty( $post->post_name ) ? (string) $post->post_name : sanitize_title( $post->post_title );
			$pattern_slug = $this->build_pattern_slug( $pattern_slug );
		}

		// Always include core fields
		$header_parts = [
			'Title' => $post->post_title,
			'Slug'  => $pattern_slug,
		];

		// Add fields only if they have values (except Inserter and Viewport Width which should always be included)
		if ( ! empty( $metadata['_btd_description'] ) ) {
			$header_parts['Description'] = $metadata['_btd_description'];
		}

		if ( ! empty( $categories ) ) {
			$header_parts['Categories'] = implode( ', ', $categories );
		}

		if ( ! empty( $metadata['_btd_post_types'] ) && is_array( $metadata['_btd_post_types'] ) ) {
			$header_parts['Post Types'] = implode( ', ', $metadata['_btd_post_types'] );
		}

		if ( ! empty( $metadata['_btd_keywords'] ) && is_array( $metadata['_btd_keywords'] ) ) {
			$header_parts['Keywords'] = implode( ', ', $metadata['_btd_keywords'] );
		}

		if ( ! empty( $metadata['_btd_block_types'] ) && is_array( $metadata['_btd_block_types'] ) ) {
			$header_parts['Block Types'] = implode( ', ', $metadata['_btd_block_types'] );
		}

		if ( ! empty( $metadata['_btd_template_types'] ) && is_array( $metadata['_btd_template_types'] ) ) {
			$header_parts['Template Types'] = implode( ', ', $metadata['_btd_template_types'] );
		}

		// Always include Viewport Width and Inserter
		$header_parts['Viewport Width'] = isset( $metadata['_btd_viewport_width'] ) ? (int) $metadata['_btd_viewport_width'] : 1280;
		$header_parts['Inserter']       = ( isset( $metadata['_btd_inserter'] ) ? $metadata['_btd_inserter'] : true ) ? 'true' : 'false';

		if ( ! empty( $metadata['_btd_synced'] ) ) {
			$header_parts['Synced'] = 'true';
		}

		// Build the docblock header
		$header = "<?php\n/**\n";
		foreach ( $header_parts as $key => $value ) {
			$header .= " * {$key}: {$value}\n";
		}

		$package_tag = $this->get_theme_package_tag();
		if ( null !== $package_tag ) {
			$header .= " *\n";
			$header .= " * @package {$package_tag}\n";
		}

		$header .= " */\n\n";

		// WordPress 7.0 applies content-only behaviour on insert. Keep the
		// source pattern unlocked so Appearance → Editor stays fully editable.
		$export_content = $this->strip_content_only_template_lock( (string) $post->post_content );
		$content        = $this->format_exported_block_content( $export_content );

		return $header . "?>\n" . $content;
	}

	/**
	 * Remove templateLock contentOnly from pattern markup.
	 *
	 * @param string $content Pattern block markup content.
	 * @return string Updated content.
	 */
	public function strip_content_only_template_lock( string $content ): string {
		if ( false === strpos( $content, 'templateLock' ) || false === strpos( $content, 'contentOnly' ) ) {
			return $content;
		}

		if ( false === strpos( $content, '<!-- wp:' ) ) {
			return $content;
		}

		$blocks = parse_blocks( $content );
		if ( empty( $blocks ) ) {
			return $content;
		}

		if ( ! $this->strip_content_only_from_blocks( $blocks ) ) {
			return $content;
		}

		return serialize_blocks( $blocks );
	}

	/**
	 * Recursively unset contentOnly templateLock attributes.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return bool Whether any block was changed.
	 */
	private function strip_content_only_from_blocks( array &$blocks ): bool {
		$changed = false;

		foreach ( $blocks as &$block ) {
			$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];

			if ( isset( $attrs['templateLock'] ) && 'contentOnly' === $attrs['templateLock'] ) {
				unset( $attrs['templateLock'] );
				$block['attrs'] = $attrs;
				$changed        = true;
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$changed = $this->strip_content_only_from_blocks( $block['innerBlocks'] ) || $changed;
			}
		}

		return $changed;
	}

	/**
	 * Format exported block markup for readability.
	 *
	 * @param string $content Raw block content.
	 * @return string
	 */
	private function format_exported_block_content( string $content ): string {
		$normalized_content = str_replace( [ "\r\n", "\r" ], "\n", $content );
		$trimmed_content    = trim( $normalized_content );

		if ( '' === $trimmed_content || false === strpos( $normalized_content, '<!-- wp:' ) ) {
			return $normalized_content;
		}

		$blocks = parse_blocks( $normalized_content );

		if ( empty( $blocks ) ) {
			$this->log( 'Block formatting skipped: parse_blocks returned empty for non-empty content.' );
			return $normalized_content;
		}

		$formatted = $this->render_formatted_blocks( $blocks, 0 );
		$formatted = trim( $formatted );

		if ( '' === $formatted ) {
			$this->log( 'Block formatting skipped: formatted output was empty.' );
			return $normalized_content;
		}

		$formatted_blocks = parse_blocks( $formatted );
		if ( ! $this->is_safe_formatted_output( $blocks, $formatted_blocks ) ) {
			$this->log( 'Block formatting skipped: formatted block structure did not match source.' );
			return $normalized_content;
		}

		return $formatted;
	}

	/**
	 * Render parsed blocks with deterministic indentation.
	 *
	 * @param array $blocks Parsed blocks.
	 * @param int   $depth Current nesting depth.
	 * @return string
	 */
	private function render_formatted_blocks( array $blocks, int $depth ): string {
		$rendered_blocks = [];

		foreach ( $blocks as $block ) {
			$rendered = $this->render_formatted_block( $block, $depth );
			if ( '' !== $rendered ) {
				$rendered_blocks[] = $rendered;
			}
		}

		return implode( "\n\n", $rendered_blocks );
	}

	/**
	 * Render a single parsed block with indentation.
	 *
	 * @param array $block Parsed block.
	 * @param int   $depth Current nesting depth.
	 * @return string
	 */
	private function render_formatted_block( array $block, int $depth ): string {
		$block_name = isset( $block['blockName'] ) ? $block['blockName'] : null;
		$indent     = $this->get_indent( $depth );

		if ( null === $block_name ) {
			$raw_html = isset( $block['innerHTML'] ) ? (string) $block['innerHTML'] : '';
			return $this->format_html_chunk( $raw_html, $depth );
		}

		$attrs            = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : [];
		$serialized_name  = strip_core_block_namespace( (string) $block_name );
		$attributes_chunk = '';

		if ( ! empty( $attrs ) ) {
			$attributes_chunk = ' ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		}

		$inner_content = $this->render_block_inner_content( $block, $depth + 1 );

		if ( '' === trim( $inner_content ) ) {
			return sprintf( '%1$s<!-- wp:%2$s%3$s /-->', $indent, $serialized_name, $attributes_chunk );
		}

		return implode(
			"\n",
			[
				sprintf( '%1$s<!-- wp:%2$s%3$s -->', $indent, $serialized_name, $attributes_chunk ),
				$inner_content,
				sprintf( '%1$s<!-- /wp:%2$s -->', $indent, $serialized_name ),
			]
		);
	}

	/**
	 * Render inner content for a parsed block.
	 *
	 * @param array $block Parsed block.
	 * @param int   $depth Current nesting depth.
	 * @return string
	 */
	private function render_block_inner_content( array $block, int $depth ): string {
		$segments            = [];
		$inner_blocks        = isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ? $block['innerBlocks'] : [];
		$inner_content_parts = isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ? $block['innerContent'] : [];
		$inner_block_index   = 0;

		foreach ( $inner_content_parts as $part ) {
			if ( is_string( $part ) ) {
				$formatted_html = $this->format_html_chunk( $part, $depth );
				if ( '' !== $formatted_html ) {
					$segments[] = $formatted_html;
				}
				continue;
			}

			if ( null === $part && isset( $inner_blocks[ $inner_block_index ] ) && is_array( $inner_blocks[ $inner_block_index ] ) ) {
				$segments[] = $this->render_formatted_block( $inner_blocks[ $inner_block_index ], $depth );
				++$inner_block_index;
			}
		}

		return implode( "\n\n", array_values( array_filter( $segments, static fn( string $segment ): bool => '' !== trim( $segment ) ) ) );
	}

	/**
	 * Format an HTML fragment with indentation.
	 *
	 * @param string $html Fragment to format.
	 * @param int    $depth Current indentation depth.
	 * @return string
	 */
	private function format_html_chunk( string $html, int $depth ): string {
		$normalized = trim( str_replace( [ "\r\n", "\r" ], "\n", $html ) );
		if ( '' === $normalized ) {
			return '';
		}

		// Split tightly packed tags onto separate lines for readable indentation.
		$normalized = preg_replace( '/>\s*</', ">\n<", $normalized );
		$lines          = preg_split( '/\n+/', $normalized );
		$current_depth  = $depth;
		$formatted      = [];

		foreach ( $lines as $line ) {
			$trimmed_line = trim( (string) $line );
			if ( '' === $trimmed_line ) {
				continue;
			}

			$closing_tags = $this->count_closing_tags_at_start( $trimmed_line );
			if ( $closing_tags > 0 ) {
				$current_depth = max( $depth, $current_depth - $closing_tags );
			}

			$formatted[] = $this->get_indent( $current_depth ) . $trimmed_line;

			$opening_tags = $this->count_opening_tags( $trimmed_line );
			$closing_tags = $this->count_closing_tags( $trimmed_line );

			$current_depth += max( 0, $opening_tags - $closing_tags );
			$current_depth = max( $depth, $current_depth );
		}

		return implode( "\n", $formatted );
	}

	/**
	 * Count opening HTML tags in a line.
	 *
	 * @param string $line HTML line.
	 * @return int
	 */
	private function count_opening_tags( string $line ): int {
		if ( ! preg_match_all( '/<([a-zA-Z][a-zA-Z0-9:-]*)(\s[^>]*)?>/', $line, $matches, PREG_SET_ORDER ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $matches as $match ) {
			$tag = strtolower( $match[1] );

			if ( $this->is_void_html_tag( $tag ) ) {
				continue;
			}

			if ( preg_match( '/\/\s*>$/', $match[0] ) ) {
				continue;
			}

			++$count;
		}

		return $count;
	}

	/**
	 * Count all closing HTML tags in a line.
	 *
	 * @param string $line HTML line.
	 * @return int
	 */
	private function count_closing_tags( string $line ): int {
		preg_match_all( '/<\/([a-zA-Z][a-zA-Z0-9:-]*)\s*>/', $line, $matches );
		return count( $matches[0] );
	}

	/**
	 * Count closing HTML tags that appear at the beginning of a line.
	 *
	 * @param string $line HTML line.
	 * @return int
	 */
	private function count_closing_tags_at_start( string $line ): int {
		if ( ! preg_match( '/^(?:<\/[a-zA-Z][a-zA-Z0-9:-]*\s*>)+/', $line, $matches ) ) {
			return 0;
		}

		preg_match_all( '/<\/[a-zA-Z][a-zA-Z0-9:-]*\s*>/', $matches[0], $closing_matches );
		return count( $closing_matches[0] );
	}

	/**
	 * Whether a tag is a void HTML tag.
	 *
	 * @param string $tag_name Tag name.
	 * @return bool
	 */
	private function is_void_html_tag( string $tag_name ): bool {
		static $void_tags = [
			'area',
			'base',
			'br',
			'col',
			'embed',
			'hr',
			'img',
			'input',
			'link',
			'meta',
			'param',
			'source',
			'track',
			'wbr',
		];

		return in_array( $tag_name, $void_tags, true );
	}

	/**
	 * Determine whether formatted output is safe to write.
	 *
	 * @param array $original_blocks Parsed original blocks.
	 * @param array $formatted_blocks Parsed formatted blocks.
	 * @return bool
	 */
	private function is_safe_formatted_output( array $original_blocks, array $formatted_blocks ): bool {
		return $this->flatten_block_names( $original_blocks ) === $this->flatten_block_names( $formatted_blocks );
	}

	/**
	 * Flatten block names depth-first for structure comparison.
	 *
	 * @param array $blocks Parsed blocks.
	 * @return array
	 */
	private function flatten_block_names( array $blocks ): array {
		$names = [];

		foreach ( $blocks as $block ) {
			$block_name = isset( $block['blockName'] ) && is_string( $block['blockName'] ) ? $block['blockName'] : '__html__';
			$names[]    = $block_name;

			if ( isset( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				$names = array_merge( $names, $this->flatten_block_names( $block['innerBlocks'] ) );
			}
		}

		return $names;
	}

	/**
	 * Get indentation whitespace for a nesting depth.
	 *
	 * @param int $depth Nesting depth.
	 * @return string
	 */
	private function get_indent( int $depth ): string {
		return str_repeat( "\t", max( 0, $depth ) );
	}

	/**
	 * Write file using WordPress filesystem
	 *
	 * @param string $file_path The file path.
	 * @param string $content The file content.
	 * @return bool True on success, false on failure.
	 */
	private function write_file( string $file_path, string $content ): bool {
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$filesystem = WP_Filesystem();
		if ( ! $filesystem ) {
			return false;
		}

		global $wp_filesystem;

		// Write the file
		$result = $wp_filesystem->put_contents( $file_path, $content, FS_CHMOD_FILE );

		return false !== $result;
	}

	/**
	 * Write to debug log when enabled.
	 *
	 * @param string $message Log message.
	 * @return void
	 */
	private function log( string $message ): void {
		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			error_log( '[Block Theme Developer] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/**
	 * Check if active theme supports patterns
	 *
	 * @return bool True if theme supports patterns, false otherwise.
	 */
	public function theme_supports_patterns(): bool {
		return current_theme_supports( 'block-templates' ) || is_dir( $this->get_theme_patterns_directory() );
	}

	/**
	 * Get all pattern files from the active theme
	 *
	 * @return array Array of pattern file paths.
	 */
	public function get_theme_pattern_files(): array {
		$patterns_dir = $this->get_theme_patterns_directory();

		if ( ! is_dir( $patterns_dir ) ) {
			return [];
		}

		$pattern_files = [];
		$files = scandir( $patterns_dir );

		if ( false === $files ) {
			return [];
		}

		foreach ( $files as $file ) {
			if ( '.' === $file || '..' === $file ) {
				continue;
			}

			$file_path = $patterns_dir . $file;

			// Only include .php files
			if ( is_file( $file_path ) && '.php' === substr( $file, -4 ) ) {
				$pattern_files[] = $file_path;
			}
		}

		return $pattern_files;
	}
}
