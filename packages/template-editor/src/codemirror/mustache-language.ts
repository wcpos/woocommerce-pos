import { ViewPlugin, MatchDecorator, Decoration, type DecorationSet } from '@codemirror/view';

const mustacheDecorator = new MatchDecorator({
	regexp: /\{\{\{?(?:[^}]|\}(?!\}\}?))*\}?\}\}/g,
	decoration: Decoration.mark({ class: 'cm-mustache' }),
});

export const mustacheOverlay = ViewPlugin.define(
	(view) => ({
		decorations: mustacheDecorator.createDeco(view),
		update(u) {
			this.decorations = mustacheDecorator.updateDeco(u, this.decorations);
		},
	}),
	{ decorations: (v) => v.decorations as DecorationSet },
);
