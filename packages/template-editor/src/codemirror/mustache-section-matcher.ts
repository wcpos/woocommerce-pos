import { Decoration, EditorView, ViewPlugin, type DecorationSet } from '@codemirror/view';
import type { EditorState } from '@codemirror/state';

/**
 * Highlights the matching pair when the cursor is on a mustache section tag.
 *
 * Examples:
 *   {{#order.items}}  ↔  {{/order.items}}
 *   {{^customer}}      ↔  {{/customer}}
 *
 * The match decoration is a CSS class added via theme.ts (.cm-mustache-section-match).
 */

const TAG_REGEX = /\{\{[#^/]\s*([^{}]+?)\s*\}\}/g;

export interface SectionTag {
	from: number;
	to: number;
	kind: '#' | '^' | '/';
	name: string;
}

export function readTagsFromText(text: string): SectionTag[] {
	const tags: SectionTag[] = [];
	let match: RegExpExecArray | null;
	TAG_REGEX.lastIndex = 0;
	while ((match = TAG_REGEX.exec(text)) !== null) {
		const fullMatch = match[0];
		const kind = fullMatch[2] as '#' | '^' | '/';
		tags.push({
			from: match.index,
			to: match.index + fullMatch.length,
			kind,
			name: match[1].trim(),
		});
	}
	return tags;
}

function readAllTags(state: EditorState): SectionTag[] {
	return readTagsFromText(state.doc.toString());
}

export function findEnclosingPair(tags: SectionTag[], cursor: number): [SectionTag, SectionTag] | null {
	const cursorTag = tags.find((tag) => cursor >= tag.from && cursor < tag.to);
	if (!cursorTag) return null;

	if (cursorTag.kind === '#' || cursorTag.kind === '^') {
		const partner = findMatchingClose(tags, cursorTag);
		return partner ? [cursorTag, partner] : null;
	}

	if (cursorTag.kind === '/') {
		const partner = findMatchingOpen(tags, cursorTag);
		return partner ? [partner, cursorTag] : null;
	}

	return null;
}

function findMatchingClose(tags: SectionTag[], opener: SectionTag): SectionTag | null {
	let depth = 0;
	for (const tag of tags) {
		if (tag.from <= opener.from) continue;
		if (tag.name !== opener.name) continue;
		if (tag.kind === '#' || tag.kind === '^') {
			depth += 1;
			continue;
		}
		if (tag.kind === '/') {
			if (depth === 0) return tag;
			depth -= 1;
		}
	}
	return null;
}

function findMatchingOpen(tags: SectionTag[], closer: SectionTag): SectionTag | null {
	let depth = 0;
	for (let i = tags.length - 1; i >= 0; i -= 1) {
		const tag = tags[i];
		if (tag.from >= closer.from) continue;
		if (tag.name !== closer.name) continue;
		if (tag.kind === '/') {
			depth += 1;
			continue;
		}
		if (tag.kind === '#' || tag.kind === '^') {
			if (depth === 0) return tag;
			depth -= 1;
		}
	}
	return null;
}

function buildDecorations(state: EditorState): DecorationSet {
	const selection = state.selection.main;
	if (!selection.empty) return Decoration.none;

	const tags = readAllTags(state);
	const pair = findEnclosingPair(tags, selection.head);
	if (!pair) return Decoration.none;

	const mark = Decoration.mark({ class: 'cm-mustache-section-match' });
	return Decoration.set([
		mark.range(pair[0].from, pair[0].to),
		mark.range(pair[1].from, pair[1].to),
	]);
}

export const mustacheSectionMatcher = ViewPlugin.define(
	(view) => ({
		decorations: buildDecorations(view.state),
		update(u) {
			if (u.docChanged || u.selectionSet) {
				this.decorations = buildDecorations(u.state);
			}
		},
	}),
	{ decorations: (v) => v.decorations as DecorationSet },
);

// Re-export EditorView so this file is a complete unit even when tree-shaken.
export { EditorView };
