import * as React from 'react';

import classNames from 'classnames';

interface FormRowProps {
	label?: string;
	htmlFor?: string;
	description?: string;
	children: React.ReactNode;
	className?: string;
}

export function FormRow({ label, htmlFor, description, children, className }: FormRowProps) {
	return (
		<div
			className={classNames(
				'wcpos:flex wcpos:flex-col wcpos:sm:flex-row wcpos:sm:items-start wcpos:gap-1 wcpos:sm:gap-4 wcpos:py-3',
				className
			)}
		>
			{label && (
				<div className="wcpos:sm:w-[30%] wcpos:sm:max-w-[200px] wcpos:shrink-0 wcpos:sm:pt-1">
					<label
						htmlFor={htmlFor}
						className="wcpos:text-sm wcpos:font-medium wcpos:text-gray-700"
					>
						{label}
					</label>
				</div>
			)}
			<div className="wcpos:flex-1 wcpos:min-w-0">
				{children}
				{description && (
					<p className="wcpos:mt-1 wcpos:text-sm wcpos:text-gray-500">{description}</p>
				)}
			</div>
		</div>
	);
}
