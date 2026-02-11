import * as React from 'react';

import { useRegisteredFields, useFieldModifications } from '../../store/use-registry';

import type { FieldRegistration } from '../../store/types';

interface RegisteredFieldsProps {
	page: string;
	section?: string;
	data: Record<string, unknown>;
	mutate: (data: Record<string, unknown>) => void;
}

/**
 * Wrapper that resolves a single field (handling lazy imports) and applies modifications.
 */
function FieldRenderer({
	field,
	data,
	mutate,
}: {
	field: FieldRegistration;
	data: Record<string, unknown>;
	mutate: (data: Record<string, unknown>) => void;
}) {
	const modifications = useFieldModifications(field.page, field.id);
	const [LazyComponent, setLazyComponent] = React.useState<React.ComponentType | null>(null);

	const isLazy = typeof field.component === 'function' && !('$$typeof' in field.component);

	React.useEffect(() => {
		if (isLazy) {
			const loader = field.component as () => Promise<{ default: React.ComponentType }>;
			loader().then((mod) => setLazyComponent(() => mod.default));
		}
	}, [field.component, isLazy]);

	const Component = isLazy ? LazyComponent : (field.component as React.ComponentType);

	if (!Component) {
		return null;
	}

	return <Component data={data} mutate={mutate} {...modifications} />;
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
