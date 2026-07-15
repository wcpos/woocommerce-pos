import { useCallback, useLayoutEffect, useRef, useState } from 'react';
import type {
	KeyboardEvent as ReactKeyboardEvent,
	PointerEvent as ReactPointerEvent,
	RefObject,
} from 'react';

interface UseResizableWidthOptions {
	/** localStorage key the chosen width is persisted under. */
	storageKey: string;
	defaultWidth: number;
	minWidth: number;
	maxWidth: number;
	/**
	 * Horizontal space (px) kept free for sibling content when clamping
	 * against the container, so the panel can never crowd it out on
	 * narrow viewports. Ignored when the container has no layout yet.
	 */
	reservedSpace?: number;
	/** Pixels per arrow-key press. */
	keyboardStep?: number;
}

interface SeparatorProps {
	role: 'separator';
	'aria-orientation': 'vertical';
	'aria-valuemin': number;
	'aria-valuemax': number;
	'aria-valuenow': number;
	tabIndex: number;
	onPointerDown: (event: ReactPointerEvent<HTMLElement>) => void;
	onPointerMove: (event: ReactPointerEvent<HTMLElement>) => void;
	onPointerUp: () => void;
	onPointerCancel: () => void;
	onLostPointerCapture: () => void;
	onKeyDown: (event: ReactKeyboardEvent<HTMLElement>) => void;
}

interface UseResizableWidthResult {
	width: number;
	isResizing: boolean;
	panelRef: RefObject<HTMLDivElement>;
	separatorProps: SeparatorProps;
}

function readStoredWidth(storageKey: string, fallback: number, min: number, max: number): number {
	try {
		const stored = Number(window.localStorage.getItem(storageKey));
		if (Number.isFinite(stored) && stored > 0) {
			return Math.min(max, Math.max(min, stored));
		}
	} catch {
		// localStorage unavailable (private browsing, blocked storage) — use the default.
	}
	return fallback;
}

function storeWidth(storageKey: string, width: number): void {
	try {
		window.localStorage.setItem(storageKey, String(width));
	} catch {
		// Best-effort persistence only.
	}
}

/**
 * Width state for a panel resizable via a draggable / keyboard-operable
 * separator on its inline-end edge. Handles pointer capture, RTL, clamping
 * (both static and against the container), and localStorage persistence
 * (written once per completed drag, not per pointermove).
 */
export function useResizableWidth({
	storageKey,
	defaultWidth,
	minWidth,
	maxWidth,
	reservedSpace = 360,
	keyboardStep = 16,
}: UseResizableWidthOptions): UseResizableWidthResult {
	const panelRef = useRef<HTMLDivElement>(null);
	const isResizingRef = useRef(false);
	const [isResizing, setIsResizing] = useState(false);
	const [width, setWidth] = useState(() =>
		readStoredWidth(storageKey, defaultWidth, minWidth, maxWidth),
	);
	const widthRef = useRef(width);

	const clampWidth = useCallback(
		(value: number) => {
			let max = maxWidth;
			const parentWidth =
				panelRef.current?.parentElement?.getBoundingClientRect().width ?? 0;
			if (parentWidth > 0) {
				max = Math.max(minWidth, Math.min(maxWidth, parentWidth - reservedSpace));
			}
			return Math.min(max, Math.max(minWidth, value));
		},
		[minWidth, maxWidth, reservedSpace],
	);

	const applyWidth = useCallback(
		(value: number) => {
			const clamped = clampWidth(value);
			widthRef.current = clamped;
			setWidth(clamped);
			return clamped;
		},
		[clampWidth],
	);

	// A width persisted on a wide screen may not fit the current container;
	// re-clamp once real layout information is available.
	useLayoutEffect(() => {
		const clamped = clampWidth(widthRef.current);
		if (clamped !== widthRef.current) {
			widthRef.current = clamped;
			setWidth(clamped);
			storeWidth(storageKey, clamped);
		}
	}, [clampWidth, storageKey]);

	const endResize = useCallback(() => {
		if (!isResizingRef.current) return;
		isResizingRef.current = false;
		setIsResizing(false);
		storeWidth(storageKey, widthRef.current);
	}, [storageKey]);

	const handlePointerDown = useCallback((event: ReactPointerEvent<HTMLElement>) => {
		// Primary button only: a right-click must not start a drag.
		if (event.button !== 0) return;
		// Prevent text selection while dragging; keyboard users focus via Tab.
		event.preventDefault();
		try {
			event.currentTarget.setPointerCapture(event.pointerId);
		} catch {
			// Pointer capture is unavailable in some environments (e.g. jsdom).
		}
		isResizingRef.current = true;
		setIsResizing(true);
	}, []);

	const handlePointerMove = useCallback(
		(event: ReactPointerEvent<HTMLElement>) => {
			if (!isResizingRef.current) return;
			// Drag ended outside our control (e.g. button released over a
			// context menu) — bail out instead of resizing on hover.
			if ((event.buttons & 1) === 0) {
				endResize();
				return;
			}
			const panel = panelRef.current;
			if (!panel) return;

			const rect = panel.getBoundingClientRect();
			const isRtl = window.getComputedStyle(panel).direction === 'rtl';
			const next = isRtl ? rect.right - event.clientX : event.clientX - rect.left;
			if (!Number.isFinite(next)) return;
			applyWidth(next);
		},
		[applyWidth, endResize],
	);

	const handleKeyDown = useCallback(
		(event: ReactKeyboardEvent<HTMLElement>) => {
			if (event.altKey || event.ctrlKey || event.metaKey) return;

			const panel = panelRef.current;
			const isRtl = panel ? window.getComputedStyle(panel).direction === 'rtl' : false;
			// The separator sits on the inline-end edge, so the arrow pointing
			// away from the panel grows it; RTL inverts which arrow that is.
			const growKey = isRtl ? 'ArrowLeft' : 'ArrowRight';
			const shrinkKey = isRtl ? 'ArrowRight' : 'ArrowLeft';

			let committed: number;
			if (event.key === growKey) {
				committed = applyWidth(widthRef.current + keyboardStep);
			} else if (event.key === shrinkKey) {
				committed = applyWidth(widthRef.current - keyboardStep);
			} else if (event.key === 'Home') {
				committed = applyWidth(minWidth);
			} else if (event.key === 'End') {
				committed = applyWidth(maxWidth);
			} else {
				return;
			}
			event.preventDefault();
			storeWidth(storageKey, committed);
		},
		[applyWidth, keyboardStep, minWidth, maxWidth, storageKey],
	);

	return {
		width,
		isResizing,
		panelRef,
		separatorProps: {
			role: 'separator',
			'aria-orientation': 'vertical',
			'aria-valuemin': minWidth,
			'aria-valuemax': maxWidth,
			'aria-valuenow': width,
			tabIndex: 0,
			onPointerDown: handlePointerDown,
			onPointerMove: handlePointerMove,
			onPointerUp: endResize,
			onPointerCancel: endResize,
			onLostPointerCapture: endResize,
			onKeyDown: handleKeyDown,
		},
	};
}
