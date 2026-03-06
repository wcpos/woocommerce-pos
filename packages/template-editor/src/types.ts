export interface FieldInfo {
	type: 'string' | 'string[]' | 'number' | 'money';
	label: string;
}

export interface SectionInfo {
	label: string;
	is_array?: boolean;
	fields: Record<string, FieldInfo>;
}

export type FieldSchema = Record<string, SectionInfo>;

export interface EditorConfig {
	fieldSchema: FieldSchema;
	sampleData: Record<string, unknown>;
	engine: 'logicless' | 'legacy-php' | 'thermal';
	templateId: number;
	previewUrl: string;
	postContent: string;
}

declare global {
	interface Window {
		wcposTemplateEditor: EditorConfig;
	}
}
