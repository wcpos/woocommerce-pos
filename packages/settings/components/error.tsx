import * as React from 'react';

import { FallbackProps } from 'react-error-boundary';

import { t } from '../translations';
import Notice from './notice';

const ErrorFallback = ({ error, resetErrorBoundary }: FallbackProps) => {
	return (
		<Notice status="error" onRemove={resetErrorBoundary}>
			<p>
				{t('Something went wrong', { _tags: 'wp-admin-settings' })}: <code>{error.message}</code>
			</p>
		</Notice>
	);
};

export default ErrorFallback;
