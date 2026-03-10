import * as React from 'react';

import {
	draggable,
	dropTargetForElements,
	monitorForElements,
} from '@atlaskit/pragmatic-drag-and-drop/element/adapter';
import {
	attachClosestEdge,
	extractClosestEdge,
} from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge';
import { reorderWithEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/util/reorder-with-edge';
import classnames from 'classnames';

import type { Edge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge';
import type { AnyTemplate } from '../types';

function formatCategory(slug: string): string {
	return slug
		.split('-')
		.map((word) => word.charAt(0).toUpperCase() + word.slice(1))
		.join(' ');
}

function formatEngine(engine: string): string {
	const engines: Record<string, string> = {
		'legacy-php': 'Legacy PHP',
		logicless: 'Logicless',
		thermal: 'Thermal',
	};
	return engines[engine] ?? engine;
}

interface DraggableRowProps {
	template: AnyTemplate;
	index: number;
	onPreview: (id: number | string) => void;
	onDisable: (id: number | string) => void;
	onDelete: (id: number) => void;
	isToggling: boolean;
	isDeleting: boolean;
}

function DraggableRow({
	template,
	index,
	onPreview,
	onDisable,
	onDelete,
	isToggling,
	isDeleting,
}: DraggableRowProps) {
	const rowRef = React.useRef<HTMLTableRowElement>(null);
	const handleRef = React.useRef<HTMLTableCellElement>(null);
	const [isDragging, setIsDragging] = React.useState(false);
	const [closestEdge, setClosestEdge] = React.useState<Edge | null>(null);

	const adminUrl = (window as any).wcpos?.templateGallery?.adminUrl ?? `${window.location.origin}/wp-admin`;
	const isVirtual = template.is_virtual;
	const editUrl = !isVirtual ? `${adminUrl}/post.php?post=${template.id}&action=edit` : null;
	const canDelete = !template.is_premade && !isVirtual;

	React.useEffect(() => {
		const row = rowRef.current;
		const handle = handleRef.current;
		if (!row || !handle || isVirtual) return;

		const cleanupDrag = draggable({
			element: row,
			dragHandle: handle,
			getInitialData: () => ({ id: template.id, index }),
			onDragStart: () => setIsDragging(true),
			onDrop: () => setIsDragging(false),
		});

		const cleanupDrop = dropTargetForElements({
			element: row,
			getData: ({ input, element }) => {
				return attachClosestEdge(
					{ id: template.id, index },
					{ element, input, allowedEdges: ['top', 'bottom'] },
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
	}, [template.id, index, isVirtual]);

	return (
		<tr
			ref={rowRef}
			className={classnames(
				'wcpos:relative wcpos:border-b wcpos:border-gray-100',
				isDragging && 'wcpos:opacity-50',
			)}
		>
			{closestEdge && (
				<td
					className="wcpos:p-0 wcpos:border-0"
					style={{ position: 'absolute', left: 0, right: 0, top: closestEdge === 'top' ? 0 : undefined, bottom: closestEdge === 'bottom' ? 0 : undefined, height: '2px', padding: 0 }}
				>
					<div className="wcpos:h-0.5 wcpos:bg-wp-admin-theme-color wcpos:w-full" />
				</td>
			)}
			<td
				ref={handleRef}
				className={classnames(
					'wcpos:px-3 wcpos:py-2 wcpos:text-gray-400 wcpos:text-center wcpos:w-8',
					isVirtual ? 'wcpos:cursor-default' : 'wcpos:cursor-grab',
				)}
			>
				{isVirtual ? '' : '≡'}
			</td>
			<td className="wcpos:px-3 wcpos:py-2 wcpos:text-sm wcpos:font-medium wcpos:text-gray-900">
				{template.title}
			</td>
			<td className="wcpos:px-3 wcpos:py-2 wcpos:text-sm wcpos:text-gray-600">
				{formatCategory(template.category)}
			</td>
			<td className="wcpos:px-3 wcpos:py-2 wcpos:text-sm wcpos:text-gray-600">
				{formatEngine(template.engine)}
			</td>
			<td className="wcpos:px-3 wcpos:py-2">
				<div className="wcpos:flex wcpos:gap-3 wcpos:items-center">
					<button
						type="button"
						onClick={() => onPreview(template.id)}
						className="wcpos:text-xs wcpos:text-wp-admin-theme-color hover:wcpos:underline wcpos:bg-transparent wcpos:border-0 wcpos:p-0 wcpos:cursor-pointer"
					>
						Preview
					</button>
					{editUrl && (
						<a
							href={editUrl}
							className="wcpos:text-xs wcpos:text-wp-admin-theme-color hover:wcpos:underline wcpos:no-underline"
						>
							Edit
						</a>
					)}
					{!isVirtual && (
						<button
							type="button"
							onClick={() => onDisable(template.id)}
							disabled={isToggling}
							className="wcpos:text-xs wcpos:text-wp-admin-theme-color hover:wcpos:underline wcpos:bg-transparent wcpos:border-0 wcpos:p-0 wcpos:cursor-pointer disabled:wcpos:opacity-50 disabled:wcpos:cursor-not-allowed"
						>
							Disable
						</button>
					)}
					{canDelete && (
						<button
							type="button"
							onClick={() => {
								if (window.confirm(`Delete "${template.title}" permanently?`)) {
									onDelete(template.id as number);
								}
							}}
							disabled={isDeleting}
							className="wcpos:text-xs wcpos:text-red-600 hover:wcpos:underline wcpos:bg-transparent wcpos:border-0 wcpos:p-0 wcpos:cursor-pointer disabled:wcpos:opacity-50 disabled:wcpos:cursor-not-allowed"
						>
							Delete
						</button>
					)}
				</div>
			</td>
		</tr>
	);
}

interface ActiveTemplatesTableProps {
	templates: AnyTemplate[];
	onPreview: (id: number | string) => void;
	onDisable: (id: number | string) => void;
	onDelete: (id: number) => void;
	onReorder: (updates: Array<{ id: number | string; menu_order: number }>) => void;
	isToggling: boolean;
	isDeleting: boolean;
}

export function ActiveTemplatesTable({
	templates,
	onPreview,
	onDisable,
	onDelete,
	onReorder,
	isToggling,
	isDeleting,
}: ActiveTemplatesTableProps) {
	const activeTemplates = React.useMemo(() => {
		return templates
			.filter((t): t is AnyTemplate => {
				if ('status' in t && t.status === 'publish') return true;
				if (t.is_virtual && t.is_active) return true;
				return false;
			})
			.sort((a, b) => a.menu_order - b.menu_order);
	}, [templates]);

	React.useEffect(() => {
		return monitorForElements({
			onDrop: ({ source, location }) => {
				const target = location.current.dropTargets[0];
				if (!target) return;

				const sourceId = source.data.id as number | string;
				const targetId = target.data.id as number | string;
				if (sourceId === targetId) return;

				const sourceIndex = activeTemplates.findIndex((t) => t.id === sourceId);
				const targetIndex = activeTemplates.findIndex((t) => t.id === targetId);
				if (sourceIndex < 0 || targetIndex < 0) return;

				const edge = extractClosestEdge(target.data);

				const reordered = reorderWithEdge({
					list: activeTemplates,
					startIndex: sourceIndex,
					indexOfTarget: targetIndex,
					closestEdgeOfTarget: edge,
					axis: 'vertical',
				});

				const updates = reordered.map((t, i) => ({
					id: t.id,
					menu_order: i,
				}));

				onReorder(updates);
			},
		});
	}, [activeTemplates, onReorder]);

	if (activeTemplates.length === 0) {
		return (
			<div className="wcpos:bg-white wcpos:border wcpos:border-gray-200 wcpos:rounded-lg wcpos:p-8 wcpos:text-center">
				<p className="wcpos:text-sm wcpos:text-gray-500 wcpos:m-0">
					No active templates. Browse the gallery below to enable one.
				</p>
			</div>
		);
	}

	return (
		<div className="wcpos:bg-white wcpos:border wcpos:border-gray-200 wcpos:rounded-lg wcpos:overflow-hidden">
			<table className="wcpos:w-full wcpos:border-collapse">
				<thead>
					<tr className="wcpos:border-b wcpos:border-gray-200 wcpos:bg-gray-50">
						<th className="wcpos:px-3 wcpos:py-2 wcpos:w-8" />
						<th className="wcpos:px-3 wcpos:py-2 wcpos:text-left wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider">
							Title
						</th>
						<th className="wcpos:px-3 wcpos:py-2 wcpos:text-left wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider">
							Category
						</th>
						<th className="wcpos:px-3 wcpos:py-2 wcpos:text-left wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider">
							Engine
						</th>
						<th className="wcpos:px-3 wcpos:py-2 wcpos:text-left wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider">
							Actions
						</th>
					</tr>
				</thead>
				<tbody>
					{activeTemplates.map((template, index) => (
						<DraggableRow
							key={template.id}
							template={template}
							index={index}
							onPreview={onPreview}
							onDisable={onDisable}
							onDelete={onDelete}
							isToggling={isToggling}
							isDeleting={isDeleting}
						/>
					))}
				</tbody>
			</table>
		</div>
	);
}
