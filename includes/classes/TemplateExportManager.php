<?php
/**
 * Template Export Manager
 *
 * Handles template and template part exports to theme files.
 *
 * @package Block Theme Developer
 */

namespace Eighteen73\BlockThemeDeveloper;

/**
 * Template Export Manager class.
 */
class TemplateExportManager {

	use Singleton;

	/**
	 * Prevent recursive cleanup loops when deleting template overrides.
	 *
	 * @var bool
	 */
	private static bool $is_cleaning_up = false;

	/**
	 * Initialize the class.
	 */
	public function __construct() {
		add_action( 'save_post_wp_template', [ $this, 'handle_template_save' ], 20, 3 );
		add_action( 'save_post_wp_template_part', [ $this, 'handle_template_save' ], 20, 3 );
		add_action( 'rest_after_insert_wp_template', [ $this, 'handle_template_save_rest' ], 10, 3 );
		add_action( 'rest_after_insert_wp_template_part', [ $this, 'handle_template_save_rest' ], 10, 3 );
	}

	/**
	 * Check if plugin is running in file mode.
	 *
	 * @return bool
	 */
	private function is_file_mode(): bool {
		return defined( 'BLOCK_THEME_DEVELOPER_MODE' ) && 'file' === BLOCK_THEME_DEVELOPER_MODE;
	}

	/**
	 * Handle classic save_post for templates.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @param bool    $update Whether this is an update.
	 * @return void
	 */
	public function handle_template_save( int $post_id, $post, bool $update ): void {
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$this->process_template_save( $post_id, $post );
	}

	/**
	 * Handle REST saves for templates.
	 *
	 * @param WP_Post         $post Post object.
	 * @param WP_REST_Request $request Request object.
	 * @param bool            $creating Whether this is a create operation.
	 * @return void
	 */
	public function handle_template_save_rest( $post, $request, bool $creating ): void {
		if ( ! isset( $post->ID ) ) {
			return;
		}

		$this->process_template_save( (int) $post->ID, $post );
	}

	/**
	 * Process template export + strict cleanup flow.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post Post object.
	 * @return bool True when export succeeded.
	 */
	private function process_template_save( int $post_id, $post ): bool {
		if ( ! $this->is_file_mode() ) {
			return false;
		}

		if ( ! $post || ! in_array( $post->post_type, [ 'wp_template', 'wp_template_part' ], true ) ) {
			return false;
		}

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return false;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return false;
		}

		if ( in_array( $post->post_status, [ 'auto-draft', 'draft', 'trash', 'inherit' ], true ) ) {
			return false;
		}

		$file_path = FileOperations::instance()->get_template_file_path( $post );
		$saved     = FileOperations::instance()->save_template_to_file( $post_id );

		if ( ! $saved ) {
			$this->log( sprintf( 'Template export failed for post %d.', $post_id ) );
			return false;
		}

		$this->log(
			sprintf(
				'Template export succeeded for post %1$d to %2$s.',
				$post_id,
				$file_path ? $file_path : '(unknown path)'
			)
		);

		$cleaned_up = $this->delete_template_override_post( $post_id, $post->post_type );

		if ( ! $cleaned_up ) {
			$this->log( sprintf( 'Template export succeeded but cleanup failed for post %d.', $post_id ) );
		}

		return true;
	}

	/**
	 * Delete db template override after successful export.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $post_type Post type.
	 * @return bool
	 */
	private function delete_template_override_post( int $post_id, string $post_type ): bool {
		if ( self::$is_cleaning_up ) {
			return false;
		}

		self::$is_cleaning_up = true;

		remove_action( "save_post_{$post_type}", [ $this, 'handle_template_save' ], 20 );

		$deleted = wp_delete_post( $post_id, true );

		add_action( "save_post_{$post_type}", [ $this, 'handle_template_save' ], 20, 3 );

		self::$is_cleaning_up = false;

		return (bool) $deleted;
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
}
