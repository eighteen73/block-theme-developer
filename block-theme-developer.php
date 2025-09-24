<?php
/**
 * Plugin Name:       Block Theme Developer
 * Description:       A developer focused companion plugin for building block themes for WordPress.
 * Version:           1.0.4
 * Requires at least: 6.8
 * Requires PHP:      8.3
 * Author:            eighteen73
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/eighteen73/block-theme-developer
 * Text Domain:       block-theme-developer
 *
 * @package Block Theme Developer
 */

namespace Eighteen73\BlockThemeDeveloper;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Useful global constants.
define( 'BLOCK_THEME_DEVELOPER_URL', plugin_dir_url( __FILE__ ) );
define( 'BLOCK_THEME_DEVELOPER_PATH', plugin_dir_path( __FILE__ ) );

// Set up BLOCK_THEME_DEVELOPER_MODE constant
if ( ! defined( 'BLOCK_THEME_DEVELOPER_MODE' ) ) {
	$environment  = wp_get_environment_type();
	$default_mode = in_array( $environment, [ 'development', 'local' ], true ) ? 'file' : 'api';
	define( 'BLOCK_THEME_DEVELOPER_MODE', $default_mode );
}

// Require the autoloader.
require_once 'autoload.php';

// Initialise classes.
PatternManager::instance();
FileOperations::instance();
RestApi::instance();
