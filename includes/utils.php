<?php
/**
 * The plugin utils class.
 *
 * @package PRC\Platform\Related_Posts
 */

namespace PRC\Platform\Related_Posts;

/**
 * Return the first value from get_post_meta( ..., false ) without using single=true.
 *
 * WordPress can warn on Undefined array key 0 when single=true and a meta row exists but has no values (PRC-PLATFORM-PHP-W4).
 *
 * @param mixed $values Result of get_post_meta( $id, $key, false ).
 * @return mixed|null First stored value, or null when none.
 */
function first_post_meta_value( $values ) {
	if ( ! is_array( $values ) || array() === $values ) {
		return null;
	}

	return $values[0];
}
