/**
 * External Dependencies
 */
import {
	useAISuggest,
	AISuggestButton,
	AISuggestModal,
	AISuggestionsList,
} from '@prc/components';

/**
 * WordPress Dependencies
 */
import { __, sprintf } from '@wordpress/i18n';
import { useState, useCallback, useMemo, useEffect } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { Button } from '@wordpress/components';

/**
 * Generates a random ID.
 *
 * @return {string} A random string identifier.
 */
function randomId() {
	return `_${Math.random().toString(36).substr(2, 9)}`;
}

/**
 * AI Suggest Button component.
 *
 * Renders a button that triggers an AI-powered related posts suggestion,
 * displays results in a modal, and allows the editor to insert selected items.
 */
export default function SEOSuggestButton() {
	const [isOpen, setIsOpen] = useState(false);
	const [selectedIds, setSelectedIds] = useState(new Set());

	const { append } = useDispatch('prc/related-posts');

	const { postId, postType } = useSelect(
		(select) => ({
			postId: select('core/editor').getCurrentPostId(),
			postType: select('core/editor').getCurrentPostType(),
		}),
		[]
	);

	const existingItems = useSelect(
		(select) => select('prc/related-posts').getItems(),
		[]
	);

	const abilityName =
		window.prcRelatedPostsAI?.abilityName || 'prc-related-posts/suggest';

	const { isLoading, error, result, fetch, dismissError } = useAISuggest({
		abilityName,
		transformResult: (raw) => {
			if (!raw.suggestions || raw.suggestions.length === 0) {
				return [];
			}
			// Filter out suggestions that are already in the related posts list.
			const existingPostIds = new Set(
				existingItems.map((item) => item.postId)
			);
			return raw.suggestions.filter(
				(s) => !existingPostIds.has(s.postId)
			);
		},
	});

	/**
	 * Opens the modal and triggers the AI suggestion fetch.
	 */
	const handleOpen = useCallback(() => {
		setIsOpen(true);
		setSelectedIds(new Set());
		fetch({ post_id: postId }).then(() => {
			// After fetch completes, if we have results select all by default.
			// This is handled via the effect-like pattern below.
		});
	}, [fetch, postId]);

	// Stabilise the suggestions reference so downstream hooks don't
	// re-fire on every render.
	const suggestions = useMemo(() => result || [], [result]);

	// Select all suggestions by default when results arrive.
	useEffect(() => {
		if (suggestions.length > 0 && !isLoading) {
			setSelectedIds(new Set(suggestions.map((s) => s.postId)));
		}
	}, [suggestions, isLoading]);

	/**
	 * Toggles a suggestion's selection state.
	 *
	 * @param {number} suggestionPostId The post ID to toggle.
	 */
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

	/**
	 * Inserts the selected suggestions into the related posts list.
	 */
	const handleInsert = useCallback(() => {
		const selected = suggestions.filter((s) => selectedIds.has(s.postId));
		selected.forEach((suggestion) => {
			append({
				key: randomId(),
				link: suggestion.url,
				postId: suggestion.postId,
				title: suggestion.title,
				date: suggestion.date,
				label: suggestion.label,
			});
		});
		setIsOpen(false);
	}, [suggestions, selectedIds, append]);

	// Check if the current post type supports related posts.
	const enabledPostTypes = window.prcRelatedPostsAI?.enabledPostTypes || [];
	if (!enabledPostTypes.includes(postType)) {
		return null;
	}

	return (
		<>
			<AISuggestButton
				label={__('Suggest Related Posts', 'prc-related-posts')}
				text={__('Suggest Related Posts', 'prc-related-posts')}
				onClick={handleOpen}
			/>

			<AISuggestModal
				title={__('Related Posts Suggestions', 'prc-related-posts')}
				isOpen={isOpen}
				onClose={() => setIsOpen(false)}
				isLoading={isLoading}
				loadingMessage={__(
					'Analyzing topics and finding related posts…',
					'prc-related-posts'
				)}
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
											marginLeft: '28px',
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
