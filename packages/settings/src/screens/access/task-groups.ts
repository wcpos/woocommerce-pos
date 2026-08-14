/**
 * Task-based grouping for the Access screen.
 *
 * Merchants think in tasks ("create & edit products"), not in the WordPress
 * primitive capabilities a task actually requires. Each task group below maps a
 * plain-language label onto every capability that has to be granted for that
 * task to work in the POS. The raw per-capability toggles remain available
 * under the Advanced disclosure, so partial grants stay editable.
 */

/** Capability groups exactly as the settings API returns them. */
export type CapabilityGroups = Record<string, Record<string, boolean>>;

export interface TaskGroup {
	/** Stable id, used for test ids and the translation key. */
	id: string;
	/** Translation key for the merchant-facing label. */
	labelKey: string;
	/** Capabilities that must all be granted for the task to work. */
	capabilities: string[];
	/**
	 * Sets where the site only ever exposes one of the alternatives. The first
	 * name present in the API response is used and the rest ignored, so we never
	 * show, or write, both spellings.
	 */
	alternatives?: string[][];
}

/** A capability resolved against the API response. */
export interface TaskGroupMember {
	/** API group the capability lives in, e.g. `wcpos` or `wc`. */
	group: string;
	/** Raw capability name. */
	name: string;
}

export interface ResolvedTaskGroup {
	task: TaskGroup;
	members: TaskGroupMember[];
	/** Every member is granted. */
	allGranted: boolean;
	/** At least one, but not every, member is granted. */
	partiallyGranted: boolean;
}

export const TASK_GROUPS: TaskGroup[] = [
	{
		id: 'use_pos',
		labelKey: 'access.task_use_pos',
		capabilities: ['access_woocommerce_pos'],
	},
	{
		id: 'manage_settings',
		labelKey: 'access.task_manage_settings',
		capabilities: ['manage_woocommerce_pos'],
	},
	{
		id: 'edit_products',
		labelKey: 'access.task_edit_products',
		capabilities: [
			'edit_product',
			'edit_products',
			'edit_others_products',
			'edit_private_products',
			'edit_published_products',
			'publish_products',
			'read_private_products',
		],
	},
	{
		id: 'delete_products',
		labelKey: 'access.task_delete_products',
		capabilities: [
			'delete_product',
			'delete_products',
			'delete_others_products',
			'delete_private_products',
			'delete_published_products',
		],
	},
	{
		id: 'edit_coupons',
		labelKey: 'access.task_edit_coupons',
		capabilities: [
			'edit_shop_coupons',
			'edit_others_shop_coupons',
			'edit_private_shop_coupons',
			'edit_published_shop_coupons',
			'publish_shop_coupons',
			'read_private_shop_coupons',
		],
	},
	{
		id: 'delete_coupons',
		labelKey: 'access.task_delete_coupons',
		capabilities: [
			'delete_shop_coupons',
			'delete_others_shop_coupons',
			'delete_private_shop_coupons',
			'delete_published_shop_coupons',
		],
	},
	{
		id: 'manage_orders',
		labelKey: 'access.task_manage_orders',
		capabilities: [
			'read_private_shop_orders',
			'publish_shop_orders',
			'edit_shop_orders',
			'edit_others_shop_orders',
		],
	},
	{
		id: 'manage_customers',
		labelKey: 'access.task_manage_customers',
		capabilities: ['edit_users', 'list_users'],
		// WooCommerce 9.9 renamed promote_users to create_customers.
		alternatives: [['create_customers', 'promote_users']],
	},
	{
		id: 'manage_categories',
		labelKey: 'access.task_manage_categories',
		capabilities: ['manage_product_terms'],
	},
];

/**
 * Map every capability name in the response to the API group it belongs to.
 */
export function buildCapabilityIndex(capabilities: CapabilityGroups): Record<string, string> {
	const index: Record<string, string> = {};

	Object.entries(capabilities || {}).forEach(([group, caps]) => {
		Object.keys(caps || {}).forEach((name) => {
			index[name] = group;
		});
	});

	return index;
}

/**
 * Resolve the task groups against a role's capabilities.
 *
 * Groups are built from the names actually present in the response, so a task
 * whose capabilities the server no longer exposes simply drops out rather than
 * rendering a toggle that can never be satisfied.
 */
export function resolveTaskGroups(capabilities: CapabilityGroups): ResolvedTaskGroup[] {
	const index = buildCapabilityIndex(capabilities);

	return TASK_GROUPS.filter(
		(task) =>
			task.capabilities.every((name) => name in index) &&
			(task.alternatives || []).every((alternatives) =>
				alternatives.some((name) => name in index)
			)
	).map((task) => {
		const names = [...task.capabilities];

		(task.alternatives || []).forEach((alternatives) => {
			const present = alternatives.find((name) => name in index);
			if (present) {
				names.push(present);
			}
		});

		const members = names
			.filter((name) => name in index)
			.map((name) => ({ group: index[name], name }));

		const grantedCount = members.filter(({ group, name }) => capabilities[group][name]).length;

		return {
			task,
			members,
			allGranted: members.length > 0 && grantedCount === members.length,
			partiallyGranted: grantedCount > 0 && grantedCount < members.length,
		};
	}).filter(({ members }) => members.length > 0);
}

/**
 * Build the nested capabilities payload that grants, or revokes, every member of
 * a task group in a single write.
 */
export function buildTaskGroupPayload(
	members: TaskGroupMember[],
	granted: boolean
): Record<string, Record<string, boolean>> {
	return members.reduce<Record<string, Record<string, boolean>>>((payload, { group, name }) => {
		if (!payload[group]) {
			payload[group] = {};
		}
		payload[group][name] = granted;

		return payload;
	}, {});
}
