// vite.config.mts runs svgr on every `*.svg` import, so the default export is a
// React component, not vite/client's URL string. Referenced ahead of vite/client.
declare module '*.svg' {
	import type { FunctionComponent, SVGProps } from 'react';

	const ReactComponent: FunctionComponent<SVGProps<SVGSVGElement>>;
	export default ReactComponent;
}
