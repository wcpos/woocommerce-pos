import * as React from 'react';

import { Field, Label, Description, Switch } from '@headlessui/react';
import classNames from 'classnames';

interface ToggleProps {
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
	return (
		<Field>
			<div className={classNames('wcpos:flex wcpos:items-center wcpos:gap-3', className)}>
				<Switch
					checked={checked}
					onChange={onChange}
					disabled={disabled}
					className={classNames(
						'wcpos:relative wcpos:inline-flex wcpos:h-5 wcpos:w-9 wcpos:shrink-0 wcpos:cursor-pointer wcpos:rounded-full wcpos:border-2 wcpos:border-transparent wcpos:transition-colors wcpos:duration-200 wcpos:ease-in-out focus:wcpos:outline-none focus:wcpos:ring-2 focus:wcpos:ring-wp-admin-theme-color focus:wcpos:ring-offset-2',
						checked ? 'wcpos:bg-wp-admin-theme-color' : 'wcpos:bg-gray-200',
						disabled && 'wcpos:opacity-50 wcpos:cursor-not-allowed'
					)}
				>
					<span
						className={classNames(
							'wcpos:pointer-events-none wcpos:inline-block wcpos:h-4 wcpos:w-4 wcpos:transform wcpos:rounded-full wcpos:bg-white wcpos:shadow wcpos:ring-0 wcpos:transition wcpos:duration-200 wcpos:ease-in-out',
							checked ? 'wcpos:translate-x-4' : 'wcpos:translate-x-0'
						)}
					/>
				</Switch>
				{label && (
					<div>
						<Label className="wcpos:text-sm wcpos:font-medium wcpos:text-gray-900 wcpos:cursor-pointer">
							{label}
						</Label>
						{description && (
							<Description className="wcpos:text-sm wcpos:text-gray-500">{description}</Description>
						)}
					</div>
				)}
			</div>
		</Field>
	);
}
