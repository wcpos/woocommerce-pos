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
		]),
	);
}

export function sanitizeReceiptDataForRendering(data: Record<string, unknown>): Record<string, unknown> {
	return sanitizeValue(data) as Record<string, unknown>;
}
