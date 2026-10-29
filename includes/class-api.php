<?php
/**
 * The related posts API class.
 *
 * @package PRC\Platform\Related_Posts
 */

namespace PRC\Platform\Related_Posts;

use PRC\Platform\Schema_SEO\Primary_Term;
use WP_Query;

/**
 * The related posts API class.
 */
class API {
	/**
	 * How many of the newest posts in the primary term to consider.
	 *
	 * @var int
	 */
	const CANDIDATE_POOL_SIZE = 100;

	/**
	 * Object cache group for per-term candidate pools and their last_changed salt.
	 *
	 * Core's `posts` last_changed is bumped by every postmeta write on the network, so pools use their own salt.
	 *
	 * @var string
	 */
	const POOL_CACHE_GROUP = 'relatedPostsPool';

	/**
	 * Post types that can appear as related posts.
	 *
	 * @var string[]
	 */
	const CANDIDATE_POST_TYPES = array( 'post', 'short-read', 'feature', 'fact-sheet' );

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
	public $post_type = '';

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
			return;
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
		// The object term cache is ordered by name, like wp_get_object_terms(), and is primed by _prime_post_caches().
		$terms = get_the_terms( $post_id, 'formats' );
		$label = 'Report';
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			$label = reset( $terms )->name;
		}
		if ( null === $label ) {
			$label = 'Report';
		}
		return ucwords( str_replace( '-', ' ', $label ) );
	}

	/**
	 * Order candidate posts so those sharing the primary term come first.
	 *
	 * Uses the same effective primary term as the editor shows, which falls back to the first
	 * assigned term when none is saved. Candidate order is preserved within each group.
	 *
	 * @param int[]  $candidate_ids   Candidate post IDs.
	 * @param int    $primary_term_id The current post's primary term ID.
	 * @param string $taxonomy        Taxonomy slug.
	 * @return int[]
	 */
	public static function rank_by_primary_term( array $candidate_ids, int $primary_term_id, string $taxonomy ): array {
		return self::rank_flagged( self::flag_primary_term_matches( $candidate_ids, $primary_term_id, $taxonomy ) );
	}

	/**
	 * Flag which candidates have the given term as their effective primary term.
	 *
	 * Same answer as comparing Primary_Term::get_id() per candidate, but the first-assigned-term fallback
	 * runs as one query for all candidates instead of one per candidate.
	 *
	 * @param int[]  $candidate_ids   Candidate post IDs.
	 * @param int    $primary_term_id The term ID to match.
	 * @param string $taxonomy        Taxonomy slug.
	 * @return array<int, bool> Candidate ID => whether it shares the primary term, in candidate order.
	 */
	public static function flag_primary_term_matches( array $candidate_ids, int $primary_term_id, string $taxonomy ): array {
		$primary_ids = array();
		foreach ( $candidate_ids as $candidate_id ) {
			$primary_ids[ (int) $candidate_id ] = Primary_Term::get_id( (int) $candidate_id, $taxonomy, false );
		}

		$without_primary = array_keys( $primary_ids, null, true );
		if ( ! empty( $without_primary ) ) {
			// Ordered by name, like the wp_get_post_terms() fallback in Primary_Term::get_id().
			$terms = wp_get_object_terms(
				$without_primary,
				$taxonomy,
				array(
					'fields'                 => 'all_with_object_id',
					'orderby'                => 'name',
					'update_term_meta_cache' => false,
				)
			);
			if ( ! is_wp_error( $terms ) ) {
				foreach ( $terms as $term ) {
					$primary_ids[ (int) $term->object_id ] ??= (int) $term->term_id;
				}
			}
		}

		$flags = array();
		foreach ( $primary_ids as $candidate_id => $candidate_primary_id ) {
			$flags[ $candidate_id ] = $primary_term_id === $candidate_primary_id;
		}
		return $flags;
	}

	/**
	 * Order flagged candidates so primary-term matches come first, keeping candidate order within each group.
	 *
	 * @param array<int, bool> $flags Candidate ID => whether it shares the primary term.
	 * @return int[]
	 */
	private static function rank_flagged( array $flags ): array {
		return array_merge( array_keys( $flags, true, true ), array_keys( $flags, false, true ) );
	}

	/**
	 * Get the newest published candidates in a term, flagged by whether the term is also their primary term.
	 *
	 * The pool is the same for every post in the term, so it is cached per term and shared by all visitors.
	 * It holds one extra candidate so the caller can drop itself and still have CANDIDATE_POOL_SIZE.
	 *
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return array<int, bool> Candidate ID => same primary term, newest first.
	 */
	private static function get_candidate_pool( int $term_id, string $taxonomy ): array {
		$cache_key = $taxonomy . ':' . $term_id . ':' . wp_cache_get_last_changed( self::POOL_CACHE_GROUP );
		$pool      = wp_cache_get( $cache_key, self::POOL_CACHE_GROUP );
		if ( is_array( $pool ) ) {
			return $pool;
		}

		// Primary terms live in serialized Schema SEO meta, so match them in PHP over the newest posts in the term.
		$query = new WP_Query(
			array(
				'post_type'              => self::CANDIDATE_POST_TYPES,
				// Explicit so the shared pool never holds private posts a logged-in editor could read.
				'post_status'            => 'publish',
				'post_parent'            => 0,
				'posts_per_page'         => self::CANDIDATE_POOL_SIZE + 1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
				'tax_query'              => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					array(
						'taxonomy' => $taxonomy,
						'field'    => 'term_id',
						'terms'    => $term_id,
					),
				),
			)
		);

		$candidate_ids = array_map( 'intval', $query->posts );
		update_meta_cache( 'post', $candidate_ids );
		$pool = self::flag_primary_term_matches( $candidate_ids, $term_id, $taxonomy );

		wp_cache_set( $cache_key, $pool, self::POOL_CACHE_GROUP, 6 * HOUR_IN_SECONDS );
		return $pool;
	}

	/**
	 * Invalidate every cached candidate pool.
	 *
	 * @return void
	 */
	public static function flush_candidate_pools() {
		wp_cache_set_last_changed( self::POOL_CACHE_GROUP );
	}

	/**
	 * Get the newest posts in this post's primary term, preferring posts that share it as their primary term.
	 *
	 * @param int $posts_per_page The number of posts to return.
	 * @return array
	 */
	private function get_posts_in_primary_term( $posts_per_page = 5 ) {
		$taxonomy = $this->args['taxonomy'];

		if ( ! class_exists( Primary_Term::class ) ) {
			return array();
		}
		$primary_taxonomy_term = Primary_Term::get_term( $this->ID, $taxonomy );
		if ( ! ( $primary_taxonomy_term instanceof \WP_Term ) ) {
			return array();
		}

		$pool = self::get_candidate_pool( $primary_taxonomy_term->term_id, $taxonomy );
		if ( array_key_exists( $this->ID, $pool ) ) {
			unset( $pool[ $this->ID ] );
		} else {
			$pool = array_slice( $pool, 0, self::CANDIDATE_POOL_SIZE, true );
		}

		$ranked_ids = array_slice( self::rank_flagged( $pool ), 0, $posts_per_page );
		_prime_post_caches( $ranked_ids );

		return array_map(
			function ( $post_id ) {
				return array(
					'postId'   => $post_id,
					'postType' => get_post_type( $post_id ),
					'url'      => get_permalink( $post_id ),
					'title'    => get_the_title( $post_id ),
					'date'     => get_the_date( '', $post_id ),
					'excerpt'  => false,
					'label'    => $this->get_label( $post_id ),
				);
			},
			$ranked_ids
		);
	}

	/**
	 * Structures custom related post data.
	 *
	 * @return array
	 */
	private function get_custom_related_posts() {
		$data = Plugin::get_related_posts_meta_raw( $this->ID );
		if ( null === $data ) {
			return array();
		}
		if ( $this->is_json( $data ) ) {
			$data = json_decode( $data, true );
		}

		$related_posts = array();
		if ( empty( $data ) || ! is_array( $data ) ) {
			return $related_posts;
		}

		foreach ( $data as $key => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
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
			do_action( 'qm/debug', 'Custom Related Posts Disabled For Legacy Post' ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores -- Query Monitor hook.
			$custom_posts = array();
		} else {
			$custom_posts = $this->get_custom_related_posts();
		}

		if ( 5 > count( $custom_posts ) && ( empty( $related_posts ) || false === $related_posts ) ) {
			$related_posts = $this->get_posts_in_primary_term( $per_page );
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
			wp_cache_set( $post_id, $related_posts, Plugin::$cache_key, HOUR_IN_SECONDS );
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
		if ( '' === $this->post_type || ! in_array( $this->post_type, Plugin::get_enabled_post_types(), true ) ) {
			return array();
		}
		return $this->get_related_posts();
	}
}
