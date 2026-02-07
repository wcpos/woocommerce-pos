import * as React from 'react';

import classNames from 'classnames';

interface TooltipProps {
	text: string;
	children: React.ReactNode;
	position?: 'top' | 'bottom';
	className?: string;
}

export function Tooltip({ text, children, position = 'top', className }: TooltipProps) {
	return (
		<span className={classNames('wcpos:relative wcpos:inline-flex wcpos:group', className)}>
			{children}
			<span
				role="tooltip"
				className={classNames(
					'wcpos:invisible wcpos:opacity-0 group-hover:wcpos:visible group-hover:wcpos:opacity-100',
					'wcpos:absolute wcpos:z-50 wcpos:whitespace-nowrap wcpos:rounded wcpos:bg-gray-900 wcpos:px-2 wcpos:py-1 wcpos:text-xs wcpos:text-white wcpos:shadow-lg',
					'wcpos:pointer-events-none wcpos:transition-opacity wcpos:duration-150 wcpos:delay-300',
					'wcpos:left-1/2 wcpos:-translate-x-1/2',
					position === 'top' && 'wcpos:bottom-full wcpos:mb-1',
					position === 'bottom' && 'wcpos:top-full wcpos:mt-1'
				)}
			>
				{text}
			</span>
		</span>
	);
}
