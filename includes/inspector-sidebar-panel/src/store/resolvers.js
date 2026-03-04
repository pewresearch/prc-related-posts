/**
 * External Dependencies
 */
import { listStoreActions } from '@prc/components';

/**
 * WordPress Dependencies
 */
import { select } from '@wordpress/data';

// Initially resolve if there is saved data.
const resolvers = {
	*getItems() {
		const { relatedPosts } =
			select('core/editor').getEditedPostAttribute('meta');

		if (0 === relatedPosts?.length) {
			return;
		}

		// Seed state with data:
		yield listStoreActions.seed(relatedPosts);
	},
};

export default resolvers;
