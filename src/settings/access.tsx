import * as React from 'react';
import { TabPanel, CheckboxControl } from '@wordpress/components';
import { map, get } from 'lodash';
import useSettingsApi from '../hooks/use-settings-api';
import Notice from '../components/notice';

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

	return (
		<>
			<Notice status="warning" isDismissible={false}>
				By default, access to the POS is limited to Administrator, Shop Manager and Cashier roles.
				It is recommended that you <strong>do not change</strong> the default settings unless you
				are fully aware of the consequences. For more information please visit
				http://woopos.com.au/docs/pos-access
			</Notice>
			<TabPanel
				className="woocommerce-pos-settings-access-tabs"
				activeClass="active-tab"
				orientation="vertical"
				tabs={map(settings, (role, id) => ({
					name: id,
					title: role.name,
					capabilities: role.capabilities,
				}))}
			>
				{(tab) => (
					<>
						{map(tab.capabilities, (caps, group) => {
							return (
								<div key={group}>
									<h3>
										{
											{
												wcpos: 'WooCommerce POS',
												wc: 'WooCommerce',
												wp: 'WordPress',
											}[group]
										}
									</h3>
									<div className="capabilities">
										{map(caps, (checked, label) => (
											<CheckboxControl
												key={label}
												checked={checked}
												label={label}
												disabled={'administrator' == tab.name && 'read' == label}
												onChange={(value) => {
													dispatch({
														type: 'update-capabilities',
														payload: { cap: label, value, group, role: tab.name },
													});
												}}
											/>
										))}
									</div>
								</div>
							);
						})}
					</>
				)}
			</TabPanel>
		</>
	);
};

export default Access;
