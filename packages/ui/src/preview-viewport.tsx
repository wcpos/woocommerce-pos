import * as React from 'react';

import classNames from 'classnames';

export type PreviewZoom = 50 | 75 | 100;

export interface PreviewViewportProps extends React.HTMLAttributes<HTMLDivElement> {
	children: React.ReactNode;
	defaultZoom?: PreviewZoom;
	zoomOptions?: PreviewZoom[];
	zoomLabel: React.ReactNode;
	contentClassName?: string;
}

const DEFAULT_ZOOM_OPTIONS: PreviewZoom[] = [50, 75, 100];

export function PreviewViewport({
	children,
	defaultZoom = 100,
	zoomOptions = DEFAULT_ZOOM_OPTIONS,
	zoomLabel,
	className,
	contentClassName,
	...props
}: PreviewViewportProps) {
	const [zoom, setZoom] = React.useState<PreviewZoom>(defaultZoom);
	const scale = zoom / 100;
	const inverseWidth = `${100 / scale}%`;

	return (
		<div
			className={classNames('wcpos:flex wcpos:min-h-0 wcpos:flex-1 wcpos:flex-col', className)}
			{...props}
		>
			<div className="wcpos:flex wcpos:items-center wcpos:justify-end wcpos:gap-2 wcpos:pb-2">
				<span className="wcpos:text-xs wcpos:text-gray-500">{zoomLabel}</span>
				<div className="wcpos:inline-flex wcpos:overflow-hidden wcpos:rounded wcpos:border wcpos:border-gray-300 wcpos:bg-white">
					{zoomOptions.map((option) => (
						<button
							key={option}
							type="button"
							onClick={() => setZoom(option)}
							aria-pressed={zoom === option}
							className={classNames(
								'wcpos:border-0 wcpos:border-l wcpos:border-gray-300 wcpos:bg-transparent wcpos:px-2 wcpos:py-1 wcpos:text-xs wcpos:leading-none wcpos:first:border-l-0',
								zoom === option
									? 'wcpos:bg-wp-admin-theme-color wcpos:text-white'
									: 'wcpos:text-gray-700 wcpos:hover:bg-gray-50',
							)}
						>
							{option}%
						</button>
					))}
				</div>
			</div>
			<div className="wcpos:min-h-0 wcpos:flex-1 wcpos:overflow-auto wcpos:rounded wcpos:border wcpos:border-gray-200 wcpos:bg-gray-100 wcpos:p-4">
				<div
					data-testid="preview-viewport-canvas"
					className={classNames('wcpos:mx-auto', contentClassName)}
					style={{
						width: inverseWidth,
						transform: `scale(${scale})`,
						transformOrigin: 'top left',
					}}
				>
					{children}
				</div>
			</div>
		</div>
	);
}
