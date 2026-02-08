import * as React from 'react';
import * as ReactDOM from 'react-dom';

import classNames from 'classnames';

interface TooltipProps {
	text: string;
	children: React.ReactNode;
	className?: string;
}

export function Tooltip({ text, children, className }: TooltipProps) {
	const triggerRef = React.useRef<HTMLSpanElement>(null);
	const [visible, setVisible] = React.useState(false);
	const [coords, setCoords] = React.useState({ top: 0, left: 0 });
	const tooltipId = React.useId();

	const updatePosition = React.useCallback(() => {
		if (triggerRef.current) {
			const rect = triggerRef.current.getBoundingClientRect();
			setCoords({
				top: rect.top - 4,
				left: rect.left + rect.width / 2,
			});
		}
	}, []);

	const show = React.useCallback(() => {
		updatePosition();
		setVisible(true);
	}, [updatePosition]);

	const hide = React.useCallback(() => {
		setVisible(false);
	}, []);

	React.useEffect(() => {
		if (!visible) return;

		window.addEventListener('scroll', updatePosition, true);
		window.addEventListener('resize', updatePosition);

		return () => {
			window.removeEventListener('scroll', updatePosition, true);
			window.removeEventListener('resize', updatePosition);
		};
	}, [visible, updatePosition]);

	return (
		<>
			<span
				ref={triggerRef}
				className={classNames('wcpos:inline-flex', className)}
				onMouseEnter={show}
				onMouseLeave={hide}
				onFocus={show}
				onBlur={hide}
				aria-describedby={visible ? tooltipId : undefined}
			>
				{children}
			</span>
			{visible &&
				ReactDOM.createPortal(
					<span
						id={tooltipId}
						role="tooltip"
						className="wcpos:fixed wcpos:z-[99999] wcpos:whitespace-nowrap wcpos:rounded wcpos:bg-gray-900 wcpos:px-2 wcpos:py-1 wcpos:text-xs wcpos:text-white wcpos:shadow-lg wcpos:pointer-events-none wcpos:-translate-x-1/2 wcpos:-translate-y-full"
						style={{ top: coords.top, left: coords.left }}
					>
						{text}
					</span>,
					document.body
				)}
		</>
	);
}
