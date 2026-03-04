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

use WordPress\AiClient\AiClient;
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
					),
				),
				'execute_callback'    => array( $this, 'find_related_posts' ),
				'permission_callback' => function () {
					return current_user_can( 'edit_posts' );
				},
				'meta'                => array(
					'annotations'  => array(
						'instructions' => 'This ability takes a post ID, retrieves its categories, finds up to 15 candidate posts sharing those categories, and uses AI to select and rank the top 5 most relevant suggestions. The result includes the reasoning for each suggestion.',
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

		// First, try to find posts that share the primary category.
		$primary_category_id = null;
		if ( function_exists( '\PRC\Platform\get_primary_term_id' ) ) {
			$primary_category_id = \PRC\Platform\get_primary_term_id( $post_id, 'category' );
		}

		$candidates = array();

		// Phase 1: Posts matching the primary category (up to 10).
		if ( $primary_category_id && is_numeric( $primary_category_id ) ) {
			$primary_term = get_term_by( 'term_taxonomy_id', (int) $primary_category_id, 'category' );
			if ( $primary_term ) {
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
			$response = AiClient::prompt( $prompt )
				->usingSystemInstruction( self::get_ranking_instructions() )
				->usingTemperature( 0.2 )
				->usingModelPreference( array( 'claude-haiku-4-5', 'gemini-2.5-flash' ) )
				->asJsonResponse()
				->generateText();

			$ranked = json_decode( $response, true );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $ranked ) ) {
				return array();
			}

			return $ranked;
		} catch ( \Exception $e ) {
			return array();
		}
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

		// Get the post's categories.
		$categories = wp_get_post_terms( $post_id, 'category' );
		if ( is_wp_error( $categories ) || empty( $categories ) ) {
			return array(
				'error'       => 'No categories found for this post. Categories are required to find related content.',
				'suggestions' => array(),
			);
		}

		$category_names = wp_list_pluck( $categories, 'name' );

		$source_post = array(
			'postId'     => (int) $post_id,
			'title'      => get_the_title( $post_id ),
			'categories' => $category_names,
		);

		// Get up to 15 candidate posts.
		$candidates = $this->get_candidate_posts( $post_id, $categories );

		if ( empty( $candidates ) ) {
			return array(
				'error'           => 'No candidate posts found matching the post\'s categories.',
				'suggestions'     => array(),
				'source_post'     => $source_post,
				'candidate_count' => 0,
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
		);
	}
}
