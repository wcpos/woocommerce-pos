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

import { t } from '../translations';
import type { AnyTemplate, Template, VirtualTemplate } from '../types';

function isThermal(template: AnyTemplate): boolean {
	return (
		template.engine === 'thermal' ||
		template.output_type === 'escpos' ||
		template.output_type === 'thermal'
	);
}

function isOffline(template: AnyTemplate): boolean {
	return (
		template.engine === 'logicless' ||
		template.engine === 'thermal' ||
		template.offline_capable
	);
}

function getPrintMethod(template: AnyTemplate): string {
	return isThermal(template) ? t('table.receipt_printer') : t('table.browser');
}

function getPaperSize(template: AnyTemplate): string {
	if (!isThermal(template)) return '\u2014';
	const pw = 'paper_width' in template ? template.paper_width : null;
	return pw || '\u2014';
}

function getAvailability(template: AnyTemplate): string {
	return isOffline(template) ? t('table.offline') : t('table.server');
}

function formatCategory(slug: string | undefined): string {
	if (!slug) return '\u2014';
	return slug
		.split('-')
		.map((word) => word.charAt(0).toUpperCase() + word.slice(1))
		.join(' ');
}

function isTemplateEnabled(template: AnyTemplate): boolean {
	if (template.is_virtual) {
		return !('is_disabled' in template && (template as VirtualTemplate).is_disabled);
	}
	return (template as Template).status === 'publish';
}

interface DraggableRowProps {
	template: AnyTemplate;
	index: number;
	onPreview: (id: number | string) => void;
	onToggle: (id: number | string) => void;
	onDelete: (id: number) => void;
	isToggling: boolean;
	isDeleting: boolean;
}

function DraggableRow({
	template,
	index,
	onPreview,
	onToggle,
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
	const canDelete = !isVirtual;
	const enabled = isTemplateEnabled(template);

	React.useEffect(() => {
		const row = rowRef.current;
		const handle = handleRef.current;
		if (!row || !handle) return;

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
	}, [template.id, index]);

	return (
		<tr
			ref={rowRef}
			className={classnames(
				'wcpos:relative wcpos:border-b wcpos:border-gray-100',
				isDragging && 'wcpos:opacity-50',
				!enabled && 'wcpos:opacity-60',
			)}
		>
			<td
				ref={handleRef}
				className="wcpos:px-3 wcpos:py-2 wcpos:text-gray-400 wcpos:text-center wcpos:w-8 wcpos:relative wcpos:overflow-visible wcpos:cursor-grab"
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
				&#8801;
			</td>
			<td className="wcpos:px-3 wcpos:py-2">
				<div className="wcpos:text-sm wcpos:font-medium wcpos:text-gray-900">
					{template.title}
				</div>
			</td>
			<td className="wcpos:px-3 wcpos:py-2 wcpos:text-sm wcpos:text-gray-600">
				{formatCategory(template.category)}
			</td>
			<td className="wcpos:px-3 wcpos:py-2 wcpos:text-sm wcpos:text-gray-600">
				{getPrintMethod(template)}
			</td>
			<td className="wcpos:px-3 wcpos:py-2 wcpos:text-sm wcpos:text-gray-600">
				{getPaperSize(template)}
			</td>
			<td className="wcpos:px-3 wcpos:py-2 wcpos:text-sm wcpos:text-gray-600">
				{getAvailability(template)}
			</td>
			<td className="wcpos:px-3 wcpos:py-2 wcpos:text-center">
				<button
					type="button"
					onClick={() => onToggle(template.id)}
					disabled={isToggling}
					aria-label={enabled ? t('table.deactivate_template') : t('table.activate_template')}
					aria-pressed={enabled}
					className={classnames(
						'wcpos:relative wcpos:inline-flex wcpos:h-5 wcpos:w-9 wcpos:shrink-0 wcpos:cursor-pointer wcpos:rounded-full wcpos:border-2 wcpos:border-transparent wcpos:transition-colors wcpos:duration-200 wcpos:ease-in-out wcpos:bg-transparent wcpos:p-0',
						'focus:wcpos:outline-none focus:wcpos:ring-2 focus:wcpos:ring-wp-admin-theme-color focus:wcpos:ring-offset-2',
						enabled ? 'wcpos:bg-wp-admin-theme-color' : 'wcpos:bg-gray-200',
						isToggling && 'wcpos:opacity-50 wcpos:cursor-not-allowed',
					)}
				>
					<span
						className={classnames(
							'wcpos:pointer-events-none wcpos:inline-block wcpos:h-4 wcpos:w-4 wcpos:transform wcpos:rounded-full wcpos:bg-white wcpos:shadow wcpos:ring-0 wcpos:transition wcpos:duration-200 wcpos:ease-in-out',
							enabled ? 'wcpos:translate-x-4' : 'wcpos:translate-x-0',
						)}
					/>
				</button>
			</td>
			<td className="wcpos:px-3 wcpos:py-2">
				<div className="wcpos:flex wcpos:gap-3 wcpos:items-center">
					<button
						type="button"
						onClick={() => onPreview(template.id)}
						className="wcpos:text-xs wcpos:text-wp-admin-theme-color hover:wcpos:underline wcpos:bg-transparent wcpos:border-0 wcpos:p-0 wcpos:cursor-pointer"
					>
						{t('common.preview')}
					</button>
					{editUrl && (
						<a
							href={editUrl}
							className="wcpos:text-xs wcpos:text-wp-admin-theme-color hover:wcpos:underline wcpos:no-underline"
						>
							{t('common.edit')}
						</a>
					)}
					{canDelete && (
						<button
							type="button"
							onClick={() => {
								if (window.confirm(t('table.confirm_delete', { title: template.title }))) {
									onDelete(template.id as number);
								}
							}}
							disabled={isDeleting}
							className="wcpos:text-xs wcpos:text-red-600 hover:wcpos:underline wcpos:bg-transparent wcpos:border-0 wcpos:p-0 wcpos:cursor-pointer disabled:wcpos:opacity-50 disabled:wcpos:cursor-not-allowed"
						>
							{t('common.delete')}
						</button>
					)}
				</div>
			</td>
		</tr>
	);
}

interface TemplatesTableProps {
	templates: AnyTemplate[];
	onPreview: (id: number | string) => void;
	onToggle: (id: number | string) => void;
	onDelete: (id: number) => void;
	onReorder: (orderedIds: Array<number | string>) => void;
	togglingId: number | string | null;
	deletingId: number | null;
}

export function TemplatesTable({
	templates,
	onPreview,
	onToggle,
	onDelete,
	onReorder,
	togglingId,
	deletingId,
}: TemplatesTableProps) {
	const tableRef = React.useRef<HTMLTableElement>(null);

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

				const sourceIndex = templates.findIndex((t) => t.id === sourceId);
				const targetIndex = templates.findIndex((t) => t.id === targetId);
				if (sourceIndex < 0 || targetIndex < 0) return;

				const edge = extractClosestEdge(target.data);

				const reordered = reorderWithEdge({
					list: templates,
					startIndex: sourceIndex,
					indexOfTarget: targetIndex,
					closestEdgeOfTarget: edge,
					axis: 'vertical',
				});

				onReorderRef.current(reordered.map((t) => t.id));
			},
		});
	}, [templates]);

	if (templates.length === 0) {
		return (
			<div className="wcpos:bg-white wcpos:border wcpos:border-gray-200 wcpos:rounded-lg wcpos:p-8 wcpos:text-center">
				<p className="wcpos:text-sm wcpos:text-gray-500 wcpos:m-0">
					{t('table.no_templates')}
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
							{t('common.title')}
						</th>
						<th className="wcpos:px-3 wcpos:py-2 wcpos:text-left wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider">
							{t('common.category')}
						</th>
						<th className="wcpos:px-3 wcpos:py-2 wcpos:text-left wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider">
							{t('table.header_print')}
						</th>
						<th className="wcpos:px-3 wcpos:py-2 wcpos:text-left wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider">
							{t('table.header_paper')}
						</th>
						<th className="wcpos:px-3 wcpos:py-2 wcpos:text-left wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider">
							{t('table.header_mode')}
						</th>
						<th className="wcpos:px-3 wcpos:py-2 wcpos:text-center wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider">
							{t('table.header_active')}
						</th>
						<th className="wcpos:px-3 wcpos:py-2 wcpos:text-left wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider">
							{t('table.header_actions')}
						</th>
					</tr>
				</thead>
				<tbody>
					{templates.map((template, index) => (
						<DraggableRow
							key={template.id}
							template={template}
							index={index}
							onPreview={onPreview}
							onToggle={onToggle}
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
