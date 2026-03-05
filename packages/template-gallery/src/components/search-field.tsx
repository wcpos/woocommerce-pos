interface SearchFieldProps {
	value: string;
	onChange: (value: string) => void;
}

export function SearchField({ value, onChange }: SearchFieldProps) {
	return (
		<input
			type="search"
			placeholder="Search templates..."
			value={value}
			onChange={(e) => onChange(e.target.value)}
			className="wcpos:px-3 wcpos:py-1.5 wcpos:border wcpos:border-gray-300 wcpos:rounded-md wcpos:text-sm wcpos:w-64 focus:wcpos:outline-none focus:wcpos:ring-1 focus:wcpos:ring-wp-admin-theme-color focus:wcpos:border-wp-admin-theme-color"
		/>
	);
}
