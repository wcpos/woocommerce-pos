import * as React from 'react';
import apiFetch from '@wordpress/api-fetch';
import { find, set } from 'lodash';
import useNotices from './use-notices';

// @ts-ignore
function reducer(state, action) {
	const { type, payload } = action;

	switch (type) {
		case 'update':
			return { ...state, ...payload };
		case 'sync':
			return payload;
		case 'update-gateway':
			if (payload.id) {
				const gateway = find(state.gateways, { id: payload.id });
				Object.assign(gateway, payload);
			}
			return { ...state };
		case 'update-capabilities':
			const { role, group, cap, value } = payload;
			return set({ ...state }, [role, 'capabilities', group, cap], value);
		default:
			throw new Error('no action');
	}
}

const useSettingsApi = (id: string, initialSettings: Record<string, any>) => {
	const [settings, dispatch] = React.useReducer(reducer, initialSettings);
	const { setNotice, setSnackbar } = useNotices();
	const silent = React.useRef(true);
	const isSaving = React.useRef(false);

	React.useEffect(() => {
		async function updateSettings() {
			isSaving.current = true;
			setSnackbar({ message: 'Saving', timeout: false });
			const data = await apiFetch({
				path: `wcpos/v1/settings/${id}?wcpos=1`,
				method: 'POST',
				data: settings,
			})
				.catch((err) => setNotice({ type: 'error', message: err.message }))
				.finally(() => {
					isSaving.current = false;
				});

			if (data) {
				silent.current = true;
				dispatch({ type: 'sync', payload: data });
				setSnackbar({ message: 'Saved', timeout: true });
			}
		}

		if (silent.current) {
			silent.current = false;
		} else {
			if (!isSaving.current) {
				updateSettings();
			}
		}
	}, [id, settings, dispatch, setNotice, setSnackbar]);

	return { settings, dispatch };
};

export default useSettingsApi;
