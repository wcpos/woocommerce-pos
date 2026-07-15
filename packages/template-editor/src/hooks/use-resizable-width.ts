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
	const grabOffsetRef = useRef(0);
	const [isResizing, setIsResizing] = useState(false);
	const [width, setWidth] = useState(() =>
		readStoredWidth(storageKey, defaultWidth, minWidth, maxWidth),
	);
	const widthRef = useRef(width);
	// The width the user explicitly chose (restored from storage, updated on
	// drag/keyboard commits). The displayed width may be temporarily smaller
	// when the container can't fit it, but the preference itself is only
	// changed — and persisted — by explicit user resizes.
	const preferredWidthRef = useRef(width);

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

	// A preferred width saved on a wide screen may not fit the current
	// container. Re-derive the displayed width from the preference once real
	// layout exists, and again whenever the container is resized — without
	// touching the stored preference, so returning to a wide viewport
	// restores the user's choice.
	useLayoutEffect(() => {
		const fitPreferred = () => {
			// Never fight an active drag; it clamps against the live
			// container on every move anyway.
			if (isResizingRef.current) return;
			const clamped = clampWidth(preferredWidthRef.current);
			if (clamped !== widthRef.current) {
				widthRef.current = clamped;
				setWidth(clamped);
			}
		};

		fitPreferred();

		const parent = panelRef.current?.parentElement;
		if (!parent || typeof ResizeObserver === 'undefined') return undefined;
		const observer = new ResizeObserver(fitPreferred);
		observer.observe(parent);
		return () => observer.disconnect();
	}, [clampWidth]);

	const endResize = useCallback(() => {
		if (!isResizingRef.current) return;
		isResizingRef.current = false;
		setIsResizing(false);
		preferredWidthRef.current = widthRef.current;
		storeWidth(storageKey, widthRef.current);
	}, [storageKey]);

	// Pointer distance from the panel's inline-start edge — i.e. the width
	// the panel would have if its inline-end edge sat exactly under the
	// pointer. Direction-aware so the same math serves LTR and RTL.
	const widthAtPointer = useCallback((clientX: number): number | null => {
		const panel = panelRef.current;
		if (!panel) return null;
		const rect = panel.getBoundingClientRect();
		const isRtl = window.getComputedStyle(panel).direction === 'rtl';
		const value = isRtl ? rect.right - clientX : clientX - rect.left;
		return Number.isFinite(value) ? value : null;
	}, []);

	const handlePointerDown = useCallback(
		(event: ReactPointerEvent<HTMLElement>) => {
			// Primary button only: a right-click must not start a drag.
			if (event.button !== 0) return;
			// Prevent text selection while dragging; keyboard users focus via Tab.
			event.preventDefault();
			try {
				event.currentTarget.setPointerCapture(event.pointerId);
			} catch {
				// Pointer capture is unavailable in some environments (e.g. jsdom).
			}
			// Remember where inside the hit zone the drag started, so moves
			// track the grab point instead of jumping the edge to the pointer.
			const pointerWidth = widthAtPointer(event.clientX);
			grabOffsetRef.current = pointerWidth === null ? 0 : pointerWidth - widthRef.current;
			isResizingRef.current = true;
			setIsResizing(true);
		},
		[widthAtPointer],
	);

	const handlePointerMove = useCallback(
		(event: ReactPointerEvent<HTMLElement>) => {
			if (!isResizingRef.current) return;
			// Drag ended outside our control (e.g. button released over a
			// context menu) — bail out instead of resizing on hover.
			if ((event.buttons & 1) === 0) {
				endResize();
				return;
			}
			const pointerWidth = widthAtPointer(event.clientX);
			if (pointerWidth === null) return;
			applyWidth(pointerWidth - grabOffsetRef.current);
		},
		[applyWidth, endResize, widthAtPointer],
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
			preferredWidthRef.current = committed;
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
