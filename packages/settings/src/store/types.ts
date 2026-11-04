import type { ComponentType } from 'react';

export interface PageRegistration {
	id: string;
	label: string;
	group: 'settings' | 'tools' | 'account' | string;
	component: ComponentType | (() => Promise<{ default: ComponentType }>);
	priority?: number;
}

export interface FieldRegistration {
	page: string;
	section?: string;
	id: string;
	/**
	 * Rendered with {@link FieldComponentProps} inside a Suspense boundary, so a
	 * code-split field is `React.lazy(() => import('./field'))`. A bare loader
	 * function is not accepted: a plain function component is indistinguishable
	 * from one at runtime.
	 */
	component: ComponentType<FieldComponentProps>;
	priority?: number;
	after?: string;
	before?: string;
}

export interface FieldModification {
	page: string;
	id: string;
	props: Record<string, unknown>;
}

export interface FieldComponentProps {
	data: Record<string, unknown>;
	mutate: (data: Record<string, unknown>) => void;
}

export interface SettingsRegistryState {
	pages: PageRegistration[];
	fields: FieldRegistration[];
	modifications: FieldModification[];
	components: Record<string, ComponentType>;
	registerPage: (page: PageRegistration) => void;
	registerField: (field: FieldRegistration) => void;
	modifyField: (mod: FieldModification) => void;
	registerComponent: (key: string, component: ComponentType) => void;
	getComponent: (key: string) => ComponentType | undefined;
	getPages: (group?: string) => PageRegistration[];
	getFields: (page: string, section?: string) => FieldRegistration[];
	getModifications: (page: string, id: string) => Record<string, unknown>;
}
