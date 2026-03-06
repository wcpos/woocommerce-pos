interface SearchFieldProps {
	value: string;
	onChange: (value: string) => void;
}

export function SearchField({ value, onChange }: SearchFieldProps) {
	return (
		<input
			type="text"
			value={value}
			onChange={(e) => onChange(e.target.value)}
			placeholder="Search fields..."
			className="wcpos:w-full wcpos:px-2 wcpos:py-1.5 wcpos:border wcpos:border-gray-300 wcpos:rounded wcpos:text-sm"
		/>
	);
}
