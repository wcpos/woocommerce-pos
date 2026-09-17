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
	const store = (sanitized.store ?? {}) as Record<string, unknown>;
	const hints = (sanitized.presentation_hints ?? {}) as Record<string, unknown>;

	// Translate WordPress script modifiers before canonicalising BCP 47 subtags.
	const [baseLocale, modifier] = String(hints.locale || store.locale || 'en-US').split('@');
	const subtags = baseLocale.replace(/_/g, '-').split('-');
	const script = { latin: 'Latn', cyrillic: 'Cyrl' }[modifier as 'latin' | 'cyrillic'];
	if (script) {
		if (/^[A-Za-z]{4}$/.test(subtags[1] ?? '')) subtags[1] = script;
		else subtags.splice(1, 0, script);
	}
	let locale = subtags.join('-');
	for (;;) {
		try {
			locale = Intl.getCanonicalLocales(locale)[0];
			break;
		} catch {
			const suffix = locale.lastIndexOf('-');
			locale = suffix > 0 ? locale.slice(0, suffix) : 'en';
		}
	}
	const currency = String(breakdowns.currency ?? order?.currency ?? 'USD');
	const decimals = hints.price_num_decimals ?? store.price_decimals;
	// WooCommerce presentation hints contain HTML-encoded currency symbols.
	const symbolElement = document.createElement('textarea');
	symbolElement.innerHTML = String(hints.currency_symbol ?? '');
	const currencySymbol = hints.currency_symbol == null ? undefined : symbolElement.value;
	const moneyOptions: Intl.NumberFormatOptions = {
		style: 'currency',
		currency,
		currencyDisplay: 'narrowSymbol',
		...(decimals == null
			? {}
			: {
					minimumFractionDigits: Number(decimals),
					maximumFractionDigits: Number(decimals),
				}),
	};
	let formatter: Intl.NumberFormat | undefined;
	const money = (value: unknown) => {
		if (value == null || value === '') return '';
		if (!formatter) {
			try {
				formatter = new Intl.NumberFormat(locale, moneyOptions);
			} catch {
				locale = 'en';
				formatter = new Intl.NumberFormat(locale, moneyOptions);
			}
		}
		// Round the decimal magnitude as an integer; Intl only receives exact BigInts.
		const negative = String(value).startsWith('-');
		const [integer, fraction = ''] = String(value).replace(/^[+-]/, '').split('.');
		// Currency formatting always resolves a fraction-digit count.
		const places = formatter.resolvedOptions().maximumFractionDigits!;
		const padded = fraction.padEnd(places + 1, '0');
		const rounded = (
			BigInt(integer + padded.slice(0, places)) + (padded[places] >= '5' ? 1n : 0n)
		)
			.toString()
			.padStart(places + 1, '0');
		const whole = BigInt(places ? rounded.slice(0, -places) : rounded);
		// Format fractional digits separately to preserve locale numbering systems.
		const localizedFraction = places
			? formatter
					.formatToParts(BigInt('1' + rounded.slice(-places)))
					.filter((part) => part.type === 'integer')
					.map((part) => part.value)
					.join('')
					.slice(1)
			: '';
		const parts = formatter
			.formatToParts(negative ? (whole === 0n ? -0 : -whole) : whole)
			.map((part) => ({
				...part,
				value: String(
					(
						{
							fraction: localizedFraction,
							group: hints.price_thousand_separator,
							decimal: hints.price_decimal_separator,
							currency: currencySymbol,
						} as Record<string, unknown>
					)[part.type] ?? part.value
				),
			}));
		if (!hints.currency_position) return parts.map((part) => part.value).join('');
		const symbol = parts.find((part) => part.type === 'currency')?.value ?? currency;
		const amount = parts
			.filter((part) => ['integer', 'group', 'decimal', 'fraction'].includes(part.type))
			.map((part) => part.value)
			.join('');
		const position = String(hints.currency_position);
		const space = position.endsWith('_space') ? ' ' : '';
		return (
			(negative ? '-' : '') +
			(position.startsWith('right') ? amount + space + symbol : symbol + space + amount)
		);
	};
	const date = (gmt: unknown) => {
		const instant = new Date(gmt ? String(gmt).replace(' ', 'T') + 'Z' : NaN);

		const hourToken = typeof hints.hour_token === 'string' ? hints.hour_token : '';
		const hour12 = hourToken ? hourToken[0] === 'h' : hints.hour12;
		const format = (options: Intl.DateTimeFormatOptions, language = locale) => {
			if (Number.isNaN(instant.getTime())) return '';
			const formatter = new Intl.DateTimeFormat(language, {
				timeZone: String(breakdowns.timezone || hints.timezone || store.timezone || 'UTC'),
				...(typeof hour12 === 'boolean' ? { hourCycle: hour12 ? 'h12' : 'h23' } : {}),
				...options,
			});
			if (!hourToken || !options.timeStyle) return formatter.format(instant);
			const zero = new Intl.NumberFormat(language, {
				useGrouping: false,
			}).format(0);
			return formatter
				.formatToParts(instant)
				.map((part) => {
					if (part.type !== 'hour') return part.value;
					const hour =
						part.value.length > 1 && part.value.startsWith(zero)
							? part.value.slice(zero.length)
							: part.value;
					return hourToken.length === 2 ? hour.padStart(2, zero) : hour;
				})
				.join('')
				.replace(/ /g, ' ');
		};
		const result: Record<string, string> = {
			datetime: format({ dateStyle: 'medium', timeStyle: 'short' }),
			date: format({ dateStyle: 'medium' }),
			time: format({ timeStyle: 'short' }),
			date_ymd: format({ year: 'numeric', month: '2-digit', day: '2-digit' }, 'en-CA'),
			date_dmy: format({ year: 'numeric', month: '2-digit', day: '2-digit' }, 'en-GB'),
			date_mdy: format({ year: 'numeric', month: '2-digit', day: '2-digit' }, 'en-US'),
		};
		for (const style of ['short', 'long', 'full'] as const) {
			result[`datetime_${style}`] = format({
				dateStyle: style,
				timeStyle: 'short',
			});
			result[`date_${style}`] = format({ dateStyle: style });
		}
		for (const field of ['weekday', 'month'] as const)
			for (const style of ['short', 'long'] as const)
				result[`${field}_${style}`] = format({ [field]: style });
		for (const field of ['day', 'month', 'year'] as const)
			result[field] = format({
				[field]: field === 'year' ? 'numeric' : '2-digit',
			});
		return result;
	};
	const withMoney = (row: Record<string, unknown>, fields: string[]) => {
		for (const field of fields) row[`${field}_display`] ??= money(row[field]);
		return row;
	};
	const title = (method: string) =>
		method.replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
	const recordEntries = (value: unknown) =>
		Object.entries((value ?? {}) as Record<string, unknown>).filter(
			(entry): entry is [string, Record<string, unknown>] =>
				entry[1] !== null && typeof entry[1] === 'object' && !Array.isArray(entry[1])
		);
	const payments = recordEntries(breakdowns.payment_methods);
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
	for (const field of ['opened_at', 'closed_at'])
		closure[field] ??= date(closure[`${field}_gmt`]);
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
		const rows = recordEntries(breakdowns[section]);
		closure[`has_${section}`] ??= rows.length > 0;
		breakdowns[section] = rows.map(([key, row]) => {
			if (section === 'payment_methods') row.method ??= key;
			if (section !== 'movements') row.name ??= row.method ?? row.rate ?? key;
			else {
				row.created_at ??= date(row.created_at_gmt);
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
