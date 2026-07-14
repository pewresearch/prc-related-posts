<?php
/**
 * CLI regression coverage for primary-term resolution via Schema SEO.
 *
 * Related posts must use Primary_Term::get_term() (term_id + WP_Error guard),
 * not get_term_by( 'term_taxonomy_id', … ).
 *
 * Run from repo root:
 *   php plugins/prc-related-posts/tests/test-resolve-primary-term.php
 *
 * @package PRC\Platform\Related_Posts
 */

declare(strict_types=1);

namespace {

	/**
	 * Minimal WP_Error stand-in for CLI tests without WordPress bootstrap.
	 */
	class WP_Error {
		/**
		 * @var string
		 */
		public $code;

		/**
		 * @param string $code Error code.
		 */
		public function __construct( string $code = 'error' ) {
			$this->code = $code;
		}
	}

	/**
	 * Minimal WP_Term stand-in for CLI tests without WordPress bootstrap.
	 */
	class WP_Term {
		/**
		 * @var int
		 */
		public $term_id;

		/**
		 * @var string
		 */
		public $name;

		/**
		 * @var string
		 */
		public $taxonomy;

		/**
		 * @param int    $term_id  Term ID.
		 * @param string $name     Term name.
		 * @param string $taxonomy Taxonomy slug.
		 */
		public function __construct( int $term_id, string $name = 'Example', string $taxonomy = 'category' ) {
			$this->term_id  = $term_id;
			$this->name     = $name;
			$this->taxonomy = $taxonomy;
		}
	}

	/**
	 * @var array{seo_data: mixed, get_term: mixed, post_terms: array<int, int>|WP_Error}
	 */
	$GLOBALS['prc_related_posts_test_stubs'] = array(
		'seo_data'   => array(),
		'get_term'   => null,
		'post_terms' => array(),
	);

	/**
	 * @param mixed $thing Value to check.
	 * @return bool
	 */
	function is_wp_error( $thing ): bool {
		return $thing instanceof WP_Error;
	}

	/**
	 * @param mixed $value Value to absint.
	 * @return int
	 */
	function absint( $value ): int {
		return abs( (int) $value );
	}

	/**
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @param bool   $single  Whether to return a single value.
	 * @return mixed
	 */
	function get_post_meta( $post_id, $key, $single = false ) {
		return $GLOBALS['prc_related_posts_test_stubs']['seo_data'];
	}

	/**
	 * @param mixed  $value   Filter value.
	 * @param string $hook    Filter hook.
	 * @param mixed  ...$args Additional args.
	 * @return mixed
	 */
	function apply_filters( $hook, $value, ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		return $value;
	}

	/**
	 * @param int    $term_id  Term ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @return WP_Term|WP_Error|null|false
	 */
	function get_term( $term_id, $taxonomy = '' ) {
		return $GLOBALS['prc_related_posts_test_stubs']['get_term'];
	}

	/**
	 * @param int    $post_id  Post ID.
	 * @param string $taxonomy Taxonomy slug.
	 * @param array  $args     Query args.
	 * @return array<int, int>|WP_Error
	 */
	function wp_get_post_terms( $post_id, $taxonomy, $args = array() ) {
		return $GLOBALS['prc_related_posts_test_stubs']['post_terms'];
	}

	/**
	 * @param bool   $condition Condition.
	 * @param string $message   Failure message.
	 */
	function prc_related_posts_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			fwrite( STDERR, "FAIL: {$message}\n" );
			exit( 1 );
		}
	}
}

namespace PRC\Platform\Schema_SEO {

	require_once dirname( __DIR__, 2 ) . '/prc-schema-seo/includes/class-primary-term.php';

	// Valid primary term_id resolves to that term.
	$GLOBALS['prc_related_posts_test_stubs']['seo_data']   = array(
		'primary_terms' => array( 'category' => 42 ),
	);
	$GLOBALS['prc_related_posts_test_stubs']['get_term']   = new \WP_Term( 42, 'Politics', 'category' );
	$GLOBALS['prc_related_posts_test_stubs']['post_terms'] = array();
	$resolved = Primary_Term::get_term( 1001, 'category' );
	prc_related_posts_assert( $resolved instanceof \WP_Term, 'valid primary term returns WP_Term' );
	prc_related_posts_assert( 42 === $resolved->term_id, 'valid term keeps primary term_id' );

	// WP_Error from get_term must not be treated as a term (no WP_Error::$term_id).
	$GLOBALS['prc_related_posts_test_stubs']['seo_data']   = array(
		'primary_terms' => array( 'category' => 999 ),
	);
	$GLOBALS['prc_related_posts_test_stubs']['get_term']   = new \WP_Error( 'invalid_term' );
	$GLOBALS['prc_related_posts_test_stubs']['post_terms'] = array( 7 );
	$resolved = Primary_Term::get_term( 1001, 'category', false );
	prc_related_posts_assert( null === $resolved, 'WP_Error from get_term returns null' );

	// Missing primary falls back to first assigned term id, then get_term.
	$GLOBALS['prc_related_posts_test_stubs']['seo_data']   = array();
	$GLOBALS['prc_related_posts_test_stubs']['post_terms'] = array( 7 );
	$GLOBALS['prc_related_posts_test_stubs']['get_term']   = new \WP_Term( 7, 'Fallback', 'category' );
	$resolved = Primary_Term::get_term( 1001, 'category', true );
	prc_related_posts_assert( $resolved instanceof \WP_Term, 'fallback primary returns WP_Term' );
	prc_related_posts_assert( 7 === $resolved->term_id, 'fallback uses first assigned term_id' );

	fwrite( STDOUT, "test-resolve-primary-term: OK\n" );
	exit( 0 );
}
