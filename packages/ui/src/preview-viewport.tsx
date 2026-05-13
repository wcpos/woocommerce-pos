import * as React from 'react';

import classNames from 'classnames';

export type PreviewPaperWidth = 'a4' | '58mm' | '80mm';

export const PREVIEW_ZOOM_STEPS = [
	10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120, 130, 140, 150, 160, 170, 180, 190, 200,
] as const;

export type PreviewZoom = (typeof PREVIEW_ZOOM_STEPS)[number];

const CANVAS_PAD_PX = 16;

export const PAPER_DIMENSIONS: Record<PreviewPaperWidth, { width: number; height: number }> = {
	a4: { width: 794, height: 1123 },
	'58mm': { width: 219, height: 520 },
	'80mm': { width: 302, height: 520 },
};

export interface PreviewViewportProps
	extends Omit<React.HTMLAttributes<HTMLDivElement>, 'children'> {
	children: React.ReactNode;
	paperWidth?: PreviewPaperWidth;
	zoomInLabel: string;
	zoomOutLabel: string;
	contentClassName?: string;
}

function pickAutoFitZoom(paperW: number, paperH: number, availW: number, availH: number): PreviewZoom {
	if (availW <= 0 || availH <= 0) return 100;
	const candidates = PREVIEW_ZOOM_STEPS.filter((z) => z <= 100).slice().reverse();
	for (const z of candidates) {
		const s = z / 100;
		if (paperW * s <= availW && paperH * s <= availH) return z;
	}
	return candidates[candidates.length - 1];
}

export function PreviewViewport({
	children,
	paperWidth = 'a4',
	zoomInLabel,
	zoomOutLabel,
	className,
	contentClassName,
	...props
}: PreviewViewportProps) {
	const containerRef = React.useRef<HTMLDivElement>(null);
	const { width: paperW, height: paperH } = PAPER_DIMENSIONS[paperWidth];
	const [zoom, setZoom] = React.useState<PreviewZoom>(100);
	const userPickedRef = React.useRef(false);

	React.useLayoutEffect(() => {
		userPickedRef.current = false;
		const el = containerRef.current;
		if (!el) return;
		const availW = el.clientWidth - CANVAS_PAD_PX * 2;
		const availH = el.clientHeight - CANVAS_PAD_PX * 2;
		setZoom(pickAutoFitZoom(paperW, paperH, availW, availH));
	}, [paperW, paperH]);

	const scale = zoom / 100;
	const currentIndex = PREVIEW_ZOOM_STEPS.indexOf(zoom);
	const canZoomOut = currentIndex > 0;
	const canZoomIn = currentIndex < PREVIEW_ZOOM_STEPS.length - 1;
	const stepZoom = (delta: number) => {
		const next = Math.max(0, Math.min(PREVIEW_ZOOM_STEPS.length - 1, currentIndex + delta));
		userPickedRef.current = true;
		setZoom(PREVIEW_ZOOM_STEPS[next]);
	};

	const buttonClass =
		'wcpos:flex wcpos:h-7 wcpos:w-7 wcpos:cursor-pointer wcpos:items-center wcpos:justify-center wcpos:border-0 wcpos:bg-transparent wcpos:text-base wcpos:leading-none wcpos:text-gray-700 wcpos:hover:bg-gray-100 wcpos:disabled:cursor-default wcpos:disabled:text-gray-300 wcpos:disabled:hover:bg-transparent';

	return (
		<div
			ref={containerRef}
			className={classNames(
				'wcpos:relative wcpos:flex wcpos:min-h-0 wcpos:flex-1 wcpos:flex-col wcpos:rounded wcpos:border wcpos:border-gray-200 wcpos:bg-gray-100',
				className,
			)}
			{...props}
		>
			<div
				data-testid="preview-viewport-zoom-controls"
				className="wcpos:absolute wcpos:top-2 wcpos:right-2 wcpos:z-10 wcpos:inline-flex wcpos:items-stretch wcpos:overflow-hidden wcpos:rounded-md wcpos:border wcpos:border-gray-300 wcpos:bg-white/95 wcpos:shadow-sm"
			>
				<button
					type="button"
					aria-label={zoomOutLabel}
					title={zoomOutLabel}
					disabled={!canZoomOut}
					onClick={() => stepZoom(-1)}
					className={buttonClass}
				>
					&minus;
				</button>
				<span
					data-testid="preview-viewport-zoom-value"
					className="wcpos:inline-flex wcpos:min-w-[44px] wcpos:items-center wcpos:justify-center wcpos:border-l wcpos:border-r wcpos:border-gray-200 wcpos:px-2 wcpos:text-xs wcpos:tabular-nums wcpos:text-gray-700"
					role="status"
					aria-label={`Zoom ${zoom}%`}
					aria-live="polite"
				>
					{zoom}%
				</span>
				<button
					type="button"
					aria-label={zoomInLabel}
					title={zoomInLabel}
					disabled={!canZoomIn}
					onClick={() => stepZoom(1)}
					className={buttonClass}
				>
					+
				</button>
			</div>
			<div
				data-testid="preview-viewport-scroll-area"
				className="wcpos:min-h-0 wcpos:flex-1 wcpos:overflow-auto wcpos:p-4"
			>
				<div
					data-testid="preview-viewport-canvas-frame"
					className="wcpos:mx-auto wcpos:overflow-hidden"
					style={{
						width: paperW * scale,
						height: paperH * scale,
					}}
				>
					<div
						data-testid="preview-viewport-canvas"
						className={contentClassName}
						style={{
							width: paperW,
							height: paperH,
							transform: `scale(${scale})`,
							transformOrigin: 'top left',
						}}
					>
						{children}
					</div>
				</div>
			</div>
		</div>
	);
}
