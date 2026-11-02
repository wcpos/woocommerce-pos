import { useSuspenseQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import { useSnackbar } from '@wcpos/ui';

import { t } from '../translations';

import type { GalleryTemplate, Template } from '../types';

export function useGalleryTemplates(type = 'receipt') {
	return useSuspenseQuery({
		queryKey: ['gallery-templates', type],
		queryFn: () =>
			apiFetch<GalleryTemplate[]>({
				path: `wcpos/v1/templates/gallery?wcpos=1&type=${type}`,
				method: 'GET',
			}),
	});
}

export function useInstallGalleryTemplate(type = 'receipt') {
	const queryClient = useQueryClient();
	const { addSnackbar } = useSnackbar();

	return useMutation({
		mutationFn: async (galleryKey: string) =>
			apiFetch<Template>({
				path: 'wcpos/v1/templates/install?wcpos=1',
				method: 'POST',
				data: { gallery_key: galleryKey },
			}),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['templates'] });
			queryClient.invalidateQueries({ queryKey: ['gallery-templates'] });
			addSnackbar({
				message: t(
					type === 'display' ? 'snackbar.display_installed' : 'snackbar.gallery_installed'
				),
				status: 'success',
			});
		},
		onError: () => {
			addSnackbar({ message: t('snackbar.install_failed'), status: 'error' });
		},
	});
}
