import * as React from 'react';

import { useQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';
import { map } from 'lodash';

import Select from '../../components/select';
import useNotices from '../../hooks/use-notices';

interface OrderStatusSelectProps {
	selectedStatus: string;
	mutate: (data: Record<string, string>) => void;
}

const OrderStatusSelect = ({ selectedStatus, mutate }: OrderStatusSelectProps) => {
	const { setNotice } = useNotices();

	const { data: options } = useQuery({
		queryKey: ['order-statuses'],
		queryFn: async () => {
			const response = await apiFetch<Record<string, string>>({
				path: `wcpos/v1/settings/checkout/order-statuses?wcpos=1`,
				method: 'GET',
			}).catch((err) => {
				console.error(err);
				return err;
			});

			// if we have an error response, set the notice
			if (response?.code && response?.message) {
				setNotice({ type: 'error', message: response?.message });
			}

			return map(response, (label, value) => ({
				label,
				value,
			}));
		},
		placeholderData: [],
	});

	return (
		<Select
			name="order-status"
			options={options ? options : []}
			selected={selectedStatus}
			onChange={(order_status: string) => {
				mutate({ order_status });
			}}
		/>
	);
};

export default OrderStatusSelect;
