import { useStore } from 'zustand';
import { settingsRegistry } from './settings-registry';
import type { SettingsRegistryState } from './types';

export function useSettingsRegistry<T>(selector: (state: SettingsRegistryState) => T): T {
	return useStore(settingsRegistry, selector);
}

export function useRegisteredPages(group?: string) {
	return useSettingsRegistry((state) => state.getPages(group));
}

export function useRegisteredFields(page: string, section?: string) {
	return useSettingsRegistry((state) => state.getFields(page, section));
}

export function useFieldModifications(page: string, id: string) {
	return useSettingsRegistry((state) => state.getModifications(page, id));
}
