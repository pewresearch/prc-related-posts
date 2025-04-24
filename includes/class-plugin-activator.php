<?php
/**
 * The plugin activator class.
 *
 * @package PRC\Platform\Related_Posts
 */

namespace PRC\Platform\Related_Posts;

use DEFAULT_TECHNICAL_CONTACT;

/**
 * The plugin activator class.
 */
class Plugin_Activator {
	/**
	 * Activate the plugin.
	 */
	public static function activate() {
		flush_rewrite_rules();

		wp_mail(
			DEFAULT_TECHNICAL_CONTACT,
			'PRC Related Posts Activated',
			'The PRC Related Posts plugin has been activated on ' . get_site_url()
		);
	}
}
