import { useState, useCallback, useEffect } from 'react';

declare const jQuery: any;

interface PhpPreviewProps {
	previewUrl: string;
}

export function PhpPreview({ previewUrl }: PhpPreviewProps) {
	const [iframeKey, setIframeKey] = useState(0);

	const handleSaveAndPreview = useCallback(() => {
		const wp = (window as any).wp;
		if (wp?.autosave?.server) {
			wp.autosave.server.triggerSave();
		} else {
			setIframeKey((k) => k + 1);
		}
	}, []);

	useEffect(() => {
		if (typeof jQuery === 'undefined') return;

		const onAutosaveComplete = (_event: unknown, data?: { success?: boolean }) => {
			if (data && data.success === false) return;
			setIframeKey((k) => k + 1);
		};

		jQuery(document).on('after-autosave', onAutosaveComplete);
		return () => {
			jQuery(document).off('after-autosave', onAutosaveComplete);
		};
	}, []);

	if (!previewUrl) {
		return (
			<div className="wcpos:border wcpos:border-gray-300 wcpos:bg-gray-50 wcpos:flex wcpos:items-center wcpos:justify-center wcpos:p-6 wcpos:text-sm wcpos:text-gray-500">
				No POS orders found. Create an order in the POS to preview templates.
			</div>
		);
	}

	return (
		<div className="wcpos:border wcpos:border-gray-300 wcpos:bg-gray-50 wcpos:flex wcpos:flex-col">
			<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:px-3 wcpos:py-2 wcpos:border-b wcpos:border-gray-200 wcpos:bg-white">
				<span className="wcpos:text-xs wcpos:font-semibold wcpos:text-gray-500 wcpos:uppercase">
					Preview
				</span>
				<div className="wcpos:flex wcpos:gap-2">
					<button
						type="button"
						onClick={handleSaveAndPreview}
						className="wcpos:text-xs wcpos:px-2 wcpos:py-1 wcpos:bg-blue-600 wcpos:text-white wcpos:rounded hover:wcpos:bg-blue-700"
					>
						Save & Preview
					</button>
					<a
						href={previewUrl}
						target="_blank"
						rel="noopener noreferrer"
						className="wcpos:text-xs wcpos:text-blue-600 hover:wcpos:underline wcpos:self-center"
					>
						Open in tab
					</a>
				</div>
			</div>
			<div className="wcpos:p-2 wcpos:text-xs wcpos:text-amber-700 wcpos:bg-amber-50 wcpos:border-b wcpos:border-amber-200">
				PHP templates require saving before the preview updates.
			</div>
			<div className="wcpos:flex-1 wcpos:overflow-auto wcpos:flex wcpos:justify-center wcpos:p-4">
				<iframe
					key={iframeKey}
					src={previewUrl}
					style={{ width: '100%', maxWidth: 400, border: '1px solid #ddd', background: '#fff', minHeight: 400 }}
					title="Template preview"
				/>
			</div>
		</div>
	);
}
