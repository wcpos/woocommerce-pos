import { describe, it, expect, beforeEach } from 'vitest';
import { settingsRegistry } from '../settings-registry';

describe('settingsRegistry', () => {
	beforeEach(() => {
		settingsRegistry.setState({ pages: [], fields: [], modifications: [], components: {} });
	});

	describe('registerPage', () => {
		it('adds a page to the store', () => {
			settingsRegistry.getState().registerPage({
				id: 'test-page',
				label: 'Test Page',
				group: 'settings',
				component: () => null,
			});
			expect(settingsRegistry.getState().pages).toHaveLength(1);
			expect(settingsRegistry.getState().pages[0].id).toBe('test-page');
		});

		it('prevents duplicate page registration', () => {
			const page = {
				id: 'dup',
				label: 'Dup',
				group: 'settings' as const,
				component: () => null,
			};
			settingsRegistry.getState().registerPage(page);
			settingsRegistry.getState().registerPage(page);
			expect(settingsRegistry.getState().pages).toHaveLength(1);
		});

		it('sorts pages by priority', () => {
			settingsRegistry.getState().registerPage({
				id: 'b',
				label: 'B',
				group: 'settings',
				component: () => null,
				priority: 20,
			});
			settingsRegistry.getState().registerPage({
				id: 'a',
				label: 'A',
				group: 'settings',
				component: () => null,
				priority: 5,
			});
			const pages = settingsRegistry.getState().getPages('settings');
			expect(pages[0].id).toBe('a');
			expect(pages[1].id).toBe('b');
		});
	});

	describe('registerField', () => {
		it('adds a field to the store', () => {
			settingsRegistry.getState().registerField({
				page: 'general',
				section: 'products',
				id: 'test-field',
				component: () => null,
			});
			expect(settingsRegistry.getState().fields).toHaveLength(1);
		});

		it('filters fields by page and section', () => {
			settingsRegistry.getState().registerField({
				page: 'general',
				section: 'products',
				id: 'f1',
				component: () => null,
			});
			settingsRegistry.getState().registerField({
				page: 'general',
				section: 'customers',
				id: 'f2',
				component: () => null,
			});
			settingsRegistry.getState().registerField({
				page: 'checkout',
				section: 'orders',
				id: 'f3',
				component: () => null,
			});

			expect(settingsRegistry.getState().getFields('general', 'products')).toHaveLength(1);
			expect(settingsRegistry.getState().getFields('general')).toHaveLength(2);
			expect(settingsRegistry.getState().getFields('checkout')).toHaveLength(1);
		});
	});

	describe('modifyField', () => {
		it('returns merged modifications', () => {
			settingsRegistry.getState().modifyField({
				page: 'general',
				id: 'decimal_qty',
				props: { disabled: false },
			});
			settingsRegistry.getState().modifyField({
				page: 'general',
				id: 'decimal_qty',
				props: { description: 'Pro enabled' },
			});
			const mods = settingsRegistry.getState().getModifications('general', 'decimal_qty');
			expect(mods).toEqual({ disabled: false, description: 'Pro enabled' });
		});

		it('returns empty object when no modifications exist', () => {
			const mods = settingsRegistry.getState().getModifications('general', 'nonexistent');
			expect(mods).toEqual({});
		});
	});

	describe('registerComponent / getComponent', () => {
		it('registers and retrieves a component by key', () => {
			const DummyComponent = () => null;
			settingsRegistry.getState().registerComponent('extensions.action', DummyComponent);
			expect(settingsRegistry.getState().getComponent('extensions.action')).toBe(DummyComponent);
		});

		it('returns undefined for unregistered key', () => {
			expect(settingsRegistry.getState().getComponent('nonexistent')).toBeUndefined();
		});

		it('overwrites a previously registered component', () => {
			const First = () => null;
			const Second = () => null;
			settingsRegistry.getState().registerComponent('slot', First);
			settingsRegistry.getState().registerComponent('slot', Second);
			expect(settingsRegistry.getState().getComponent('slot')).toBe(Second);
		});
	});
});
