import * as React from 'react';

import { TextInput, type TextInputProps } from './text-input';

export const MoneyInput = React.forwardRef<HTMLInputElement, TextInputProps & { symbol: string }>(
	({ symbol, className, ...props }, ref) => (
		<div className="wcpos:flex wcpos:items-stretch wcpos:rounded-md wcpos:border wcpos:border-gray-300 wcpos:shadow-xs">
			<span className="wcpos:flex wcpos:items-center wcpos:px-2.5 wcpos:bg-gray-50 wcpos:text-gray-500 wcpos:text-sm wcpos:rounded-l-md wcpos:border-r wcpos:border-gray-300">
				{symbol}
			</span>
			<TextInput
				{...props}
				ref={ref}
				inputMode="decimal"
				pattern="\d+(?:\.\d+)?"
				className={`wcpos:rounded-l-none wcpos:border-0 wcpos:shadow-none ${className ?? ''}`}
			/>
		</div>
	)
);
MoneyInput.displayName = 'MoneyInput';
