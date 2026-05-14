import * as React from 'react';

import classNames from 'classnames';

export type PreviewPaperWidth = 'a4' | '58mm' | '80mm';

export const PREVIEW_ZOOM_STEPS = [
	10, 20, 30, 40, 50, 60, 70, 80, 90, 100, 110, 120, 130, 140, 150, 160, 170, 180, 190, 200,
] as const;

export type PreviewZoom = (typeof PREVIEW_ZOOM_STEPS)[number];

const CANVAS_PAD_PX = 16;

interface ContentSize {
	width: number;
	height: number;
}

/**
 * True paper dimensions in CSS px. Used as the preview size until the rendered
 * document has been measured — the measured content size takes over once known.
 */
export const PAPER_DIMENSIONS: Record<PreviewPaperWidth, ContentSize> = {
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
	const canvasRef = React.useRef<HTMLDivElement>(null);
	const fallback = PAPER_DIMENSIONS[paperWidth];
	const [contentSize, setContentSize] = React.useState<ContentSize | null>(null);
	const canvasW = contentSize?.width ?? fallback.width;
	const canvasH = contentSize?.height ?? fallback.height;
	const [zoom, setZoom] = React.useState<PreviewZoom>(100);
	const [userPicked, setUserPicked] = React.useState(false);

	// Paper size changed (e.g. a different template) — drop the stale
	// measurement and re-enable auto-fit until the new frame is measured.
	const [measuredPaperWidth, setMeasuredPaperWidth] = React.useState(paperWidth);
	if (measuredPaperWidth !== paperWidth) {
		setMeasuredPaperWidth(paperWidth);
		setContentSize(null);
		setUserPicked(false);
	}

	// Measure the wrapped iframe so the canvas tracks the rendered document
	// instead of the fixed paper dimensions. srcDoc previews are same-origin and
	// measurable; a cross-origin `src` falls back to the paper dimensions.
	React.useEffect(() => {
		const canvas = canvasRef.current;
		if (!canvas) return;

		let iframe: HTMLIFrameElement | null = null;
		let resizeObserver: ResizeObserver | null = null;

		const measure = () => {
			let body: HTMLElement | null = null;
			try {
				body = iframe?.contentDocument?.body ?? null;
			} catch {
				return; // cross-origin document — not measurable from the host
			}
			if (!body) return;
			const width = body.scrollWidth;
			const height = body.scrollHeight;
			if (width <= 0 || height <= 0) return;
			setContentSize((prev) =>
				prev && prev.width === width && prev.height === height ? prev : { width, height },
			);
		};

		const handleLoad = () => {
			let doc: Document | null = null;
			try {
				doc = iframe?.contentDocument ?? null;
			} catch {
				doc = null;
			}
			resizeObserver?.disconnect();
			resizeObserver = null;
			if (!doc?.body) return;
			// Zero the UA body margin and hide overflow so the measurement
			// reflects the document itself, not a transient scrollbar.
			if (!doc.getElementById('wcpos-preview-reset')) {
				const style = doc.createElement('style');
				style.id = 'wcpos-preview-reset';
				style.textContent = 'html,body{margin:0;padding:0;overflow:hidden;}';
				doc.head?.appendChild(style);
			}
			measure();
			if (typeof ResizeObserver !== 'undefined') {
				resizeObserver = new ResizeObserver(measure);
				resizeObserver.observe(doc.body);
			}
		};

		const attach = () => {
			const next = canvas.querySelector('iframe');
			if (next === iframe) return;
			iframe?.removeEventListener('load', handleLoad);
			resizeObserver?.disconnect();
			resizeObserver = null;
			iframe = next;
			if (!iframe) return;
			iframe.addEventListener('load', handleLoad);
			// srcDoc content can already be parsed by the time we attach.
			try {
				if (iframe.contentDocument?.readyState === 'complete') handleLoad();
			} catch {
				// cross-origin — leave it to the paper-dimension fallback
			}
		};

		attach();
		// The iframe element can be swapped out (e.g. a keyed remount), so watch
		// the canvas subtree and re-attach when that happens.
		const mutationObserver = new MutationObserver(attach);
		mutationObserver.observe(canvas, { childList: true, subtree: true });

		return () => {
			mutationObserver.disconnect();
			iframe?.removeEventListener('load', handleLoad);
			resizeObserver?.disconnect();
		};
	}, []);

	// Auto-fit the preview to the viewport until the user picks a zoom; re-runs
	// when the measured content size changes so a freshly measured frame is
	// fitted, and observes the container so a modal that lays out at 0×0 first
	// is still fitted once it has real dimensions.
	React.useLayoutEffect(() => {
		if (userPicked) return;
		const el = containerRef.current;
		if (!el) return;
		const applyAutoFit = () => {
			if (userPicked) return;
			const availW = el.clientWidth - CANVAS_PAD_PX * 2;
			const availH = el.clientHeight - CANVAS_PAD_PX * 2;
			if (availW <= 0 || availH <= 0) return;
			setZoom(pickAutoFitZoom(canvasW, canvasH, availW, availH));
		};

		applyAutoFit();
		if (typeof ResizeObserver === 'undefined') return;

		const resizeObserver = new ResizeObserver(applyAutoFit);
		resizeObserver.observe(el);
		return () => resizeObserver.disconnect();
	}, [canvasW, canvasH, userPicked]);

	const scale = zoom / 100;
	const currentIndex = PREVIEW_ZOOM_STEPS.indexOf(zoom);
	const canZoomOut = currentIndex > 0;
	const canZoomIn = currentIndex < PREVIEW_ZOOM_STEPS.length - 1;
	const stepZoom = (delta: number) => {
		const next = Math.max(0, Math.min(PREVIEW_ZOOM_STEPS.length - 1, currentIndex + delta));
		setUserPicked(true);
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
						width: canvasW * scale,
						height: canvasH * scale,
					}}
				>
					<div
						ref={canvasRef}
						data-testid="preview-viewport-canvas"
						className={contentClassName}
						style={{
							width: canvasW,
							height: canvasH,
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
