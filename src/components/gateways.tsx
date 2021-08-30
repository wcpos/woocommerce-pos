import * as React from 'react';
import { DragDropContext, Droppable, Draggable } from 'react-beautiful-dnd';
import DragIcon from '../../assets/img/drag-icon.svg';
import GatewayModal from './gateway-modal';
import Toggle from '../components/toggle';
import Button from '../components/button';

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
		},
		[setItems, items]
	);

	return (
		<div className="overflow-hidden border border-gray-200 sm:rounded-lg">
			<DragDropContext onDragEnd={onDragEnd}>
				<table className="min-w-full divide-y divide-gray-200">
					<thead className="bg-gray-50">
						<tr>
							<th scope="col"></th>
							<th
								scope="col"
								className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider text-center"
							>
								Default
							</th>
							<th
								scope="col"
								className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
							>
								Gateway
							</th>
							<th
								scope="col"
								className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"
							>
								Gateway ID
							</th>
							<th
								scope="col"
								className="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider text-center"
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
								className="bg-white divide-y divide-gray-200"
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
												<td className="px-4 py-2 whitespace-nowrap">
													<DragIcon className="w-5 h-5 text-gray-400 fill-current" />
												</td>
												<td className="px-4 py-2 whitespace-nowrap text-center">
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
												<td className="px-4 py-2 whitespace-nowrap">
													<strong>{item.title}</strong>
												</td>
												<td className="px-4 py-2 whitespace-nowrap">{item.id}</td>
												<td className="px-4 py-2 whitespace-nowrap text-center">
													<Toggle
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
												<td className="px-4 py-2 whitespace-nowrap">
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
