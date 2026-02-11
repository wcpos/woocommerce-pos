import type { ComponentType } from 'react';

import { createStore } from 'zustand/vanilla';

import type {
	SettingsRegistryState,
	PageRegistration,
	FieldRegistration,
	FieldModification,
} from './types';

export const settingsRegistry = createStore<SettingsRegistryState>((set, get) => ({
	pages: [],
	fields: [],
	modifications: [],
	components: {},

	registerPage: (page: PageRegistration) => {
		set((state) => {
			if (state.pages.some((p) => p.id === page.id)) {
				console.warn(`[wcpos] Page "${page.id}" is already registered.`);
				return state;
			}
			return { pages: [...state.pages, { priority: 10, ...page }] };
		});
	},

	registerField: (field: FieldRegistration) => {
		set((state) => {
			const key = `${field.page}:${field.section || ''}:${field.id}`;
			if (state.fields.some((f) => `${f.page}:${f.section || ''}:${f.id}` === key)) {
				console.warn(`[wcpos] Field "${key}" is already registered.`);
				return state;
			}
			return { fields: [...state.fields, { priority: 10, ...field }] };
		});
	},

	modifyField: (mod: FieldModification) => {
		set((state) => ({
			modifications: [...state.modifications, mod],
		}));
	},

	getPages: (group?: string) => {
		const { pages } = get();
		const filtered = group ? pages.filter((p) => p.group === group) : pages;
		return filtered.sort((a, b) => (a.priority ?? 10) - (b.priority ?? 10));
	},

	getFields: (page: string, section?: string) => {
		const { fields } = get();
		return fields
			.filter((f) => f.page === page && (section === undefined || f.section === section))
			.sort((a, b) => (a.priority ?? 10) - (b.priority ?? 10));
	},

	getModifications: (page: string, id: string) => {
		const { modifications } = get();
		return modifications
			.filter((m) => m.page === page && m.id === id)
			.reduce((acc, m) => ({ ...acc, ...m.props }), {});
	},

	registerComponent: (key: string, component: ComponentType) => {
		set((state) => ({
			components: { ...state.components, [key]: component },
		}));
	},

	getComponent: (key: string) => {
		return get().components[key];
	},
}));

// Expose globally for pro plugin
if (typeof window !== 'undefined') {
	(window as any).wcpos = (window as any).wcpos || {};
	(window as any).wcpos.settings = {
		...(window as any).wcpos.settings,
		registerPage: settingsRegistry.getState().registerPage,
		registerField: settingsRegistry.getState().registerField,
		modifyField: settingsRegistry.getState().modifyField,
		registerComponent: settingsRegistry.getState().registerComponent,
		getComponent: settingsRegistry.getState().getComponent,
	};
}
