export interface FilterState {
	search: string;
	categories: string[];
	connectivity: 'all' | 'offline' | 'online';
	output: 'all' | 'html' | 'thermal';
	source: 'all' | 'custom' | 'builtin';
}

export const DEFAULT_FILTERS: FilterState = {
	search: '',
	categories: [],
	connectivity: 'all',
	output: 'all',
	source: 'all',
};

interface FilterSidebarProps {
	filters: FilterState;
	onChange: (filters: FilterState) => void;
	availableCategories: string[];
	collapsed: boolean;
	onToggleCollapse: () => void;
}

interface RadioGroupProps {
	label: string;
	name: string;
	value: string;
	options: { value: string; label: string }[];
	onChange: (value: string) => void;
}

function formatLabel(slug: string): string {
	if (slug === 'all') return 'All';
	return slug
		.replace(/-/g, ' ')
		.replace(/\b\w/g, (c) => c.toUpperCase());
}

function RadioGroup({ label, name, value, options, onChange }: RadioGroupProps) {
	return (
		<fieldset className="wcpos:space-y-2 wcpos:border-0 wcpos:p-0 wcpos:m-0">
			<legend className="wcpos:text-xs wcpos:font-semibold wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wide wcpos:p-0">
				{label}
			</legend>
			{options.map((option) => (
				<label
					key={option.value}
					className="wcpos:flex wcpos:items-center wcpos:gap-2 wcpos:text-sm wcpos:text-gray-700 wcpos:cursor-pointer"
				>
					<input
						type="radio"
						name={name}
						value={option.value}
						checked={value === option.value}
						onChange={() => onChange(option.value)}
						className="wcpos:accent-wp-admin-theme-color"
					/>
					{option.label}
				</label>
			))}
		</fieldset>
	);
}

function isFiltered(filters: FilterState): boolean {
	return (
		filters.search !== '' ||
		filters.categories.length > 0 ||
		filters.connectivity !== 'all' ||
		filters.output !== 'all' ||
		filters.source !== 'all'
	);
}

export function FilterSidebar({
	filters,
	onChange,
	availableCategories,
	collapsed,
	onToggleCollapse,
}: FilterSidebarProps) {
	if (collapsed) {
		return (
			<div className="wcpos:shrink-0">
				<button
					type="button"
					onClick={onToggleCollapse}
					aria-label="Expand filters"
					className="wcpos:p-2 wcpos:border wcpos:border-gray-300 wcpos:rounded-md wcpos:bg-white wcpos:cursor-pointer wcpos:text-gray-600 hover:wcpos:border-gray-400"
				>
					&#9776;
				</button>
			</div>
		);
	}

	const handleSearchChange = (value: string) => {
		onChange({ ...filters, search: value });
	};

	const handleCategoryToggle = (category: string) => {
		const next = filters.categories.includes(category)
			? filters.categories.filter((c) => c !== category)
			: [...filters.categories, category];
		onChange({ ...filters, categories: next });
	};

	const handleClear = () => {
		onChange({ ...DEFAULT_FILTERS });
	};

	return (
		<div className="wcpos:shrink-0 wcpos:w-56 wcpos:space-y-5">
			{/* Header */}
			<div className="wcpos:flex wcpos:items-center wcpos:justify-between">
				<span className="wcpos:text-sm wcpos:font-semibold wcpos:text-gray-900">
					Filters
				</span>
				<button
					type="button"
					onClick={onToggleCollapse}
					aria-label="Collapse filters"
					className="wcpos:bg-transparent wcpos:border-0 wcpos:p-0 wcpos:cursor-pointer wcpos:text-gray-400 hover:wcpos:text-gray-600 wcpos:text-lg wcpos:leading-none"
				>
					&#10005;
				</button>
			</div>

			{/* Search */}
			<input
				type="search"
				aria-label="Search templates"
				placeholder="Search templates..."
				value={filters.search}
				onChange={(e) => handleSearchChange(e.target.value)}
				className="wcpos:w-full wcpos:px-3 wcpos:py-1.5 wcpos:border wcpos:border-gray-300 wcpos:rounded-md wcpos:text-sm focus:wcpos:outline-none focus:wcpos:ring-1 focus:wcpos:ring-wp-admin-theme-color focus:wcpos:border-wp-admin-theme-color"
			/>

			{/* Categories */}
			<fieldset className="wcpos:space-y-2 wcpos:border-0 wcpos:p-0 wcpos:m-0">
				<legend className="wcpos:text-xs wcpos:font-semibold wcpos:text-gray-500 wcpos:uppercase wcpos:tracking-wide wcpos:p-0">
					Category
				</legend>
				{availableCategories.map((cat) => (
					<label
						key={cat}
						className="wcpos:flex wcpos:items-center wcpos:gap-2 wcpos:text-sm wcpos:text-gray-700 wcpos:cursor-pointer"
					>
						<input
							type="checkbox"
							checked={filters.categories.includes(cat)}
							onChange={() => handleCategoryToggle(cat)}
							className="wcpos:accent-wp-admin-theme-color"
						/>
						{formatLabel(cat)}
					</label>
				))}
			</fieldset>

			{/* Connectivity */}
			<RadioGroup
				label="Connectivity"
				name="filter-connectivity"
				value={filters.connectivity}
				options={[
					{ value: 'all', label: 'All' },
					{ value: 'offline', label: 'Offline capable' },
					{ value: 'online', label: 'Online only' },
				]}
				onChange={(v) =>
					onChange({
						...filters,
						connectivity: v as FilterState['connectivity'],
					})
				}
			/>

			{/* Output */}
			<RadioGroup
				label="Output"
				name="filter-output"
				value={filters.output}
				options={[
					{ value: 'all', label: 'All' },
					{ value: 'html', label: 'HTML' },
					{ value: 'thermal', label: 'Thermal' },
				]}
				onChange={(v) =>
					onChange({
						...filters,
						output: v as FilterState['output'],
					})
				}
			/>

			{/* Source */}
			<RadioGroup
				label="Source"
				name="filter-source"
				value={filters.source}
				options={[
					{ value: 'all', label: 'All' },
					{ value: 'custom', label: 'Custom' },
					{ value: 'builtin', label: 'Built-in' },
				]}
				onChange={(v) =>
					onChange({
						...filters,
						source: v as FilterState['source'],
					})
				}
			/>

			{/* Clear all */}
			{isFiltered(filters) && (
				<button
					type="button"
					onClick={handleClear}
					className="wcpos:text-sm wcpos:text-wp-admin-theme-color wcpos:bg-transparent wcpos:border-0 wcpos:p-0 wcpos:cursor-pointer hover:wcpos:underline"
				>
					Clear all filters
				</button>
			)}
		</div>
	);
}
