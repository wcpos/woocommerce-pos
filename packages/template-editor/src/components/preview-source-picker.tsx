import { useState } from 'react';
import { t } from '../translations';
import type { OrderSummary } from '../hooks/use-preview-data';

interface PreviewSourcePickerProps {
	source: 'sample' | 'order';
	orders: OrderSummary[];
	ordersLoading: boolean;
	dataLoading: boolean;
	onSelectSource: (source: 'sample' | 'order', orderId?: number) => void;
	onRequestOrders: () => void;
}

export function PreviewSourcePicker({
	source,
	orders,
	ordersLoading,
	dataLoading,
	onSelectSource,
	onRequestOrders,
}: PreviewSourcePickerProps) {
	const [expanded, setExpanded] = useState(false);

	const handleToggle = () => {
		if (source === 'order') {
			onSelectSource('sample');
			setExpanded(false);
			return;
		}
		setExpanded(true);
		onRequestOrders();
	};

	const handleOrderSelect = (e: React.ChangeEvent<HTMLSelectElement>) => {
		const orderId = parseInt(e.target.value, 10);
		if (orderId > 0) {
			onSelectSource('order', orderId);
		}
	};

	const handleBackToSample = () => {
		onSelectSource('sample');
		setExpanded(false);
	};

	return (
		<div className="wcpos:flex wcpos:items-center wcpos:gap-2">
			{source === 'sample' && !expanded && (
				<button
					type="button"
					onClick={handleToggle}
					className="wcpos:text-xs wcpos:text-blue-600 hover:wcpos:underline wcpos:cursor-pointer"
				>
					{t('editor.order_history')}
				</button>
			)}

			{source === 'sample' && expanded && (
				<>
					{ordersLoading ? (
						<span className="wcpos:text-xs wcpos:text-gray-500">
							{t('editor.loading_orders')}
						</span>
					) : orders.length === 0 ? (
						<span className="wcpos:text-xs wcpos:text-gray-500">
							{t('editor.no_pos_orders')}
						</span>
					) : (
						<select
							onChange={handleOrderSelect}
							defaultValue=""
							className="wcpos:text-xs wcpos:border wcpos:border-gray-300 wcpos:rounded wcpos:px-1 wcpos:py-0.5"
						>
							<option value="" disabled>
								{t('editor.select_order')}
							</option>
							{orders.map((order) => (
								<option key={order.id} value={order.id}>
									#{order.number} — {order.date} — {order.customer_name || 'Guest'} — {order.total}
								</option>
							))}
						</select>
					)}
				</>
			)}

			{source === 'order' && (
				<>
					{dataLoading && (
						<span className="wcpos:text-xs wcpos:text-gray-500">
							{t('editor.loading_data')}
						</span>
					)}
					<button
						type="button"
						onClick={handleBackToSample}
						className="wcpos:text-xs wcpos:text-blue-600 hover:wcpos:underline wcpos:cursor-pointer"
					>
						{t('editor.sample_data')}
					</button>
				</>
			)}
		</div>
	);
}
