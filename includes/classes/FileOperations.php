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
	 * Initialize the class
	 */
	public function __construct() {
		// Constructor intentionally empty
	}

	/**
	 * Save pattern to theme file
	 *
	 * @param int $post_id The post ID.
	 * @return bool True on success, false on failure.
	 */
	public function save_pattern_to_file( int $post_id ): bool {
		$post = get_post( $post_id );
		if ( ! $post || 'btd_pattern' !== $post->post_type ) {
			return false;
		}

		// Get the active theme's patterns directory
		$patterns_dir = $this->get_theme_patterns_directory();

		// Create patterns directory if it doesn't exist
		if ( ! $this->ensure_directory_exists( $patterns_dir ) ) {
			return false;
		}

		// Generate file name from post title
		$file_name = sanitize_title( $post->post_title );
		if ( empty( $file_name ) ) {
			$file_name = 'pattern-' . $post_id;
		}
		$file_path = "{$patterns_dir}{$file_name}.php";

		// Get pattern metadata
		$metadata = PatternManager::get_pattern_metadata( $post_id );

		// Get pattern categories from metadata
		$categories = isset( $metadata['_btd_categories'] ) && is_array( $metadata['_btd_categories'] ) ? $metadata['_btd_categories'] : [];

		// Generate PHP file content
		$file_content = $this->generate_pattern_file_content( $post, $metadata, $categories );

		// Write the file
		return $this->write_file( $file_path, $file_content );
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
	 * Generate pattern file content
	 *
	 * @param WP_Post $post The post object.
	 * @param array   $metadata Pattern metadata.
	 * @param array   $categories Pattern categories.
	 * @return string The file content.
	 */
	private function generate_pattern_file_content( $post, array $metadata, array $categories ): string {
		// Always include core fields
		$header_parts = [
			'Title' => $post->post_title,
			'Slug'  => $post->post_name,
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

		// Build the docblock header
		$header = "<?php\n/**\n";
		foreach ( $header_parts as $key => $value ) {
			$header .= " * {$key}: {$value}\n";
		}
		$header .= " */\n\n";

		// Add the pattern content
		$content = $post->post_content;

		return $header . "?>\n" . $content;
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
	 * Get patterns directory path for active theme
	 *
	 * @return string|false The patterns directory path, or false if theme doesn't support patterns.
	 */
	public function get_theme_patterns_directory() {
		$theme_dir = get_template_directory();
		return trailingslashit( $theme_dir . '/patterns' );
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
