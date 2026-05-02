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

		// Load block classes.
		require_once plugin_dir_path( __DIR__ ) . '/build/related-posts-query/class-related-posts-query.php';
	}

	/**
	 * Initialize the dependencies.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function init_dependencies() {
		$this->loader->add_action( 'init', $this, 'register_default_post_type_support', 5 );
		$this->loader->add_action( 'init', $this, 'register_meta_fields' );
		$this->loader->add_action( 'rest_api_init', $this, 'register_rest_fields', 99 );
		$this->loader->add_action( 'enqueue_block_editor_assets', $this, 'enqueue_assets' );
		$this->loader->add_action( 'wpcom_vip_cache_pre_execute_purges', $this, 'clear_cache_on_purge' );
		$this->loader->add_action( 'prc_platform_on_update', $this, 'clear_cache_on_update' );

		// Initialize the block.
		wp_register_block_metadata_collection(
			plugin_dir_path( __DIR__ ) . 'build',
			plugin_dir_path( __DIR__ ) . 'build/blocks-manifest.php'
		);
		new Related_Posts_Query( $this->get_loader() );

		// After WP AI plugins_loaded bootstrap (priority 10); Abstract_Feature is not autoloadable before that.
		add_action( 'plugins_loaded', array( $this, 'register_wp_ai_features' ), 11 );
	}

	/**
	 * Load Related Posts AI classes and register the feature with the WP AI plugin.
	 *
	 * @return void
	 */
	public function register_wp_ai_features() {
		if ( ! class_exists( '\WordPress\AI\Abstracts\Abstract_Feature' ) ) {
			return;
		}

		require_once plugin_dir_path( __DIR__ ) . '/includes/ai-experiment/class-related-posts-ai-ability.php';
		require_once plugin_dir_path( __DIR__ ) . '/includes/ai-experiment/class-related-posts-ai-experiment.php';

		add_action(
			'wpai_register_features',
			function ( $registry ) {
				$registry->register_feature( new Related_Posts_AI_Experiment() );
			}
		);
	}

	/**
	 * Register default post type support for related posts.
	 *
	 * @hook init
	 */
	public function register_default_post_type_support() {
		add_post_type_support( 'post', 'prc-related-posts' );
	}

	/**
	 * Get the enabled post types.
	 *
	 * @since    1.0.0
	 * @access   public
	 * @return   array
	 */
	public static function get_enabled_post_types() {
		$post_types      = get_post_types( array( 'public' => true ), 'names' );
		$supported_types = array_values(
			array_filter(
				$post_types,
				function ( $pt ) {
					return post_type_supports( $pt, 'prc-related-posts' );
				}
			)
		);
		// Maintain backward compatibility with filter.
		$filter_types       = apply_filters( 'prc_platform__related_posts_enabled_post_types', array() );
		$enabled_post_types = array_unique( array_merge( $supported_types, $filter_types ) );
		return array_values( $enabled_post_types );
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
					'single'            => true,
					'type'              => 'array',
					'description'       => 'Array of custom related posts.',
					'show_in_rest'      => array(
						'schema' => array(
							'items' => array(
								'type'       => 'object',
								'properties' => self::$schema_properties,
							),
						),
					),
					'revisions_enabled' => true,
					'auth_callback'     => function () {
						return current_user_can( 'edit_posts' );
					},
				)
			);
		}
	}

	/**
	 * Register the relatedPostsOrdered REST field for RTC compatibility.
	 *
	 * The editor reads/writes through this field instead of raw meta, so each
	 * mutation is a single editPost() call with no dual-write churn.
	 *
	 * @hook rest_api_init
	 */
	public function register_rest_fields(): void {
		$schema = array(
			'description' => 'Ordered related posts for RTC-safe editing.',
			'type'        => 'array',
			'items'       => array(
				'type'       => 'object',
				'properties' => self::$schema_properties,
			),
		);

		foreach ( self::get_enabled_post_types() as $post_type ) {
			register_rest_field(
				$post_type,
				'relatedPostsOrdered',
				array(
					'get_callback'    => array( $this, 'get_related_posts_ordered' ),
					'update_callback' => array( $this, 'update_related_posts_ordered' ),
					'schema'          => $schema,
				)
			);
		}
	}

	/**
	 * REST get callback: relatedPostsOrdered.
	 *
	 * @param mixed $object Prepared post (array or WP_Post).
	 * @return array<int,array<string,mixed>>
	 */
	public function get_related_posts_ordered( mixed $object ): array {
		$post_id = is_array( $object ) && isset( $object['id'] ) ? (int) $object['id'] : 0;
		if ( $post_id <= 0 ) {
			return array();
		}
		$raw = get_post_meta( $post_id, self::$meta_key, true );
		return is_array( $raw ) ? $this->sanitize_related_posts_array( $raw ) : array();
	}

	/**
	 * REST update callback: relatedPostsOrdered.
	 *
	 * @param mixed $value  New value from the editor.
	 * @param mixed $object Post object.
	 * @return bool|\WP_Error
	 */
	public function update_related_posts_ordered( mixed $value, mixed $object ): bool|\WP_Error {
		$post_id = $object->ID ?? ( is_array( $object ) ? ( $object['id'] ?? 0 ) : 0 );
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return new \WP_Error( 'invalid_post', 'Invalid post for relatedPostsOrdered.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error( 'rest_forbidden', 'Sorry, you are not allowed to edit this post.' );
		}
		$sanitized = $this->sanitize_related_posts_array( is_array( $value ) ? $value : array() );
		update_post_meta( $post_id, self::$meta_key, $sanitized );
		wp_cache_delete( $post_id, self::$cache_key );
		return true;
	}

	/**
	 * Sanitize an array of related post rows from REST input.
	 *
	 * @param array $value Raw array.
	 * @return array<int,array<string,mixed>>
	 */
	private function sanitize_related_posts_array( array $value ): array {
		$out = array();
		foreach ( $value as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$out[] = array(
				'date'      => isset( $row['date'] ) ? (string) $row['date'] : '',
				'key'       => isset( $row['key'] ) ? (string) $row['key'] : '',
				'link'      => isset( $row['link'] ) ? esc_url_raw( (string) $row['link'] ) : '',
				'permalink' => isset( $row['permalink'] ) ? esc_url_raw( (string) $row['permalink'] ) : '',
				'postId'    => isset( $row['postId'] ) ? (int) $row['postId'] : 0,
				'title'     => isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '',
				'label'     => isset( $row['label'] ) ? sanitize_text_field( (string) $row['label'] ) : '',
			);
		}
		return $out;
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
			$screen_post_type = \PRC\BlockUtils\get_wp_admin_current_post_type();
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
