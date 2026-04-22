import { groupByDay } from '../group-by-day';

describe('groupByDay', () => {
	const NOW = new Date('2026-04-23T15:00:00Z').getTime();

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

		const groups = groupByDay(entries, NOW);

		expect(groups).toHaveLength(3);
		expect(groups[0].label).toBe('today');
		expect(groups[0].entries).toHaveLength(2);
		expect(groups[1].label).toBe('yesterday');
		expect(groups[1].entries).toHaveLength(1);
		expect(groups[2].label).toBe('2026-04-20');
		expect(groups[2].entries).toHaveLength(1);
	});

	it('returns an empty array for empty input', () => {
		expect(groupByDay([], NOW)).toEqual([]);
	});

	it('preserves entry order within a group', () => {
		const a = entry('2026-04-23T10:00:00+00:00');
		const b = entry('2026-04-23T08:00:00+00:00');
		const groups = groupByDay([a, b], NOW);
		expect(groups[0].entries).toEqual([a, b]);
	});

	it('treats entries with unparseable timestamps as their literal date string', () => {
		const groups = groupByDay([entry('not-a-date')], NOW);
		expect(groups).toHaveLength(1);
		expect(groups[0].label).toBe('not-a-date');
	});
});
