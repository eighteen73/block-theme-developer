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
	 * Prevent re-entrant file removal for the same post.
	 *
	 * @var array<int, true>
	 */
	private static array $removing_pattern_ids = [];

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
		add_action( 'trashed_post', [ $this, 'handle_pattern_removal' ] );
		add_action( 'before_delete_post', [ $this, 'handle_pattern_removal' ] );
		// After core theme pattern registration (10) and Orbit synced upsert (20).
		add_action( 'init', [ $this, 'sync_unsynced_theme_patterns' ], 21 );
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

		if (
			( $file && file_exists( $file ) )
			|| '1' === (string) get_post_meta( $post->ID, self::ORBIT_MANAGED_META, true )
		) {
			FileOperations::instance()->hydrate_wp_block_meta_from_theme_file( (int) $post->ID );
		}

		$data = $response->get_data();
		if ( isset( $data['meta'] ) && is_array( $data['meta'] ) ) {
			foreach ( array_keys( PatternManager::get_metadata_fields() ) as $meta_key ) {
				$data['meta'][ $meta_key ] = get_post_meta( $post->ID, $meta_key, true );
			}
		}

		if ( isset( $data['content']['raw'] ) && is_string( $data['content']['raw'] ) ) {
			$stripped = FileOperations::instance()->strip_content_only_template_lock( $data['content']['raw'] );
			if ( $stripped !== $data['content']['raw'] ) {
				$data['content']['raw'] = $stripped;
			}
		}

		$response->set_data( $data );

		return $response;
	}

	/**
	 * Import unsynced theme pattern files into editable wp_block posts.
	 *
	 * @return void
	 */
	public function sync_unsynced_theme_patterns(): void {
		FileOperations::instance()->sync_unsynced_theme_patterns();
	}

	/**
	 * Remove the theme pattern file when the last matching wp_block is deleted.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function handle_pattern_removal( int $post_id ): void {
		if ( isset( self::$removing_pattern_ids[ $post_id ] ) ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return;
		}

		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}

		$slug = FileOperations::instance()->resolve_wp_block_pattern_slug( $post );
		if ( '' === $slug || $this->pattern_has_remaining_post( $post_id, $slug ) ) {
			return;
		}

		self::$removing_pattern_ids[ $post_id ] = true;

		FileOperations::instance()->delete_pattern_file_for_post( $post_id );
		$this->clear_orbit_sync_cache();
	}

	/**
	 * Whether another live wp_block still represents this theme pattern slug.
	 *
	 * @param int    $post_id   Post being removed.
	 * @param string $full_slug Theme-namespaced pattern slug.
	 * @return bool
	 */
	private function pattern_has_remaining_post( int $post_id, string $full_slug ): bool {
		$query_args = [
			'post_type'              => 'wp_block',
			'post_status'            => [ 'publish', 'draft', 'private', 'pending' ],
			'post__not_in'           => [ $post_id ],
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => true,
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		];

		$by_meta = get_posts(
			array_merge(
				$query_args,
				[
					'meta_query' => [
						'relation' => 'OR',
						[
							'key'   => FileOperations::META_THEME_SLUG,
							'value' => $full_slug,
						],
						[
							'key'   => '_orbit_theme_pattern_slug',
							'value' => $full_slug,
						],
					],
				]
			)
		);

		if ( ! empty( $by_meta ) ) {
			return true;
		}

		$file_ops = FileOperations::instance();
		$short    = $file_ops->normalize_pattern_slug( $full_slug );
		$names    = array_values(
			array_unique(
				array_filter(
					[
						$short,
						$file_ops->get_theme_slug() . '-' . $short,
					]
				)
			)
		);

		foreach ( $names as $post_name ) {
			$by_name = get_posts(
				array_merge(
					$query_args,
					[
						'name' => $post_name,
					]
				)
			);

			if ( ! empty( $by_name ) ) {
				return true;
			}
		}

		return false;
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
		if ( FileOperations::instance()->is_syncing_from_files() ) {
			return;
		}

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
	 * Once Orbit has claimed the wp_block, synced is permanent. Existing
	 * page instances store a core/block ref; un-syncing would leave those
	 * refs in place and re-register a duplicate unsynced theme pattern.
	 *
	 * @param \WP_Post $post Post object.
	 * @return bool
	 */
	private function is_synced_pattern( $post ): bool {
		if ( '1' === (string) get_post_meta( $post->ID, self::ORBIT_MANAGED_META, true ) ) {
			return true;
		}

		if ( metadata_exists( 'post', $post->ID, '_btd_synced' ) ) {
			return $this->is_truthy( get_post_meta( $post->ID, '_btd_synced', true ) );
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
