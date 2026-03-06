import { useCallback, useRef } from 'react';

export function useContentSync() {
	const textareaRef = useRef<HTMLTextAreaElement | null>(null);

	const getTextarea = useCallback(() => {
		if (!textareaRef.current) {
			textareaRef.current = document.getElementById('wcpos-template-content') as HTMLTextAreaElement;
		}
		return textareaRef.current;
	}, []);

	const sync = useCallback((content: string) => {
		const textarea = getTextarea();
		if (textarea) {
			textarea.value = content;
		}
	}, [getTextarea]);

	return sync;
}
