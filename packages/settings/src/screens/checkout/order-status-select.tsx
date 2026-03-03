import * as React from 'react';

import { Select } from '../../components/ui';

interface OrderStatusSelectProps {
	selectedStatus: string;
	onChange: (value: string) => void;
	disabled?: boolean;
}

function OrderStatusSelect({ selectedStatus, onChange, disabled }: OrderStatusSelectProps) {
	const order_statuses = window?.wcpos?.settings?.order_statuses ?? {};

	const options = React.useMemo(() => {
		return Object.entries(order_statuses).map(([value, label]) => ({ value, label }));
	}, [order_statuses]);

	return (
		<Select
			options={options ? options : []}
			value={selectedStatus}
			onChange={({ value }) => {
				onChange(String(value));
			}}
			disabled={disabled}
		/>
	);
}

export default OrderStatusSelect;
