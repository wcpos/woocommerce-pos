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
import type { AnyTemplate, Template } from '../types';

function formatCategory(slug: string | undefined): string {
	if (!slug) return '\u2014';
	return slug
		.split('-')
		.map((word) => word.charAt(0).toUpperCase() + word.slice(1))
		.join(' ');
}

function formatConnectivity(template: AnyTemplate): {
	label: string;
	className: string;
} {
	const isOffline =
		template.engine === 'logicless' ||
		template.engine === 'thermal' ||
		('offline_capable' in template && template.offline_capable);

	return isOffline
		? { label: 'Offline', className: 'wcpos:text-green-600' }
		: { label: 'Online', className: 'wcpos:text-gray-500' };
}

function formatOutput(template: AnyTemplate): string {
	if (template.output_type === 'thermal') return 'Thermal';
	return 'HTML';
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

	const connectivity = formatConnectivity(template);

	return (
		<tr
			ref={rowRef}
			className={classnames(
				'wcpos:relative wcpos:border-b wcpos:border-gray-100',
				isDragging && 'wcpos:opacity-50',
			)}
		>
			<td
				ref={handleRef}
				className={classnames(
					'wcpos:px-3 wcpos:py-2 wcpos:text-gray-400 wcpos:text-center wcpos:w-8 wcpos:relative wcpos:overflow-visible',
					isVirtual ? 'wcpos:cursor-default' : 'wcpos:cursor-grab',
				)}
			>
				{closestEdge && (
					<div
						className={classnames(
							'wcpos:pointer-events-none wcpos:absolute wcpos:left-0 wcpos:h-0.5 wcpos:bg-wp-admin-theme-color wcpos:z-10',
							closestEdge === 'top' ? 'wcpos:-top-px' : 'wcpos:-bottom-px',
						)}
						style={{ width: 'calc(var(--table-width, 100%) + 1px)' }}
					/>
				)}
				{isVirtual ? '' : '\u2261'}
			</td>
			<td className="wcpos:px-3 wcpos:py-2 wcpos:text-sm wcpos:font-medium wcpos:text-gray-900">
				{template.title}
			</td>
			<td className="wcpos:px-3 wcpos:py-2 wcpos:text-sm wcpos:text-gray-600">
				{formatCategory(template.category)}
			</td>
			<td className={classnames('wcpos:px-3 wcpos:py-2 wcpos:text-sm', connectivity.className)}>
				{connectivity.label}
			</td>
			<td className="wcpos:px-3 wcpos:py-2 wcpos:text-sm wcpos:text-gray-600">
				{formatOutput(template)}
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
	onReorder: (updates: Array<{ id: number; menu_order: number }>) => void;
	togglingId: number | null;
	deletingId: number | null;
}

export function ActiveTemplatesTable({
	templates,
	onPreview,
	onDisable,
	onDelete,
	onReorder,
	togglingId,
	deletingId,
}: ActiveTemplatesTableProps) {
	const tableRef = React.useRef<HTMLTableElement>(null);

	const activeTemplates = React.useMemo(() => {
		return templates
			.filter((t) => {
				if ('status' in t && t.status === 'publish') return true;
				if (t.is_virtual && t.is_active) return true;
				return false;
			})
			.sort((a, b) => a.menu_order - b.menu_order);
	}, [templates]);

	const onReorderRef = React.useRef(onReorder);
	onReorderRef.current = onReorder;

	// Set --table-width CSS variable so the drop indicator can span the full row.
	React.useEffect(() => {
		const table = tableRef.current;
		if (!table) return;

		const observer = new ResizeObserver(([entry]) => {
			table.style.setProperty('--table-width', `${entry.contentRect.width}px`);
		});
		observer.observe(table);
		return () => observer.disconnect();
	}, []);

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

				const updates = reordered
					.filter((t): t is Template => typeof t.id === 'number')
					.map((t, i) => ({
						id: t.id,
						menu_order: i,
					}));

				onReorderRef.current(updates);
			},
		});
	}, [activeTemplates]);

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
			<table ref={tableRef} className="wcpos:w-full wcpos:border-collapse">
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
							Connectivity
						</th>
						<th className="wcpos:px-3 wcpos:py-2 wcpos:text-left wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider">
							Output
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
							isToggling={template.id === togglingId}
							isDeleting={template.id === deletingId}
						/>
					))}
				</tbody>
			</table>
		</div>
	);
}
