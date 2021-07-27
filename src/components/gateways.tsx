import * as React from 'react';
import { DragDropContext, Droppable, Draggable } from 'react-beautiful-dnd';

export interface GatewaysProps {
	gateways: Record<string, string>[];
}

// fake data generator
const getItems = (count: number) =>
	Array.from({ length: count }, (v, k) => k).map((k) => ({
		id: `item-${k}`,
		content: `item ${k}`,
	}));

// a little function to help us with reordering the result
const reorder = (list: any[], startIndex: number, endIndex: number) => {
	const result = Array.from(list);
	const [removed] = result.splice(startIndex, 1);
	result.splice(endIndex, 0, removed);

	return result;
};

const grid = 8;

const getItemStyle = (isDragging: boolean, draggableStyle: any) => ({
	// some basic styles to make the items look a bit nicer
	userSelect: 'none',
	padding: grid * 2,
	margin: `0 0 ${grid}px 0`,

	// change background colour if dragging
	background: isDragging ? 'lightgreen' : 'grey',

	// styles we need to apply on draggables
	...draggableStyle,
});

const Gateways = ({ gateways }: GatewaysProps) => {
	const [items, setItems] = React.useState(getItems(10));

	const onDragEnd = (result: any) => {
		// dropped outside the list
		if (!result.destination) {
			return;
		}

		const orderedItems = reorder(items, result.source.index, result.destination.index);
		setItems(orderedItems);
	};

	const getListStyle = (isDraggingOver: boolean) => ({
		background: isDraggingOver ? 'lightblue' : 'lightgrey',
		padding: grid,
		width: 250,
	});

	return (
		// <>
		// 	{gateways.map((gateway) => (
		// 		<p key={gateway.id}>{gateway.title}</p>
		// 	))}
		// </>
		<DragDropContext onDragEnd={onDragEnd}>
			<Droppable droppableId="droppable">
				{(provided: any, snapshot: any) => (
					<div
						{...provided.droppableProps}
						ref={provided.innerRef}
						style={getListStyle(snapshot.isDraggingOver)}
					>
						{items.map((item, index) => (
							<Draggable key={item.id} draggableId={item.id} index={index}>
								{(provided: any, snapshot: any) => (
									<div
										ref={provided.innerRef}
										{...provided.draggableProps}
										{...provided.dragHandleProps}
										style={getItemStyle(snapshot.isDragging, provided.draggableProps.style)}
									>
										{item.content}
									</div>
								)}
							</Draggable>
						))}
						{provided.placeholder}
					</div>
				)}
			</Droppable>
		</DragDropContext>
	);
};

export default Gateways;
