import * as React from 'react';

import type { Extension } from './index';

type ExtensionSlot = React.ComponentType<{ extension: Extension }>;

/**
 * The component Pro registered under `key`, if any.
 */
export function getSlot(key: string): ExtensionSlot | undefined {
	return (window as any).wcpos?.settings?.getComponent?.(key);
}

/**
 * Renders the component Pro registered under `key` for this extension, or null.
 */
export function renderSlot(key: string, extension: Extension): React.ReactNode {
	const Slot = getSlot(key);
	return Slot ? <Slot extension={extension} /> : null;
}
