import * as React from 'react';

import classNames from 'classnames';

interface FormSectionProps {
	title?: string;
	description?: string;
	children: React.ReactNode;
	className?: string;
}

export function FormSection({ title, description, children, className }: FormSectionProps) {
	return (
		<div
			className={classNames(
				'wcpos:border-b wcpos:border-gray-200 wcpos:pb-6 wcpos:mb-6 last:wcpos:border-b-0 last:wcpos:pb-0 last:wcpos:mb-0',
				className
			)}
		>
			{title && (
				<div className="wcpos:mb-4">
					<h3 className="wcpos:text-base wcpos:font-semibold wcpos:text-gray-900">
						{title}
					</h3>
					{description && (
						<p className="wcpos:mt-1 wcpos:text-sm wcpos:text-gray-500">{description}</p>
					)}
				</div>
			)}
			<div className="wcpos:space-y-1">{children}</div>
		</div>
	);
}
