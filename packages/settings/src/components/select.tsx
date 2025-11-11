import * as React from 'react';

import { Listbox, Transition } from '@headlessui/react';
import classNames from 'classnames';

import Check from '../../assets/check.svg';
import ChevronDown from '../../assets/chevron-down.svg';

export interface OptionProps extends Record<string, unknown> {
	value: string | number;
	label: string;
}

interface SelectProps {
	value: string | number;
	options: OptionProps[];
	onChange: (value: OptionProps) => void;
}

function Select({ value, options, onChange }: SelectProps) {
	/**
	 *
	 */
	const selected = React.useMemo(
		() => options.find((option) => option.value === value),
		[options, value]
	);

	return (
		<Listbox value={selected} onChange={onChange}>
			<div className="wcpos:relative">
				<Listbox.Button
					className={classNames([
						'wcpos:relative',
						'wcpos:w-full',
						'wcpos:cursor-default',
						'wcpos:rounded-md',
						'wcpos:bg-white',
						'wcpos:border',
						'wcpos:border-gray-300',
						'wcpos:py-1',
						'wcpos:pl-3',
						'wcpos:pr-10',
						'wcpos:text-left',
						// 'wcpos:focus:outline-none',
						// 'focus-visible:wcpos:border-indigo-500',
						// 'focus-visible:wcpos:ring-2',
						// 'focus-visible:wcpos:ring-white',
						// 'focus-visible:wcpos:ring-opacity-75',
						// 'focus-visible:wcpos:ring-offset-2',
						// 'focus-visible:wcpos:ring-offset-orange-300',
						'wcpos:shadow-sm',
						'wcpos:focus:ring-indigo-500',
						'wcpos:focus:border-wp-admin-theme-color',
						'wcpos:sm:text-sm',
					])}
				>
					<span className="wcpos:block wcpos:truncate">{selected?.label || ''}</span>
					<span
						className={classNames([
							'wcpos:pointer-events-none',
							'wcpos:absolute',
							'wcpos:inset-y-0',
							'wcpos:right-0',
							'wcpos:flex',
							'wcpos:items-center',
							'wcpos:pr-2',
						])}
					>
						<ChevronDown className="h-5 w-5 text-gray-400" aria-hidden="true" />
					</span>
				</Listbox.Button>
				<Transition
					as={React.Fragment}
					leave="transition ease-in duration-100"
					leaveFrom="opacity-100"
					leaveTo="opacity-0"
				>
					<Listbox.Options
						className={classNames([
							'wcpos:absolute',
							'wcpos:z-10',
							'wcpos:mt-1',
							'wcpos:max-h-60',
							'wcpos:w-full',
							'wcpos:overflow-auto',
							'wcpos:rounded-md',
							'wcpos:bg-white',
							'wcpos:py-1',
							'wcpos:text-base',
							'wcpos:shadow-lg',
							'wcpos:ring-1',
							'wcpos:ring-black',
							'wcpos:ring-opacity-5',
							'wcpos:focus:outline-none',
							'wcpos:sm:text-sm',
						])}
					>
						{options.map((option, idx) => (
							<Listbox.Option
								key={idx}
								className={({ active }) =>
									classNames([
										'wcpos:relative',
										'wcpos:cursor-default',
										'wcpos:select-none',
										'wcpos:py-1',
										'wcpos:pl-10',
										'wcpos:pr-4',
										'wcpos:m-0',
										{ 'wcpos:bg-wp-admin-theme-color-lightest': active },
										{ 'wcpos:text-wp-admin-theme-color-darker-10': active },
										{ 'wcpos:text-gray-900': !active },
									])
								}
								value={option}
							>
								{({ selected }) => (
									<>
										<span
											className={`wcpos:block wcpos:truncate ${
												selected ? 'wcpos:font-medium' : 'wcpos:font-normal'
											}`}
										>
											{option.label}
										</span>
										{selected ? (
											<span
												className={classNames([
													'wcpos:absolute',
													'wcpos:inset-y-0',
													'wcpos:left-0',
													'wcpos:flex',
													'wcpos:items-center',
													'wcpos:pl-3',
													'wcpos:text-wp-admin-theme-color-darker-10',
												])}
											>
												<Check className="wcpos:h-5 wcpos:w-5" fill="#006ba1" aria-hidden="true" />
											</span>
										) : null}
									</>
								)}
							</Listbox.Option>
						))}
					</Listbox.Options>
				</Transition>
			</div>
		</Listbox>
	);
}

export default Select;
