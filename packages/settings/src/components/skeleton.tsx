import classNames from 'classnames';

interface SkeletonProps {
	className?: string;
	width?: string;
	height?: string;
}

/**
 * Base skeleton pulse element — a rounded gray bar that animates.
 */
export function Skeleton({ className, width, height }: SkeletonProps) {
	return (
		<div
			className={classNames(
				'wcpos:animate-pulse wcpos:rounded wcpos:bg-gray-200',
				className
			)}
			style={{ width, height }}
		/>
	);
}

/**
 * A skeleton that mimics a FormSection with several FormRow items.
 */
export function FormSkeleton({ rows = 5 }: { rows?: number }) {
	return (
		<div className="wcpos:pb-4 wcpos:mb-4">
			<div className="wcpos:space-y-0">
				{Array.from({ length: rows }).map((_, i) => (
					<div
						key={i}
						className="wcpos:flex wcpos:flex-col wcpos:sm:flex-row wcpos:sm:items-center wcpos:gap-1 wcpos:sm:gap-3 wcpos:py-2.5"
					>
						{/* Label area — some rows have labels, some don't */}
						{i % 2 === 0 && (
							<div className="wcpos:sm:w-[30%] wcpos:sm:max-w-[200px] wcpos:shrink-0">
								<Skeleton className="wcpos:h-4" width={`${60 + (i * 17) % 40}%`} />
							</div>
						)}
						{/* Control area */}
						<div className="wcpos:flex-1 wcpos:min-w-0">
							<Skeleton
								className="wcpos:h-5"
								width={i % 2 === 0 ? '200px' : `${40 + (i * 23) % 30}%`}
							/>
						</div>
					</div>
				))}
			</div>
		</div>
	);
}

/**
 * Skeleton for card grid layouts (extensions page).
 */
export function CardGridSkeleton({ cards = 4 }: { cards?: number }) {
	return (
		<div>
			{/* Search bar skeleton */}
			<div className="wcpos:mb-4">
				<Skeleton className="wcpos:h-9 wcpos:w-full wcpos:rounded-md" />
			</div>
			{/* Category tabs skeleton */}
			<div className="wcpos:flex wcpos:gap-2 wcpos:mb-6">
				{Array.from({ length: 3 }).map((_, i) => (
					<Skeleton key={i} className="wcpos:h-7 wcpos:rounded-full" width={`${60 + i * 20}px`} />
				))}
			</div>
			{/* Card grid skeleton */}
			<div className="wcpos:grid wcpos:grid-cols-1 wcpos:sm:grid-cols-2 wcpos:gap-4">
				{Array.from({ length: cards }).map((_, i) => (
					<div
						key={i}
						className="wcpos:border wcpos:border-gray-200 wcpos:rounded-lg wcpos:p-4 wcpos:space-y-3"
					>
						<div className="wcpos:flex wcpos:items-center wcpos:gap-3">
							<Skeleton className="wcpos:h-10 wcpos:w-10 wcpos:rounded-lg wcpos:shrink-0" />
							<div className="wcpos:flex-1 wcpos:space-y-2">
								<Skeleton className="wcpos:h-4" width="60%" />
								<Skeleton className="wcpos:h-3" width="40%" />
							</div>
						</div>
						<Skeleton className="wcpos:h-3" width="90%" />
						<Skeleton className="wcpos:h-3" width="70%" />
					</div>
				))}
			</div>
		</div>
	);
}

/**
 * Skeleton for table/list layouts (logs, sessions).
 */
export function ListSkeleton({ rows = 8 }: { rows?: number }) {
	return (
		<div>
			{/* Filter bar */}
			<div className="wcpos:flex wcpos:gap-2 wcpos:mb-4">
				{Array.from({ length: 3 }).map((_, i) => (
					<Skeleton key={i} className="wcpos:h-7 wcpos:rounded-full" width={`${50 + i * 15}px`} />
				))}
			</div>
			{/* List items */}
			<div className="wcpos:space-y-1">
				{Array.from({ length: rows }).map((_, i) => (
					<div
						key={i}
						className="wcpos:flex wcpos:items-center wcpos:gap-3 wcpos:border wcpos:border-gray-200 wcpos:rounded-md wcpos:px-3 wcpos:py-2"
					>
						<Skeleton className="wcpos:h-5 wcpos:rounded wcpos:shrink-0" width="55px" />
						<Skeleton className="wcpos:h-3 wcpos:shrink-0" width="130px" />
						<Skeleton className="wcpos:h-3 wcpos:flex-1" width={`${50 + (i * 19) % 40}%`} />
					</div>
				))}
			</div>
		</div>
	);
}

/**
 * Skeleton for the access page (sidebar list + capability checkboxes).
 */
export function AccessSkeleton() {
	return (
		<>
			<div className="wcpos:p-4">
				<Skeleton className="wcpos:h-10 wcpos:w-full wcpos:rounded-md" />
			</div>
			<div className="wcpos:sm:grid wcpos:sm:grid-cols-3 wcpos:sm:gap-4 wcpos:p-4 wcpos:pt-0">
				{/* Role list */}
				<div>
					<div className="wcpos:space-y-1">
						{Array.from({ length: 5 }).map((_, i) => (
							<Skeleton key={i} className="wcpos:h-9 wcpos:rounded" />
						))}
					</div>
				</div>
				{/* Capability checkboxes */}
				<div>
					<Skeleton className="wcpos:h-5 wcpos:mb-3" width="80px" />
					<div className="wcpos:space-y-2">
						{Array.from({ length: 6 }).map((_, i) => (
							<div key={i} className="wcpos:flex wcpos:items-center wcpos:gap-2">
								<Skeleton className="wcpos:h-4 wcpos:w-4 wcpos:rounded wcpos:shrink-0" />
								<Skeleton className="wcpos:h-3" width={`${60 + (i * 17) % 30}%`} />
							</div>
						))}
					</div>
				</div>
			</div>
		</>
	);
}

/**
 * Skeleton for sessions page (toggle buttons + table).
 */
export function SessionsSkeleton() {
	return (
		<div className="wcpos:p-4">
			<Skeleton className="wcpos:h-10 wcpos:w-full wcpos:rounded-md wcpos:mb-3" />
			<div className="wcpos:mb-3 wcpos:flex wcpos:gap-2">
				<Skeleton className="wcpos:h-8 wcpos:rounded" width="90px" />
				<Skeleton className="wcpos:h-8 wcpos:rounded" width="100px" />
			</div>
			<div className="wcpos:space-y-2">
				{Array.from({ length: 4 }).map((_, i) => (
					<div
						key={i}
						className="wcpos:flex wcpos:items-center wcpos:gap-3 wcpos:border wcpos:border-gray-200 wcpos:rounded-md wcpos:p-3"
					>
						<Skeleton className="wcpos:h-8 wcpos:w-8 wcpos:rounded-full wcpos:shrink-0" />
						<div className="wcpos:flex-1 wcpos:space-y-1">
							<Skeleton className="wcpos:h-4" width="30%" />
							<Skeleton className="wcpos:h-3" width="50%" />
						</div>
						<Skeleton className="wcpos:h-7 wcpos:rounded" width="70px" />
					</div>
				))}
			</div>
		</div>
	);
}

/**
 * Skeleton for the license page.
 */
export function LicenseSkeleton() {
	return (
		<div className="wcpos:px-4 wcpos:py-5 wcpos:sm:grid wcpos:sm:grid-cols-3 wcpos:sm:gap-4">
			<div className="wcpos:flex wcpos:sm:justify-end">
				<Skeleton className="wcpos:h-4" width="90px" />
			</div>
			<div>
				<Skeleton className="wcpos:h-9 wcpos:w-full wcpos:rounded-md" />
			</div>
			<div>
				<Skeleton className="wcpos:h-9 wcpos:rounded" width="90px" />
			</div>
		</div>
	);
}
