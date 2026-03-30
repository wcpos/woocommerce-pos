import { useState, useCallback, useRef } from 'react';
import apiFetch from '@wordpress/api-fetch';

export interface OrderSummary {
	id: number;
	number: string;
	date: string;
	customer_name: string;
	total: string;
}

interface PreviewDataState {
	source: 'sample' | 'order';
	data: Record<string, unknown>;
	orders: OrderSummary[];
	ordersLoading: boolean;
	dataLoading: boolean;
	error: string | null;
}

export function usePreviewData(
	sampleData: Record<string, unknown>,
	templateId: number,
) {
	const [state, setState] = useState<PreviewDataState>({
		source: 'sample',
		data: sampleData,
		orders: [],
		ordersLoading: false,
		dataLoading: false,
		error: null,
	});

	const ordersFetched = useRef(false);
	const previewAbortRef = useRef<AbortController | null>(null);

	const fetchOrders = useCallback(async () => {
		if (ordersFetched.current) return;
		ordersFetched.current = true;

		setState((prev) => ({ ...prev, ordersLoading: true, error: null }));

		try {
			const orders = await apiFetch<OrderSummary[]>({
				path: 'wcpos/v1/templates/preview-orders?wcpos=1',
			});
			setState((prev) => ({ ...prev, orders, ordersLoading: false }));
		} catch {
			ordersFetched.current = false; // Allow retry on failure.
			setState((prev) => ({
				...prev,
				ordersLoading: false,
				error: 'Failed to load orders',
			}));
		}
	}, []);

	const selectSource = useCallback(
		(source: 'sample' | 'order', orderId?: number) => {
			// Abort any in-flight preview request.
			if (previewAbortRef.current) {
				previewAbortRef.current.abort();
				previewAbortRef.current = null;
			}

			if (source === 'sample') {
				setState((prev) => ({
					...prev,
					source: 'sample',
					data: sampleData,
					error: null,
					dataLoading: false,
				}));
				return;
			}

			if (!orderId) return;

			const controller = new AbortController();
			previewAbortRef.current = controller;

			setState((prev) => ({ ...prev, source: 'order', dataLoading: true, error: null }));

			apiFetch<{ receipt_data?: Record<string, unknown> }>({
				path: `wcpos/v1/templates/${templateId}/preview?order_id=${orderId}&wcpos=1`,
				signal: controller.signal,
			})
				.then((response) => {
					if (controller.signal.aborted) return;
					const data = response.receipt_data ?? response;
					setState((prev) => ({
						...prev,
						data: data as Record<string, unknown>,
						dataLoading: false,
					}));
				})
				.catch((err: Error) => {
					if (err.name === 'AbortError') return;
					setState((prev) => ({
						...prev,
						data: sampleData,
						source: 'sample',
						dataLoading: false,
						error: 'Failed to load order data',
					}));
				});
		},
		[sampleData, templateId],
	);

	return {
		...state,
		fetchOrders,
		selectSource,
	};
}
