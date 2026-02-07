import * as React from 'react';

import { Listbox, ListboxButton, ListboxOptions, ListboxOption } from '@headlessui/react';
import classNames from 'classnames';

import Check from '../../../assets/check.svg';
import ChevronDown from '../../../assets/chevron-down.svg';

export interface OptionProps extends Record<string, unknown> {
	value: string | number;
	label: string;
}

interface SelectProps {
	value: string | number;
	options: OptionProps[];
	onChange: (value: OptionProps) => void;
	disabled?: boolean;
	className?: string;
}

export function Select({ value, options, onChange, disabled, className }: SelectProps) {
	const selected = React.useMemo(
		() => options.find((option) => option.value === value),
		[options, value]
	);

	return (
		<Listbox value={selected} onChange={onChange} disabled={disabled}>
			<div className={classNames('wcpos:relative', className)}>
				<ListboxButton
					className={classNames(
						'wcpos:relative wcpos:w-full wcpos:cursor-default wcpos:rounded-md wcpos:bg-white wcpos:border wcpos:border-gray-300 wcpos:py-1 wcpos:pl-3 wcpos:pr-10 wcpos:text-left wcpos:shadow-xs wcpos:sm:text-sm',
						'focus:wcpos:outline-none focus:wcpos:ring-2 focus:wcpos:ring-wp-admin-theme-color focus:wcpos:border-wp-admin-theme-color',
						disabled && 'wcpos:opacity-50 wcpos:cursor-not-allowed'
					)}
				>
					<span className="wcpos:block wcpos:truncate">{selected?.label || ''}</span>
					<span className="wcpos:pointer-events-none wcpos:absolute wcpos:inset-y-0 wcpos:right-0 wcpos:flex wcpos:items-center wcpos:pr-2">
						<ChevronDown className="wcpos:h-5 wcpos:w-5 wcpos:text-gray-400" aria-hidden="true" />
					</span>
				</ListboxButton>
				<ListboxOptions
					transition
					className={classNames(
						'wcpos:absolute wcpos:z-10 wcpos:mt-1 wcpos:max-h-60 wcpos:w-full wcpos:overflow-auto wcpos:rounded-md wcpos:bg-white wcpos:py-1 wcpos:text-base wcpos:shadow-lg wcpos:ring-1 wcpos:ring-black/5 wcpos:focus:outline-none wcpos:sm:text-sm',
						'wcpos:transition wcpos:duration-100 wcpos:ease-in',
						'data-[closed]:wcpos:opacity-0'
					)}
				>
					{options.map((option, idx) => (
						<ListboxOption
							key={idx}
							className={classNames(
								'wcpos:relative wcpos:cursor-default wcpos:select-none wcpos:py-1 wcpos:pl-10 wcpos:pr-4 wcpos:m-0',
								'data-[focus]:wcpos:bg-wp-admin-theme-color-lightest data-[focus]:wcpos:text-wp-admin-theme-color-darker-10',
								'wcpos:text-gray-900'
							)}
							value={option}
						>
							{({ selected }) => (
								<>
									<span
										className={classNames(
											'wcpos:block wcpos:truncate',
											selected ? 'wcpos:font-medium' : 'wcpos:font-normal'
										)}
									>
										{option.label}
									</span>
									{selected && (
										<span className="wcpos:absolute wcpos:inset-y-0 wcpos:left-0 wcpos:flex wcpos:items-center wcpos:pl-3 wcpos:text-wp-admin-theme-color-darker-10">
											<Check
												className="wcpos:h-5 wcpos:w-5"
												fill="#006ba1"
												aria-hidden="true"
											/>
										</span>
									)}
								</>
							)}
						</ListboxOption>
					))}
				</ListboxOptions>
			</div>
		</Listbox>
	);
}
