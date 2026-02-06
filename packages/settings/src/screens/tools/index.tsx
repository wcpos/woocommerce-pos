import * as React from 'react';

import { ToggleControl, CheckboxControl } from '@wordpress/components';
import Label from '../../components/label';
import useSettingsApi from '../../hooks/use-settings-api';
import { t } from '../../translations';

export interface ToolsSettingsProps {
	use_jwt_as_param: boolean;
}

const Tools = () => {
	const { data, mutate } = useSettingsApi('tools');

	return (
		<div className="wcpos:px-4 wcpos:py-5 wcpos:sm:grid wcpos:sm:grid-cols-3 wcpos:sm:gap-4">
			<div></div>
			<div className="wcpos:col-span-2">
				<ToggleControl
					label={
						<Label
							tip={t('settings.authorize_via_url_param_tip')}
						>
							{t('settings.authorize_via_url_param')}
						</Label>
					}
					checked={!!data?.use_jwt_as_param}
					onChange={(use_jwt_as_param: boolean) => {
						mutate({ use_jwt_as_param });
					}}
				/>
			</div>
			<div></div>
		</div>
	);
};

export default Tools;
