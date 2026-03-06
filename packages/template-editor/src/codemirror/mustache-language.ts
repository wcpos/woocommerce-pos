import { StreamLanguage, StringStream } from '@codemirror/language';

interface MustacheState {
	closeDelimiter: '}}' | '}}}' | null;
}

export const mustacheLanguage = StreamLanguage.define<MustacheState>({
	name: 'mustache',
	startState(): MustacheState {
		return { closeDelimiter: null };
	},
	token(stream: StringStream, state: MustacheState): string | null {
		if (state.closeDelimiter) {
			if (stream.match(state.closeDelimiter)) {
				state.closeDelimiter = null;
				return 'brace';
			}
			const ch = stream.next();
			if (ch === '#' || ch === '/' || ch === '^' || ch === '>') {
				return 'keyword';
			}
			if (ch === '.') {
				return 'punctuation';
			}
			return 'variableName';
		}

		if (stream.match('{{{')) {
			state.closeDelimiter = '}}}';
			return 'brace';
		}

		if (stream.match('{{')) {
			state.closeDelimiter = '}}';
			return 'brace';
		}

		while (stream.next() != null) {
			if (stream.match('{{{', false) || stream.match('{{', false)) {
				break;
			}
		}
		return null;
	},
});
