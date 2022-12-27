import { useQueryClient, useQuery, useMutation } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';
import { merge, cloneDeep } from 'lodash';

import useSnackbar from '../components/snackbar';
import useNotices from '../hooks/use-notices';

const placeholders = {
	general: {},
	checkout: {},
	'payment-gateways': {},
	access: {},
	license: {},
};

type PlaceholderKeys = keyof typeof placeholders;

const useSettingsApi = (id: PlaceholderKeys) => {
	const queryClient = useQueryClient();
	const { addSnackbar } = useSnackbar();
	const { setNotice } = useNotices();
	const endpoint = `wcpos/v1/settings/${id}?wcpos=1`;

	const { data } = useQuery({
		queryKey: [id],
		queryFn: async () => {
			const response = await apiFetch<Record<string, unknown>>({
				path: endpoint,
				method: 'GET',
			}).catch((err) => {
				throw new Error(err.message);
			});

			return response;
		},
		placeholderData: placeholders[id],
	});

	const mutation = useMutation({
		mutationFn: async (data: Record<string, unknown>) => {
			const response = await apiFetch<Record<string, unknown>>({
				path: endpoint,
				method: 'POST',
				data,
			}).catch((err) => {
				throw new Error(err.message);
			});

			return response;
		},
		onMutate: async (newData) => {
			setNotice(null);
			addSnackbar({ message: 'Saving', id });
			await queryClient.cancelQueries({ queryKey: [id] });
			const previousSettings = queryClient.getQueryData([id]);
			queryClient.setQueryData([id], (oldData) => {
				return merge(cloneDeep(oldData), newData);
			});
			return { previousSettings };
		},
		onSettled: (data, error, variables, context) => {
			if (error) {
				setNotice({ type: 'error', message: error.message });
				// rollback data
				return queryClient.setQueryData([id], context.previousSettings);
			}
			addSnackbar({ message: 'Saved', id });
			return queryClient.setQueryData([id], data);
		},
	});

	return { data, mutate: mutation.mutate };
};

export default useSettingsApi;
