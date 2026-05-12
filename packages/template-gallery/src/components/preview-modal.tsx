import * as React from 'react';

import { buildPreviewFrameHtml, renderLogiclessPreview, renderThermalPreview } from '@wcpos/thermal-utils';
import { Button } from '@wcpos/ui';

import { usePreview } from '../hooks/use-preview';
import { t } from '../translations';
import type { PreviewResponse } from '../types';
import { PreviewToggle } from './preview-toggle';

interface PreviewModalProps {
	templateId: number | string;
	templateName: string;
	templateDescription?: string;
	isGallery: boolean;
	onClose: () => void;
	onActivate?: () => void;
	onCustomize?: () => void;
}

function buildRenderedPreviewFrame(preview: PreviewResponse): string {
	if (preview.engine === 'thermal' && preview.template_content && preview.receipt_data) {
		return buildPreviewFrameHtml({
			bodyHtml: renderThermalPreview(preview.template_content, preview.receipt_data),
			paperWidth: preview.paper_width,
		});
	}

	if (preview.engine === 'logicless' && preview.template_content && preview.receipt_data) {
		return buildPreviewFrameHtml({
			bodyHtml: renderLogiclessPreview(preview.template_content, {
				t: true,
				...preview.receipt_data,
			}),
			paperWidth: 'a4',
		});
	}

	return '';
}

function PreviewFrameContent({ preview, templateName }: { preview: PreviewResponse; templateName: string }) {
	const srcdoc = React.useMemo(() => {
		try {
			return buildPreviewModalSrcDoc(preview);
		} catch {
			return buildPreviewFrameHtml({
				bodyHtml: `<div style="color:red;padding:16px;">${t('modal.render_error')}</div>`,
				paperWidth: preview.paper_width ?? 'a4',
			});
		}
	}, [preview]);

	if (!srcdoc) {
		return null;
	}

	return (
		<iframe
			srcDoc={srcdoc}
			title={t('modal.preview_title', { templateName })}
			className="wcpos:w-full wcpos:flex-1 wcpos:border wcpos:border-gray-200 wcpos:rounded wcpos:bg-white"
			sandbox="allow-same-origin"
		/>
	);
}


export function buildPreviewModalSrcDoc(preview: PreviewResponse): string {
	const renderedFrame = buildRenderedPreviewFrame(preview);
	if (renderedFrame) {
		return renderedFrame;
	}

	if (preview.preview_html) {
		return isFullHtmlDocument(preview.preview_html)
			? preview.preview_html
			: buildPreviewFrameHtml({ bodyHtml: preview.preview_html, paperWidth: preview.paper_width ?? 'a4' });
	}

	return '';
}

function isFullHtmlDocument(html: string): boolean {
	const h = html.trimStart().toLowerCase();
	return h.startsWith('<!doctype') || h.startsWith('<html');
}

export function PreviewModal({
	templateId,
	templateName,
	templateDescription,
	isGallery,
	onClose,
	onActivate,
	onCustomize,
}: PreviewModalProps) {
	const hasPosOrders = Boolean((window as any).wcpos?.templateGallery?.hasPosOrders);
	const [source, setSource] = React.useState<'sample' | 'order'>(hasPosOrders ? 'order' : 'sample');
	const orderId = source === 'order' ? 'latest' : undefined;
	const { data: preview, isLoading, isFetching, isError } = usePreview(templateId, orderId);
	const dialogRef = React.useRef<HTMLDivElement>(null);
	const closeButtonRef = React.useRef<HTMLButtonElement>(null);
	const previousFocusedElementRef = React.useRef<HTMLElement | null>(null);
	const titleId = React.useId();
	const canRenderFrame = Boolean(
		preview &&
		(preview.engine === 'thermal' || preview.engine === 'logicless') &&
		((preview.template_content && preview.receipt_data) || preview.preview_html)
	);

	// Revert to sample if order fetch fails
	React.useEffect(() => {
		if (isError && source === 'order') {
			setSource('sample');
		}
	}, [isError, source]);

	React.useEffect(() => {
		previousFocusedElementRef.current = document.activeElement instanceof HTMLElement
			? document.activeElement
			: null;

		const previousOverflow = document.body.style.overflow;
		document.body.style.overflow = 'hidden';
		closeButtonRef.current?.focus();

		const handler = (e: KeyboardEvent) => {
			if (e.key === 'Escape') {
				onClose();
				return;
			}

			if (e.key !== 'Tab') return;
			const dialog = dialogRef.current;
			if (!dialog) return;

			const focusableElements = dialog.querySelectorAll<HTMLElement>(
				'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])',
			);
			if (focusableElements.length === 0) {
				e.preventDefault();
				return;
			}

			const first = focusableElements[0];
			const last = focusableElements[focusableElements.length - 1];
			const active = document.activeElement as HTMLElement | null;

			if (e.shiftKey) {
				if (active === first || !dialog.contains(active)) {
					e.preventDefault();
					last.focus();
				}
				return;
			}

			if (active === last) {
				e.preventDefault();
				first.focus();
			}
		};

		document.addEventListener('keydown', handler);

		return () => {
			document.removeEventListener('keydown', handler);
			document.body.style.overflow = previousOverflow;
			previousFocusedElementRef.current?.focus();
		};
	}, [onClose]);

	return (
		<div
			className="wcpos:fixed wcpos:inset-0 wcpos:z-50 wcpos:flex wcpos:items-center wcpos:justify-center wcpos:bg-black/50"
			onClick={onClose}
			role="dialog"
			aria-modal="true"
			aria-labelledby={titleId}
		>
			<div
				ref={dialogRef}
				tabIndex={-1}
				className="wcpos:bg-white wcpos:rounded-lg wcpos:shadow-xl wcpos:max-w-3xl wcpos:w-full wcpos:h-[90vh] wcpos:flex wcpos:flex-col wcpos:m-4"
				onClick={(e) => e.stopPropagation()}
			>
				{/* Header */}
				<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:p-4 wcpos:border-b wcpos:border-gray-200">
					<div className="wcpos:min-w-0">
						<h2 id={titleId} className="wcpos:text-lg wcpos:font-semibold wcpos:m-0">
							{templateName}
						</h2>
						{templateDescription && (
							<p className="wcpos:text-sm wcpos:text-gray-500 wcpos:m-0 wcpos:mt-1">
								{templateDescription}
							</p>
						)}
					</div>
					<div className="wcpos:flex wcpos:items-center wcpos:gap-2 wcpos:shrink-0">
						<PreviewToggle
							source={source}
							disabled={!hasPosOrders}
							onToggle={setSource}
						/>
						<button
							ref={closeButtonRef}
							type="button"
							onClick={onClose}
							className="wcpos:text-gray-400 hover:wcpos:text-gray-600 wcpos:text-2xl wcpos:leading-none wcpos:bg-transparent wcpos:border-0 wcpos:cursor-pointer wcpos:p-1"
							aria-label={t('modal.close')}
						>
							&times;
						</button>
					</div>
				</div>

				{/* Preview iframe */}
				<div className="wcpos:flex-1 wcpos:min-h-0 wcpos:flex wcpos:flex-col wcpos:p-4 wcpos:bg-gray-50">
					{isFetching ? (
						<div className="wcpos:flex wcpos:flex-1 wcpos:items-center wcpos:justify-center">
							<span className="wcpos:text-gray-400">{t('modal.loading')}</span>
						</div>
					) : preview && canRenderFrame ? (
						<PreviewFrameContent preview={preview} templateName={templateName} />
					) : preview?.preview_html ? (
						<iframe
							srcDoc={buildPreviewModalSrcDoc(preview)}
							title={t('modal.preview_title', { templateName })}
							className="wcpos:w-full wcpos:flex-1 wcpos:border wcpos:border-gray-200 wcpos:rounded wcpos:bg-white"
							sandbox="allow-same-origin"
						/>
					) : preview?.preview_url ? (
						<iframe
							src={preview.preview_url}
							title={t('modal.preview_title', { templateName })}
							className="wcpos:w-full wcpos:flex-1 wcpos:border wcpos:border-gray-200 wcpos:rounded wcpos:bg-white"
							sandbox="allow-scripts"
						/>
					) : (
						<div className="wcpos:text-gray-500 wcpos:text-center wcpos:py-8">
							{t('modal.no_preview')}
						</div>
					)}
				</div>

				{/* Footer actions */}
				<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:p-4 wcpos:border-t wcpos:border-gray-200">
					<div className="wcpos:text-xs wcpos:text-gray-500">
						{preview?.order_id ? <>{t('modal.preview_order', { orderId: preview.order_id })}</> : null}
					</div>
					<div className="wcpos:flex wcpos:gap-2">
						{isGallery ? (
							<Button
								variant="primary"
								onClick={() => {
									onCustomize?.();
									onClose();
								}}
							>
								{t('common.use_template')}
							</Button>
						) : (
							<Button
								variant="primary"
								onClick={() => {
									onActivate?.();
									onClose();
								}}
							>
								{t('common.activate')}
							</Button>
						)}
						{preview?.preview_url && (
							<a
								href={preview.preview_url}
								target="_blank"
								rel="noopener noreferrer"
								className="wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:font-medium wcpos:text-gray-700 wcpos:bg-white wcpos:border wcpos:border-gray-300 wcpos:rounded wcpos:no-underline hover:wcpos:bg-gray-50"
							>
								{t('modal.open_new_tab')}
							</a>
						)}
					</div>
				</div>
			</div>
		</div>
	);
}
