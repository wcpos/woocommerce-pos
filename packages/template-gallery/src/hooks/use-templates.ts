import { useSuspenseQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

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

	return useMutation({
		mutationFn: async ({ id, status }: { id: number; status: 'publish' | 'draft' }) =>
			apiFetch<Template>({
				path: `wcpos/v1/templates/${id}?wcpos=1`,
				method: 'PATCH',
				data: { status },
			}),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['templates'] });
		},
	});
}

export function useCopyTemplate() {
	const queryClient = useQueryClient();

	return useMutation({
		mutationFn: async (id: number) =>
			apiFetch<Template>({
				path: `wcpos/v1/templates/${id}/copy?wcpos=1`,
				method: 'POST',
			}),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['templates'] });
		},
	});
}

export function useReorderTemplates() {
	const queryClient = useQueryClient();

	return useMutation({
		mutationFn: async (updates: Array<{ id: number; menu_order: number }>) =>
			apiFetch({
				path: 'wcpos/v1/templates/batch?wcpos=1',
				method: 'POST',
				data: { update: updates },
			}),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['templates'] });
		},
	});
}
