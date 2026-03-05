import * as React from 'react';

import {
	draggable,
	dropTargetForElements,
} from '@atlaskit/pragmatic-drag-and-drop/element/adapter';
import {
	attachClosestEdge,
	extractClosestEdge,
} from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge';
import classnames from 'classnames';

import type { Edge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge';

interface DraggableCardProps {
	id: number;
	index: number;
	total: number;
	onMove: (id: number, direction: 'previous' | 'next') => void;
	children: React.ReactNode;
	disableDrag?: boolean;
}

export function DraggableCard({
	id,
	index,
	total,
	onMove,
	children,
	disableDrag = false,
}: DraggableCardProps) {
	const ref = React.useRef<HTMLDivElement>(null);
	const [isDragging, setIsDragging] = React.useState(false);
	const [isKeyboardDragging, setIsKeyboardDragging] = React.useState(false);
	const [closestEdge, setClosestEdge] = React.useState<Edge | null>(null);

	React.useEffect(() => {
		const el = ref.current;
		if (!el || disableDrag) return;

		const cleanupDrag = draggable({
			element: el,
			getInitialData: () => ({ id, index }),
			onDragStart: () => setIsDragging(true),
			onDrop: () => setIsDragging(false),
		});

		const cleanupDrop = dropTargetForElements({
			element: el,
			getData: ({ input, element }) => {
				return attachClosestEdge(
					{ id, index },
					{ element, input, allowedEdges: ['left', 'right'] },
				);
			},
			onDragEnter: ({ self }) => setClosestEdge(extractClosestEdge(self.data)),
			onDrag: ({ self }) => setClosestEdge(extractClosestEdge(self.data)),
			onDragLeave: () => setClosestEdge(null),
			onDrop: () => setClosestEdge(null),
		});

		return () => {
			cleanupDrag();
			cleanupDrop();
		};
	}, [disableDrag, id, index]);

	const flashEdge = React.useCallback((edge: Edge) => {
		setClosestEdge(edge);
		window.setTimeout(() => setClosestEdge(null), 250);
	}, []);

	const movePrevious = React.useCallback(() => {
		if (index <= 0) return;
		flashEdge('left');
		onMove(id, 'previous');
	}, [flashEdge, id, index, onMove]);

	const moveNext = React.useCallback(() => {
		if (index >= total - 1) return;
		flashEdge('right');
		onMove(id, 'next');
	}, [flashEdge, id, index, onMove, total]);

	const handleKeyDown = React.useCallback((event: React.KeyboardEvent<HTMLDivElement>) => {
		if (event.key === ' ' || event.key === 'Enter') {
			event.preventDefault();
			setIsKeyboardDragging((value) => !value);
			return;
		}

		if (event.key === 'Escape') {
			setIsKeyboardDragging(false);
			setClosestEdge(null);
			return;
		}

		if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
			event.preventDefault();
			movePrevious();
			return;
		}

		if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
			event.preventDefault();
			moveNext();
		}
	}, [moveNext, movePrevious]);

	return (
		<div
			ref={ref}
			role="listitem"
			tabIndex={disableDrag ? -1 : 0}
			aria-grabbed={isDragging || isKeyboardDragging}
			aria-label={`Template ${index + 1} of ${total}. Use arrow keys to reorder.`}
			onKeyDown={handleKeyDown}
			className={classnames(
				'wcpos:relative focus:wcpos:outline-none focus:wcpos:ring-2 focus:wcpos:ring-wp-admin-theme-color',
				isDragging && 'wcpos:opacity-50',
			)}
		>
			{closestEdge && (
				<div
					className={classnames(
						'wcpos:absolute wcpos:top-0 wcpos:bottom-0 wcpos:w-0.5 wcpos:bg-wp-admin-theme-color wcpos:z-10',
						closestEdge === 'left' ? 'wcpos:left-0' : 'wcpos:right-0',
					)}
				/>
			)}
			<div className="wcpos:absolute wcpos:top-2 wcpos:right-2 wcpos:z-20 wcpos:flex wcpos:gap-1">
				<button
					type="button"
					onClick={movePrevious}
					disabled={index <= 0}
					aria-label="Move template earlier"
					className="wcpos:h-6 wcpos:w-6 wcpos:border wcpos:border-gray-300 wcpos:rounded wcpos:bg-white wcpos:text-gray-700 wcpos:text-xs wcpos:cursor-pointer disabled:wcpos:opacity-40 disabled:wcpos:cursor-not-allowed"
				>
					↑
				</button>
				<button
					type="button"
					onClick={moveNext}
					disabled={index >= total - 1}
					aria-label="Move template later"
					className="wcpos:h-6 wcpos:w-6 wcpos:border wcpos:border-gray-300 wcpos:rounded wcpos:bg-white wcpos:text-gray-700 wcpos:text-xs wcpos:cursor-pointer disabled:wcpos:opacity-40 disabled:wcpos:cursor-not-allowed"
				>
					↓
				</button>
			</div>
			{children}
		</div>
	);
}
