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
	 * @param WP_Post $post The template post object.
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
		$content = $this->format_exported_block_content( (string) $post->post_content );

		return $header . "?>\n" . $content;
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
