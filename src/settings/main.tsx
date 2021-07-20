import * as React from 'react';
import { __ } from '@wordpress/i18n';
import {
	BaseControl,
	Button,
	ExternalLink,
	Modal,
	PanelBody,
	PanelRow,
	Placeholder,
	Spinner,
	ToggleControl,
} from '@wordpress/components';

const Main = () => {
	return (
		<PanelBody title="PanelBody">
			<PanelRow>
				<ToggleControl label="Toggle" />
			</PanelRow>
			<PanelRow>
				<BaseControl label="Base Control" id="base-control">
					<input type="text" placeholder="input" />
					<Button
						isPrimary
						isLarge
						onClick={() => {
							console.log('click');
						}}
					>
						{__('Save')}
					</Button>
					<ExternalLink href="https://wcpos.com">{__('Link')}</ExternalLink>
				</BaseControl>
			</PanelRow>
		</PanelBody>
	);
};

export default Main;
