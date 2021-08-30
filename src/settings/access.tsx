import * as React from 'react';
import { map, get } from 'lodash';
import classNames from 'classnames';
import useSettingsApi from '../hooks/use-settings-api';
import Notice from '../components/notice';
import Checkbox from '../components/checkbox';

export type AccessSettingsProps = Record<
	string,
	{
		name: string;
		capabilities: Record<string, { wc: Record<string, boolean>; wcpos: Record<string, boolean> }>;
	}
>;

interface AccessProps {
	hydrate: import('../settings').HydrateProps;
}

const Access = ({ hydrate }: AccessProps) => {
	const { settings, dispatch } = useSettingsApi('access', get(hydrate, ['settings', 'access']));
	const [selected, setSelected] = React.useState('administrator');

	return (
		<>
			<Notice status="info" isDismissible={false}>
				By default, access to the POS is limited to Administrator, Shop Manager and Cashier roles.
				It is recommended that you <strong>do not change</strong> the default settings unless you
				are fully aware of the consequences. For more information please visit 
				<a href="https://wcpos.com/docs/pos-access" target="_blank" rel="noreferrer">
					https://wcpos.com/docs/pos-access
				</a>
				.
			</Notice>
			<div className="sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
				<div className="">
					<ul>
						{map(settings, (role, id) => (
							<li
								key={id}
								className={classNames(
									'p-4 mb-1 rounded font-medium text-sm hover:bg-gray-100 cursor-pointer',
									id == selected &&
										'bg-wp-admin-theme-color-lightest hover:bg-wp-admin-theme-color-lightest'
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
					{map(settings[selected].capabilities, (caps, group) => {
						return (
							<div key={group}>
								<h2 className="text-base">
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
										const disabled = 'administrator' == selected && 'read' == label;
										return (
											<label
												key={label}
												className={classNames('block mb-1', disabled ? 'cursor-not-allowed' : '')}
											>
												<Checkbox
													key={label}
													checked={checked}
													disabled={disabled}
													onChange={(value) => {
														dispatch({
															type: 'update-capabilities',
															payload: { cap: label, value, group, role: selected },
														});
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
