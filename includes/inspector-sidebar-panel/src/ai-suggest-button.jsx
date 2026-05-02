/**
 * External Dependencies
 */
import {
	useAISuggest,
	AISuggestButton,
	AISuggestModal,
	AISuggestionsList,
} from '@prc/components';
import styled from '@emotion/styled';

/**
 * WordPress Dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useCallback, useMemo, useEffect } from '@wordpress/element';
import { useSelect } from '@wordpress/data';
import { Button } from '@wordpress/components';

/**
 * Generates a random ID.
 *
 * @return {string} A random string identifier.
 */
function randomId() {
	return `_${Math.random().toString(36).substr(2, 9)}`;
}

const AISuggestButtonContainer = styled.div`
	margin-bottom: 1em;
`;

/**
 * AI Suggest Button component.
 *
 * Renders a button that triggers an AI-powered related posts suggestion,
 * displays results in a modal, and allows the editor to insert selected items.
 *
 * @param {Object}   props               Component props.
 * @param {Function} props.append        Callback to append an item to the related posts list.
 * @param {Array}    props.existingItems Current related posts items (from meta.relatedPosts).
 */
export default function SEOSuggestButton({ append, existingItems = [] }) {
	const [isOpen, setIsOpen] = useState(false);
	const [selectedIds, setSelectedIds] = useState(new Set());

	const { postId, postType, postStatus } = useSelect(
		(select) => ({
			postId: select('core/editor').getCurrentPostId(),
			postType: select('core/editor').getCurrentPostType(),
			postStatus: select('core/editor').getCurrentPostAttribute('status'),
		}),
		[]
	);

	const abilityName =
		window.prcRelatedPostsAI?.abilityName || 'prc-related-posts/suggest';

	const { isLoading, error, result, fetch, dismissError } = useAISuggest({
		abilityName,
		transformResult: (raw) => {
			const existingPostIds = new Set(
				existingItems.map((item) => item.postId)
			);
			const filtered = (raw.suggestions || []).filter(
				(s) => !existingPostIds.has(s.postId)
			);
			return {
				suggestions: filtered,
				source: raw.source ?? 'category-query',
			};
		},
	});

	const handleOpen = useCallback(() => {
		setIsOpen(true);
		setSelectedIds(new Set());
		fetch({ post_id: postId });
	}, [fetch, postId]);

	const suggestions = useMemo(() => result?.suggestions ?? [], [result]);
	const suggestionSource = result?.source ?? 'category-query';

	const loadingMessage =
		postStatus === 'publish'
			? __('Fetching recommendations from Parse.ly…', 'prc-related-posts')
			: __(
					'Analyzing topics and finding related posts…',
					'prc-related-posts'
			  );

	useEffect(() => {
		if (suggestions.length > 0 && !isLoading) {
			setSelectedIds(new Set(suggestions.map((s) => s.postId)));
		}
	}, [suggestions, isLoading]);

	const toggleSelection = useCallback((suggestionPostId) => {
		setSelectedIds((prev) => {
			const next = new Set(prev);
			if (next.has(suggestionPostId)) {
				next.delete(suggestionPostId);
			} else {
				next.add(suggestionPostId);
			}
			return next;
		});
	}, []);

	const handleInsert = useCallback(() => {
		const selected = suggestions.filter((s) => selectedIds.has(s.postId));
		const items = selected.map((suggestion) => ({
			key: randomId(),
			link: suggestion.url,
			postId: suggestion.postId,
			title: suggestion.title,
			date: suggestion.date,
			label: suggestion.label,
		}));
		append(...items);
		setIsOpen(false);
	}, [suggestions, selectedIds, append]);

	const enabledPostTypes = window.prcRelatedPostsAI?.enabledPostTypes || [];
	if (!enabledPostTypes.includes(postType)) {
		return null;
	}

	return (
		<>
			<AISuggestButtonContainer>
				<AISuggestButton
					label={__('Suggest Related Posts', 'prc-related-posts')}
					text={__('Suggest Related Posts', 'prc-related-posts')}
					onClick={handleOpen}
				/>
			</AISuggestButtonContainer>

			<AISuggestModal
				title={__('Related Posts Suggestions', 'prc-related-posts')}
				isOpen={isOpen}
				onClose={() => setIsOpen(false)}
				isLoading={isLoading}
				loadingMessage={loadingMessage}
				error={error}
				onDismissError={dismissError}
				footer={
					suggestions.length > 0 ? (
						<>
							<Button
								variant="tertiary"
								onClick={() => setIsOpen(false)}
							>
								{__('Cancel', 'prc-related-posts')}
							</Button>
							<Button
								variant="primary"
								onClick={handleInsert}
								disabled={selectedIds.size === 0}
							>
								{sprintf(
									/* translators: %d: number of selected posts */
									__(
										'Insert %d selected',
										'prc-related-posts'
									),
									selectedIds.size
								)}
							</Button>
						</>
					) : null
				}
			>
				{suggestions.length > 0 && (
					<>
						<p
							style={{
								color: '#666',
								fontSize: '13px',
								marginTop: 0,
							}}
						>
							{__(
								'Select the posts you want to add to your related posts list:',
								'prc-related-posts'
							)}
						</p>
						{suggestionSource === 'parsely' && (
							<p
								style={{
									fontSize: '12px',
									color: '#757575',
									margin: '0 0 12px',
								}}
							>
								<span
									style={{
										display: 'inline-block',
										backgroundColor: '#f0f0f0',
										border: '1px solid #ddd',
										padding: '2px 8px',
										borderRadius: '2px',
										fontSize: '11px',
										fontWeight: 600,
									}}
								>
									{__(
										'Powered by Parse.ly',
										'prc-related-posts'
									)}
								</span>
							</p>
						)}
						{suggestionSource === 'category-query' &&
							postStatus !== 'publish' && (
								<p
									style={{
										fontSize: '12px',
										color: '#1e1e1e',
										backgroundColor: '#f0f6fc',
										borderLeft: '4px solid #0969da',
										padding: '8px 8px 8px 12px',
										margin: '0 0 12px',
									}}
								>
									{__(
										'This post is not yet published. Suggestions are based on category matching. Once published, recommendations will be powered by Parse.ly.',
										'prc-related-posts'
									)}
								</p>
							)}
						<AISuggestionsList
							suggestions={suggestions}
							selectedIds={selectedIds}
							onToggle={toggleSelection}
							getId={(s) => s.postId}
							renderItem={(suggestion) => (
								<>
									<span style={{ fontWeight: 600 }}>
										{suggestion.title}
									</span>
									<div
										style={{
											fontSize: '12px',
											color: '#666',
										}}
									>
										<span
											style={{
												display: 'inline-block',
												backgroundColor: '#e8e8e8',
												padding: '2px 6px',
												borderRadius: '3px',
												marginRight: '8px',
												fontSize: '11px',
											}}
										>
											{suggestion.label}
										</span>
										<span>{suggestion.date}</span>
										{suggestion.reason && (
											<p
												style={{
													marginTop: '4px',
													marginBottom: 0,
													fontStyle: 'italic',
												}}
											>
												{suggestion.reason}
											</p>
										)}
									</div>
								</>
							)}
						/>
					</>
				)}

				{!isLoading && !error && suggestions.length === 0 && (
					<p style={{ color: '#666', padding: '20px 0' }}>
						{__('No suggestions available.', 'prc-related-posts')}
					</p>
				)}
			</AISuggestModal>
		</>
	);
}
