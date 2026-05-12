import { describe, expect, it } from 'vitest';
import { findEnclosingPair, readTagsFromText } from './mustache-section-matcher';

describe('mustacheSectionMatcher helpers', () => {
	it('reads opening, closing and inverted section tags', () => {
		const text = '{{#order.items}}\n  {{name}}\n{{/order.items}}\n{{^customer.tax_id}}none{{/customer.tax_id}}';
		const tags = readTagsFromText(text);

		expect(tags).toHaveLength(4);
		expect(tags[0].kind).toBe('#');
		expect(tags[0].name).toBe('order.items');
		expect(tags[1].kind).toBe('/');
		expect(tags[2].kind).toBe('^');
		expect(tags[3].kind).toBe('/');
	});

	it('finds the matching close when cursor is on the open tag', () => {
		const text = '{{#lines}}{{name}}{{/lines}}';
		const tags = readTagsFromText(text);
		// Cursor in middle of {{#lines}} (chars 0..10)
		const pair = findEnclosingPair(tags, 4);

		expect(pair).not.toBeNull();
		expect(pair![0].kind).toBe('#');
		expect(pair![1].kind).toBe('/');
		expect(pair![1].name).toBe('lines');
	});

	it('finds the matching open when cursor is on the close tag', () => {
		const text = '{{#lines}}{{name}}{{/lines}}';
		const tags = readTagsFromText(text);
		// {{/lines}} starts at index 18
		const pair = findEnclosingPair(tags, 22);

		expect(pair).not.toBeNull();
		expect(pair![0].kind).toBe('#');
		expect(pair![0].from).toBe(0);
	});

	it('respects nesting when finding the matching close', () => {
		const text = '{{#a}}{{#a}}{{/a}}{{/a}}';
		const tags = readTagsFromText(text);
		const outer = findEnclosingPair(tags, 2);

		expect(outer).not.toBeNull();
		// outer match should be the LAST {{/a}}
		expect(outer![1].from).toBe(text.lastIndexOf('{{/a}}'));
	});

	it('returns null when the cursor is not on a section tag', () => {
		const text = '{{name}}\nsome text\n{{#lines}}{{/lines}}';
		const tags = readTagsFromText(text);

		expect(findEnclosingPair(tags, 14)).toBeNull();
	});

	it('uses half-open bounds when checking whether the cursor is on a tag', () => {
		const text = '{{#a}}x{{/a}}';
		const tags = readTagsFromText(text);

		expect(findEnclosingPair(tags, tags[0].to)).toBeNull();
		expect(findEnclosingPair(tags, tags[1].from)).not.toBeNull();
	});

	it('returns null when a section has no matching close', () => {
		const text = '{{#unclosed}}\n{{name}}';
		const tags = readTagsFromText(text);

		expect(findEnclosingPair(tags, 3)).toBeNull();
	});
});
