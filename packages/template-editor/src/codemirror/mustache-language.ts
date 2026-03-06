import { StreamLanguage, StringStream } from '@codemirror/language';

interface MustacheState {
	inTag: boolean;
}

export const mustacheLanguage = StreamLanguage.define<MustacheState>({
	name: 'mustache',
	startState(): MustacheState {
		return { inTag: false };
	},
	token(stream: StringStream, state: MustacheState): string | null {
		if (state.inTag) {
			if (stream.match('}}')) {
				state.inTag = false;
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

		if (stream.match('{{')) {
			state.inTag = true;
			return 'brace';
		}

		while (stream.next() != null) {
			if (stream.match('{{', false)) {
				break;
			}
		}
		return null;
	},
});
