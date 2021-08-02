import * as React from 'react';
import { TabPanel, CheckboxControl, Notice } from '@wordpress/components';
import { map, get, set } from 'lodash';
import apiFetch from '@wordpress/api-fetch';

export type AccessSettingsProps = Record<
	string,
	{
		name: string;
		capabilities: Record<string, { wc: Record<string, boolean>; wcpos: Record<string, boolean> }>;
	}
>;

interface AccessProps {
	hydrate: import('../settings').HydrateProps;
	setNotice: (args: import('../settings').NoticeProps) => void;
}

// @ts-ignore
function reducer(state, action) {
	const { type, payload } = action;

	switch (type) {
		case 'update':
			const { role, group, cap, value } = payload;
			return set({ ...state }, [role, 'capabilities', group, cap], value);
		// return state;
		default:
			// @ts-ignore
			throw new Error('no action');
	}
}

const Access = ({ hydrate, setNotice }: AccessProps) => {
	const [settings, dispatch] = React.useReducer(reducer, get(hydrate, ['settings', 'access']));
	const silent = React.useRef(true);

	React.useEffect(() => {
		async function updateSettings() {
			const data = await apiFetch({
				path: 'wcpos/v1/settings/access?wcpos=1',
				method: 'POST',
				data: settings,
			}).catch((err) => setNotice({ type: 'error', message: err.message }));

			if (data) {
				silent.current = true;
				dispatch({ type: 'update', payload: data });
			}
		}

		if (silent.current) {
			silent.current = false;
		} else {
			updateSettings();
		}
	}, [settings, dispatch, setNotice]);

	return (
		<>
			<Notice status="warning">
				By default, access to the POS is limited to Administrator and Shop Manager roles. It is
				recommended that you do not change the default settings unless you are fully aware of the
				consequences. For more information please visit http://woopos.com.au/docs/pos-access
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
									<h3>{group}</h3>
									<div className="capabilities">
										{map(caps, (checked, label) => (
											<CheckboxControl
												key={label}
												checked={checked}
												label={label}
												disabled={'administrator' == tab.name && 'read' == label}
												onChange={(value) => {
													dispatch({
														type: 'update',
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
