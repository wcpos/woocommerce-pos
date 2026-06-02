import * as React from 'react';

interface CardGridProps extends React.HTMLAttributes<HTMLDivElement> {
	children: React.ReactNode;
}

export function CardGrid({ children, className = '', ...props }: CardGridProps) {
	return (
		<div
			className={`wcpos:grid wcpos:grid-cols-[repeat(auto-fill,minmax(min(100%,340px),1fr))] wcpos:gap-4 ${className}`}
			{...props}
		>
			{children}
		</div>
	);
}
