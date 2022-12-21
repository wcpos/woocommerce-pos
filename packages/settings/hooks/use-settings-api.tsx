import { useQueryClient, useQuery, useMutation } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import useSnackbar from '../components/snackbar';

const useSettingsApi = (id: string) => {
	const queryClient = useQueryClient();
	const { addSnackbar } = useSnackbar();
	const endpoint = `wcpos/v1/settings/${id}?wcpos=1`;

	const { data } = useQuery({
		queryKey: [id],
		queryFn: async () => {
			const response = await apiFetch({
				path: endpoint,
				method: 'GET',
			}).catch((err) => {
				throw new Error(err.message);
			});

			return response;
		},
	});

	const mutation = useMutation({
		mutationFn: async (data) => {
			const response = await apiFetch({
				path: endpoint,
				method: 'POST',
				data,
			}).catch((err) => {
				throw new Error(err.message);
			});

			return response;
		},
		onMutate: async (newData) => {
			addSnackbar({ message: 'Saving' });
			await queryClient.cancelQueries({ queryKey: [id] });
			const previousSettings = queryClient.getQueryData([id]);
			queryClient.setQueryData(['checkout'], (oldData) => ({ ...oldData, ...newData }));
			return { previousSettings };
		},
		onSettled: (data, error, variables, context) => {
			if (error) {
				addSnackbar({ message: error.message });
				// rollback data
				return queryClient.setQueryData([id], context.previousSettings);
			}
			addSnackbar({ message: 'Saved' });
			return queryClient.setQueryData([id], data);
		},
	});

	return { data, mutate: mutation.mutate };
};

export default useSettingsApi;
