<?php
/**
 * Related Posts AI ability.
 *
 * Uses a post's categories to find up to 15 candidate posts and then uses AI
 * to whittle them down to the 5 most relevant suggestions for an editor.
 *
 * @package PRC\Platform\Related_Posts
 */

namespace PRC\Platform\Related_Posts;

use WP_Query;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Related Posts AI Ability class.
 *
 * @since 1.0.0
 */
class Related_Posts_AI_Ability {

	/**
	 * Ability name.
	 *
	 * @var string
	 */
	public static $ability_name = 'prc-related-posts/suggest';

	/**
	 * Register the ability with WP Abilities API.
	 *
	 * @hook wp_abilities_api_init
	 */
	public function register_ability() {
		wp_register_ability(
			self::$ability_name,
			array(
				'label'               => __( 'Suggest Related Posts', 'prc-related-posts' ),
				'description'         => __( 'Analyzes a post\'s categories to find and rank the most relevant related posts for editorial curation.', 'prc-related-posts' ),
				'category'            => 'data-retrieval',
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'        => 'integer',
							'description' => 'The post ID to find related posts for.',
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'error'           => array(
							'type'        => 'string',
							'description' => 'An error message, if any.',
						),
						'suggestions'     => array(
							'type'        => 'array',
							'description' => 'Up to 5 suggested related posts, ranked by relevance.',
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'postId' => array(
										'type'        => 'integer',
										'description' => 'The WordPress post ID.',
									),
									'title'  => array(
										'type'        => 'string',
										'description' => 'The post title.',
									),
									'url'    => array(
										'type'        => 'string',
										'description' => 'The post permalink.',
									),
									'date'   => array(
										'type'        => 'string',
										'description' => 'The post publish date.',
									),
									'label'  => array(
										'type'        => 'string',
										'description' => 'The post format label (e.g. Report, Short Read).',
									),
									'reason' => array(
										'type'        => 'string',
										'description' => 'A brief explanation of why this post is relevant.',
									),
								),
							),
						),
						'source_post'     => array(
							'type'       => 'object',
							'properties' => array(
								'postId'     => array(
									'type' => 'integer',
								),
								'title'      => array(
									'type' => 'string',
								),
								'categories' => array(
									'type'  => 'array',
									'items' => array(
										'type' => 'string',
									),
								),
							),
						),
						'candidate_count' => array(
							'type'        => 'integer',
							'description' => 'How many candidate posts were found before AI ranking.',
						),
						'source'          => array(
							'type'        => 'string',
							'enum'        => array( 'parsely', 'category-query' ),
							'description' => 'The source of the recommendations.',
						),
					),
				),
				'execute_callback'    => array( $this, 'find_related_posts' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => array(
					'annotations'  => array(
						'instructions' => 'This ability takes a post ID. For published posts it requests Parse.ly content recommendations when configured; otherwise it finds up to 15 candidate posts sharing the post\'s categories and uses AI to select and rank the top 5 most relevant suggestions. The result includes the reasoning for each suggestion and a source field.',
						'readonly'     => true,
						'destructive'  => false,
						'idempotent'   => false,
					),
					'show_in_rest' => true,
					'mcp'          => array(
						'public' => true,
						'type'   => 'tool',
					),
				),
			)
		);
	}

	/**
	 * Get the format label for a post.
	 *
	 * @param int $post_id The post ID.
	 * @return string The format label.
	 */
	private function get_label( $post_id ) {
		$terms = wp_get_object_terms( $post_id, 'formats', array( 'fields' => 'names' ) );
		$label = 'Report';
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			$label = array_shift( $terms );
		}
		if ( null === $label ) {
			$label = 'Report';
		}
		return ucwords( str_replace( '-', ' ', $label ) );
	}

	/**
	 * Get candidate posts that share categories with the source post.
	 *
	 * @param int   $post_id    The source post ID.
	 * @param array $categories The category term objects.
	 * @return array Candidate posts data.
	 */
	private function get_candidate_posts( $post_id, $categories ) {
		$category_ids = wp_list_pluck( $categories, 'term_id' );

		if ( empty( $category_ids ) ) {
			return array();
		}

		$candidates = array();

		// Phase 1: Posts matching the primary category (up to 10).
		$primary_term = class_exists( '\PRC\Platform\Schema_SEO\Primary_Term' )
			? \PRC\Platform\Schema_SEO\Primary_Term::get_term( (int) $post_id, 'category' )
			: null;
		if ( $primary_term instanceof \WP_Term ) {
			$primary_query = new WP_Query(
				array(
					'post_type'      => array( 'post', 'short-read', 'feature', 'fact-sheet' ),
					'post_parent'    => 0,
					'posts_per_page' => 10,
					'post_status'    => 'publish',
					'post__not_in'   => array( $post_id ),
					'orderby'        => 'date',
					'order'          => 'DESC',
					'tax_query'      => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
						array(
							'taxonomy' => 'category',
							'field'    => 'term_id',
							'terms'    => $primary_term->term_id,
						),
					),
					'facetwp'        => false,
				)
			);

			if ( $primary_query->have_posts() ) {
				while ( $primary_query->have_posts() ) {
					$primary_query->the_post();
					$pid                = get_the_ID();
					$candidates[ $pid ] = array(
						'postId'  => $pid,
						'title'   => get_the_title(),
						'url'     => get_permalink( $pid ),
						'date'    => get_the_date( 'Y-m-d' ),
						'excerpt' => wp_trim_words( get_the_excerpt(), 30, '...' ),
						'label'   => $this->get_label( $pid ),
					);
				}
			}
			wp_reset_postdata();
		}

		// Phase 2: Posts matching any of the post's categories (fill up to 15).
		$remaining = 15 - count( $candidates );
		if ( $remaining > 0 ) {
			$exclude_ids = array_merge( array( $post_id ), array_keys( $candidates ) );

			$tax_query = array(
				'relation' => 'OR',
			);
			foreach ( $category_ids as $cat_id ) {
				$tax_query[] = array(
					'taxonomy' => 'category',
					'field'    => 'term_id',
					'terms'    => $cat_id,
				);
			}

			$broader_query = new WP_Query(
				array(
					'post_type'      => array( 'post', 'short-read', 'feature', 'fact-sheet' ),
					'post_parent'    => 0,
					'posts_per_page' => $remaining,
					'post_status'    => 'publish',
					'post__not_in'   => $exclude_ids,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'tax_query'      => $tax_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
					'facetwp'        => false,
				)
			);

			if ( $broader_query->have_posts() ) {
				while ( $broader_query->have_posts() ) {
					$broader_query->the_post();
					$pid = get_the_ID();
					if ( ! isset( $candidates[ $pid ] ) ) {
						$candidates[ $pid ] = array(
							'postId'  => $pid,
							'title'   => get_the_title(),
							'url'     => get_permalink( $pid ),
							'date'    => get_the_date( 'Y-m-d' ),
							'excerpt' => wp_trim_words( get_the_excerpt(), 30, '...' ),
							'label'   => $this->get_label( $pid ),
						);
					}
				}
			}
			wp_reset_postdata();
		}

		return array_values( $candidates );
	}

	/**
	 * Get the system instructions for the AI ranking.
	 *
	 * @return string The system instructions.
	 */
	private static function get_ranking_instructions() {
		return 'You are an editorial assistant for Pew Research Center. Your task is to evaluate a list of candidate posts and select the 5 most relevant related posts for a given source post.

CRITERIA FOR RANKING:
1. Topical relevance - How closely related is the candidate post\'s subject matter to the source post?
2. Recency - More recent posts are generally preferred, but strong topical matches can override this.
3. Complementary coverage - Prefer posts that add different angles or depth to the same topic, rather than duplicates.
4. Format diversity - Try to include a mix of formats (Reports, Short Reads, Fact Sheets) when possible.
5. Editorial value - Would a reader of the source post find this related post valuable?

CRITICAL OUTPUT REQUIREMENTS:
- Return ONLY valid JSON matching the exact structure specified.
- Do not include explanatory text, markdown formatting, or commentary outside the JSON.
- Do not wrap JSON in code blocks or backticks.
- Return raw JSON only.
- Select exactly 5 posts (or fewer if fewer than 5 candidates are available).
- Include a brief "reason" for each selection explaining why it is relevant.';
	}

	/**
	 * Get the expected output format for the AI.
	 *
	 * @return string JSON schema example.
	 */
	private static function get_output_format() {
		$format = array(
			array(
				'postId' => 12345,
				'reason' => 'Brief explanation of why this post is relevant to the source post.',
			),
		);
		return wp_json_encode( $format );
	}

	/**
	 * Use AI to rank and select the top 5 related posts.
	 *
	 * @param array $source_post The source post data.
	 * @param array $candidates  The candidate posts to rank.
	 * @return array The top 5 ranked post IDs with reasons.
	 */
	private function rank_candidates_with_ai( $source_post, $candidates ) {
		$output_format = self::get_output_format();

		$prompt = wp_sprintf(
			'SOURCE POST:
Title: %s
Categories: %s

CANDIDATE POSTS:
%s

Select the 5 most relevant related posts from the candidates above. Return ONLY a JSON array in this format: %s

Each item must include the exact postId from the candidates and a brief reason for selection.',
			$source_post['title'],
			implode( ', ', $source_post['categories'] ),
			wp_json_encode( $candidates ),
			$output_format
		);

		try {
			$builder = wp_ai_client_prompt( $prompt );
			if ( is_wp_error( $builder ) ) {
				return array();
			}

			$response = $builder
				->using_system_instruction( self::get_ranking_instructions() )
				->using_temperature( 0.2 )
				->using_model_preference( 'claude-haiku-4-5', 'gemini-2.5-flash' )
				->as_json_response(
					array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					)
				)
				->generate_text();

			if ( is_wp_error( $response ) ) {
				return array();
			}

			$ranked = json_decode( (string) $response, true );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $ranked ) ) {
				return array();
			}

			return $ranked;
		} catch ( \Exception $e ) {
			return array();
		}
	}

	/**
	 * Resolve a front-end URL to a post ID (VIP-aware).
	 *
	 * @param string $url URL to resolve.
	 * @return int Post ID or 0.
	 */
	private function resolve_url_to_post_id( string $url ): int {
		if ( function_exists( 'wpcom_vip_url_to_postid' ) ) {
			return (int) wpcom_vip_url_to_postid( $url );
		}
		return (int) url_to_postid( $url );
	}

	/**
	 * Primary category (section) name for optional Parse.ly filtering.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null Section name or null.
	 */
	private function get_primary_section_name( int $post_id ): ?string {
		if ( ! class_exists( '\PRC\Platform\Schema_SEO\Primary_Term' ) ) {
			return null;
		}
		$name = \PRC\Platform\Schema_SEO\Primary_Term::get_name( $post_id, 'category' );
		return '' !== $name ? $name : null;
	}

	/**
	 * Fetch related post recommendations from Parse.ly (published content only).
	 *
	 * @param int $post_id Post ID.
	 * @return array List of suggestion arrays, empty on failure or misconfiguration.
	 */
	private function get_parsely_recommendations( int $post_id ): array {
		if ( ! defined( 'PRC_PLATFORM_PARSELY_API_KEY' ) || '' === constant( 'PRC_PLATFORM_PARSELY_API_KEY' ) ) {
			return array();
		}

		$cache_key = 'parsely_suggest_' . $post_id;
		$cached    = wp_cache_get( $cache_key, 'prc_related_posts' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		$permalink = get_permalink( $post_id );
		if ( ! $permalink ) {
			return array();
		}

		$normalized_url = \PRC\BlockUtils\normalize_url_to_production( $permalink );
		$api_key        = constant( 'PRC_PLATFORM_PARSELY_API_KEY' );

		$params = array(
			'apikey' => $api_key,
			'url'    => $normalized_url,
			'limit'  => 10,
			'sort'   => '_score',
		);

		$section = $this->get_primary_section_name( $post_id );
		if ( $section ) {
			$params['section'] = $section;
		}

		$api_url = add_query_arg( $params, 'https://api.parsely.com/v2/related' );

		$request_args = array(
			'timeout' => 15,
			'headers' => array(
				'Accept' => 'application/json',
			),
		);

		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			$response = \vip_safe_wp_remote_get(
				$api_url,
				'',
				3,
				15,
				20,
				array(
					'headers' => array(
						'Accept' => 'application/json',
					),
				)
			);
		} else {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.wp_remote_get_wp_remote_get -- Fallback when VIP function unavailable (e.g. local Playground).
			$response = wp_remote_get( $api_url, $request_args );
		}

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( 200 !== $code || '' === $body ) {
			return array();
		}

		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) || empty( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
			return array();
		}

		$reason_base = $section
			/* translators: %s: Primary section (category) name. */
			? sprintf( __( 'Recommended by Parse.ly — related content in %s.', 'prc-related-posts' ), $section )
			: __( 'Recommended by Parse.ly.', 'prc-related-posts' );

		$suggestions = array();
		foreach ( $decoded['data'] as $item ) {
			if ( count( $suggestions ) >= 5 ) {
				break;
			}
			if ( empty( $item['url'] ) || ! is_string( $item['url'] ) ) {
				continue;
			}
			$resolved_id = $this->resolve_url_to_post_id( $item['url'] );
			if ( $resolved_id <= 0 || $resolved_id === $post_id ) {
				continue;
			}
			$title = isset( $item['title'] ) ? (string) $item['title'] : get_the_title( $resolved_id );
			$date  = '';
			if ( ! empty( $item['pub_date'] ) ) {
				$ts   = strtotime( (string) $item['pub_date'] );
				$date = $ts ? gmdate( 'Y-m-d', $ts ) : '';
			}
			if ( '' === $date ) {
				$date = get_the_date( 'Y-m-d', $resolved_id );
			}

			$suggestions[] = array(
				'postId' => $resolved_id,
				'title'  => $title,
				'url'    => get_permalink( $resolved_id ),
				'date'   => $date,
				'label'  => $this->get_label( $resolved_id ),
				'reason' => $reason_base,
			);
		}

		wp_cache_set( $cache_key, $suggestions, 'prc_related_posts', HOUR_IN_SECONDS );

		return $suggestions;
	}

	/**
	 * Find related posts for a given post ID.
	 *
	 * This is the main ability execute callback.
	 *
	 * @param array $input The input parameters containing post_id.
	 * @return array The result with suggestions.
	 */
	public function find_related_posts( $input ) {
		$post_id = $input['post_id'] ?? 0;

		if ( empty( $post_id ) ) {
			return array(
				'error'       => 'A valid post_id is required.',
				'suggestions' => array(),
			);
		}

		$post = get_post( $post_id );
		if ( ! $post ) {
			return array(
				'error'       => 'Post not found.',
				'suggestions' => array(),
			);
		}

		// Check if this is a child post; if so, use the parent.
		if ( 0 !== $post->post_parent ) {
			$post_id = $post->post_parent;
			$post    = get_post( $post_id );
		}

		// Check if the post type supports related posts.
		if ( ! post_type_supports( get_post_type( $post_id ), 'prc-related-posts' ) ) {
			return array(
				'error'       => 'This post type does not support related posts.',
				'suggestions' => array(),
			);
		}

		$categories = wp_get_post_terms( $post_id, 'category' );
		if ( is_wp_error( $categories ) ) {
			$categories = array();
		}

		$category_names = wp_list_pluck( $categories, 'name' );

		$source_post = array(
			'postId'     => (int) $post_id,
			'title'      => get_the_title( $post_id ),
			'categories' => $category_names,
		);

		// Published posts: try Parse.ly first (canonical URL in index).
		if ( 'publish' === get_post_status( $post_id ) ) {
			$parsely_suggestions = $this->get_parsely_recommendations( $post_id );
			if ( ! empty( $parsely_suggestions ) ) {
				return array(
					'error'           => '',
					'suggestions'     => $parsely_suggestions,
					'source_post'     => $source_post,
					'candidate_count' => count( $parsely_suggestions ),
					'source'          => 'parsely',
				);
			}
		}

		if ( empty( $categories ) ) {
			return array(
				'error'       => 'No categories found for this post. Categories are required to find related content.',
				'suggestions' => array(),
			);
		}

		// Draft / pending / fallback: category query and optional AI ranking.
		$candidates = $this->get_candidate_posts( $post_id, $categories );

		if ( empty( $candidates ) ) {
			return array(
				'error'           => 'No candidate posts found matching the post\'s categories.',
				'suggestions'     => array(),
				'source_post'     => $source_post,
				'candidate_count' => 0,
				'source'          => 'category-query',
			);
		}

		$candidate_count = count( $candidates );

		// If we have 5 or fewer candidates, skip AI ranking and return them all.
		if ( $candidate_count <= 5 ) {
			$suggestions = array_map(
				function ( $candidate ) {
					$candidate['reason'] = 'Shares categories with the source post.';
					unset( $candidate['excerpt'] );
					return $candidate;
				},
				$candidates
			);

			return array(
				'error'           => '',
				'suggestions'     => $suggestions,
				'source_post'     => $source_post,
				'candidate_count' => $candidate_count,
				'source'          => 'category-query',
			);
		}

		// Use AI to rank and select the top 5 from the candidates.
		$ranked = $this->rank_candidates_with_ai( $source_post, $candidates );

		if ( empty( $ranked ) ) {
			// Fallback: return the first 5 candidates sorted by date.
			$suggestions = array_slice( $candidates, 0, 5 );
			$suggestions = array_map(
				function ( $candidate ) {
					$candidate['reason'] = 'Shares categories with the source post.';
					unset( $candidate['excerpt'] );
					return $candidate;
				},
				$suggestions
			);

			return array(
				'error'           => '',
				'suggestions'     => $suggestions,
				'source_post'     => $source_post,
				'candidate_count' => $candidate_count,
				'source'          => 'category-query',
			);
		}

		// Map ranked results back to full candidate data.
		$candidates_by_id = array();
		foreach ( $candidates as $candidate ) {
			$candidates_by_id[ $candidate['postId'] ] = $candidate;
		}

		$suggestions = array();
		foreach ( $ranked as $item ) {
			$pid = $item['postId'] ?? 0;
			if ( isset( $candidates_by_id[ $pid ] ) ) {
				$suggestion           = $candidates_by_id[ $pid ];
				$suggestion['reason'] = $item['reason'] ?? 'AI-selected as relevant.';
				unset( $suggestion['excerpt'] );
				$suggestions[] = $suggestion;
			}
		}

		// Ensure we have at most 5.
		$suggestions = array_slice( $suggestions, 0, 5 );

		return array(
			'error'           => '',
			'suggestions'     => $suggestions,
			'source_post'     => $source_post,
			'candidate_count' => $candidate_count,
			'source'          => 'category-query',
		);
	}
}
