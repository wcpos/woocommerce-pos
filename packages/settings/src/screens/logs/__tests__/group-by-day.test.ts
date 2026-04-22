import { groupByDay } from '../group-by-day';

describe('groupByDay', () => {
	const NOW = new Date('2026-04-23T15:00:00Z').getTime();
	const UTC = { timeZone: 'UTC' };

	function entry(timestamp: string) {
		return { timestamp, level: 'info', message: 'hello', context: '' };
	}

	it('buckets entries into Today / Yesterday / ISO-date labels', () => {
		const entries = [
			entry('2026-04-23T10:00:00+00:00'),
			entry('2026-04-23T08:00:00+00:00'),
			entry('2026-04-22T22:00:00+00:00'),
			entry('2026-04-20T09:00:00+00:00'),
		];

		const groups = groupByDay(entries, NOW, UTC);

		expect(groups).toHaveLength(3);
		expect(groups[0].label).toBe('today');
		expect(groups[0].entries).toHaveLength(2);
		expect(groups[1].label).toBe('yesterday');
		expect(groups[1].entries).toHaveLength(1);
		expect(groups[2].label).toBe('2026-04-20');
		expect(groups[2].entries).toHaveLength(1);
	});

	it('returns an empty array for empty input', () => {
		expect(groupByDay([], NOW, UTC)).toEqual([]);
	});

	it('preserves entry order within a group', () => {
		const a = entry('2026-04-23T10:00:00+00:00');
		const b = entry('2026-04-23T08:00:00+00:00');
		const groups = groupByDay([a, b], NOW, UTC);
		expect(groups[0].entries).toEqual([a, b]);
	});

	it('treats entries with unparseable timestamps as their literal date string', () => {
		const groups = groupByDay([entry('not-a-date')], NOW, UTC);
		expect(groups).toHaveLength(1);
		expect(groups[0].label).toBe('not-a-date');
	});

	it('buckets by local wallclock when a timezone is provided', () => {
		// 2026-04-23T14:00Z is 2026-04-24 00:00 in Sydney (UTC+10).
		// When "now" is 2026-04-24T01:00Z (11:00 Sydney on the 24th),
		// the 14:00Z entry should bucket as "today" in Sydney.
		const sydneyNow = new Date('2026-04-24T01:00:00Z').getTime();
		const groups = groupByDay(
			[entry('2026-04-23T14:00:00+00:00')],
			sydneyNow,
			{ timeZone: 'Australia/Sydney' }
		);
		expect(groups[0].label).toBe('today');
	});

	it('labels "yesterday" by calendar day across a DST spring-forward', () => {
		// Sydney spring-forward: 2024-10-06 at 02:00 AEST → 03:00 AEDT
		// (UTC+10 → UTC+11). "now" = 2024-10-07 00:30 AEDT = 2024-10-06T13:30Z.
		// A naive nowMs - 24h lands at 2024-10-05T13:30Z = 2024-10-05 23:30 AEST,
		// which would mislabel yesterday (Oct 6) entries with an ISO date and
		// move the "yesterday" bucket onto Oct 5.
		const sydneyNow = new Date('2024-10-06T13:30:00Z').getTime();
		const groups = groupByDay(
			[
				// 2024-10-07 00:00 AEDT (today in Sydney)
				entry('2024-10-06T13:00:00+00:00'),
				// 2024-10-06 12:00 AEDT (yesterday in Sydney)
				entry('2024-10-06T01:00:00+00:00'),
				// 2024-10-05 20:00 AEST (two days ago in Sydney)
				entry('2024-10-05T10:00:00+00:00'),
			],
			sydneyNow,
			{ timeZone: 'Australia/Sydney' }
		);
		expect(groups.map((g) => g.label)).toEqual(['today', 'yesterday', '2024-10-05']);
	});
});
