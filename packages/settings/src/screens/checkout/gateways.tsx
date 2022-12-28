import * as React from 'react';

import { map, sortBy, keyBy } from 'lodash';
import { DragDropContext, Droppable, Draggable } from 'react-beautiful-dnd';

import DragIcon from '../../../assets/drag-icon.svg';
import Button from '../../components/button';
import Toggle from '../../components/toggle';
import useSettingsApi from '../../hooks/use-settings-api';
import { t } from '../../translations';
import GatewayModal from './gateway-modal';

import type {
	DraggableProvided,
	DroppableProvided,
	DraggableStateSnapshot,
	DropResult,
} from 'react-beautiful-dnd';

export interface GatewayProps {
	id: string;
	title: string;
	description: string;
	enabled: boolean;
}

export interface GatewaysProps {
	gateways: GatewayProps[];
	defaultGateway: string;
	dispatch: React.Dispatch<any>;
}

// a little function to help us with reordering the result
const reorder = (list: any[], startIndex: number, endIndex: number) => {
	const result = Array.from(list);
	const [removed] = result.splice(startIndex, 1);
	result.splice(endIndex, 0, removed);

	return result;
};

const getItemStyle = (isDragging: boolean, draggableStyle: any, index: number) => ({
	background: isDragging ? '#e5f1f8' : index % 2 == 0 ? 'transparent' : '#F9FAFB',
	display: isDragging ? 'table' : 'table-row',

	// styles we need to apply on draggables
	...draggableStyle,
});

/**
 *
 */
const Gateways = () => {
	const { data, mutate } = useSettingsApi('payment-gateways');
	const [isOpen, setOpen] = React.useState(false);
	const modalGateway = React.useRef<GatewayProps>(null);

	/**
	 * Sort gateways by order.
	 * NOTE: This will convert associative array to indexed array, we will need to keyBy when saving.
	 */
	const gateways = sortBy(
		data?.gateways as Record<
			string,
			{ id: string; title: string; order: number; enabled: boolean }
		>,
		['order', 'id']
	);

	const onDragEnd = React.useCallback(
		(result: DropResult) => {
			// dropped outside the list
			if (!result.destination) {
				return;
			}

			const orderedItems = reorder(gateways, result.source.index, result.destination.index);
			const mutatedList = map(orderedItems, (item, index) => {
				item.order = index;
				return item;
			});

			mutate({ gateways: keyBy(mutatedList, 'id') });
		},
		[gateways, mutate]
	);

	return (
		<div className="wcpos-overflow-hidden wcpos-border wcpos-border-gray-200 sm:wcpos-rounded-lg">
			<DragDropContext onDragEnd={onDragEnd}>
				<table className="wcpos-min-w-full wcpos-divide-y wcpos-divide-gray-200">
					<thead className="wcpos-bg-gray-50">
						<tr>
							<th scope="col"></th>
							<th
								scope="col"
								className="wcpos-px-4 wcpos-py-2 text-left wcpos-text-xs wcpos-font-medium wcpos-text-gray-500 wcpos-uppercase wcpos-tracking-wider wcpos-text-center"
							>
								{t('Default', { _tags: 'wp-admin-settings' })}
							</th>
							<th
								scope="col"
								className="wcpos-px-4 wcpos-py-2 text-left wcpos-text-xs wcpos-font-medium wcpos-text-gray-500 wcpos-uppercase wcpos-tracking-wider wcpos-text-left"
							>
								{t('Gateway', { _tags: 'wp-admin-settings' })}
							</th>
							<th
								scope="col"
								className="wcpos-px-4 wcpos-py-2 text-left wcpos-text-xs wcpos-font-medium wcpos-text-gray-500 wcpos-uppercase wcpos-tracking-wider wcpos-text-left"
							>
								{t('Gateway ID', { _tags: 'wp-admin-settings' })}
							</th>
							<th
								scope="col"
								className="wcpos-px-4 wcpos-py-2 text-left wcpos-text-xs wcpos-font-medium wcpos-text-gray-500 wcpos-uppercase wcpos-tracking-wider wcpos-text-center"
							>
								{t('Enabled', { _tags: 'wp-admin-settings' })}
							</th>
							<th scope="col"></th>
						</tr>
					</thead>
					<Droppable droppableId="woocommerce-pos-gateways">
						{(provided: DroppableProvided) => (
							<tbody
								{...provided.droppableProps}
								ref={provided.innerRef}
								className="wcpos-bg-white wcpos-divide-y wcpos-divide-gray-200"
							>
								{map(gateways, (item, index) => (
									<Draggable key={item.id} draggableId={item.id} index={index}>
										{(provided: DraggableProvided, snapshot: DraggableStateSnapshot) => (
											<tr
												ref={provided.innerRef}
												{...provided.draggableProps}
												{...provided.dragHandleProps}
												style={getItemStyle(
													snapshot.isDragging,
													provided.draggableProps.style,
													index
												)}
											>
												<td className="wcpos-px-4 wcpos-py-2 wcpos-whitespace-nowrap">
													<DragIcon className="wcpos-w-5 wcpos-h-5 wcpos-text-gray-400 wcpos-fill-current" />
												</td>
												<td className="wcpos-px-4 wcpos-py-2 wcpos-whitespace-nowrap wcpos-text-center">
													<input
														type="radio"
														value={item.id}
														checked={data?.default_gateway === item.id}
														className=""
														onChange={() => {
															mutate({ default_gateway: item.id });
														}}
													/>
												</td>
												<td className="wcpos-px-4 wcpos-py-2">
													<strong>{item.title}</strong>
												</td>
												<td className="wcpos-px-4 wcpos-py-2 wcpos-whitespace-nowrap">{item.id}</td>
												<td className="wcpos-px-4 wcpos-py-2 wcpos-whitespace-nowrap">
													<Toggle
														name={item.id}
														checked={item.enabled}
														onChange={() => {
															mutate({
																gateways: {
																	[item.id]: {
																		enabled: !item.enabled,
																	},
																},
															});
														}}
													/>
												</td>
												<td className="wcpos-px-4 wcpos-py-2 wcpos-whitespace-nowrap wcpos-text-right">
													<Button
														background="outline"
														onClick={() => {
															// @ts-ignore
															modalGateway.current = item;
															setOpen(true);
														}}
													>
														{t('Settings', { _tags: 'wp-admin-settings' })}
													</Button>
												</td>
											</tr>
										)}
									</Draggable>
								))}
								{provided.placeholder}
							</tbody>
						)}
					</Droppable>
				</table>
			</DragDropContext>
			{isOpen && modalGateway.current && (
				<GatewayModal
					gateway={modalGateway.current}
					mutate={mutate}
					closeModal={() => setOpen(false)}
				/>
			)}
		</div>
	);
};

export default Gateways;
