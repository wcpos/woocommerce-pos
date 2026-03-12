/** A template stored in the database (custom or installed from gallery). */
export interface Template {
	id: number;
	title: string;
	description: string;
	content?: string;
	type: string;
	category: string;
	language: string;
	engine: 'legacy-php' | 'logicless' | 'thermal';
	output_type: string;
	tax_display: string;
	is_virtual: false;
	is_premade: boolean;
	status: 'publish' | 'draft';
	is_active: boolean;
	offline_capable: boolean;
	gallery_key: string | null;
	gallery_version: number;
	source: 'custom';
	menu_order: number;
	date_created: string;
	date_modified: string;
}

/** A virtual template read from the filesystem (virtual default or gallery). */
export interface VirtualTemplate {
	id: string;
	title: string;
	description: string;
	content?: string;
	type: string;
	category: string;
	language: string;
	engine: string;
	output_type: string;
	is_virtual: true;
	is_premade: boolean;
	is_active: boolean;
	is_disabled: boolean;
	offline_capable: boolean;
	source: 'virtual' | 'gallery';
	menu_order: number;
}

/** A gallery template from the /templates/gallery endpoint. */
export interface GalleryTemplate {
	key: string;
	title: string;
	description: string;
	type: string;
	category: string;
	engine: string;
	output_type: string;
	paper_width: string | null;
	version: number;
	content?: string;
	is_premade: true;
	is_virtual: true;
	source: 'gallery';
	offline_capable: boolean;
}

/** Union of all template types returned by the /templates endpoint. */
export type AnyTemplate = Template | VirtualTemplate;

export interface PreviewResponse {
	preview_url?: string;
	preview_html?: string;
	engine?: string;
	template_content?: string;
	receipt_data?: Record<string, unknown>;
	order_id: number;
	template_id: number | string;
}
