import * as React from 'react';

import Label from '../../components/label';
import { Toggle } from '../../components/ui';
import { FormRow, FormSection } from '../../components/form';
import useSettingsApi from '../../hooks/use-settings-api';
import { t } from '../../translations';

export interface ToolsSettingsProps {
	use_jwt_as_param: boolean;
}

const Tools = () => {
	const { data, mutate } = useSettingsApi('tools');

	return (
		<FormSection>
			<FormRow>
				<Label tip={t('settings.authorize_via_url_param_tip')}>
					<Toggle
						checked={!!data?.use_jwt_as_param}
						onChange={(use_jwt_as_param: boolean) => {
							mutate({ use_jwt_as_param });
						}}
						label={t('settings.authorize_via_url_param')}
					/>
				</Label>
			</FormRow>
		</FormSection>
	);
};

export default Tools;
