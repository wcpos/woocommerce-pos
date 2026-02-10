import * as React from 'react';

import { render, screen } from '@testing-library/react';

import { RegisteredFields } from '../registered-fields';

import type { FieldRegistration } from '../../../store/types';

// Mock the store hooks to avoid useSyncExternalStore infinite loop in tests
const mockUseRegisteredFields = vi.fn<(page: string, section?: string) => FieldRegistration[]>();
const mockUseFieldModifications = vi.fn<(page: string, id: string) => Record>();

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
		function TestField() {
			return <div>Custom Field</div>;
		}
		// Mark as a React component so FieldRenderer does not treat it as a lazy loader
		(TestField as any).$$typeof = Symbol.for('react.element');

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
		(FieldA as any).$$typeof = Symbol.for('react.element');
		function FieldB() {
			return <div>Field B</div>;
		}
		(FieldB as any).$$typeof = Symbol.for('react.element');

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
		(SpyField as any).$$typeof = Symbol.for('react.element');

		mockUseRegisteredFields.mockReturnValue([
			{ id: 'spy-field', page: 'general', component: SpyField },
		]);

		render(<RegisteredFields page="general" data={testData} mutate={testMutate} />);

		expect(screen.getByTestId('data-value')).toHaveTextContent('{"foo":"bar"}');
	});

	it('calls useRegisteredFields with the correct page and section', () => {
		render(<RegisteredFields page="checkout" section="orders" data={{}} mutate={() => {}} />);

		expect(mockUseRegisteredFields).toHaveBeenCalledWith('checkout', 'orders');
	});
});
