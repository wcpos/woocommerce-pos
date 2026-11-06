import * as React from 'react';

import classNames from 'classnames';

export type SnackbarStatus = 'saving' | 'success' | 'error' | 'info';

export interface SnackbarProps {
	id: string;
	message: string;
	status?: SnackbarStatus;
	onRemove?: () => void;
	/** Defaults to `false` when `actions` or `dismissible` is given, otherwise `true`. */
	timeout?: boolean;
	/** Rendered as text buttons after the message. */
	actions?: { label: string; onClick: () => void }[];
	/** Renders an × (only when `onRemove` is given) that slides the bar out, then calls `onRemove`. */
	dismissible?: boolean;
	/** Accessible label for the dismiss control. */
	dismissLabel?: string;
}

const statusClasses: Record<SnackbarStatus, string> = {
	saving: 'wcpos:bg-gray-900 wcpos:text-white',
	success: 'wcpos:bg-green-600 wcpos:text-white',
	error: 'wcpos:bg-red-600 wcpos:text-white',
	info: 'wcpos:bg-gray-700 wcpos:text-white',
};

export function Snackbar({
	message,
	status = 'saving',
	onRemove,
	timeout,
	actions,
	dismissible = false,
	dismissLabel = 'Dismiss',
}: SnackbarProps) {
	// A snackbar the user can act on stays until they do.
	const autoDismiss = timeout ?? !((actions?.length ?? 0) > 0 || dismissible);
	const [visible, setVisible] = React.useState(false);
	const removeTimer = React.useRef<ReturnType<typeof setTimeout> | undefined>(undefined);

	// Slide in on mount, and again when the provider reuses this instance
	// under the same id with a new message: that drops any pending removal.
	React.useEffect(() => {
		clearTimeout(removeTimer.current);
		if (!message) return;
		const frame = requestAnimationFrame(() => setVisible(true));
		return () => cancelAnimationFrame(frame);
	}, [message]);

	// Slide out, then remove once the 300ms transition has finished.
	const dismiss = React.useCallback(() => {
		setVisible(false);
		clearTimeout(removeTimer.current);
		removeTimer.current = setTimeout(() => onRemove?.(), 300);
	}, [onRemove]);

	// Never call onRemove after unmount.
	React.useEffect(() => () => clearTimeout(removeTimer.current), []);

	// Auto-dismiss.
	// "saving" status never auto-dismisses — it's meant to be replaced by a
	// terminal status (success/error) when the operation completes.
	React.useEffect(() => {
		if (!message || status === 'saving' || !autoDismiss) return;
		const timer = setTimeout(dismiss, 2000);
		return () => clearTimeout(timer);
	}, [message, status, dismiss, autoDismiss]);

	if (!message) return null;

	return (
		<div
			className={classNames(
				'wcpos:w-full wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:text-center wcpos:transition-all wcpos:duration-300 wcpos:ease-out',
				statusClasses[status],
				visible
					? 'wcpos:translate-y-0 wcpos:opacity-100'
					: 'wcpos:-translate-y-full wcpos:opacity-0'
			)}
		>
			<div className="wcpos:flex wcpos:items-center wcpos:justify-center wcpos:gap-4">
				<span>{message}</span>
				{actions?.map((action, index) => (
					<button
						key={index}
						type="button"
						onClick={action.onClick}
						className="wcpos:shrink-0 wcpos:whitespace-nowrap wcpos:cursor-pointer wcpos:font-semibold wcpos:underline wcpos:underline-offset-2"
					>
						{action.label}
					</button>
				))}
				{dismissible && onRemove && (
					<button
						type="button"
						aria-label={dismissLabel}
						onClick={dismiss}
						className="wcpos:shrink-0 wcpos:cursor-pointer wcpos:px-1 wcpos:leading-none wcpos:hover:opacity-80"
					>
						&times;
					</button>
				)}
			</div>
		</div>
	);
}
