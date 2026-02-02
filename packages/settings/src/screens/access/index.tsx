import * as React from 'react';

import { CheckboxControl } from '@wordpress/components';
import classNames from 'classnames';
import { map, get } from 'lodash';

import Notice from '../../components/notice';
import useSettingsApi from '../../hooks/use-settings-api';
import { Trans } from '../../translations';

export type AccessSettingsProps = Record<
	string,
	{
		name: string;
		capabilities: Record<string, { wc: Record<string, boolean>; wcpos: Record<string, boolean> }>;
	}
>;

const Access = () => {
	const { data, mutate } = useSettingsApi('access');
	const [selected, setSelected] = React.useState('administrator');
	const capabilities = get(data, [selected, 'capabilities'], null);

	return (
		<>
			<div className="wcpos:p-4">
				<Notice status="info" isDismissible={false}>
					<Trans
						i18nKey="By default, access to the POS is limited to Administrator, Shop Manager and Cashier roles. It is recommended that you <1>do not change</1> the default settings unless you are fully aware of the consequences."
						components={[, <strong />]}
					/>
					&nbsp;
					<Trans
						i18nKey="For more information please visit the <1>documentation</1>."
						components={[
							,
							<a href="https://docs.wcpos.com/pos-access" target="_blank" rel="noreferrer" />,
						]}
					/>
				</Notice>
			</div>
			<div className="wcpos:sm:grid wcpos:sm:grid-cols-3 wcpos:sm:gap-4 wcpos:p-4 wcpos:pt-0">
				<div className="">
					<ul>
						{map(data, (role: { name: string }, id) => (
							<li
								key={id}
								className={classNames(
									'wcpos:p-4 wcpos:mb-1 wcpos:rounded wcpos:font-medium wcpos:text-sm wcpos:hover:bg-gray-100 wcpos:cursor-pointer',
									id === selected &&
										'wcpos:bg-wp-admin-theme-color-lightest wcpos:hover:bg-wp-admin-theme-color-lightest'
								)}
								onClick={() => {
									setSelected(id);
								}}
							>
								{role.name}
							</li>
						))}
					</ul>
				</div>
				<div className="">
					{capabilities &&
						map(capabilities, (caps, group) => {
							return (
								<div key={group}>
									<h2 className="wcpos:text-base">
										{
											{
												wcpos: 'WCPOS',
												wc: 'WooCommerce',
												wp: 'WordPress',
											}[group]
										}
									</h2>
									<div>
										{map(caps, (checked, label) => {
											const disabled = selected === 'administrator' && label === 'read';
											return (
												<CheckboxControl
													key={label}
													label={label}
													checked={checked}
													disabled={disabled}
													onChange={(value) => {
														mutate({
															[selected]: {
																capabilities: {
																	[group]: {
																		[label]: value,
																	},
																},
															},
														});
													}}
												/>
											);
										})}
									</div>
								</div>
							);
						})}
				</div>
			</div>
		</>
	);
};

export default Access;
