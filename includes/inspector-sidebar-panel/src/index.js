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
import { useRef, useCallback } from '@wordpress/element';
import { registerPlugin } from '@wordpress/plugins';
import { useDispatch, useSelect } from '@wordpress/data';
import { PluginDocumentSettingPanel } from '@wordpress/edit-post';

/**
 * Internal Dependencies
 */
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
const SEOSuggestButton = isAIEnabled
	? require('./ai-suggest-button').default
	: null;

function randomId() {
	return `_${Math.random().toString(36).substr(2, 9)}`;
}

function RelatedPostsPanel() {
	const { editPost } = useDispatch('core/editor');

	const rawMeta = useSelect(
		(select) => select('core/editor').getEditedPostAttribute('meta'),
		[]
	);
	const meta = rawMeta ?? {};
	const items = meta?.relatedPosts;

	const metaRef = useRef(meta);
	metaRef.current = meta;
	const itemsRef = useRef(items);
	itemsRef.current = items;

	const safeItems = Array.isArray(items) ? items : [];

	const writeRelatedPosts = useCallback(
		(nextItems) => {
			const next = Array.isArray(nextItems) ? nextItems : [];
			itemsRef.current = next;
			const nextMeta = { ...metaRef.current, relatedPosts: next };
			metaRef.current = nextMeta;
			editPost({ meta: nextMeta });
		},
		[editPost]
	);

	const append = useCallback(
		(...newItems) => {
			const current = Array.isArray(itemsRef.current)
				? [...itemsRef.current]
				: [];
			writeRelatedPosts([...current, ...newItems]);
		},
		[writeRelatedPosts]
	);

	const reorder = useCallback(
		(oldIndex, newIndex) => {
			const current = Array.isArray(itemsRef.current)
				? [...itemsRef.current]
				: [];
			const [moved] = current.splice(oldIndex, 1);
			current.splice(newIndex, 0, moved);
			writeRelatedPosts(current);
		},
		[writeRelatedPosts]
	);

	const remove = useCallback(
		(index) => {
			const current = Array.isArray(itemsRef.current)
				? [...itemsRef.current]
				: [];
			current.splice(index, 1);
			writeRelatedPosts(current);
		},
		[writeRelatedPosts]
	);

	const updateItemProp = useCallback(
		(index, prop, value) => {
			const current = Array.isArray(itemsRef.current)
				? [...itemsRef.current]
				: [];
			current[index] = { ...current[index], [prop]: value };
			writeRelatedPosts(current);
		},
		[writeRelatedPosts]
	);

	return (
		<PluginDocumentSettingPanel
			name="prc-related-posts"
			title="Related Posts"
		>
			{SEOSuggestButton && (
				<SEOSuggestButton append={append} existingItems={safeItems} />
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
				showExcerpt={true}
			>
				<List
					lockVertically
					values={safeItems}
					onChange={({ oldIndex, newIndex }) =>
						reorder(oldIndex, newIndex)
					}
					renderList={({ children, props }) => (
						<div {...props}>{children}</div>
					)}
					renderItem={({ value, props, index }) => (
						<div {...props}>
							<ListStoreItem
								key={value.key}
								label={value.title}
								defaultLabel="Related Post"
								index={index}
								onRemove={() => remove(index)}
								onLabelChange={(newLabel) =>
									updateItemProp(index, 'title', newLabel)
								}
								lastItem={index === safeItems.length - 1}
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
