import { useQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import type { PreviewResponse } from '../types';

export function usePreview(templateId: number | string | null, orderId?: number) {
	const hasTemplateId = templateId !== undefined && templateId !== null;

	return useQuery({
		queryKey: ['preview', templateId, orderId],
		queryFn: () => {
			const params = new URLSearchParams({ wcpos: '1' });
			if (orderId !== undefined && orderId !== null) {
				params.set('order_id', String(orderId));
			}

			return apiFetch<PreviewResponse>({
				path: `wcpos/v1/templates/${encodeURIComponent(String(templateId))}/preview?${params.toString()}`,
				method: 'GET',
			});
		},
		enabled: hasTemplateId,
	});
}
