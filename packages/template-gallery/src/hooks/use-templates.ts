import { useSuspenseQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import { useSnackbar } from '../components/snackbar';

import type { AnyTemplate, Template } from '../types';

export function useTemplates(type = 'receipt') {
	return useSuspenseQuery({
		queryKey: ['templates', type],
		queryFn: () =>
			apiFetch<AnyTemplate[]>({
				path: `wcpos/v1/templates?wcpos=1&type=${type}`,
				method: 'GET',
			}),
	});
}

export function useToggleTemplate() {
	const queryClient = useQueryClient();
	const { addSnackbar } = useSnackbar();

	return useMutation({
		mutationFn: async ({ id, status }: { id: number; status: 'publish' | 'draft' }) =>
			apiFetch<Template>({
				path: `wcpos/v1/templates/${id}?wcpos=1`,
				method: 'PATCH',
				data: { status },
			}),
		onSuccess: (_data, { status }) => {
			queryClient.invalidateQueries({ queryKey: ['templates'] });
			addSnackbar({
				message: status === 'publish' ? 'Template activated' : 'Template deactivated',
				status: 'success',
			});
		},
		onError: () => {
			addSnackbar({ message: 'Failed to update template', status: 'error' });
		},
	});
}

export function useCopyTemplate() {
	const queryClient = useQueryClient();
	const { addSnackbar } = useSnackbar();

	return useMutation({
		mutationFn: async (id: number) =>
			apiFetch<Template>({
				path: `wcpos/v1/templates/${id}/copy?wcpos=1`,
				method: 'POST',
			}),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['templates'] });
			addSnackbar({ message: 'Template copied', status: 'success' });
		},
		onError: () => {
			addSnackbar({ message: 'Failed to copy template', status: 'error' });
		},
	});
}

export function useReorderTemplates() {
	const queryClient = useQueryClient();
	const { addSnackbar } = useSnackbar();

	return useMutation({
		mutationFn: async (updates: Array<{ id: number; menu_order: number }>) =>
			apiFetch({
				path: 'wcpos/v1/templates/batch?wcpos=1',
				method: 'POST',
				data: { update: updates },
			}),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['templates'] });
			addSnackbar({ message: 'Order saved', status: 'success' });
		},
		onError: () => {
			addSnackbar({ message: 'Failed to save order', status: 'error' });
		},
	});
}
