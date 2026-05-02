<?php
/**
 * Related Posts AI Experiment.
 *
 * Registers the Related Posts AI Suggest experiment with the WordPress AI
 * Experiments plugin. When enabled, this experiment registers the
 * prc-related-posts/suggest ability and adds an "AI Suggest" button to the
 * Related Posts editor sidebar panel.
 *
 * @package PRC\Platform\Related_Posts
 */

namespace PRC\Platform\Related_Posts;

use WordPress\AI\Abstracts\Abstract_Feature;
use WordPress\AI\Experiments\Experiment_Category;

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Related Posts AI Experiment class.
 *
 * @since 1.0.0
 */
class Related_Posts_AI_Experiment extends Abstract_Feature {

	/**
	 * Feature identifier.
	 *
	 * @since 1.0.0
	 */
	public static function get_id(): string {
		return 'related-posts-ai-suggest';
	}

	/**
	 * Loads feature metadata.
	 *
	 * @since 1.0.0
	 *
	 * @return array{label: string, description: string, category: string} Feature metadata.
	 */
	protected function load_metadata(): array {
		return array(
			'label'       => __( 'Related Posts AI Suggest', 'prc-related-posts' ),
			'description' => __( 'Uses AI to analyze a post\'s categories and suggest the most relevant related posts for editorial curation. Adds a "Suggest with AI" button to the Related Posts sidebar panel in the editor.', 'prc-related-posts' ),
			'category'    => Experiment_Category::EDITOR,
		);
	}

	/**
	 * Registers the experiment's hooks and functionality.
	 *
	 * This method is only called when the experiment is enabled.
	 *
	 * @since 1.0.0
	 */
	public function register(): void {
		// Register the AI ability when the experiment is enabled.
		$ability = new Related_Posts_AI_Ability();
		add_action( 'wp_abilities_api_init', array( $ability, 'register_ability' ) );

		// Localize experiment data for the editor.
		add_action( 'enqueue_block_editor_assets', array( $this, 'localize_experiment_data' ), 20 );
	}

	/**
	 * Localizes the experiment enabled state for the editor script.
	 *
	 * The inspector sidebar panel script is already enqueued by the
	 * Related Posts plugin. We add a localized variable to tell the
	 * JS component that the AI experiment is active.
	 *
	 * @hook enqueue_block_editor_assets
	 * @since 1.0.0
	 */
	public function localize_experiment_data(): void {
		$handle = Plugin::$handle;

		// Only localize if the parent script is enqueued.
		if ( ! wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}

		wp_localize_script(
			$handle,
			'prcRelatedPostsAI',
			array(
				'enabled'          => true,
				'abilityName'      => Related_Posts_AI_Ability::$ability_name,
				'enabledPostTypes' => Plugin::get_enabled_post_types(),
			)
		);
	}
}
