import * as React from 'react';

import { render, screen } from '@testing-library/react';

import { RegisteredFields } from '../registered-fields';

import type { FieldComponentProps, FieldRegistration } from '../../../store/types';

// Mock the store hooks to avoid useSyncExternalStore infinite loop in tests
const mockUseRegisteredFields = vi.fn<(page: string, section?: string) => FieldRegistration[]>();
const mockUseFieldModifications = vi.fn<(page: string, id: string) => Record<string, unknown>>();

vi.mock('../../../store/use-registry', () => ({
	useRegisteredFields: (...args: [string, string?]) => mockUseRegisteredFields(...args),
	useFieldModifications: (...args: [string, string]) => mockUseFieldModifications(...args),
}));

describe('RegisteredFields', () => {
	beforeEach(() => {
		mockUseRegisteredFields.mockReturnValue([]);
		mockUseFieldModifications.mockReturnValue({});
	});

	it('renders nothing when no fields are registered', () => {
		const { container } = render(<RegisteredFields page="general" data={{}} mutate={() => {}} />);
		expect(container.innerHTML).toBe('');
	});

	it('renders a registered field component', () => {
		// A plain function component, nothing marking it as React's: the renderer
		// must not mistake it for a loader and call it without props.
		function TestField() {
			return <div>Custom Field</div>;
		}

		mockUseRegisteredFields.mockReturnValue([
			{
				id: 'test-field',
				page: 'general',
				component: TestField,
			},
		]);

		render(<RegisteredFields page="general" data={{}} mutate={() => {}} />);
		expect(screen.getByText('Custom Field')).toBeInTheDocument();
	});

	it('does not render fields for a different page', () => {
		// When requesting page "general" but no fields match, the hook returns []
		mockUseRegisteredFields.mockReturnValue([]);

		const { container } = render(<RegisteredFields page="general" data={{}} mutate={() => {}} />);
		expect(container.innerHTML).toBe('');
	});

	it('renders multiple fields', () => {
		function FieldA() {
			return <div>Field A</div>;
		}
		function FieldB() {
			return <div>Field B</div>;
		}

		mockUseRegisteredFields.mockReturnValue([
			{ id: 'field-a', page: 'general', component: FieldA, priority: 5 },
			{ id: 'field-b', page: 'general', component: FieldB, priority: 20 },
		]);

		render(<RegisteredFields page="general" data={{}} mutate={() => {}} />);

		expect(screen.getByText('Field A')).toBeInTheDocument();
		expect(screen.getByText('Field B')).toBeInTheDocument();
	});

	it('passes data and mutate to field components', () => {
		const testData = { foo: 'bar' };
		const testMutate = vi.fn();

		function SpyField({ data, mutate }: any) {
			return (
				<div>
					<span data-testid="data-value">{JSON.stringify(data)}</span>
					<button onClick={() => mutate({ foo: 'baz' })}>Mutate</button>
				</div>
			);
		}

		mockUseRegisteredFields.mockReturnValue([
			{ id: 'spy-field', page: 'general', component: SpyField },
		]);

		render(<RegisteredFields page="general" data={testData} mutate={testMutate} />);

		expect(screen.getByTestId('data-value')).toHaveTextContent('{"foo":"bar"}');
	});

	it('renders a React.lazy field once its module resolves', async () => {
		const LazyField = React.lazy(() =>
			Promise.resolve({
				default: function LazyLoaded({ data }: FieldComponentProps) {
					return <div>Lazy {String(data.foo)}</div>;
				},
			})
		);

		mockUseRegisteredFields.mockReturnValue([
			{ id: 'lazy-field', page: 'general', component: LazyField },
		]);

		const { container } = render(
			<RegisteredFields page="general" data={{ foo: 'bar' }} mutate={() => {}} />
		);

		// Suspense fallback is null: nothing shows while the module loads.
		expect(container.innerHTML).toBe('');
		expect(await screen.findByText('Lazy bar')).toBeInTheDocument();
	});

	it('calls useRegisteredFields with the correct page and section', () => {
		render(<RegisteredFields page="checkout" section="orders" data={{}} mutate={() => {}} />);

		expect(mockUseRegisteredFields).toHaveBeenCalledWith('checkout', 'orders');
	});
});
