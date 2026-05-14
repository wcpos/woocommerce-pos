import { useState, useCallback, useEffect, useRef } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { buildPreviewFrameHtml } from '@wcpos/thermal-utils';
import { PreviewViewport } from '@wcpos/ui';
import type { CSSProperties } from 'react';

import { t } from '../translations';

interface PhpPreviewProps {
	previewUrl: string;
}

export interface PhpPreviewResponse {
	preview_url?: string;
	preview_html?: string;
	requires_order?: boolean;
}

interface PreviewState {
	src: string | null;
	srcDoc: string | null;
	loading: boolean;
	requiresOrder: boolean;
}

export function getPhpPreviewRequestUrl(previewUrl: string): string {
	if (!previewUrl) return previewUrl;

	let url = previewUrl;
	// Legacy-php previews must run against a real WC_Order. Request the latest
	// POS order; the REST endpoint replies with requires_order when none exists.
	if (!url.includes('order_id=')) {
		url += `${url.includes('?') ? '&' : '?'}order_id=latest`;
	}
	if (!url.includes('wcpos=')) {
		url += `${url.includes('?') ? '&' : '?'}wcpos=1`;
	}
	return url;
}

function isFullHtmlDocument(html: string): boolean {
	const h = html.trimStart().toLowerCase();
	return h.startsWith('<!doctype') || h.startsWith('<html');
}

export function getPhpPreviewIframeStyle(): CSSProperties {
	return {
		display: 'block',
		width: '100%',
		height: '100%',
		border: 0,
		background: '#fff',
	};
}

export function getPhpPreviewFrame(response: PhpPreviewResponse): Pick<PreviewState, 'src' | 'srcDoc'> {
	if (response.preview_html) {
		return {
			src: null,
			srcDoc: isFullHtmlDocument(response.preview_html)
				? response.preview_html
				: buildPreviewFrameHtml({ bodyHtml: response.preview_html, paperWidth: 'a4' }),
		};
	}

	return {
		src: response.preview_url ?? null,
		srcDoc: null,
	};
}

export function getPhpPreviewBodyClassName(): string {
	return 'wcpos:flex wcpos:flex-1 wcpos:min-h-0 wcpos:flex-col wcpos:p-0 wcpos:bg-gray-50';
}

export function PhpPreview({ previewUrl }: PhpPreviewProps) {
	const [iframeKey, setIframeKey] = useState(0);
	const requestIdRef = useRef(0);
	const [previewState, setPreviewState] = useState<PreviewState>({
		src: null,
		srcDoc: null,
		loading: Boolean(previewUrl),
		requiresOrder: false,
	});

	const loadPreview = useCallback(() => {
		if (!previewUrl) return;
		const requestId = requestIdRef.current + 1;
		requestIdRef.current = requestId;

		setPreviewState((prev) => ({ ...prev, loading: true }));

		apiFetch<PhpPreviewResponse>({
			url: getPhpPreviewRequestUrl(previewUrl),
			method: 'GET',
		})
			.then((response) => {
				if (requestId !== requestIdRef.current) return;

				if (response.requires_order) {
					setPreviewState({
						src: null,
						srcDoc: null,
						loading: false,
						requiresOrder: true,
					});
					setIframeKey((k) => k + 1);
					return;
				}

				setPreviewState({
					...getPhpPreviewFrame(response),
					loading: false,
					requiresOrder: false,
				});
				setIframeKey((k) => k + 1);
			})
			.catch(() => {
				if (requestId !== requestIdRef.current) return;

				setPreviewState({
					src: null,
					srcDoc: `<div style="padding:40px;text-align:center;font-family:sans-serif;color:#c00;">${t('editor.preview_failed')}</div>`,
					loading: false,
					requiresOrder: false,
				});
				setIframeKey((k) => k + 1);
			});
	}, [previewUrl]);

	useEffect(() => {
		loadPreview();
	}, [loadPreview]);

	if (!previewUrl) {
		return (
			<div className="wcpos:border wcpos:border-gray-200 wcpos:bg-white wcpos:flex wcpos:items-center wcpos:justify-center wcpos:p-6 wcpos:text-sm wcpos:text-gray-500 wcpos:rounded-lg">
				{t('editor.no_orders')}
			</div>
		);
	}

	return (
		<div className="wcpos:border wcpos:border-gray-200 wcpos:bg-white wcpos:flex wcpos:flex-col wcpos:rounded-lg wcpos:overflow-hidden">
			<div className="wcpos:flex wcpos:items-center wcpos:px-3 wcpos:py-2 wcpos:border-b wcpos:border-gray-200 wcpos:bg-gray-50">
				<span className="wcpos:text-xs wcpos:font-semibold wcpos:text-gray-500 wcpos:uppercase">
					{t('editor.preview')}
				</span>
			</div>
			<div className="wcpos:p-2 wcpos:text-xs wcpos:text-amber-700 wcpos:bg-amber-50 wcpos:border-b wcpos:border-amber-200">
				{t('editor.php_save_notice')}
			</div>
			<div className={getPhpPreviewBodyClassName()}>
				{previewState.loading ? (
					<div className="wcpos:text-sm wcpos:text-gray-500">{t('editor.loading_data')}</div>
				) : previewState.requiresOrder ? (
					<div className="wcpos:flex wcpos:flex-1 wcpos:items-center wcpos:justify-center wcpos:p-6 wcpos:text-sm wcpos:text-gray-500 wcpos:text-center">
						{t('editor.no_orders')}
					</div>
				) : (
					<PreviewViewport
						paperWidth="a4"
						zoomInLabel={t('editor.zoom_in')}
						zoomOutLabel={t('editor.zoom_out')}
					>
						<iframe
							key={iframeKey}
							src={previewState.src ?? undefined}
							srcDoc={previewState.srcDoc ?? undefined}
							style={getPhpPreviewIframeStyle()}
							title={t('editor.template_preview')}
						/>
					</PreviewViewport>
				)}
			</div>
		</div>
	);
}
