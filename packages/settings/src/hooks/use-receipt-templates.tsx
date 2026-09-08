import { useMemo } from 'react';

import { useQuery, useSuspenseQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

export type TemplateEngine = 'legacy-php' | 'logicless' | 'thermal';

export interface ReceiptTemplate {
	id: number | string;
	title: string;
	status?: 'publish' | 'draft';
	is_active: boolean;
	is_virtual?: boolean;
	engine: TemplateEngine;
}

export interface TemplateOption {
	value: string;
	label: string;
	engine: TemplateEngine;
}

const ENDPOINT = 'wcpos/v1/templates?wcpos=1&type=receipt';

/**
 * Receipt-template options for settings pickers.
 *
 * Derived from the templates endpoint, filtered to templates that are published
 * or active. Drafts that are not active are dropped. The `engine` is threaded
 * through so the rule picker can filter per-printer (thermal-only for direct
 * polling printers); see `templateOptionsForProvider`.
 *
 * @param includeInactiveVirtual Include selectable filesystem templates.
 */
export function useReceiptTemplateOptions(includeInactiveVirtual = false): TemplateOption[] {
	const { data } = useSuspenseQuery<ReceiptTemplate[]>({
		queryKey: ['templates', 'receipt'],
		queryFn: () => apiFetch({ path: ENDPOINT, method: 'GET' }) as Promise<ReceiptTemplate[]>,
	});

	return data
		.filter(
			(template) =>
				template.status === 'publish' ||
				template.is_active === true ||
				(includeInactiveVirtual && template.is_virtual === true)
		)
		.map((template) => ({
			value: String(template.id),
			label: template.title,
			engine: template.engine,
		}));
}

/**
 * Id → title map for every receipt template the site knows about.
 *
 * Deliberately unfiltered — drafts and inactive virtual templates included —
 * because historical queue rows can reference a template that is no longer
 * selectable. Shares the ['templates', 'receipt'] cache with
 * `useReceiptTemplateOptions`, so it costs no extra request. Ids missing from
 * the map (deleted templates) are the caller's fallback case.
 */
export function useReceiptTemplateNames(): Map<string, string> {
	const { data } = useQuery<ReceiptTemplate[]>({
		queryKey: ['templates', 'receipt'],
		queryFn: () => apiFetch({ path: ENDPOINT, method: 'GET' }) as Promise<ReceiptTemplate[]>,
	});

	return useMemo(
		() => new Map((Array.isArray(data) ? data : []).map((t) => [String(t.id), t.title])),
		[data]
	);
}
