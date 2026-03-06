import { useState, useEffect, useRef } from 'react';
import Mustache from 'mustache';

export function useMustachePreview(template: string, data: Record<string, unknown>, debounceMs = 300) {
	const [html, setHtml] = useState('');
	const timeoutRef = useRef<ReturnType<typeof setTimeout>>();

	useEffect(() => {
		if (timeoutRef.current) {
			clearTimeout(timeoutRef.current);
		}

		timeoutRef.current = setTimeout(() => {
			try {
				const rendered = Mustache.render(template, data);
				setHtml(rendered);
			} catch (error) {
				console.warn('Mustache rendering error:', error);
				setHtml('<div style="color:red;padding:16px;">Template rendering error. Check your Mustache syntax.</div>');
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
