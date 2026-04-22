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
import classNames from 'classnames';
import { sortBy, keyBy } from 'lodash';

import { DropdownMenu, DropdownMenuItem } from '@wcpos/ui';

import GatewayModal from './gateway-modal';
import OrderStatusSelect from './order-status-select';
import DragIcon from '../../../assets/drag-icon.svg';
import MoreVerticalIcon from '../../../assets/more-vertical-icon.svg';
import Notice from '../../components/notice';
import { Toggle } from '../../components/ui';
import { captureUpgradeCtaClicked, captureUpgradeCtaViewed } from '../../lib/analytics';
import useSettingsApi from '../../hooks/use-settings-api';
import { t } from '../../translations';

import type { Edge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge';

export interface GatewayProps {
	id: string;
	title: string;
	description: string;
	enabled: boolean;
}

interface GatewayItem {
	id: string;
	title: string;
	order: number;
	enabled: boolean;
	order_status?: string;
}

interface GatewayRowProps {
	item: GatewayItem;
	index: number;
	data: any;
	mutate: (data: any) => void;
	proEnabled: boolean;
	onEditGateway: (item: GatewayItem) => void;
}

/**
 * Individual gateway row with drag-and-drop support.
 */
function GatewayRow({ item, index, data, mutate, proEnabled, onEditGateway }: GatewayRowProps) {
	const rowRef = React.useRef<HTMLTableRowElement>(null);
	const dragHandleRef = React.useRef<HTMLTableCellElement>(null);
	const [isDragging, setIsDragging] = React.useState(false);
	const [closestEdge, setClosestEdge] = React.useState<Edge | null>(null);

	React.useEffect(() => {
		const row = rowRef.current;
		const handle = dragHandleRef.current;
		if (!row || !handle) return;

		const cleanupDrag = draggable({
			element: row,
			dragHandle: handle,
			getInitialData: () => ({ id: item.id, index }),
			onDragStart: () => setIsDragging(true),
			onDrop: () => setIsDragging(false),
		});

		const cleanupDrop = dropTargetForElements({
			element: row,
			getData: ({ input, element }) => {
				return attachClosestEdge(
					{ id: item.id, index },
					{ element, input, allowedEdges: ['top', 'bottom'] }
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
	}, [item.id, index]);

	return (
		<tr
			ref={rowRef}
			className={classNames(
				'wcpos:relative',
				isDragging && 'wcpos:opacity-50',
				index % 2 !== 0 && 'wcpos:bg-gray-50'
			)}
		>
			{closestEdge && (
				<td
					colSpan={7}
					className={classNames(
						'wcpos:absolute wcpos:left-0 wcpos:right-0 wcpos:h-0.5 wcpos:bg-wp-admin-theme-color wcpos:p-0',
						closestEdge === 'top' ? 'wcpos:top-0' : 'wcpos:bottom-0'
					)}
				/>
			)}
			<td
				ref={dragHandleRef}
				className="wcpos:pl-3 wcpos:pr-1 wcpos:py-2 wcpos:whitespace-nowrap wcpos:cursor-grab wcpos:w-8"
			>
				<DragIcon className="wcpos:w-5 wcpos:h-5 wcpos:text-gray-400 wcpos:fill-current" />
			</td>
			<td className="wcpos:px-4 wcpos:py-2 wcpos:whitespace-nowrap wcpos:text-center">
				<input
					type="radio"
					value={item.id}
					checked={data?.default_gateway === item.id}
					disabled={!item.enabled}
					className=""
					onChange={() => {
						mutate({ default_gateway: item.id });
					}}
				/>
			</td>
			<td className="wcpos:px-4 wcpos:py-2 wcpos:text-ellipsis wcpos:overflow-hidden wcpos:whitespace-nowrap">
				<strong>{item.title}</strong>
			</td>
			<td className="wcpos:px-4 wcpos:py-2 wcpos:whitespace-nowrap">{item.id}</td>
			<td className="wcpos:px-4 wcpos:py-2 wcpos:whitespace-nowrap wcpos:text-center">
				<Toggle
					aria-label={item.title}
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
					disabled={!proEnabled && !['pos_cash', 'pos_card'].includes(item.id)}
				/>
			</td>
			<td className="wcpos:px-4 wcpos:py-2 wcpos:whitespace-nowrap">
				<OrderStatusSelect
					selectedStatus={item.order_status || 'wc-completed'}
					onChange={(value) => {
						mutate({
							gateways: {
								[item.id]: {
									order_status: value,
								},
							},
						});
					}}
					disabled={!proEnabled && !['pos_cash', 'pos_card'].includes(item.id)}
				/>
			</td>
			<td className="wcpos:px-4 wcpos:py-2 wcpos:whitespace-nowrap wcpos:text-right">
				<DropdownMenu
					label={item.title}
					trigger={
						<span
							className={classNames(
								'wcpos:inline-flex wcpos:items-center wcpos:justify-center wcpos:rounded-md wcpos:border wcpos:border-gray-300 wcpos:bg-white wcpos:px-2 wcpos:py-1.5 wcpos:text-gray-700 wcpos:hover:bg-gray-50',
								!proEnabled &&
									!['pos_cash', 'pos_card'].includes(item.id) &&
									'wcpos:opacity-50 wcpos:cursor-not-allowed wcpos:pointer-events-none'
							)}
							aria-label={t('common.settings')}
						>
							<MoreVerticalIcon className="wcpos:w-4 wcpos:h-4 wcpos:fill-current" />
						</span>
					}
				>
					<DropdownMenuItem
						onSelect={() => onEditGateway(item)}
						disabled={!proEnabled && !['pos_cash', 'pos_card'].includes(item.id)}
					>
						{t('common.pos_settings')}
					</DropdownMenuItem>
					{!['pos_cash', 'pos_card'].includes(item.id) && (
						<DropdownMenuItem
							href={`admin.php?page=wc-settings&tab=checkout&section=${item.id}`}
						>
							{t('common.woocommerce_settings')}
						</DropdownMenuItem>
					)}
				</DropdownMenu>
			</td>
		</tr>
	);
}

/**
 * Payment gateways table with drag-and-drop reordering.
 */
function Gateways() {
	const { data, mutate } = useSettingsApi('payment-gateways');
	const [isOpen, setOpen] = React.useState(false);
	const modalGateway = React.useRef<GatewayProps>(null);
	const proEnabled = data?.pro_enabled;

	/**
	 * Sort gateways by order.
	 * NOTE: This will convert associative array to indexed array, we will need to keyBy when saving.
	 */
	const gateways = sortBy(data?.gateways as Record, ['order', 'id']);

	React.useEffect(() => {
		return monitorForElements({
			onDrop: ({ source, location }) => {
				const target = location.current.dropTargets[0];
				if (!target) return;

				const sourceIndex = source.data.index as number;
				const targetIndex = target.data.index as number;
				const edge = extractClosestEdge(target.data);

				const reordered = reorderWithEdge({
					list: gateways,
					startIndex: sourceIndex,
					indexOfTarget: targetIndex,
					closestEdgeOfTarget: edge,
					axis: 'vertical',
				});

				const mutatedList = reordered.map((item, idx) => ({ ...item, order: idx }));
				mutate({ gateways: keyBy(mutatedList, 'id') });
			},
		});
	}, [gateways, mutate]);

	React.useEffect(() => {
		if (!proEnabled) {
			captureUpgradeCtaViewed('checkout_gateways');
		}
	}, [proEnabled]);

	const handleEditGateway = React.useCallback((item: GatewayItem) => {
		// @ts-ignore
		modalGateway.current = item;
		setOpen(true);
	}, []);

	return (
		<>
			{proEnabled ? (
				''
			) : (
				<div className="wcpos:pb-5">
					<Notice status="info" isDismissible={false}>
						{t('checkout.enable_pro_gateways')}{' '}
						<a
							href="https://wcpos.com/pro"
							onClick={() => captureUpgradeCtaClicked('checkout_gateways', 'https://wcpos.com/pro')}
						>
							{t('common.upgrade_to_pro')}
						</a>
						.
					</Notice>
				</div>
			)}
			<div className="wcpos:overflow-x-auto wcpos:border wcpos:border-gray-200 wcpos:sm:rounded-lg">
				<table className="wcpos:min-w-full wcpos:divide-y wcpos:divide-gray-200">
					<thead className="wcpos:bg-gray-50">
						<tr>
							<th scope="col" />
							<th
								scope="col"
								className="wcpos:px-4 wcpos:py-2 wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider wcpos:text-center"
							>
								{t('common.default')}
							</th>
							<th
								scope="col"
								className="wcpos:px-4 wcpos:py-2 wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider wcpos:text-left"
							>
								{t('common.gateway')}
							</th>
							<th
								scope="col"
								className="wcpos:px-4 wcpos:py-2 wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider wcpos:text-left"
							>
								{t('checkout.gateway_id')}
							</th>
							<th
								scope="col"
								className="wcpos:px-4 wcpos:py-2 wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider wcpos:text-center"
							>
								{t('common.enabled')}
							</th>
							<th
								scope="col"
								className="wcpos:px-4 wcpos:py-2 wcpos:text-xs wcpos:font-medium wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wider wcpos:text-left"
							>
								{t('checkout.order_status')}
							</th>
							<th scope="col" />
						</tr>
					</thead>
					<tbody className="wcpos:bg-white wcpos:divide-y wcpos:divide-gray-200">
						{gateways.map((item, index) => (
							<GatewayRow
								key={item.id}
								item={item}
								index={index}
								data={data}
								mutate={mutate}
								proEnabled={!!proEnabled}
								onEditGateway={handleEditGateway}
							/>
						))}
					</tbody>
				</table>
				{isOpen && modalGateway.current && (
					<GatewayModal
						gateway={modalGateway.current}
						mutate={mutate}
						closeModal={() => setOpen(false)}
					/>
				)}
			</div>
		</>
	);
}

export default Gateways;
