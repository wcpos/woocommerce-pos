import { useSuspenseQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import { useSnackbar } from '@wcpos/ui';
import { t } from '../translations';

import type { AnyTemplate, Template } from '../types';

export function useTemplates(type = 'receipt') {
	return useSuspenseQuery({
		queryKey: ['templates', type],
		queryFn: () => {
			const params = new URLSearchParams({ wcpos: '1', type });
			return apiFetch<AnyTemplate[]>({
				path: `wcpos/v1/templates?${params.toString()}`,
				method: 'GET',
			});
		},
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
				message: status === 'publish' ? t('snackbar.template_activated') : t('snackbar.template_deactivated'),
				status: 'success',
			});
		},
		onError: () => {
			addSnackbar({ message: t('snackbar.update_failed'), status: 'error' });
		},
	});
}

export function useToggleVirtualTemplate(type = 'receipt') {
	const queryClient = useQueryClient();
	const { addSnackbar } = useSnackbar();

	return useMutation({
		mutationFn: async ({ id, disabled }: { id: string; disabled: boolean }) =>
			apiFetch({
				path: 'wcpos/v1/templates/batch?wcpos=1',
				method: 'POST',
				data: disabled
					? { type, disable_virtual: [id] }
					: { type, enable_virtual: [id] },
			}),
		onSuccess: (_data, { disabled }) => {
			queryClient.invalidateQueries({ queryKey: ['templates'] });
			addSnackbar({
				message: disabled ? t('snackbar.template_deactivated') : t('snackbar.template_activated'),
				status: 'success',
			});
		},
		onError: () => {
			addSnackbar({ message: t('snackbar.update_failed'), status: 'error' });
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
			addSnackbar({ message: t('snackbar.template_copied'), status: 'success' });
		},
		onError: () => {
			addSnackbar({ message: t('snackbar.copy_failed'), status: 'error' });
		},
	});
}

export function useReorderTemplates(type = 'receipt') {
	const queryClient = useQueryClient();
	const { addSnackbar } = useSnackbar();

	return useMutation({
		mutationFn: async (order: Array<number | string>) =>
			apiFetch({
				path: 'wcpos/v1/templates/batch?wcpos=1',
				method: 'POST',
				data: { type, order },
			}),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['templates'] });
			addSnackbar({ message: t('snackbar.order_saved'), status: 'success' });
		},
		onError: () => {
			addSnackbar({ message: t('snackbar.save_order_failed'), status: 'error' });
		},
	});
}

export function useDeleteTemplate() {
	const queryClient = useQueryClient();
	const { addSnackbar } = useSnackbar();

	return useMutation({
		mutationFn: async (id: number) =>
			apiFetch({
				path: `wcpos/v1/templates/${id}?wcpos=1`,
				method: 'DELETE',
			}),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['templates'] });
			addSnackbar({ message: t('snackbar.template_deleted'), status: 'success' });
		},
		onError: () => {
			addSnackbar({ message: t('snackbar.delete_failed'), status: 'error' });
		},
	});
}
