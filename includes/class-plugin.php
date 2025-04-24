<?php
/**
 * Plugin class.
 *
 * @package    PRC\Platform\Related_Posts
 */

namespace PRC\Platform\Related_Posts;

use WP_Error;

/**
 * Plugin class.
 *
 * @package    PRC\Platform\Related_Posts
 */
class Plugin {
	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * The cache key.
	 *
	 * @since    1.0.0
	 * @access   public
	 * @var      string    $cache_key    The cache key.
	 */
	public static $cache_key = 'relatedPosts';

	/**
	 * The cache time.
	 *
	 * @since    1.0.0
	 * @access   public
	 * @var      string    $cache_time    The cache time.
	 */
	public static $cache_time = 1 * HOUR_IN_SECONDS;

	/**
	 * The meta key.
	 *
	 * @since    1.0.0
	 * @access   public
	 * @var      string    $meta_key    The meta key.
	 */
	public static $meta_key = 'relatedPosts';

	/**
	 * The schema properties.
	 *
	 * @since    1.0.0
	 * @access   public
	 * @var      array    $schema_properties    The schema properties.
	 */
	public static $schema_properties = array(
		'date'      => array(
			'type' => 'string',
		),
		'key'       => array(
			'type' => 'string',
		),
		'link'      => array(
			'type' => 'string',
		),
		'permalink' => array(
			'type' => 'string',
		),
		'postId'    => array(
			'type' => 'integer',
		),
		'title'     => array(
			'type' => 'string',
		),
		'label'     => array(
			'type' => 'string',
		),
	);

	/**
	 * The handle.
	 *
	 * @since    1.0.0
	 * @access   public
	 * @var      string    $handle    The handle.
	 */
	public static $handle = 'prc-platform-related-posts';

	/**
	 * Define the core functionality of the platform as initialized by hooks.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		$this->version     = '1.0.0';
		$this->plugin_name = 'prc-related-posts';

		$this->load_dependencies();
		$this->init_dependencies();
	}


	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {
		// Load plugin loading class.
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-loader.php';

		// Initialize the loader.
		$this->loader = new Loader();

		// Load API.
		require_once plugin_dir_path( __DIR__ ) . '/includes/class-api.php';

		// Load blocks.
		require_once plugin_dir_path( __DIR__ ) . '/blocks/class-blocks.php';
	}

	/**
	 * Initialize the dependencies.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function init_dependencies() {
		$this->loader->add_action( 'init', $this, 'register_meta_fields' );
		$this->loader->add_action( 'enqueue_block_editor_assets', $this, 'enqueue_assets' );
		$this->loader->add_action( 'wpcom_vip_cache_pre_execute_purges', $this, 'clear_cache_on_purge' );
		$this->loader->add_action( 'prc_platform_on_update', $this, 'clear_cache_on_update' );

		// Initialize the blocks.
		new Blocks( $this->get_loader() );
	}

	/**
	 * Get the enabled post types.
	 *
	 * @since    1.0.0
	 * @access   public
	 * @return   array
	 */
	public static function get_enabled_post_types() {
		return apply_filters( 'prc_platform__related_posts_enabled_post_types', array( 'post' ) );
	}

	/**
	 * Register the meta fields.
	 *
	 * @since    1.0.0
	 * @access   public
	 */
	public function register_meta_fields() {
		foreach ( self::get_enabled_post_types() as $post_type ) {
			register_post_meta(
				$post_type,
				self::$meta_key,
				array(
					'single'        => true,
					'type'          => 'array',
					'description'   => 'Array of custom related posts.',
					'show_in_rest'  => array(
						'schema' => array(
							'items' => array(
								'type'       => 'object',
								'properties' => self::$schema_properties,
							),
						),
					),
					'auth_callback' => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Register the assets.
	 *
	 * @since    1.0.0
	 * @access   public
	 */
	public function register_assets() {
		$asset_file = include plugin_dir_path( __FILE__ ) . 'inspector-sidebar-panel/build/index.asset.php';
		$asset_slug = self::$handle;
		$script_src = plugin_dir_url( __FILE__ ) . 'inspector-sidebar-panel/build/index.js';

		$script = wp_register_script(
			$asset_slug,
			$script_src,
			$asset_file['dependencies'],
			$asset_file['version'],
			true
		);

		if ( ! $script ) {
			return new WP_Error( self::$handle, 'Failed to register all assets' );
		}

		return true;
	}

	/**
	 * Enqueue the assets.
	 *
	 * @hook enqueue_block_editor_assets
	 * @return void
	 */
	public function enqueue_assets() {
		$registered = $this->register_assets();
		if ( is_admin() && ! is_wp_error( $registered ) ) {
			$screen_post_type = \PRC\Platform\get_wp_admin_current_post_type();
			if ( in_array( $screen_post_type, self::get_enabled_post_types() ) ) {
				wp_enqueue_script( self::$handle );
			}
		}
	}

	/**
	 * Supports VIP caching to clear cache when requested by url.
	 *
	 * @hook wpcom_vip_cache_pre_execute_purges
	 * @param mixed $urls The URLs to clear cache for.
	 * @return void
	 */
	public function clear_cache_on_purge( $urls ) {
		foreach ( $urls as $url ) {
			$url_to_post_id = url_to_postid( $url );
			if ( 0 !== $url_to_post_id ) {
				wp_cache_delete( $url_to_post_id, self::$cache_key );
			}
		}
	}

	/**
	 * Supports VIP caching to clear cache when requested by url.
	 *
	 * @hook prc_platform_on_update
	 * @param mixed $post The post object.
	 */
	public function clear_cache_on_update( $post ) {
		$post_id = $post->ID;
		wp_cache_delete( $post_id, self::$cache_key );
	}

	/**
	 * Process the related posts.
	 *
	 * @hook prc_related_posts
	 *
	 * @param mixed $post_id The post ID.
	 * @param mixed $args The arguments.
	 */
	public function process( $post_id, $args = array() ) {
		$api = new API( $post_id, $args );
		return $api->query();
	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    PRC\Platform\Related_Posts\Loader
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}
}
