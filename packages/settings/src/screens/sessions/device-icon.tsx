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

function pickIcon({ app_type, device_type }: DeviceInfo) {
	switch (app_type) {
		case 'ios_app':
		case 'android_app':
			return device_type === 'mobile' ? DeviceSmartphone : DeviceTablet;
		case 'electron_app':
			return DeviceLaptop;
		case 'web':
		default:
			if (device_type === 'mobile') return DeviceSmartphone;
			if (device_type === 'tablet') return DeviceTablet;
			return DeviceWeb;
	}
}

function DeviceIcon({ deviceInfo, className }: DeviceIconProps) {
	const Icon = pickIcon(deviceInfo);
	return (
		<Icon
			className={classNames('wcpos:fill-current', className)}
			aria-hidden="true"
			focusable="false"
		/>
	);
}

export default DeviceIcon;
