import * as React from 'react';

import MoreVerticalIcon from '../../assets/more-vertical-icon.svg';

interface KebabMenuTriggerProps {
	label: string;
	testId?: string;
}

export function KebabMenuTrigger({ label, testId }: KebabMenuTriggerProps) {
	return (
		<span
			className="wcpos:inline-flex wcpos:items-center wcpos:justify-center wcpos:w-7 wcpos:h-7 wcpos:rounded-md wcpos:text-gray-500 wcpos:hover:bg-gray-100 wcpos:hover:text-gray-700 wcpos:cursor-pointer"
			data-testid={testId}
			aria-label={label}
		>
			<MoreVerticalIcon className="wcpos:w-4 wcpos:h-4 wcpos:fill-current" />
		</span>
	);
}
