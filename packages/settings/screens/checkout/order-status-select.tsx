import * as React from 'react';

import { useQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';
import { map } from 'lodash';

import Select from '../../components/select';

const OrderStatusSelect = ({ selectedStatus, mutate }) => {
	const { data: options } = useQuery({
		queryKey: ['order-statuses'],
		queryFn: async () => {
			const response = await apiFetch({
				path: `wcpos/v1/settings/checkout/order-statuses?wcpos=1`,
				method: 'GET',
			}).catch((err) => {
				throw new Error(err.message);
			});

			return map(response, (label, value) => ({
				label,
				value,
			}));
		},
	});

	return (
		<Select
			name="order-status"
			options={options}
			selected={selectedStatus}
			onChange={(order_status: string) => {
				mutate({ order_status });
			}}
		/>
	);
};

export default OrderStatusSelect;
