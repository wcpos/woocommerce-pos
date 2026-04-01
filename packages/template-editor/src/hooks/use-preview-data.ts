import { useState, useCallback, useRef } from 'react';
import apiFetch from '@wordpress/api-fetch';

interface PreviewDataState {
	source: 'sample' | 'order';
	data: Record<string, unknown>;
	loading: boolean;
}

export function usePreviewData(
	sampleData: Record<string, unknown>,
	templateId: number,
) {
	const [state, setState] = useState<PreviewDataState>({
		source: 'sample',
		data: sampleData,
		loading: false,
	});

	const abortRef = useRef<AbortController | null>(null);
	const orderCacheRef = useRef<Record<string, unknown> | null>(null);

	const selectSource = useCallback(
		(source: 'sample' | 'order') => {
			if (abortRef.current) {
				abortRef.current.abort();
				abortRef.current = null;
			}

			if (source === 'sample') {
				setState({ source: 'sample', data: sampleData, loading: false });
				return;
			}

			// Return cached order data if available.
			if (orderCacheRef.current) {
				setState({ source: 'order', data: orderCacheRef.current, loading: false });
				return;
			}

			const controller = new AbortController();
			abortRef.current = controller;

			setState((prev) => ({ ...prev, source: 'order', loading: true }));

			apiFetch<{ receipt_data?: Record<string, unknown> }>({
				path: `wcpos/v1/templates/${templateId}/preview?order_id=latest&wcpos=1`,
				signal: controller.signal,
			})
				.then((response) => {
					if (controller.signal.aborted) return;
					const data = (response.receipt_data ?? response) as Record<string, unknown>;
					orderCacheRef.current = data;
					setState({ source: 'order', data, loading: false });
				})
				.catch((err: Error) => {
					if (err.name === 'AbortError') return;
					// Revert to sample on failure.
					setState({ source: 'sample', data: sampleData, loading: false });
				});
		},
		[sampleData, templateId],
	);

	return {
		source: state.source,
		data: state.data,
		loading: state.loading,
		selectSource,
	};
}
