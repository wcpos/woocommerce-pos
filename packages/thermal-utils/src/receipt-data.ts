function isPrivateMetaEntry(value: unknown): boolean {
	if (!value || typeof value !== 'object' || Array.isArray(value)) {
		return false;
	}

	const meta = value as { key?: unknown; display_key?: unknown };
	const key = meta.key ?? meta.display_key;

	return typeof key === 'string' && key.startsWith('_');
}

function sanitizeValue(value: unknown): unknown {
	if (Array.isArray(value)) {
		return value.filter((entry) => !isPrivateMetaEntry(entry)).map(sanitizeValue);
	}

	if (!value || typeof value !== 'object') {
		return value;
	}

	return Object.fromEntries(
		Object.entries(value as Record<string, unknown>).map(([key, nested]) => [
			key,
			sanitizeValue(nested),
		])
	);
}

export function sanitizeReceiptDataForRendering(
	data: Record<string, unknown>
): Record<string, unknown> {
	const sanitized = sanitizeValue(data) as Record<string, unknown>;
	const closure = sanitized.closure as
		| {
				counted?: Record<string, unknown>;
				expected?: Record<string, unknown>;
				variance?: Record<string, unknown>;
				tenders?: unknown[];
		  }
		| undefined;
	if (closure && !closure.tenders) {
		// Offline closure rows carry maps; Mustache needs the same row list as PHP.
		const methods = new Set([
			...Object.keys(closure.counted ?? {}),
			...Object.keys(closure.expected ?? {}),
		]);
		closure.tenders = [...methods].map((name) => ({
			name,
			expected: closure.expected?.[name] ?? '',
			counted: closure.counted?.[name] ?? '',
			variance: closure.variance?.[name] ?? '',
		}));
	}
	return sanitized;
}
