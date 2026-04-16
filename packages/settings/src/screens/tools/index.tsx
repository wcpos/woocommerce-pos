import * as React from 'react';

import { FormRow, FormSection } from '../../components/form';
import Label from '../../components/label';
import { Toggle } from '../../components/ui';
import useSettingsApi from '../../hooks/use-settings-api';
import { t } from '../../translations';

export interface ToolsSettingsProps {
	use_jwt_as_param: boolean;
	tracking_consent: 'undecided' | 'allowed' | 'denied';
}

function Tools() {
	const { data, mutate } = useSettingsApi('tools');
	const trackingEnabled = data?.tracking_consent === 'allowed';

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
			<FormRow>
				<Toggle
					checked={trackingEnabled}
					onChange={(enabled: boolean) => {
						mutate({ tracking_consent: enabled ? 'allowed' : 'denied' });
					}}
					label={t('settings.allow_anonymous_usage_data')}
					description={t('settings.allow_anonymous_usage_data_tip')}
				/>
			</FormRow>
		</FormSection>
	);
}

export default Tools;
