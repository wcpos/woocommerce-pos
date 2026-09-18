import { useState, useCallback, useEffect, useRef } from 'react';

import apiFetch from '@wordpress/api-fetch';

import type { EditorConfig } from '../types';

interface PreviewDataState {
	source: 'sample' | 'order';
	data: Record<string, unknown>;
	loading: boolean;
}

export function usePreviewData(
	sampleData: Record<string, unknown>,
	templateId: number,
	hasPosOrders: boolean,
	type: EditorConfig['type'] = 'receipt'
) {
	// A report document is never an order's; the route serves the fixture whatever order is
	// named, so the order source is not offered for it.
	const supportsOrderSource = type !== 'report' && hasPosOrders;
	const defaultSource = supportsOrderSource ? 'order' : 'sample';
	const [state, setState] = useState<PreviewDataState>({
		source: defaultSource,
		data: sampleData,
		loading: supportsOrderSource,
	});

	const abortRef = useRef<AbortController | null>(null);

	const selectSource = useCallback(
		(source: 'sample' | 'order') => {
			if (abortRef.current) {
				abortRef.current.abort();
				abortRef.current = null;
			}

			if (source === 'sample' || !supportsOrderSource) {
				setState({ source: 'sample', data: sampleData, loading: false });
				return;
			}

			const controller = new AbortController();
			abortRef.current = controller;

			setState((prev) => ({ ...prev, source: 'order', loading: true }));

			apiFetch<{ receipt_data?: Record<string, unknown> }>({
				path: `wcpos/v1/templates/${templateId}/preview?order_id=latest&wcpos=1&type=${type}`,
				signal: controller.signal,
			})
				.then((response) => {
					if (controller.signal.aborted) return;
					const data = (response.receipt_data ?? response) as Record<string, unknown>;
					setState({ source: 'order', data, loading: false });
				})
				.catch((err: Error) => {
					if (err.name === 'AbortError') return;
					// Revert to sample on failure.
					setState({ source: 'sample', data: sampleData, loading: false });
				});
		},
		[sampleData, templateId, type, supportsOrderSource]
	);

	// Auto-fetch order data on mount when POS orders exist.
	const mountedRef = useRef(false);
	useEffect(() => {
		if (!mountedRef.current && supportsOrderSource) {
			mountedRef.current = true;
			selectSource('order');
		}
	}, [supportsOrderSource, selectSource]);

	return {
		source: state.source,
		data: state.data,
		loading: state.loading,
		selectSource,
	};
}
