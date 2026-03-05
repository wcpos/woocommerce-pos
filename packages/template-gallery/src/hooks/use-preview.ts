import { useQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import type { PreviewResponse } from '../types';

export function usePreview(templateId: number | string | null, orderId?: number) {
	return useQuery({
		queryKey: ['preview', templateId, orderId],
		queryFn: () => {
			const params = new URLSearchParams({ wcpos: '1' });
			if (orderId) params.set('order_id', String(orderId));
			return apiFetch<PreviewResponse>({
				path: `wcpos/v1/templates/${templateId}/preview?${params}`,
				method: 'GET',
			});
		},
		enabled: !!templateId,
	});
}
