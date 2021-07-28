import * as React from 'react';
import { DragDropContext, Droppable, Draggable } from 'react-beautiful-dnd';
import {
	Button,
	FormToggle,
	Modal,
	TextControl,
	TextareaControl,
	Notice,
} from '@wordpress/components';
import DragIcon from '../../assets/img/drag-icon.svg';
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
	background: isDragging ? '#e5f1f8' : index % 2 == 0 ? '#f9f9f9' : 'transparent',

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
		<>
			<DragDropContext onDragEnd={onDragEnd}>
				<Droppable droppableId="woocommerce-pos-gateways">
					{(provided: DroppableProvided) => (
						<div
							{...provided.droppableProps}
							ref={provided.innerRef}
							className="woocommerce-pos-table"
						>
							<div className="woocommerce-pos-table-header">
								<div className="woocommerce-pos-table-cell" style={{ maxWidth: '20px' }}></div>
								<div
									className="woocommerce-pos-table-cell"
									style={{ maxWidth: '80px', textAlign: 'center' }}
								>
									Default
								</div>
								<div className="woocommerce-pos-table-cell">Gateway</div>
								<div className="woocommerce-pos-table-cell">Gateway ID</div>
								<div
									className="woocommerce-pos-table-cell"
									style={{ maxWidth: '80px', textAlign: 'center' }}
								>
									Enabled
								</div>
								<div className="woocommerce-pos-table-cell" style={{ maxWidth: '100px' }}></div>
							</div>
							{items.map((item, index) => (
								<Draggable key={item.id} draggableId={item.id} index={index}>
									{(provided: DraggableProvided, snapshot: DraggableStateSnapshot) => (
										<div
											ref={provided.innerRef}
											{...provided.draggableProps}
											{...provided.dragHandleProps}
											className="woocommerce-pos-table-row"
											style={getItemStyle(
												snapshot.isDragging,
												provided.draggableProps.style,
												index
											)}
										>
											<div className="woocommerce-pos-table-cell" style={{ maxWidth: '20px' }}>
												<DragIcon fill="#ccc" />
											</div>
											<div
												className="woocommerce-pos-table-cell"
												style={{ maxWidth: '80px', textAlign: 'center' }}
											>
												<input
													type="radio"
													value={item.id}
													checked={defaultGateway === item.id}
													className="components-radio-control__input"
													onChange={() => {
														dispatch({
															type: 'update',
															payload: { default_gateway: item.id },
														});
													}}
												/>
											</div>
											<div className="woocommerce-pos-table-cell">
												<strong>{item.title}</strong>
											</div>
											<div className="woocommerce-pos-table-cell">{item.id}</div>
											<div
												className="woocommerce-pos-table-cell"
												style={{ maxWidth: '80px', textAlign: 'center' }}
											>
												<FormToggle
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
											</div>
											<div
												className="woocommerce-pos-table-cell"
												style={{ maxWidth: '100px', textAlign: 'right' }}
											>
												<Button
													isSecondary
													onClick={() => {
														// @ts-ignore
														modalGateway.current = item;
														openModal();
													}}
												>
													Settings
												</Button>
											</div>
										</div>
									)}
								</Draggable>
							))}
							{provided.placeholder}
						</div>
					)}
				</Droppable>
			</DragDropContext>
			{isOpen && modalGateway.current && (
				<GatewayModal gateway={modalGateway.current} dispatch={dispatch} closeModal={closeModal} />
			)}
		</>
	);
};

export default Gateways;
