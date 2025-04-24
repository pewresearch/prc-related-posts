<?php
/**
 * The related posts blocks class.
 *
 * @package PRC\Platform\Related_Posts
 */

namespace PRC\Platform\Related_Posts;

/**
 * The related posts blocks class.
 */
class Blocks {
	/**
	 * Constructor.
	 *
	 * @param object $loader The loader object.
	 */
	public function __construct( $loader ) {
		require_once PRC_RELATED_POSTS_BLOCKS_DIR . '/build/related-posts-query/class-related-posts-query.php';

		$this->init( $loader );
	}

	/**
	 * Initialize the class.
	 *
	 * @param object $loader The loader object.
	 */
	public function init( $loader ) {
		\wp_register_block_metadata_collection(
			plugin_dir_path( __FILE__ ) . 'build',
			plugin_dir_path( __FILE__ ) . 'build/blocks-manifest.php'
		);

		new Related_Posts_Query( $loader );
	}
}
