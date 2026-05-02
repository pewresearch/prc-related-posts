/**
 * External Dependencies
 */
import styled from '@emotion/styled';

/**
 * WordPress Dependencies
 */
import { Icon, IconButton, TextControl } from '@wordpress/components';
import { dragHandle } from '@wordpress/icons';

const ListItem = styled('div')`
	background: white;
	padding-bottom: 1em;
	margin-bottom: 1em;
	border-bottom: 1px solid #eaeaea;

	&.is-last {
		border-bottom: none;
		margin-bottom: 0;
	}
`;

const Row = styled('div')`
	display: flex;
	align-items: center;
	flex-direction: row;
	width: 100%;
`;

const DragHandle = styled('div')`
	display: flex;
`;

const LabelControl = styled('div')`
	display: flex;
	flex-direction: column;
	flex-grow: 1;
	padding-left: 1em;

	& .components-base-control__field {
		margin-bottom: 0;
	}
`;

function ListStoreItem({
	label,
	defaultLabel,
	index,
	onRemove,
	onLabelChange,
	lastItem = false,
}) {
	return (
		<ListItem className={`${lastItem ? 'is-last' : null}`}>
			<Row>
				<DragHandle>
					<Icon icon={dragHandle} />
				</DragHandle>
				<LabelControl>
					<TextControl
						value={label ?? defaultLabel ?? ''}
						onChange={(newLabel) => {
							if (onLabelChange) {
								onLabelChange(newLabel);
							}
						}}
					/>
				</LabelControl>
				<div style={{ display: 'flex', flexDirection: 'column' }}>
					<IconButton
						icon="no-alt"
						onClick={() => {
							if (onRemove) {
								onRemove(index);
							}
						}}
					/>
				</div>
			</Row>
		</ListItem>
	);
}

export default ListStoreItem;
