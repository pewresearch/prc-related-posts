/* eslint-disable camelcase */
/**
 * External Dependencies
 */
import { WPEntitySearch } from '@prc/components';
import { List } from 'react-movable';

/**
 * WordPress Dependencies
 */
import { __ } from '@wordpress/i18n';
import { useEffect } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';
import { useDispatch, useSelect } from '@wordpress/data';
import { useEntityProp } from '@wordpress/core-data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';

/**
 * Internal Dependencies
 */
import './store';
import ListStoreItem from './list-store-item';

/**
 * Conditionally load the AI Suggest Button component.
 * The prcRelatedPostsAI global is localized by the Related Posts AI Experiment
 * when the experiment is enabled in the WP AI Experiments plugin settings.
 */
const isAIEnabled =
	typeof window !== 'undefined' &&
	typeof window.prcRelatedPostsAI !== 'undefined' &&
	window.prcRelatedPostsAI.enabled;
// Lazy import only when the experiment is enabled.
const SEOSuggestButton = isAIEnabled
	? require('./ai-suggest-button').default
	: null;

function randomId() {
	// Math.random should be unique because of its seeding algorithm.
	// Convert it to base 36 (numbers + letters), and grab the first 9 characters
	// after the decimal.
	return `_${Math.random().toString(36).substr(2, 9)}`;
}

function RelatedPostsPanel() {
	const { append, reorder } = useDispatch('prc/related-posts');

	const { items, postType } = useSelect(
		(select) => ({
			items: select('prc/related-posts').getItems(),
			postType: select('core/editor').getCurrentPostType(),
		}),
		[]
	);

	const [, setMeta] = useEntityProp('postType', postType, 'meta');

	// Sync list store (items) to post meta. Use functional setMeta so we don't
	// depend on meta in deps — otherwise setMeta triggers effect again → infinite loop (React #185).
	useEffect(() => {
		if (items.length === 0) return;
		setMeta((prev) => ({ ...prev, relatedPosts: items }));
	}, [items, setMeta]);

	return (
		<PluginDocumentSettingPanel
			name="prc-related-posts"
			title="Related Posts"
		>
			{SEOSuggestButton && (
				<>
					<SEOSuggestButton />
					<div style={{ marginTop: '12px' }} />
				</>
			)}
			<WPEntitySearch
				placeholder={__(
					'Enter URL or search for a related post',
					'prc-platform-core'
				)}
				entityType="postType"
				entitySubType={[
					'post',
					'short-read',
					'fact-sheet',
					'feature',
					'quiz',
				]}
				onSelect={(entity) => {
					// Transform the entity into something usable for related posts.
					append({
						key: randomId(),
						link: entity.entityUrl,
						postId: entity.entityId,
						title: entity.entityName,
						date: entity.entityDate,
						label: entity.entityName,
					});
				}}
				clearOnSelect={true}
			>
				<List
					lockVertically
					values={items}
					onChange={({ oldIndex, newIndex }) =>
						reorder({
							from: oldIndex,
							to: newIndex,
						})
					}
					renderList={({ children, props }) => (
						<div {...props}>{children}</div>
					)}
					renderItem={({ value, props, index }) => (
						<div {...props}>
							<ListStoreItem
								key={value.key}
								value={value}
								label={value.title}
								defaultLabel="Related Post"
								index={index}
								storeName="related-posts"
								lastItem={index === items.length - 1}
							/>
						</div>
					)}
				/>
			</WPEntitySearch>
		</PluginDocumentSettingPanel>
	);
}

registerPlugin('prc-related-posts', {
	render: RelatedPostsPanel,
	icon: null,
});
