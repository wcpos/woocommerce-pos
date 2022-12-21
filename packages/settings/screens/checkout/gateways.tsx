import * as React from 'react';

import { DragDropContext, Droppable, Draggable } from 'react-beautiful-dnd';

import DragIcon from '../../../assets/img/drag-icon.svg';
import Button from '../../components/button';
import Toggle from '../../components/toggle';
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
const Gateways = ({ gateways, defaultGateway, dispatch }: GatewaysProps) => {
	const [items, setItems] = React.useState(gateways);
	const [isOpen, setOpen] = React.useState(false);
	const openModal = () => setOpen(true);
	const closeModal = () => setOpen(false);
	const modalGateway = React.useRef<GatewayProps>(null);

	const onDragEnd = React.useCallback(
		(result: DropResult) => {
			// dropped outside the list
			if (!result.destination) {
				return;
			}

			const orderedItems = reorder(items, result.source.index, result.destination.index);
			setItems(orderedItems);
			dispatch({
				type: 'update',
				payload: { gateways: orderedItems },
			});
		},
		[setItems, items, dispatch]
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
								Default
							</th>
							<th
								scope="col"
								className="wcpos-px-4 wcpos-py-2 text-left wcpos-text-xs wcpos-font-medium wcpos-text-gray-500 wcpos-uppercase wcpos-tracking-wider"
							>
								Gateway
							</th>
							<th
								scope="col"
								className="wcpos-px-4 wcpos-py-2 text-left wcpos-text-xs wcpos-font-medium wcpos-text-gray-500 wcpos-uppercase wcpos-tracking-wider"
							>
								Gateway ID
							</th>
							<th
								scope="col"
								className="wcpos-px-4 wcpos-py-2 text-left wcpos-text-xs wcpos-font-medium wcpos-text-gray-500 wcpos-uppercase wcpos-tracking-wider wcpos-text-center"
							>
								Enabled
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
								{items.map((item, index) => (
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
														checked={defaultGateway === item.id}
														className=""
														onChange={() => {
															dispatch({
																type: 'update',
																payload: { default_gateway: item.id },
															});
														}}
													/>
												</td>
												<td className="wcpos-px-4 wcpos-py-2 wcpos-whitespace-nowrap">
													<strong>{item.title}</strong>
												</td>
												<td className="wcpos-px-4 wcpos-py-2 wcpos-whitespace-nowrap">{item.id}</td>
												<td className="wcpos-px-4 wcpos-py-2 wcpos-whitespace-nowrap">
													<Toggle
														name={item.id}
														checked={item.enabled}
														onChange={() => {
															dispatch({
																type: 'update-gateway',
																payload: {
																	id: item.id,
																	enabled: !item.enabled,
																},
															});
														}}
													/>
												</td>
												<td className="wcpos-px-4 wcpos-py-2 wcpos-whitespace-nowrap">
													<Button
														background="outline"
														onClick={() => {
															// @ts-ignore
															modalGateway.current = item;
															openModal();
														}}
													>
														Settings
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
				<GatewayModal gateway={modalGateway.current} dispatch={dispatch} closeModal={closeModal} />
			)}
		</div>
	);
};

export default Gateways;
