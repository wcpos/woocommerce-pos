import * as React from 'react';

import { Select } from '../../components/ui';

interface OrderStatusSelectProps {
	selectedStatus: string;
	mutate: (data: Record<string, string>) => void;
}

const OrderStatusSelect = ({ selectedStatus, mutate }: OrderStatusSelectProps) => {
	const order_statuses = window?.wcpos?.settings?.order_statuses;

	const options = React.useMemo(() => {
		return Object.entries(order_statuses).map(([value, label]) => ({ value, label }));
	}, [order_statuses]);

	return (
		<Select
			options={options ? options : []}
			value={selectedStatus}
			onChange={({ value }) => {
				mutate({ order_status: String(value) });
			}}
		/>
	);
};

export default OrderStatusSelect;
