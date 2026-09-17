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
	const closure = sanitized.closure as Record<string, unknown> | undefined;
	if (!closure) return sanitized;
	const breakdowns = (closure.breakdowns ?? {}) as Record<string, unknown>;
	const i18n = (sanitized.i18n ?? {}) as Record<string, string>;
	const order = sanitized.order as { currency?: string } | undefined;
	const currency = String(breakdowns.currency ?? order?.currency ?? 'USD');
	const formatter = new Intl.NumberFormat('en-US', {
		style: 'currency',
		currency,
	});
	const money = (value: unknown) =>
		value == null || value === '' ? '' : formatter.format(Number(value));
	const withMoney = (row: Record<string, unknown>, fields: string[]) => {
		for (const field of fields) row[`${field}_display`] ??= money(row[field]);
		return row;
	};
	const title = (method: string) =>
		method.replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
	const payments = Object.entries(
		(breakdowns.payment_methods ?? {}) as Record<string, Record<string, unknown>>
	);
	const tenderLabels = Object.fromEntries(
		payments.map(([key, row]) => [row.method ?? key, row.name])
	);
	if (!closure.tenders) {
		// Offline closures store tender maps; templates consume render-ready rows.
		const counted = (closure.counted ?? {}) as Record<string, unknown>;
		const expected = (closure.expected ?? {}) as Record<string, unknown>;
		const variance = (closure.variance ?? {}) as Record<string, unknown>;
		closure.tenders = [...new Set([...Object.keys(counted), ...Object.keys(expected)])].map(
			(name) => ({
				name,
				label: tenderLabels[name] || title(name),
				expected: expected[name] ?? '',
				counted: counted[name] ?? '',
				variance: variance[name] ?? '',
				has_variance: Number(variance[name] ?? 0) !== 0,
				variance_absolute_display: money(
					variance[name] == null ? '' : String(variance[name]).replace(/^-/, '')
				),
				variance_label:
					variance[name] == null
						? ''
						: Number(variance[name]) > 0
							? (i18n.over ?? 'Over')
							: Number(variance[name]) < 0
								? (i18n.short ?? 'Short')
								: (i18n.exact ?? 'Exact'),
			})
		);
	}
	for (const row of closure.tenders as Record<string, unknown>[])
		withMoney(row, ['expected', 'counted', 'variance']);
	withMoney(closure, [
		'period_sales_total',
		'period_refunds_total',
		'perpetual_sales_total',
		'perpetual_refunds_total',
		'unsynced_total',
	]);
	if (breakdowns.opening_float)
		withMoney(breakdowns.opening_float as Record<string, unknown>, [
			'expected',
			'counted',
			'variance',
		]);
	for (const [section, fields] of Object.entries({
		payment_methods: ['sales', 'refunds'],
		tax_rates: ['net', 'tax', 'gross'],
		movements: ['amount'],
	})) {
		const rows = Object.entries(
			(breakdowns[section] ?? {}) as Record<string, Record<string, unknown>>
		);
		closure[`has_${section}`] ??= rows.length > 0;
		breakdowns[section] = rows.map(([key, row]) => {
			if (section === 'payment_methods') row.method ??= key;
			if (section !== 'movements') row.name ??= row.method ?? row.rate ?? key;
			else {
				row.type_label ??= i18n[String(row.type)] ?? title(String(row.type));
				row.voided ??= Boolean(row.voided_by);
			}
			return withMoney(row, fields);
		});
	}
	closure.has_sales ??=
		closure.period_sales_total != null ||
		closure.period_refunds_total != null ||
		breakdowns.transaction_count != null ||
		breakdowns.refund_count != null;
	closure.has_perpetual ??=
		closure.perpetual_sales_total != null || closure.perpetual_refunds_total != null;
	closure.breakdowns = breakdowns;
	return sanitized;
}
