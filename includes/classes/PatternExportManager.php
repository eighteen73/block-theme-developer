<?php
/**
 * Export native wp_block patterns to theme pattern files (file mode).
 *
 * @package Block Theme Developer
 */

namespace Eighteen73\BlockThemeDeveloper;

/**
 * Pattern export manager.
 */
class PatternExportManager {

	use Singleton;

	/**
	 * Orbit post meta marking a theme-managed synced pattern.
	 */
	private const ORBIT_MANAGED_META = '_orbit_managed_theme_pattern';

	/**
	 * Initialize hooks.
	 */
	public function __construct() {
		if ( ! PatternManager::is_file_mode() ) {
			return;
		}

		add_filter( 'orbit_allow_theme_synced_pattern_updates', [ $this, 'allow_orbit_updates' ], 10, 2 );
		add_action( 'save_post_wp_block', [ $this, 'handle_pattern_save' ], 20, 3 );
		add_action( 'rest_after_insert_wp_block', [ $this, 'handle_pattern_save_rest' ], 10, 3 );
		add_filter( 'rest_prepare_wp_block', [ $this, 'hydrate_btd_meta_in_rest' ], 10, 3 );
	}

	/**
	 * Allow Orbit-managed pattern REST updates while BTD file mode is active.
	 *
	 * @param bool $allow   Current allow value.
	 * @param int  $post_id Pattern post ID.
	 * @return bool
	 */
	public function allow_orbit_updates( bool $allow, int $post_id ): bool { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		return true;
	}

	/**
	 * Seed BTD meta from the theme pattern file when preparing a wp_block in REST.
	 *
	 * @param \WP_REST_Response $response Response object.
	 * @param \WP_Post          $post     Post object.
	 * @param \WP_REST_Request  $request  Request object.
	 * @return \WP_REST_Response
	 */
	public function hydrate_btd_meta_in_rest( $response, $post, $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return $response;
		}

		$slug = FileOperations::instance()->resolve_wp_block_pattern_slug( $post );
		$file = FileOperations::instance()->get_pattern_file_path_for_slug( $slug );

		if ( ! $file || ! file_exists( $file ) ) {
			if ( '1' === (string) get_post_meta( $post->ID, self::ORBIT_MANAGED_META, true ) ) {
				FileOperations::instance()->hydrate_wp_block_meta_from_theme_file( (int) $post->ID );
			}
			return $response;
		}

		FileOperations::instance()->hydrate_wp_block_meta_from_theme_file( (int) $post->ID );

		$data = $response->get_data();
		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( array_keys( PatternManager::get_metadata_fields() ) as $meta_key ) {
				$data['meta'][ $meta_key ] = get_post_meta( $post->ID, $meta_key, true );
			}
			$response->set_data( $data );
		}

		return $response;
	}

	/**
	 * Handle classic save_post for wp_block.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @param bool     $update  Whether this is an update.
	 * @return void
	 */
	public function handle_pattern_save( int $post_id, $post, bool $update ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
			return;
		}

		$this->process_pattern_save( $post_id, $post, false );
	}

	/**
	 * Handle REST saves for wp_block.
	 *
	 * @param \WP_Post         $post     Post object.
	 * @param \WP_REST_Request $request  Request object.
	 * @param bool             $creating Whether this is a create.
	 * @return void
	 */
	public function handle_pattern_save_rest( $post, $request, bool $creating ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		if ( ! isset( $post->ID ) ) {
			return;
		}

		$this->process_pattern_save( (int) $post->ID, $post, $creating );
	}

	/**
	 * Export published wp_block patterns to the theme patterns directory.
	 *
	 * Synced: write file with Synced: true, keep the post, claim for Orbit.
	 * Unsynced: write file without Synced, keep the post (deleting breaks the open editor).
	 *
	 * @param int      $post_id  Post ID.
	 * @param \WP_Post $post     Post object.
	 * @param bool     $creating Whether this REST request is creating the post.
	 * @return void
	 */
	private function process_pattern_save( int $post_id, $post, bool $creating ): void {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! in_array( $post->post_status, [ 'publish', 'private' ], true ) ) {
			return;
		}

		// "Add pattern" creates an empty published post first — wait for real content.
		if ( $creating || ! $this->has_exportable_content( $post ) ) {
			return;
		}

		$this->sync_btd_synced_from_wp_status( $post_id );

		$synced = $this->is_synced_pattern( $post );
		$saved  = FileOperations::instance()->save_wp_block_pattern_to_file( $post_id, $synced );

		if ( $saved && $synced ) {
			$this->clear_orbit_sync_cache();
		}
	}

	/**
	 * Whether the pattern has block markup worth exporting.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	private function has_exportable_content( $post ): bool {
		$content = trim( (string) $post->post_content );

		if ( '' === $content ) {
			return false;
		}

		// Ignore empty block canvases from the create flow.
		$blocks = parse_blocks( $content );
		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Align `_btd_synced` with core's create-pattern sync choice when unset in DB.
	 *
	 * The Add Pattern modal sets `wp_pattern_sync_status`, not `_btd_synced`.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	private function sync_btd_synced_from_wp_status( int $post_id ): void {
		if ( metadata_exists( 'post', $post_id, '_btd_synced' ) ) {
			return;
		}

		$wp_status = (string) get_post_meta( $post_id, 'wp_pattern_sync_status', true );
		update_post_meta( $post_id, '_btd_synced', 'unsynced' !== $wp_status );
	}

	/**
	 * Whether this wp_block should export as a synced theme pattern.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	private function is_synced_pattern( $post ): bool {
		if ( '1' === (string) get_post_meta( $post->ID, self::ORBIT_MANAGED_META, true ) ) {
			return true;
		}

		if ( $this->is_truthy( get_post_meta( $post->ID, '_btd_synced', true ) ) ) {
			return true;
		}

		// Core: empty wp_pattern_sync_status = synced; "unsynced" = not.
		$wp_status = (string) get_post_meta( $post->ID, 'wp_pattern_sync_status', true );
		if ( 'unsynced' !== $wp_status ) {
			return true;
		}

		$slug = FileOperations::instance()->resolve_wp_block_pattern_slug( $post );
		$file = FileOperations::instance()->get_pattern_file_path_for_slug( $slug );

		if ( ! $file || ! file_exists( $file ) ) {
			return false;
		}

		$headers = get_file_data( $file, [ 'synced' => 'Synced' ] );

		return $this->is_truthy( $headers['synced'] ?? '' );
	}

	/**
	 * Clear Orbit fingerprint cache after writing a synced theme pattern file.
	 *
	 * @return void
	 */
	private function clear_orbit_sync_cache(): void {
		if ( class_exists( '\Eighteen73\Orbit\BlockEditor\SyncedPatterns' ) ) {
			\Eighteen73\Orbit\BlockEditor\SyncedPatterns::instance()->clear_sync_cache();
			return;
		}

		delete_option( 'orbit_synced_theme_patterns_cache' );
	}

	/**
	 * Truthy check for meta / header values.
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	private function is_truthy( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		$value = strtolower( trim( (string) $value ) );

		return in_array( $value, [ '1', 'true', 'yes' ], true );
	}
}
