import { useSuspenseQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

export type TemplateEngine = 'legacy-php' | 'logicless' | 'thermal';

export interface ReceiptTemplate {
	id: number | string;
	title: string;
	status: 'publish' | 'draft';
	is_active: boolean;
	engine: TemplateEngine;
}

export interface TemplateOption {
	value: string;
	label: string;
	engine: TemplateEngine;
}

const ENDPOINT = 'wcpos/v1/templates?wcpos=1&type=receipt';

/**
 * Receipt-template options for the cloud-print rule picker.
 *
 * Derived from the templates endpoint, filtered to templates that are published
 * or active. Drafts that are not active are dropped. The `engine` is threaded
 * through so the rule picker can filter per-printer (thermal-only for direct
 * polling printers); see `templateOptionsForProvider`.
 */
export function useReceiptTemplateOptions(): TemplateOption[] {
	const { data } = useSuspenseQuery<ReceiptTemplate[]>({
		queryKey: ['templates', 'receipt'],
		queryFn: () => apiFetch({ path: ENDPOINT, method: 'GET' }) as Promise<ReceiptTemplate[]>,
	});

	return data
		.filter((template) => template.status === 'publish' || template.is_active === true)
		.map((template) => ({
			value: String(template.id),
			label: template.title,
			engine: template.engine,
		}));
}
