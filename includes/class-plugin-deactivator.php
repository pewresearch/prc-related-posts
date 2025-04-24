<?php
/**
 * The plugin deactivator class.
 *
 * @package PRC\Platform\Related_Posts
 */

namespace PRC\Platform\Related_Posts;

use DEFAULT_TECHNICAL_CONTACT;

/**
 * The plugin deactivator class.
 */
class Plugin_Deactivator {

	/**
	 * Deactivate the plugin.
	 */
	public static function deactivate() {
		flush_rewrite_rules();

		wp_mail(
			DEFAULT_TECHNICAL_CONTACT,
			'PRC Related Posts Deactivated',
			'The PRC Related Posts plugin has been deactivated on ' . get_site_url()
		);
	}
}
