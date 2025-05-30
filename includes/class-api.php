<?php
/**
 * The related posts API class.
 *
 * @package PRC\Platform\Related_Posts
 */

namespace PRC\Platform\Related_Posts;

use WP_Error;
use WP_Query;

/**
 * The related posts API class.
 */
class API {
	/**
	 * The post ID.
	 *
	 * @var int
	 */
	public $ID;

	/**
	 * The post type.
	 *
	 * @var string
	 */
	public $post_type;

	/**
	 * The arguments.
	 *
	 * @var array
	 */
	public $args = array(
		'taxonomy' => 'category',
	);

	/**
	 * Constructor.
	 *
	 * @param int   $post_id The post ID.
	 * @param array $args    The arguments.
	 */
	public function __construct( $post_id, $args = array() ) {
		// Check if the post is a child of another post, if so, use the parent post ID.
		$post_parent_id = wp_get_post_parent_id( $post_id );
		if ( 0 !== $post_parent_id ) {
			$post_id = $post_parent_id;
		}
		$this->ID  = $post_id;
		$post_type = get_post_type( $post_id );
		if ( false === $post_type ) {
			return new WP_Error( 'invalid_post_id', __( 'Invalid post ID.' ) );
		}
		$this->post_type = $post_type;
		$this->args      = wp_parse_args( $args, $this->args );
	}

	/**
	 * Get the label.
	 *
	 * @param int $post_id The post ID.
	 * @return string
	 */
	private function get_label( $post_id ) {
		// Construct Label from post terms.
		$terms = wp_get_object_terms( $post_id, 'formats', array( 'fields' => 'names' ) );
		$label = 'Report';
		if ( ! is_wp_error( $terms ) || ! empty( $terms ) ) {
			$label = array_shift( $terms );
		}
		if ( null === $label ) {
			$label = 'Report';
		}
		return ucwords( str_replace( '-', ' ', $label ) );
	}

	/**
	 * Get the related posts from Parsely.
	 *
	 * @param int $post_id The post ID.
	 * @return array
	 */
	private function get_related_posts_from_parsely( $post_id ) {
		// Check cache for related posts.
		$related_posts = wp_cache_get( $post_id, 'parsely_related_posts' );
		if ( false !== $related_posts ) {
			return $related_posts;
		}
		$primary_taxonomy_term_id = \PRC\Platform\get_primary_term_id( 'category', $this->ID );
		$primary_taxonomy_term    = get_term_by( 'term_taxonomy_id', (int) $primary_taxonomy_term_id, 'category' );
		$section                  = $primary_taxonomy_term->name;
		// Query Parsely for related posts for this post by url by post id and by primary topic term/section name.
		$related_posts = array();
		$api_url       = 'https://api.parsely.com/v2/related?apikey=pewresearch.org&section=' . $section . 'url=' . get_permalink( $post_id );
		$response      = \vip_safe_wp_remote_get( $api_url );
		if ( is_wp_error( $response ) ) {
			return $related_posts;
		}

		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || empty( $data ) ) {
			return $related_posts;
		}

		$related_posts = array_map(
			function ( $item ) {
				return array(
					'postId'   => $item['post_id'],
					'postType' => get_post_type( $item['post_id'] ),
					'url'      => get_permalink( $item['post_id'] ),
					'title'    => $item['title'],
					'date'     => $item['pub_date'],
					'excerpt'  => $item['excerpt'],
					'label'    => $this->get_label( $item['post_id'] ),
				);
			},
			$data
		);

		// Store the related posts for 1 day.
		wp_cache_set( $post_id, $related_posts, 'parsely_related_posts', 1 * DAY_IN_SECONDS );
		return $related_posts;
	}

	/**
	 * Get the posts with matching primary terms.
	 *
	 * @param int  $posts_per_page The number of posts per page.
	 * @param bool $fallback_to_taxonomy The fallback to taxonomy.
	 * @return array
	 */
	private function get_posts_with_matching_primary_terms( $posts_per_page = 5, $fallback_to_taxonomy = false ) {
		$taxonomy      = $this->args['taxonomy'];
		$meta_key      = '_yoast_wpseo_primary_' . $taxonomy;
		$related_posts = array();

		// Get the primary topic for this post.
		$primary_taxonomy_term_id = \PRC\Platform\get_primary_term_id( $taxonomy, $this->ID );
		$primary_taxonomy_term    = get_term_by( 'term_taxonomy_id', (int) $primary_taxonomy_term_id, $taxonomy );

		if ( ! $primary_taxonomy_term ) {
			// Get the first term for this post.
			$terms = wp_get_post_terms( $this->ID, $taxonomy );
			if ( ! empty( $terms ) ) {
				$primary_taxonomy_term = $terms[0];
			}
		}
		if ( empty( $primary_taxonomy_term ) ) {
			return $related_posts;
		}

		$query_args = array(
			'post_type'      => array( 'post', 'short-read', 'feature', 'fact-sheet' ),
			'post_parent'    => 0,
			'posts_per_page' => $posts_per_page,
			'meta_key'       => $meta_key,
			'meta_value'     => $primary_taxonomy_term->term_id,
			'post__not_in'   => array( $this->ID ), // Exclude this post.
			'facetwp'        => false,
		);

		// If posts with matching primary term are not found, then fallback to searching for posts assigned to this posts priamry term.
		if ( true === $fallback_to_taxonomy ) {
			unset( $query_args['meta_key'] );
			unset( $query_args['meta_value'] );
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => $taxonomy,
					'field'    => 'term_id',
					'terms'    => $primary_taxonomy_term->term_id,
				),
			);
		}

		$query = new WP_Query( $query_args );
		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id         = get_the_ID();
				$label           = $this->get_label( $post_id );
				$related_posts[] = array(
					'postId'   => $post_id,
					'postType' => get_post_type(),
					'url'      => get_permalink( $post_id ),
					'title'    => get_the_title(),
					'date'     => get_the_date(),
					'excerpt'  => false,
					'label'    => $label,
				);
			}
		}
		wp_reset_postdata();
		return $related_posts;
	}

	/**
	 * Structures custom related post data.
	 *
	 * @return array
	 */
	private function get_custom_related_posts() {
		$data = get_post_meta( $this->ID, Plugin::$meta_key, true );
		if ( $this->is_json( $data ) ) {
			$data = json_decode( $data, true );
		}

		$related_posts = array();
		if ( empty( $data ) ) {
			return $related_posts;
		}

		foreach ( $data as $key => $item ) {
			if ( array_key_exists( 'postId', $item ) ) {
				$related_posts[] = array(
					'postId'   => $item['postId'],
					'postType' => get_post_type( $item['postId'] ),
					'date'     => array_key_exists( 'date', $item ) ? $item['date'] : null,
					'url'      => array_key_exists( 'permalink', $item ) ? $item['permalink'] : ( array_key_exists( 'link', $item ) ? $item['link'] : null ),
					'title'    => array_key_exists( 'title', $item ) ? stripslashes( $item['title'] ) : null,
					'label'    => array_key_exists( 'label', $item ) && ! empty( $item['label'] ) ? $item['label'] : 'Report',
				);
			}
		}

		return $related_posts;
	}

	/**
	 * Structures Jetpack Related Posts data and merges custom related posts. Sorts combined array by date desc.
	 *
	 * @return array
	 */
	private function get_related_posts() {
		$post_id  = $this->ID;
		$per_page = 5;

		$related_posts = array();

		// If the user is not logged in, or if this is not a preview, then check the cache for this data. Otherwise proceed to query for it.
		$related_posts = ! is_preview() && ! is_user_logged_in() ? wp_cache_get( $post_id, Plugin::$cache_key ) : false;
		if ( false !== $related_posts ) {
			return $related_posts;
		}

		$post_date        = get_the_date( 'Y-m-d', $post_id );
		$legacy_fix_check = get_post_meta( $post_id, '_legacy_related_posts_fixed', true );
		$legacy_fix_check = boolval( $legacy_fix_check );
		if ( ( strtotime( $post_date ) < strtotime( '2024-04-18' ) ) && true !== $legacy_fix_check ) {
			do_action( 'qm/debug', 'Custom Related Posts Disabled For Legacy Post' );
			$custom_posts = array();
		} else {
			$custom_posts = $this->get_custom_related_posts();
		}

		if ( 5 > count( $custom_posts ) && ( empty( $related_posts ) || false === $related_posts ) ) {
			$related_posts = $this->get_posts_with_matching_primary_terms( $per_page );
			// If not enough related posts are found keying off primary topic widen the search and get all posts that at least have this post's primary topic as a topic.
			if ( 5 > count( $related_posts ) ) {
				$related_posts = $this->get_posts_with_matching_primary_terms( $per_page, true );
			}
			// Sort by date desc.
			usort(
				$related_posts,
				function ( $a, $b ) {
					return strtotime( $b['date'] ) - strtotime( $a['date'] );
				}
			);
		}

		if ( false !== $related_posts && ! empty( $related_posts ) ) {
			// If there are more than 5 related posts, then only show the first 5.
			$related_posts = array_slice( $related_posts, 0, $per_page );
		} else {
			$related_posts = array();
		}

		// Splice the custom related posts into the beginning of the related posts array.
		$related_posts = array_merge( $custom_posts, $related_posts );

		// Restrict to only 5 items.
		$related_posts = array_slice( $related_posts, 0, $per_page );

		if ( ! is_preview() && ! is_user_logged_in() ) {
			// Store the related posts for 1 hour.
			wp_cache_set( $post_id, $related_posts, Plugin::$cache_key, Plugin::$cache_time );
		}

		return $related_posts;
	}

	/**
	 * Check if the string is JSON decodable.
	 *
	 * @param mixed $json_maybe The string to check if its JSON decodable.
	 * @return bool
	 */
	public function is_json( $json_maybe ) {
		return is_string( $json_maybe ) && is_array( json_decode( $json_maybe, true ) ) ? true : false;
	}

	/**
	 * Hooks on to prc_related_posts filter and returns a combined array of Jetpack and custom related posts.
	 *
	 * @return array
	 */
	public function query() {
		// If this not an approved post type then return empty array.
		if ( ! in_array( $this->post_type, Plugin::get_enabled_post_types() ) ) {
			return array();
		}
		return $this->get_related_posts();
	}
}
