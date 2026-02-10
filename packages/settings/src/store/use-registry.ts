import { useMemo } from 'react';

import { useStore } from 'zustand';

import { settingsRegistry } from './settings-registry';

import type { SettingsRegistryState } from './types';

export function useSettingsRegistry<T>(selector: (state: SettingsRegistryState) => T): T {
	return useStore(settingsRegistry, selector);
}

export function useRegisteredPages(group?: string) {
	const pages = useStore(settingsRegistry, (state) => state.pages);
	return useMemo(() => {
		const filtered = group ? pages.filter((p) => p.group === group) : [...pages];
		return filtered.sort((a, b) => (a.priority ?? 10) - (b.priority ?? 10));
	}, [pages, group]);
}

export function useRegisteredFields(page: string, section?: string) {
	const fields = useStore(settingsRegistry, (state) => state.fields);
	return useMemo(() => {
		return fields
			.filter((f) => f.page === page && (section === undefined || f.section === section))
			.sort((a, b) => (a.priority ?? 10) - (b.priority ?? 10));
	}, [fields, page, section]);
}

export function useFieldModifications(page: string, id: string) {
	const modifications = useStore(settingsRegistry, (state) => state.modifications);
	return useMemo(() => {
		return modifications
			.filter((m) => m.page === page && m.id === id)
			.reduce((acc, m) => ({ ...acc, ...m.props }), {});
	}, [modifications, page, id]);
}
