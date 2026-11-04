import * as React from 'react';

import { useRegisteredFields, useFieldModifications } from '../../store/use-registry';

import type { FieldComponentProps, FieldRegistration } from '../../store/types';

interface RegisteredFieldsProps {
	page: string;
	section?: string;
	data: Record<string, unknown>;
	mutate: (data: Record<string, unknown>) => void;
}

/**
 * Renders one registered field with its modifications applied. Lazy fields are
 * `React.lazy` components, so Suspense covers the load; nothing renders until then.
 */
function FieldRenderer({
	field,
	data,
	mutate,
}: FieldComponentProps & {
	field: FieldRegistration;
}) {
	const modifications = useFieldModifications(field.page, field.id);
	const Component = field.component;

	return (
		<React.Suspense fallback={null}>
			<Component data={data} mutate={mutate} {...modifications} />
		</React.Suspense>
	);
}

export function RegisteredFields({ page, section, data, mutate }: RegisteredFieldsProps) {
	const fields = useRegisteredFields(page, section);

	if (fields.length === 0) {
		return null;
	}

	return (
		<>
			{fields.map((field) => (
				<FieldRenderer key={field.id} field={field} data={data} mutate={mutate} />
			))}
		</>
	);
}
