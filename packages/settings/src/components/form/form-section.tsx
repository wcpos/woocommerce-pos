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
		<div className={classNames('wcpos:pb-4 wcpos:mb-4', className)}>
			{title && (
				<div className="wcpos:mb-3">
					<h3 className="wcpos:text-base wcpos:font-semibold wcpos:text-gray-900">{title}</h3>
					{description && (
						<p className="wcpos:mt-1 wcpos:text-sm wcpos:text-gray-500">{description}</p>
					)}
				</div>
			)}
			<div>{children}</div>
		</div>
	);
}
