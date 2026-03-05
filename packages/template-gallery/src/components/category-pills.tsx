import classnames from 'classnames';

interface CategoryPillsProps {
	categories: string[];
	active: string;
	onChange: (category: string) => void;
}

function formatLabel(slug: string): string {
	if (slug === 'all') return 'All';
	return slug
		.replace(/-/g, ' ')
		.replace(/\b\w/g, (c) => c.toUpperCase());
}

export function CategoryPills({ categories, active, onChange }: CategoryPillsProps) {
	const allCategories = Array.from(new Set(['all', ...categories.filter((category) => category !== 'all')]));

	return (
		<div className="wcpos:flex wcpos:gap-2 wcpos:flex-wrap">
			{allCategories.map((cat) => (
				<button
					key={cat}
					type="button"
					onClick={() => onChange(cat)}
					aria-pressed={cat === active}
					className={classnames(
						'wcpos:px-3 wcpos:py-1 wcpos:rounded-full wcpos:text-sm wcpos:border wcpos:cursor-pointer',
						cat === active
							? 'wcpos:bg-wp-admin-theme-color wcpos:text-white wcpos:border-wp-admin-theme-color'
							: 'wcpos:bg-white wcpos:text-gray-700 wcpos:border-gray-300 hover:wcpos:border-gray-400',
					)}
				>
					{formatLabel(cat)}
				</button>
			))}
		</div>
	);
}
