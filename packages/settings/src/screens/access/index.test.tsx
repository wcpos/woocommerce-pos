import * as React from 'react';

import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import Access from './index';
import { TASK_GROUPS, type CapabilityGroups } from './task-groups';

const mutateMock = vi.fn();
const settingsData: { current: Record<string, unknown> } = { current: {} };

vi.mock('../../hooks/use-settings-api', () => ({
	default: () => ({ data: settingsData.current, mutate: mutateMock }),
}));

const PRODUCT_EDIT_CAPS = [
	'edit_product',
	'edit_products',
	'edit_others_products',
	'edit_private_products',
	'edit_published_products',
	'publish_products',
	'read_private_products',
];

const WC_CUSTOMER_CAPS = ['create_customers'];
const WP_CUSTOMER_CAPS = ['edit_users', 'list_users'];

/**
 * Capability shape mirroring the settings API response, with every capability
 * revoked unless named in `granted`.
 */
function makeCapabilities(granted: string[] = []): CapabilityGroups {
	const wcNames = [
		...PRODUCT_EDIT_CAPS,
		...WC_CUSTOMER_CAPS,
		'delete_product',
		'delete_products',
		'delete_others_products',
		'delete_private_products',
		'delete_published_products',
		'manage_product_terms',
		'read_private_shop_orders',
		'publish_shop_orders',
		'edit_shop_orders',
		'edit_others_shop_orders',
	];

	const toMap = (names: string[]) =>
		names.reduce<Record<string, boolean>>((acc, name) => {
			acc[name] = granted.includes(name);
			return acc;
		}, {});

	return {
		wcpos: toMap(['access_woocommerce_pos', 'manage_woocommerce_pos']),
		wc: toMap(wcNames),
		wp: toMap(['read', ...WP_CUSTOMER_CAPS]),
	};
}

function seed(capabilities: CapabilityGroups, roleId = 'administrator', defaults = capabilities) {
	settingsData.current = {
		[roleId]: { name: 'Administrator', capabilities, defaults },
	};
}

function openAdvanced() {
	fireEvent.click(screen.getByTestId('access-advanced-summary'));
}

beforeEach(() => {
	mutateMock.mockReset();
	settingsData.current = {};
});

describe('Access screen task groups', () => {
	it('renders a task group as checked when every member capability is granted', () => {
		seed(makeCapabilities(PRODUCT_EDIT_CAPS));

		render(<Access />);

		expect(screen.getByTestId('access-task-edit_products')).toBeChecked();
	});

	it('renders a task group as indeterminate when only some members are granted', () => {
		seed(makeCapabilities(['edit_product', 'edit_products']));

		render(<Access />);

		const checkbox = screen.getByTestId('access-task-edit_products');
		expect(checkbox).not.toBeChecked();
		expect(checkbox).toBePartiallyChecked();
	});

	it('renders a task group as unchecked when no members are granted', () => {
		seed(makeCapabilities());

		render(<Access />);

		const checkbox = screen.getByTestId('access-task-edit_products');
		expect(checkbox).not.toBeChecked();
		expect(checkbox).not.toBePartiallyChecked();
	});

	it('grants every member capability in a single mutate when clicked from indeterminate', () => {
		seed(makeCapabilities(['edit_product']));

		render(<Access />);
		fireEvent.click(screen.getByTestId('access-task-edit_products'));

		expect(mutateMock).toHaveBeenCalledTimes(1);
		expect(mutateMock).toHaveBeenCalledWith({
			administrator: {
				capabilities: {
					wc: PRODUCT_EDIT_CAPS.reduce<Record<string, boolean>>((acc, name) => {
						acc[name] = true;
						return acc;
					}, {}),
				},
			},
		});
	});

	it('revokes every member capability in a single mutate when clicked from checked', () => {
		seed(makeCapabilities(PRODUCT_EDIT_CAPS));

		render(<Access />);
		fireEvent.click(screen.getByTestId('access-task-edit_products'));

		expect(mutateMock).toHaveBeenCalledTimes(1);
		const payload = mutateMock.mock.calls[0][0].administrator.capabilities.wc;
		expect(Object.keys(payload).sort()).toEqual([...PRODUCT_EDIT_CAPS].sort());
		expect(Object.values(payload).every((value) => value === false)).toBe(true);
	});

	it('writes the customer capabilities across their API groups in one mutate', () => {
		seed(makeCapabilities());

		render(<Access />);
		fireEvent.click(screen.getByTestId('access-task-manage_customers'));

		expect(mutateMock).toHaveBeenCalledTimes(1);
		const payload = mutateMock.mock.calls[0][0].administrator.capabilities;
		expect(Object.keys(payload.wc).sort()).toEqual(WC_CUSTOMER_CAPS);
		expect(Object.keys(payload.wp).sort()).toEqual([...WP_CUSTOMER_CAPS].sort());
	});

	it('uses the customer-create capability the API returns and never both spellings', () => {
		const capabilities = makeCapabilities();
		delete capabilities.wc.create_customers;
		capabilities.wc.promote_users = false;
		seed(capabilities);

		render(<Access />);
		fireEvent.click(screen.getByTestId('access-task-manage_customers'));

		const payload = mutateMock.mock.calls[0][0].administrator.capabilities.wc;
		expect(payload).toHaveProperty('promote_users', true);
		expect(payload).not.toHaveProperty('create_customers');
	});

	it('writes the WCPOS group capability for the POS access task', () => {
		seed(makeCapabilities());

		render(<Access />);
		fireEvent.click(screen.getByTestId('access-task-use_pos'));

		expect(mutateMock).toHaveBeenCalledWith({
			administrator: {
				capabilities: { wcpos: { access_woocommerce_pos: true } },
			},
		});
	});

	it('omits task groups whose capabilities are absent from the response', () => {
		const capabilities = makeCapabilities();
		delete capabilities.wc.manage_product_terms;
		seed(capabilities);

		render(<Access />);

		expect(screen.queryByTestId('access-task-manage_categories')).not.toBeInTheDocument();
		expect(screen.getByTestId('access-task-edit_products')).toBeInTheDocument();
	});

	it('omits a task group when any required capability is absent from the response', () => {
		const capabilities = makeCapabilities();
		delete capabilities.wc.edit_product;
		seed(capabilities);

		render(<Access />);

		expect(screen.queryByTestId('access-task-edit_products')).not.toBeInTheDocument();
	});

	it('omits a task group when no alternative capability is present in the response', () => {
		const capabilities = makeCapabilities();
		delete capabilities.wc.create_customers;
		seed(capabilities);

		render(<Access />);

		expect(screen.queryByTestId('access-task-manage_customers')).not.toBeInTheDocument();
	});
});

describe('Access screen advanced disclosure', () => {
	it('hides the raw capabilities until the disclosure is opened', () => {
		seed(makeCapabilities());

		render(<Access />);

		expect(screen.queryByLabelText('edit_products')).not.toBeInTheDocument();

		openAdvanced();

		expect(screen.getByLabelText('edit_products')).toBeInTheDocument();
		expect(screen.getByTestId('access-capability-group-wc')).toBeInTheDocument();
		expect(screen.getByTestId('access-capability-group-wcpos')).toBeInTheDocument();
		expect(screen.getByTestId('access-capability-group-wp')).toBeInTheDocument();
	});

	it('lists capabilities that belong to no task group', () => {
		const capabilities = makeCapabilities();
		capabilities.wc.some_future_capability = false;
		seed(capabilities);

		render(<Access />);
		openAdvanced();

		expect(screen.getByLabelText('some_future_capability')).toBeInTheDocument();
		expect(TASK_GROUPS.some((task) => task.capabilities.includes('some_future_capability'))).toBe(
			false
		);
	});

	it('writes a single capability when a raw checkbox is toggled', () => {
		seed(makeCapabilities());

		render(<Access />);
		openAdvanced();
		fireEvent.click(screen.getByLabelText('edit_products'));

		expect(mutateMock).toHaveBeenCalledWith({
			administrator: {
				capabilities: { wc: { edit_products: true } },
			},
		});
	});

	it('keeps the WordPress read capability disabled for the administrator role', () => {
		seed(makeCapabilities(['read']));

		render(<Access />);
		openAdvanced();

		expect(screen.getByLabelText('read')).toBeDisabled();
	});

	it('leaves the WordPress read capability editable for other roles', () => {
		settingsData.current = {
			cashier: {
				name: 'Cashier',
				capabilities: makeCapabilities(['read']),
				defaults: makeCapabilities(),
			},
		};

		render(<Access />);
		fireEvent.click(screen.getByTestId('access-role-cashier'));
		openAdvanced();

		expect(screen.getByLabelText('read')).not.toBeDisabled();
	});
});

describe('Access screen defaults and mixed tasks', () => {
	it('shows the granted count and lets Review open advanced permissions', () => {
		seed(makeCapabilities(PRODUCT_EDIT_CAPS.slice(0, 3)));
		render(<Access />);

		expect(screen.getByText('3 of 7')).toHaveAttribute(
			'title',
			'3 of the 7 permissions behind this task are granted. Tick the box to grant the rest.'
		);
		expect(screen.queryByText('partly granted')).not.toBeInTheDocument();
		fireEvent.click(screen.getByRole('button', { name: 'Review' }));
		expect(screen.getByTestId('access-advanced')).toHaveAttribute('open');
		expect(screen.getByLabelText('edit_products')).toBeInTheDocument();
	});

	it('disables restore at defaults and ignores permissions WCPOS does not own', () => {
		seed(makeCapabilities(PRODUCT_EDIT_CAPS), 'administrator', {
			wcpos: { access_woocommerce_pos: false, manage_woocommerce_pos: false },
		});
		render(<Access />);

		expect(screen.getByTestId('access-restore-defaults')).toBeDisabled();
		expect(screen.getByText('Using the WCPOS defaults')).toBeInTheDocument();
		expect(
			screen.getByRole('heading', { name: 'What a Administrator can do' })
		).toBeInTheDocument();
		expect(screen.getByTestId('access-role-administrator').querySelector('[title]')).toBeNull();
	});

	it('previews grants, removals and kept tasks and restores only the selected defaults', () => {
		const defaults = makeCapabilities([...PRODUCT_EDIT_CAPS, 'access_woocommerce_pos']);
		seed(makeCapabilities(), 'administrator');
		settingsData.current.cashier = {
			name: 'Cashier',
			defaults,
			capabilities: makeCapabilities(['delete_products', 'access_woocommerce_pos']),
		};
		render(<Access />);
		fireEvent.click(screen.getByTestId('access-role-cashier'));

		expect(screen.getByTestId('access-restore-defaults')).toBeEnabled();
		expect(screen.getByText('Changed from the WCPOS defaults')).toBeInTheDocument();
		expect(screen.getByTestId('access-role-cashier').querySelector('[title]')).toHaveAttribute(
			'title',
			'Changed from the WCPOS defaults'
		);
		fireEvent.click(screen.getByTestId('access-restore-defaults'));
		expect(screen.getByRole('dialog')).toHaveAccessibleName(
			'Restore the default privileges for Cashier?'
		);
		expect(screen.getByText('Grant').parentElement).toHaveTextContent('Create & edit products');
		expect(screen.getByText('Remove').parentElement).toHaveTextContent('Delete products');
		expect(screen.getByText('Keep').parentElement).toHaveTextContent('Use the POS');
		// A task that is off today and stays off is not "kept" access.
		expect(screen.getByText('Keep').parentElement).not.toHaveTextContent('Delete coupons');
		expect(mutateMock).not.toHaveBeenCalled();

		fireEvent.click(screen.getByTestId('access-restore-confirm'));
		expect(mutateMock).toHaveBeenCalledTimes(1);
		expect(mutateMock).toHaveBeenCalledWith({ cashier: { capabilities: defaults } });
		expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
	});

	it('uses removal copy and excludes tasks not wholly covered by defaults', () => {
		seed(makeCapabilities(['access_woocommerce_pos']), 'administrator', {
			wcpos: { access_woocommerce_pos: false },
			wc: { edit_products: false },
		});
		render(<Access />);
		fireEvent.click(screen.getByTestId('access-restore-defaults'));

		expect(screen.getByRole('dialog')).toHaveAccessibleName(
			'Remove POS privileges from Administrator?'
		);
		expect(screen.getByRole('dialog')).not.toHaveTextContent('Create & edit products');
		expect(screen.queryByText('Grant')).not.toBeInTheDocument();
		expect(screen.queryByText('Keep')).not.toBeInTheDocument();
		fireEvent.click(screen.getByRole('button', { name: 'Cancel' }));
		expect(mutateMock).not.toHaveBeenCalled();
		expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
	});
});
