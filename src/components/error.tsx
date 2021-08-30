import * as React from 'react';
import { FallbackProps } from 'react-error-boundary';
import Notice from './notice';

const ErrorFallback = ({ error, resetErrorBoundary }: FallbackProps) => {
	return (
		<Notice status="error" onRemove={resetErrorBoundary}>
			<p>
				Something went wrong: <code>{error.message}</code>
			</p>
		</Notice>
	);
};

export default ErrorFallback;
