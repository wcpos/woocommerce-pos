import * as React from 'react';

import classNames from 'classnames';

export interface FormSectionProps {
	title?: string;
	description?: string;
	/**
	 * Optional content rendered to the right of the title row — useful for
	 * inline controls like reset links, counts, or toggles.
	 */
	headerRight?: React.ReactNode;
	/**
	 * When true, renders a bottom border + larger bottom padding so stacked
	 * sections get a visible separator without consumers adding it themselves.
	 */
	divider?: boolean;
	children: React.ReactNode;
	className?: string;
}

export function FormSection({
	title,
	description,
	headerRight,
	divider,
	children,
	className,
}: FormSectionProps) {
	const hasHeading = Boolean(title || description);
	// Treat any renderable ReactNode as present — including numeric `0` (e.g. a "0 selected" count).
	// Only null/undefined/false are skipped, since React renders all three as nothing.
	const hasHeaderRight = headerRight !== null && headerRight !== undefined && headerRight !== false;
	const showHeader = hasHeading || hasHeaderRight;

	return (
		<div
			className={classNames(
				divider ? 'wcpos:border-b wcpos:border-gray-200 wcpos:pb-6' : 'wcpos:pb-4 wcpos:mb-4',
				className
			)}
		>
			{showHeader && (
				<div
					className={classNames(
						'wcpos:mb-3',
						hasHeaderRight &&
							classNames(
								'wcpos:flex wcpos:items-center',
								hasHeading ? 'wcpos:justify-between' : 'wcpos:justify-end'
							)
					)}
				>
					{hasHeading && (
						<div>
							{title && (
								<h3 className="wcpos:text-base wcpos:font-semibold wcpos:text-gray-900 wcpos:m-0">
									{title}
								</h3>
							)}
							{description && (
								<p
									className={classNames(
										'wcpos:text-sm wcpos:text-gray-500',
										title && 'wcpos:mt-1'
									)}
								>
									{description}
								</p>
							)}
						</div>
					)}
					{hasHeaderRight && <div className="wcpos:flex-shrink-0">{headerRight}</div>}
				</div>
			)}
			<div>{children}</div>
		</div>
	);
}
