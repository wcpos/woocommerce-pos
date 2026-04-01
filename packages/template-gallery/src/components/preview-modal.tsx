import * as React from 'react';

import { usePreview } from '../hooks/use-preview';
import { renderThermalPreview } from '@wcpos/thermal-utils';
import { t } from '../translations';
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

function ThermalPreviewContent({
	templateContent,
	receiptData,
	templateName,
}: {
	templateContent: string;
	receiptData: Record<string, unknown>;
	templateName: string;
}) {
	const html = React.useMemo(() => {
		try {
			return renderThermalPreview(templateContent, receiptData);
		} catch {
			return `<div style="color:red;padding:16px;">${t('modal.render_error')}</div>`;
		}
	}, [templateContent, receiptData]);

	const srcdoc = `<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f5f5f5;display:flex;justify-content:center;flex-direction:column;align-items:center;">${html}</body>
</html>`;

	return (
		<iframe
			srcDoc={srcdoc}
			title={t('modal.preview_title', { templateName })}
			className="wcpos:w-full wcpos:border wcpos:border-gray-200 wcpos:rounded wcpos:bg-white"
			style={{ height: '600px' }}
			sandbox="allow-same-origin"
		/>
	);
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
				className="wcpos:bg-white wcpos:rounded-lg wcpos:shadow-xl wcpos:max-w-3xl wcpos:w-full wcpos:max-h-[90vh] wcpos:flex wcpos:flex-col wcpos:m-4"
				onClick={(e) => e.stopPropagation()}
			>
				{/* Header */}
				<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:p-4 wcpos:border-b wcpos:border-gray-200">
					<div>
						<h2 id={titleId} className="wcpos:text-lg wcpos:font-semibold wcpos:m-0">
							{templateName}
						</h2>
						{templateDescription && (
							<p className="wcpos:text-sm wcpos:text-gray-500 wcpos:m-0 wcpos:mt-1">
								{templateDescription}
							</p>
						)}
					</div>
					<div className="wcpos:flex wcpos:items-center wcpos:gap-2">
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
				<div className="wcpos:flex-1 wcpos:overflow-auto wcpos:p-4 wcpos:bg-gray-50">
					{isFetching ? (
						<div className="wcpos:flex wcpos:items-center wcpos:justify-center wcpos:h-64">
							<span className="wcpos:text-gray-400">{t('modal.loading')}</span>
						</div>
					) : preview?.engine === 'thermal' && preview.template_content && preview.receipt_data ? (
						<ThermalPreviewContent
							templateContent={preview.template_content}
							receiptData={preview.receipt_data}
							templateName={templateName}
						/>
					) : preview?.preview_html ? (
						<iframe
							srcDoc={`<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>
<body style="margin:0;padding:24px;background:#f5f5f5;display:flex;justify-content:center;">${preview.preview_html}</body>
</html>`}
							title={t('modal.preview_title', { templateName })}
							className="wcpos:w-full wcpos:border wcpos:border-gray-200 wcpos:rounded wcpos:bg-white"
							style={{ height: '600px' }}
							sandbox="allow-same-origin"
						/>
					) : preview?.preview_url ? (
						<iframe
							src={preview.preview_url}
							title={t('modal.preview_title', { templateName })}
							className="wcpos:w-full wcpos:border wcpos:border-gray-200 wcpos:rounded wcpos:bg-white"
							style={{ height: '600px' }}
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
							<button
								type="button"
								onClick={() => {
									onCustomize?.();
									onClose();
								}}
								className="wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:font-medium wcpos:text-white wcpos:bg-wp-admin-theme-color wcpos:border-0 wcpos:rounded wcpos:cursor-pointer hover:wcpos:bg-wp-admin-theme-color-darker-10"
							>
								{t('common.customize')}
							</button>
						) : (
							<button
								type="button"
								onClick={() => {
									onActivate?.();
									onClose();
								}}
								className="wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:font-medium wcpos:text-white wcpos:bg-wp-admin-theme-color wcpos:border-0 wcpos:rounded wcpos:cursor-pointer hover:wcpos:bg-wp-admin-theme-color-darker-10"
							>
								{t('common.activate')}
							</button>
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
