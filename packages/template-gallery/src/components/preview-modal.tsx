import * as React from 'react';

import { addQueryArgs } from '@wordpress/url';

import {
	buildPreviewFrameHtml,
	renderLogiclessPreview,
	renderThermalPreview,
} from '@wcpos/thermal-utils';
import { Button, PreviewViewport, type PreviewPaperWidth } from '@wcpos/ui';

import { usePreview } from '../hooks/use-preview';
import { getGalleryPreviewSrc } from '../preview-assets';
import { t } from '../translations';
import { PreviewToggle } from './preview-toggle';

import type { PreviewResponse } from '../types';

interface PreviewModalProps {
	templateType: 'receipt' | 'display';
	templateId: number | string;
	templateName: string;
	templateDescription?: string;
	isGallery: boolean;
	onClose: () => void;
	onActivate?: () => void;
	/** Label for the primary action on an installed template; defaults to Activate. */
	activateLabel?: string;
	/** Hide the primary action, e.g. a disabled display cannot be set Live. */
	canActivate?: boolean;
	onCustomize?: () => void;
}

function buildRenderedPreviewFrame(preview: PreviewResponse): string {
	if (preview.engine === 'thermal' && preview.template_content != null && preview.receipt_data) {
		return buildPreviewFrameHtml({
			bodyHtml: renderThermalPreview(preview.template_content, preview.receipt_data),
			paperWidth: preview.paper_width,
		});
	}

	if (preview.engine === 'logicless' && preview.template_content != null && preview.receipt_data) {
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

function getPreviewPaperWidth(preview: PreviewResponse): PreviewPaperWidth {
	if (preview.paper_width === '58mm') return '58mm';
	if (preview.paper_width === '80mm') return '80mm';
	return 'a4';
}

const PREVIEW_IFRAME_CLASS = 'wcpos:block wcpos:h-full wcpos:w-full wcpos:border-0 wcpos:bg-white';

function PreviewFrameContent({
	preview,
	templateName,
}: {
	preview: PreviewResponse;
	templateName: string;
}) {
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
		<PreviewViewport
			paperWidth={getPreviewPaperWidth(preview)}
			zoomInLabel={t('modal.zoom_in')}
			zoomOutLabel={t('modal.zoom_out')}
		>
			<iframe
				srcDoc={srcdoc}
				title={t('modal.preview_title', { templateName })}
				className={PREVIEW_IFRAME_CLASS}
				sandbox="allow-same-origin"
			/>
		</PreviewViewport>
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
			: buildPreviewFrameHtml({
					bodyHtml: preview.preview_html,
					paperWidth: preview.paper_width ?? 'a4',
				});
	}

	return '';
}

function isFullHtmlDocument(html: string): boolean {
	const h = html.trimStart().toLowerCase();
	return h.startsWith('<!doctype') || h.startsWith('<html');
}

export function buildDisplayPreviewUrl(
	base: string,
	{ state, ...target }: { state: string; gallery?: string; template?: number | string }
): string {
	return addQueryArgs(base, { preview: state, ...target });
}

export function PreviewModal(props: PreviewModalProps) {
	return props.templateType === 'display' ? (
		<PreviewModalContent {...props} />
	) : (
		<ReceiptPreviewModal {...props} />
	);
}

function ReceiptPreviewModal(props: PreviewModalProps) {
	const hasPosOrders = Boolean((window as any).wcpos?.templateGallery?.hasPosOrders);
	const [source, setSource] = React.useState<'sample' | 'order'>(hasPosOrders ? 'order' : 'sample');
	const orderId = source === 'order' ? 'latest' : undefined;
	const { data: preview, isFetching, isError } = usePreview(props.templateId, orderId);

	// Let the errored order observer mount and retry before falling back.
	React.useEffect(() => {
		if (!isError || isFetching || source !== 'order') return;

		const fallbackTimer = window.setTimeout(() => setSource('sample'), 0);
		return () => window.clearTimeout(fallbackTimer);
	}, [isError, isFetching, source]);

	return (
		<PreviewModalContent
			{...props}
			preview={preview}
			isFetching={isFetching}
			controls={<PreviewToggle source={source} disabled={!hasPosOrders} onToggle={setSource} />}
		/>
	);
}

function PreviewModalContent({
	templateType,
	templateId,
	templateName,
	templateDescription,
	isGallery,
	onClose,
	onActivate,
	activateLabel,
	canActivate = true,
	onCustomize,
	preview,
	isFetching,
	controls,
}: PreviewModalProps & {
	preview?: PreviewResponse;
	isFetching?: boolean;
	controls?: React.ReactNode;
}) {
	const [state, setState] = React.useState('cart');
	const [viewport, setViewport] = React.useState<'screen' | 'phone'>('screen');
	const { isProActive, displayPreviewUrl } = (window as any).wcpos?.templateGallery ?? {};
	const isDisplay = templateType === 'display';
	const imageSrc = isDisplay && isGallery ? getGalleryPreviewSrc(String(templateId)) : undefined;
	const dialogRef = React.useRef<HTMLDivElement>(null);
	const closeButtonRef = React.useRef<HTMLButtonElement>(null);
	const previousFocusedElementRef = React.useRef<HTMLElement | null>(null);
	const titleId = React.useId();
	const canRenderFrame = Boolean(
		preview &&
		(preview.engine === 'thermal' || preview.engine === 'logicless') &&
		((preview.template_content != null && preview.receipt_data) || preview.preview_html)
	);

	React.useEffect(() => {
		previousFocusedElementRef.current =
			document.activeElement instanceof HTMLElement ? document.activeElement : null;

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
				'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
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
				className="wcpos:bg-white wcpos:rounded-lg wcpos:shadow-xl wcpos:max-w-4xl wcpos:w-full wcpos:h-[90vh] wcpos:flex wcpos:flex-col wcpos:m-4"
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
						{controls}
						{isDisplay && isProActive && (
							<>
								<select
									value={state}
									onChange={(e) => setState(e.target.value)}
									aria-label={t('modal.display_state')}
								>
									{[
										'idle',
										'cart.empty',
										'cart',
										'payment.started',
										'payment.approved',
										'payment.declined',
										'payment.complete',
									].map((value) => (
										<option key={value} value={value}>
											{t(`modal.state_${value.replace('.', '_')}`)}
										</option>
									))}
								</select>
								<div
									role="radiogroup"
									aria-label={t('modal.viewport')}
									className="wcpos:flex wcpos:bg-slate-100 wcpos:rounded wcpos:border wcpos:border-slate-200 wcpos:overflow-hidden"
								>
									{(['screen', 'phone'] as const).map((value) => (
										<button
											key={value}
											type="button"
											role="radio"
											aria-checked={viewport === value}
											onClick={() => setViewport(value)}
											className={`wcpos:px-2.5 wcpos:py-1 wcpos:text-xs wcpos:font-medium wcpos:transition-colors ${viewport === value ? 'wcpos:text-white' : 'wcpos:text-slate-500 wcpos:cursor-pointer'}`}
											style={
												viewport === value
													? { backgroundColor: 'var(--wp-admin-theme-color, #007cba)' }
													: undefined
											}
										>
											{t(`modal.viewport_${value}`)}
										</button>
									))}
								</div>
							</>
						)}
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
					{isDisplay ? (
						isProActive || imageSrc ? (
							<PreviewViewport
								paperWidth={isProActive ? viewport : 'screen'}
								zoomInLabel={t('modal.zoom_in')}
								zoomOutLabel={t('modal.zoom_out')}
							>
								{isProActive ? (
									<iframe
										key={viewport}
										src={buildDisplayPreviewUrl(displayPreviewUrl, {
											state,
											...(isGallery ? { gallery: String(templateId) } : { template: templateId }),
										})}
										title={t('modal.preview_title', { templateName })}
										className={PREVIEW_IFRAME_CLASS}
									/>
								) : (
									<img
										src={imageSrc}
										alt={t('modal.preview_title', { templateName })}
										className="wcpos:w-full wcpos:h-full wcpos:object-contain"
									/>
								)}
							</PreviewViewport>
						) : (
							<p className="wcpos:text-gray-500 wcpos:text-center">
								{t('modal.display_needs_pro')}{' '}
								<a
									href="https://docs.wcpos.com/customer-display"
									target="_blank"
									rel="noopener noreferrer"
									className="wcpos:text-wp-admin-theme-color hover:wcpos:underline"
								>
									{t('layout.learn_more')}
								</a>
							</p>
						)
					) : isFetching ? (
						<div className="wcpos:flex wcpos:flex-1 wcpos:items-center wcpos:justify-center">
							<span className="wcpos:text-gray-400">{t('modal.loading')}</span>
						</div>
					) : preview && canRenderFrame ? (
						<PreviewFrameContent preview={preview} templateName={templateName} />
					) : preview?.preview_html ? (
						<PreviewViewport
							paperWidth={getPreviewPaperWidth(preview)}
							zoomInLabel={t('modal.zoom_in')}
							zoomOutLabel={t('modal.zoom_out')}
						>
							<iframe
								srcDoc={buildPreviewModalSrcDoc(preview)}
								title={t('modal.preview_title', { templateName })}
								className={PREVIEW_IFRAME_CLASS}
								sandbox="allow-same-origin"
							/>
						</PreviewViewport>
					) : preview?.preview_url ? (
						<PreviewViewport
							paperWidth={getPreviewPaperWidth(preview)}
							zoomInLabel={t('modal.zoom_in')}
							zoomOutLabel={t('modal.zoom_out')}
						>
							<iframe
								src={preview.preview_url}
								title={t('modal.preview_title', { templateName })}
								className={PREVIEW_IFRAME_CLASS}
								sandbox="allow-scripts"
							/>
						</PreviewViewport>
					) : (
						<div className="wcpos:text-gray-500 wcpos:text-center wcpos:py-8">
							{t('modal.no_preview')}
						</div>
					)}
				</div>

				{/* Footer actions */}
				<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:p-4 wcpos:border-t wcpos:border-gray-200">
					<div className="wcpos:text-xs wcpos:text-gray-500">
						{preview?.order_id ? (
							<>{t('modal.preview_order', { orderId: preview.order_id })}</>
						) : null}
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
						) : canActivate ? (
							<Button
								variant="primary"
								onClick={() => {
									onActivate?.();
									onClose();
								}}
							>
								{activateLabel ?? t('common.activate')}
							</Button>
						) : null}
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
