import * as React from 'react';

import classNames from 'classnames';
import { map } from 'lodash';

import Checkbox from '../../components/checkbox';
import Notice from '../../components/notice';
import useSettingsApi from '../../hooks/use-settings-api';

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

	return (
		<>
			<Notice status="info" isDismissible={false}>
				By default, access to the POS is limited to Administrator, Shop Manager and Cashier roles.
				It is recommended that you <strong>do not change</strong> the default settings unless you
				are fully aware of the consequences. For more information please visit 
				<a href="https://docs.wcpos.com/pos-access" target="_blank" rel="noreferrer">
					https://docs.wcpos.com/pos-access
				</a>
				.
			</Notice>
			<div className="sm:wcpos-grid sm:wcpos-grid-cols-3 sm:wcpos-gap-4 sm:wcpos-px-6">
				<div className="">
					<ul>
						{map(data, (role, id) => (
							<li
								key={id}
								className={classNames(
									'wcpos-p-4 wcpos-mb-1 wcpos-rounded wcpos-font-medium wcpos-text-sm hover:wcpos-bg-gray-100 wcpos-cursor-pointer',
									id == selected &&
										'wcpos-bg-wp-admin-theme-color-lightest hover:wcpos-bg-wp-admin-theme-color-lightest'
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
					{map(data[selected].capabilities, (caps, group) => {
						return (
							<div key={group}>
								<h2 className="wcpos-text-base">
									{
										{
											wcpos: 'WooCommerce POS',
											wc: 'WooCommerce',
											wp: 'WordPress',
										}[group]
									}
								</h2>
								<div>
									{map(caps, (checked, label) => {
										const disabled = selected === 'administrator' && label === 'read';
										return (
											<label
												key={label}
												className={classNames(
													'wcpos-block wcpos-mb-1',
													disabled ? 'wcpos-cursor-not-allowed' : ''
												)}
											>
												<Checkbox
													key={label}
													checked={checked}
													disabled={disabled}
													onChange={(value) => {
														mutate({ cap: label, value, group, role: selected });
													}}
												/>
												{label}
											</label>
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
