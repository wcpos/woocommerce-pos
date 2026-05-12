import { useState, useEffect, useRef } from 'react';

import { renderLogiclessPreview } from '@wcpos/thermal-utils';

export function useMustachePreview(template: string, data: Record<string, unknown>, debounceMs = 300) {
	const [html, setHtml] = useState('');
	const timeoutRef = useRef<ReturnType<typeof setTimeout>>();

	useEffect(() => {
		if (timeoutRef.current) {
			clearTimeout(timeoutRef.current);
		}

		timeoutRef.current = setTimeout(() => {
			setHtml(renderLogiclessPreview(template, data));
		}, debounceMs);

		return () => {
			if (timeoutRef.current) {
				clearTimeout(timeoutRef.current);
			}
		};
	}, [template, data, debounceMs]);

	return html;
}
