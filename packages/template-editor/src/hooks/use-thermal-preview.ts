import { useState, useEffect, useRef } from 'react';
import { renderThermalPreview } from '@wcpos/thermal-utils';

export function useThermalPreview(
	template: string,
	data: Record<string, unknown>,
	debounceMs = 300,
) {
	const [html, setHtml] = useState('');
	const timeoutRef = useRef<ReturnType<typeof setTimeout>>();

	useEffect(() => {
		if (timeoutRef.current) {
			clearTimeout(timeoutRef.current);
		}

		timeoutRef.current = setTimeout(() => {
			try {
				const rendered = renderThermalPreview(template, data);
				setHtml(rendered);
			} catch {
				setHtml(
					'<div style="color:red;padding:16px;">Thermal template rendering error. Check your XML syntax.</div>',
				);
			}
		}, debounceMs);

		return () => {
			if (timeoutRef.current) {
				clearTimeout(timeoutRef.current);
			}
		};
	}, [template, data, debounceMs]);

	return html;
}
