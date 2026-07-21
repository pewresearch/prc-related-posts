<?php
/**
 * PRC Related Posts
 *
 * @package           PRC_RELATED_POSTS
 * @author            Seth Rubenstein
 * @copyright         2024 Pew Research Center
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       PRC Related Posts
 * Plugin URI:        https://github.com/pewresearch/prc-related-posts
 * Description:       A WordPress plugin for PRC Platformthat provides editorial tools and customizable blocks for managing related content relationships. Features an intuitive editor interface for manual curation and dynamic blocks for displaying related posts across your site with flexible layout options.
 * Version:           1.0.0
 * Requires at least: 6.7
 * Requires PHP:      8.2
 * Author:            Seth Rubenstein
 * Author URI:        https://pewresearch.org
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       prc-related-posts
 * Requires Plugins:  prc-scripts, prc-post-publish-pipeline
 */

namespace PRC\Platform\Related_Posts;

// If this file is called directly, abort.
if ( ! defined( 'WPINC' ) ) {
	die;
}
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
define( 'PRC_RELATED_POSTS_FILE', __FILE__ );
define( 'PRC_RELATED_POSTS_DIR', __DIR__ );
define( 'PRC_RELATED_POSTS_BLOCKS_DIR', __DIR__ . '/build' );
define( 'PRC_RELATED_POSTS_VERSION', '1.0.0' );

// When running inside the PRC Platform monorepo the root autoloader already
// provides every dependency; skip per-plugin Jetpack Autoloader initialization.
if ( ! defined( 'PRC_PLATFORM' ) ) {
	$prc_related_posts_autoloader = __DIR__ . '/vendor/autoload_packages.php';
	if ( file_exists( $prc_related_posts_autoloader ) ) {
		require_once $prc_related_posts_autoloader;
	}
	unset( $prc_related_posts_autoloader );
}

/**
 * Helper utilities
 */
require plugin_dir_path( __FILE__ ) . 'includes/utils.php';

/**
 * The core plugin class that is used to define the hooks that initialize the various components.
 */
require plugin_dir_path( __FILE__ ) . 'includes/class-plugin.php';

/**
 * Begins execution of the plugin.
 *
 * Since everything within the plugin is registered via hooks,
 * then kicking off the plugin from this point in the file does
 * not affect the page life cycle.
 *
 * @since    1.0.0
 */
function run_prc_related_posts() {
	$plugin = new Plugin();
	$plugin->run();
}
run_prc_related_posts();
