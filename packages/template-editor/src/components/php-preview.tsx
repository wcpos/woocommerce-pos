import { useState, useCallback, useEffect, useRef } from 'react';
import apiFetch from '@wordpress/api-fetch';

import { t } from '../translations';

declare const jQuery: any;

interface PhpPreviewProps {
	previewUrl: string;
}

export interface PhpPreviewResponse {
	preview_url?: string;
	preview_html?: string;
}

interface PreviewState {
	src: string | null;
	srcDoc: string | null;
	loading: boolean;
}

export function getPhpPreviewRequestUrl(previewUrl: string): string {
	if (!previewUrl) return previewUrl;
	const separator = previewUrl.includes('?') ? '&' : '?';
	return previewUrl.includes('wcpos=') ? previewUrl : `${previewUrl}${separator}wcpos=1`;
}

export function decodeLabel(label: string): string {
	return label.replace(/&amp;/g, '&');
}

export function getPhpPreviewFrame(response: PhpPreviewResponse): Pick<PreviewState, 'src' | 'srcDoc'> {
	return {
		src: response.preview_url ?? null,
		srcDoc: response.preview_html ?? null,
	};
}

export function PhpPreview({ previewUrl }: PhpPreviewProps) {
	const [iframeKey, setIframeKey] = useState(0);
	const requestIdRef = useRef(0);
	const [previewState, setPreviewState] = useState<PreviewState>({
		src: null,
		srcDoc: null,
		loading: Boolean(previewUrl),
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

				setPreviewState({
					...getPhpPreviewFrame(response),
					loading: false,
				});
				setIframeKey((k) => k + 1);
			})
			.catch(() => {
				if (requestId !== requestIdRef.current) return;

				setPreviewState({
					src: null,
					srcDoc: `<div style="padding:40px;text-align:center;font-family:sans-serif;color:#c00;">${t('editor.preview_failed')}</div>`,
					loading: false,
				});
				setIframeKey((k) => k + 1);
			});
	}, [previewUrl]);

	const handleSaveAndPreview = useCallback(() => {
		const wp = (window as any).wp;
		if (wp?.autosave?.server) {
			wp.autosave.server.triggerSave();
		} else {
			loadPreview();
		}
	}, [loadPreview]);

	useEffect(() => {
		loadPreview();
	}, [loadPreview]);

	useEffect(() => {
		if (typeof jQuery === 'undefined') return;

		const onAutosaveComplete = (_event: unknown, data?: { success?: boolean }) => {
			if (data && data.success === false) return;
			loadPreview();
		};

		jQuery(document).on('after-autosave', onAutosaveComplete);
		return () => {
			jQuery(document).off('after-autosave', onAutosaveComplete);
		};
	}, [loadPreview]);

	if (!previewUrl) {
		return (
			<div className="wcpos:border wcpos:border-gray-300 wcpos:bg-gray-50 wcpos:flex wcpos:items-center wcpos:justify-center wcpos:p-6 wcpos:text-sm wcpos:text-gray-500">
				{t('editor.no_orders')}
			</div>
		);
	}

	return (
		<div className="wcpos:border wcpos:border-gray-300 wcpos:bg-gray-50 wcpos:flex wcpos:flex-col">
			<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:px-3 wcpos:py-2 wcpos:border-b wcpos:border-gray-200 wcpos:bg-white">
				<span className="wcpos:text-xs wcpos:font-semibold wcpos:text-gray-500 wcpos:uppercase">
					{t('editor.preview')}
				</span>
				<div className="wcpos:flex wcpos:gap-2">
					<button
						type="button"
						onClick={handleSaveAndPreview}
						className="wcpos:text-xs wcpos:px-2 wcpos:py-1 wcpos:bg-blue-600 wcpos:text-white wcpos:rounded hover:wcpos:bg-blue-700"
					>
						{decodeLabel(t('editor.save_and_preview'))}
					</button>
					{previewState.src && (
						<a
							href={previewState.src}
							target="_blank"
							rel="noopener noreferrer"
							className="wcpos:text-xs wcpos:text-blue-600 hover:wcpos:underline wcpos:self-center"
						>
							{t('editor.open_in_tab')}
						</a>
					)}
				</div>
			</div>
			<div className="wcpos:p-2 wcpos:text-xs wcpos:text-amber-700 wcpos:bg-amber-50 wcpos:border-b wcpos:border-amber-200">
				{t('editor.php_save_notice')}
			</div>
			<div className="wcpos:flex-1 wcpos:overflow-auto wcpos:flex wcpos:justify-center wcpos:p-4">
				{previewState.loading ? (
					<div className="wcpos:text-sm wcpos:text-gray-500">{t('editor.loading_data')}</div>
				) : (
					<iframe
						key={iframeKey}
						src={previewState.src ?? undefined}
						srcDoc={previewState.srcDoc ?? undefined}
						style={{ width: '100%', maxWidth: 400, border: '1px solid #ddd', background: '#fff', minHeight: 400 }}
						title={t('editor.template_preview')}
					/>
				)}
			</div>
		</div>
	);
}
