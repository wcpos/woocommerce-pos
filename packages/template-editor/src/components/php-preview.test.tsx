import { renderToStaticMarkup } from 'react-dom/server';
import { describe, expect, it, vi } from 'vitest';

import {
	PhpPreview,
	decodeLabel,
	getPhpPreviewFrame,
	getPhpPreviewRequestUrl,
} from './php-preview';

vi.mock('@wordpress/api-fetch', () => ({
	default: vi.fn(),
}));

vi.mock('../translations', () => ({
	t: (key: string) => {
		const strings: Record<string, string> = {
			'editor.preview': 'Preview',
			'editor.save_and_preview': 'Save &amp; Preview',
			'editor.open_in_tab': 'Open in tab',
			'editor.php_save_notice': 'PHP templates require saving before the preview updates.',
			'editor.template_preview': 'Template preview',
			'editor.loading_data': 'Loading…',
		};
		return strings[key] ?? key;
	},
}));

describe('PhpPreview', () => {
	it('adds wcpos=1 when fetching the REST preview URL', () => {
		expect(getPhpPreviewRequestUrl('https://example.test/wp-json/wcpos/v1/templates/123/preview')).toBe(
			'https://example.test/wp-json/wcpos/v1/templates/123/preview?wcpos=1',
		);
		expect(getPhpPreviewRequestUrl('https://example.test/wp-json/wcpos/v1/templates/123/preview?order_id=latest')).toBe(
			'https://example.test/wp-json/wcpos/v1/templates/123/preview?order_id=latest&wcpos=1',
		);
	});

	it('decodes escaped ampersands before React renders the button label', () => {
		expect(decodeLabel('Save &amp; Preview')).toBe('Save & Preview');

		const markup = renderToStaticMarkup(
			<PhpPreview previewUrl="https://example.test/wp-json/wcpos/v1/templates/123/preview" />,
		);

		expect(markup).toContain('Save &amp; Preview');
		expect(markup).not.toContain('Save &amp;amp; Preview');
	});

	it('uses preview_html as iframe srcDoc instead of iframing the REST JSON endpoint', () => {
		expect(getPhpPreviewFrame({ preview_html: '<p>Preview HTML</p>' })).toEqual({
			src: null,
			srcDoc: '<p>Preview HTML</p>',
		});
		expect(getPhpPreviewFrame({ preview_url: 'https://example.test/receipt/1' })).toEqual({
			src: 'https://example.test/receipt/1',
			srcDoc: null,
		});
	});
});
