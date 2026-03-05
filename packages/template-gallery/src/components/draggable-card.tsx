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
	children: React.ReactNode;
}

export function DraggableCard({ id, index, children }: DraggableCardProps) {
	const ref = React.useRef<HTMLDivElement>(null);
	const [isDragging, setIsDragging] = React.useState(false);
	const [closestEdge, setClosestEdge] = React.useState<Edge | null>(null);

	React.useEffect(() => {
		const el = ref.current;
		if (!el) return;

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
	}, [id, index]);

	return (
		<div
			ref={ref}
			className={classnames(
				'wcpos:relative',
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
			{children}
		</div>
	);
}
