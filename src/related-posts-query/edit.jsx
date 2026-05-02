/**
 * External Dependencies
 */
import {
	InnerBlocksAsContextTemplate,
	useInnerBlocksContextAsQuery,
} from '@prc/components';
import { getBlockGapSupportValue } from '@prc/functions';

/**
 * WordPress Dependencies
 */
import { useBlockProps } from '@wordpress/block-editor';

const ALLOWED_BLOCKS = [
	'prc-block/story-item',
	'core/post-title',
	'core/post-date',
	'core/post-excerpt',
];

const TEMPLATE = [['prc-block/story-item']];

export default function Edit({ clientId, attributes }) {
	const { perPage } = attributes;

	const blockProps = useBlockProps({
		style: {
			gap: getBlockGapSupportValue(attributes),
		},
	});

	const { blockContexts, isResolving } = useInnerBlocksContextAsQuery(
		'post',
		perPage
	);

	return (
		<InnerBlocksAsContextTemplate
			{...{
				clientId,
				allowedBlocks: ALLOWED_BLOCKS,
				template: TEMPLATE,
				blockContexts,
				isResolving,
				wrapperProps: {
					...blockProps,
				},
			}}
		/>
	);
}
