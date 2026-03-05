import * as React from 'react';

import { usePreview } from '../hooks/use-preview';

interface PreviewModalProps {
	templateId: number | string;
	templateName: string;
	templateDescription?: string;
	isGallery: boolean;
	onClose: () => void;
	onActivate?: () => void;
	onCustomize?: () => void;
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
	const { data: preview, isLoading } = usePreview(templateId);
	const dialogRef = React.useRef<HTMLDivElement>(null);
	const closeButtonRef = React.useRef<HTMLButtonElement>(null);
	const previousFocusedElementRef = React.useRef<HTMLElement | null>(null);
	const titleId = React.useId();

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
					<button
						ref={closeButtonRef}
						type="button"
						onClick={onClose}
						className="wcpos:text-gray-400 hover:wcpos:text-gray-600 wcpos:text-2xl wcpos:leading-none wcpos:bg-transparent wcpos:border-0 wcpos:cursor-pointer wcpos:p-1"
						aria-label="Close preview"
					>
						&times;
					</button>
				</div>

				{/* Preview iframe */}
				<div className="wcpos:flex-1 wcpos:overflow-auto wcpos:p-4 wcpos:bg-gray-50">
					{isLoading ? (
						<div className="wcpos:flex wcpos:items-center wcpos:justify-center wcpos:h-64">
							<span className="wcpos:text-gray-400">Loading preview...</span>
						</div>
					) : preview?.preview_url ? (
						<iframe
							src={preview.preview_url}
							title={`Preview of ${templateName}`}
							className="wcpos:w-full wcpos:border wcpos:border-gray-200 wcpos:rounded wcpos:bg-white"
							style={{ height: '600px' }}
							sandbox="allow-scripts allow-same-origin"
						/>
					) : (
						<div className="wcpos:text-gray-500 wcpos:text-center wcpos:py-8">
							No preview available. Create a POS order first.
						</div>
					)}
				</div>

				{/* Footer actions */}
				<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:p-4 wcpos:border-t wcpos:border-gray-200">
					<div className="wcpos:text-xs wcpos:text-gray-500">
						{preview?.order_id ? <>Preview order: #{preview.order_id}</> : null}
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
								Customize
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
								Activate
							</button>
						)}
						{preview?.preview_url && (
							<a
								href={preview.preview_url}
								target="_blank"
								rel="noopener noreferrer"
								className="wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:font-medium wcpos:text-gray-700 wcpos:bg-white wcpos:border wcpos:border-gray-300 wcpos:rounded wcpos:no-underline hover:wcpos:bg-gray-50"
							>
								Open in New Tab
							</a>
						)}
					</div>
				</div>
			</div>
		</div>
	);
}
