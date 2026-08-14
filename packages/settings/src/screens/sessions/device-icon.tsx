import * as React from 'react';

import classNames from 'classnames';

import DeviceLaptop from '../../../assets/device-laptop.svg';
import DeviceSmartphone from '../../../assets/device-smartphone.svg';
import DeviceTablet from '../../../assets/device-tablet.svg';
import DeviceWeb from '../../../assets/device-web.svg';

interface DeviceInfo {
	device_type: string;
	app_type?: string;
}

interface DeviceIconProps {
	deviceInfo: DeviceInfo;
	className?: string;
}

const ICONS = {
	smartphone: DeviceSmartphone,
	tablet: DeviceTablet,
	laptop: DeviceLaptop,
	web: DeviceWeb,
} as const;

function pickIconKey({ app_type, device_type }: DeviceInfo): keyof typeof ICONS {
	switch (app_type) {
		case 'ios_app':
		case 'android_app':
			return device_type === 'mobile' ? 'smartphone' : 'tablet';
		case 'electron_app':
			return 'laptop';
		case 'web':
		default:
			if (device_type === 'mobile') return 'smartphone';
			if (device_type === 'tablet') return 'tablet';
			return 'web';
	}
}

function DeviceIcon({ deviceInfo, className }: DeviceIconProps) {
	const Icon = ICONS[pickIconKey(deviceInfo)];
	return (
		<Icon
			className={classNames('wcpos:fill-current', className)}
			aria-hidden="true"
			focusable="false"
		/>
	);
}

export default DeviceIcon;
