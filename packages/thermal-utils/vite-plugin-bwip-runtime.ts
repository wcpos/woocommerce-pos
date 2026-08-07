import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { dirname, join } from 'node:path';

const require = createRequire(import.meta.url);

interface EmitContext {
	emitFile(asset: { type: 'asset'; fileName: string; source: string }): void;
}

export function bwipRuntime() {
	return {
		name: 'bwip-runtime',
		apply: 'build' as const,
		buildStart(this: EmitContext) {
			const browserEntry = require.resolve('bwip-js/browser');
			const source = readFileSync(join(dirname(browserEntry), 'bwip-js-min.js'), 'utf8');

			this.emitFile({
				type: 'asset',
				fileName: 'js/bwip.js',
				source: `${source}\nglobalThis.WCPOSBwip = globalThis.bwipjs;\n`,
			});
		},
	};
}
