import * as React from 'react';

import classNames from 'classnames';

export interface ToggleProps {
	checked: boolean;
	onChange: (checked: boolean) => void;
	label?: string;
	description?: string;
	disabled?: boolean;
	className?: string;
}

export function Toggle({
	checked,
	onChange,
	label,
	description,
	disabled,
	className,
}: ToggleProps) {
	const generatedId = React.useId();
	const labelId = label ? `${generatedId}-label` : undefined;
	const descriptionId = description ? `${generatedId}-description` : undefined;

	return (
		<div
			className={classNames(
				'wcpos:flex wcpos:items-center wcpos:gap-3',
				className
			)}
		>
			<button
				type="button"
				role="switch"
				aria-checked={checked}
				aria-labelledby={labelId}
				aria-describedby={descriptionId}
				disabled={disabled}
				onClick={() => onChange(!checked)}
				className={classNames(
					'wcpos:relative wcpos:inline-flex wcpos:h-5 wcpos:w-9 wcpos:shrink-0 wcpos:rounded-full wcpos:border-2 wcpos:border-transparent wcpos:transition-colors wcpos:duration-200 wcpos:ease-in-out wcpos:focus:outline-none wcpos:focus:ring-2 wcpos:focus:ring-wp-admin-theme-color wcpos:focus:ring-offset-2',
					checked ? 'wcpos:bg-wp-admin-theme-color' : 'wcpos:bg-gray-200',
					disabled
						? 'wcpos:opacity-50 wcpos:cursor-not-allowed'
						: 'wcpos:cursor-pointer'
				)}
			>
				<span
					className={classNames(
						'wcpos:pointer-events-none wcpos:inline-block wcpos:h-4 wcpos:w-4 wcpos:transform wcpos:rounded-full wcpos:bg-white wcpos:shadow wcpos:ring-0 wcpos:transition wcpos:duration-200 wcpos:ease-in-out',
						checked ? 'wcpos:translate-x-4' : 'wcpos:translate-x-0'
					)}
				/>
			</button>
			{label && (
				<div>
					<label
						id={labelId}
						className="wcpos:text-sm wcpos:font-medium wcpos:text-gray-900 wcpos:cursor-pointer"
					>
						{label}
					</label>
					{description && (
						<p id={descriptionId} className="wcpos:text-sm wcpos:text-gray-500">
							{description}
						</p>
					)}
				</div>
			)}
		</div>
	);
}
