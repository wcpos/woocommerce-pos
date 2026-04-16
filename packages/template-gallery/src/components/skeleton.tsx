import { Skeleton } from '@wcpos/ui';

export { Skeleton };

/**
 * Skeleton for the gallery grid page: templates table + card grid.
 */
export function GalleryGridSkeleton() {
	return (
		<div className="wcpos:flex wcpos:flex-col wcpos:gap-6" role="status" aria-label="Loading">
			{/* Your Templates section */}
			<section>
				<div className="wcpos:flex wcpos:items-center wcpos:gap-3 wcpos:mb-3">
					<Skeleton className="wcpos:h-5" width="140px" />
					<Skeleton className="wcpos:h-7 wcpos:rounded" width="80px" />
				</div>
				{/* Table skeleton */}
				<div className="wcpos:border wcpos:border-gray-200 wcpos:rounded-lg wcpos:overflow-hidden">
					{/* Header row */}
					<div className="wcpos:flex wcpos:items-center wcpos:gap-4 wcpos:px-4 wcpos:py-2.5 wcpos:bg-gray-50 wcpos:border-b wcpos:border-gray-200">
						<Skeleton className="wcpos:h-3 wcpos:w-6" />
						<Skeleton className="wcpos:h-3 wcpos:flex-1" width="20%" />
						<Skeleton className="wcpos:h-3" width="70px" />
						<Skeleton className="wcpos:h-3" width="60px" />
						<Skeleton className="wcpos:h-3" width="70px" />
						<Skeleton className="wcpos:h-3" width="50px" />
						<Skeleton className="wcpos:h-3" width="50px" />
						<Skeleton className="wcpos:h-3" width="70px" />
					</div>
					{/* Data rows */}
					{Array.from({ length: 3 }).map((_, i) => (
						<div
							key={i}
							className="wcpos:flex wcpos:items-center wcpos:gap-4 wcpos:px-4 wcpos:py-3 wcpos:border-b wcpos:border-gray-100 last:wcpos:border-b-0"
						>
							<Skeleton className="wcpos:h-4 wcpos:w-6" />
							<Skeleton className="wcpos:h-4 wcpos:flex-1" width={`${40 + (i * 15) % 30}%`} />
							<Skeleton className="wcpos:h-5 wcpos:rounded-full" width="70px" />
							<Skeleton className="wcpos:h-5 wcpos:rounded-full" width="60px" />
							<Skeleton className="wcpos:h-5 wcpos:rounded-full" width="70px" />
							<Skeleton className="wcpos:h-5 wcpos:rounded-full" width="50px" />
							<Skeleton className="wcpos:h-5 wcpos:w-9 wcpos:rounded-full" />
							<Skeleton className="wcpos:h-7 wcpos:rounded" width="70px" />
						</div>
					))}
				</div>
			</section>

			{/* Template Gallery section */}
			<section>
				<Skeleton className="wcpos:h-5 wcpos:mb-1" width="160px" />
				<Skeleton className="wcpos:h-3 wcpos:mb-3" width="300px" />
				<div className="wcpos:flex wcpos:gap-6">
					{/* Filter sidebar skeleton */}
					<div className="wcpos:w-48 wcpos:shrink-0 wcpos:space-y-4">
						<Skeleton className="wcpos:h-9 wcpos:w-full wcpos:rounded-md" />
						<div className="wcpos:space-y-2">
							<Skeleton className="wcpos:h-4" width="80px" />
							{Array.from({ length: 4 }).map((_, i) => (
								<div key={i} className="wcpos:flex wcpos:items-center wcpos:gap-2">
									<Skeleton className="wcpos:h-4 wcpos:w-4 wcpos:rounded wcpos:shrink-0" />
									<Skeleton className="wcpos:h-3" width={`${60 + (i * 20) % 40}%`} />
								</div>
							))}
						</div>
					</div>
					{/* Card grid skeleton */}
					<div className="wcpos:flex-1 wcpos:grid wcpos:grid-cols-2 wcpos:sm:grid-cols-3 wcpos:gap-4">
						{Array.from({ length: 6 }).map((_, i) => (
							<div
								key={i}
								className="wcpos:border wcpos:border-gray-200 wcpos:rounded-lg wcpos:overflow-hidden"
							>
								<Skeleton className="wcpos:aspect-[4/3] wcpos:w-full wcpos:rounded-none" />
								<div className="wcpos:p-3 wcpos:space-y-2">
									<Skeleton className="wcpos:h-4" width="70%" />
									<Skeleton className="wcpos:h-3" width="90%" />
									<Skeleton className="wcpos:h-3" width="60%" />
									<div className="wcpos:flex wcpos:gap-1 wcpos:pt-1">
										<Skeleton className="wcpos:h-5 wcpos:rounded-full" width="50px" />
										<Skeleton className="wcpos:h-5 wcpos:rounded-full" width="60px" />
									</div>
								</div>
							</div>
						))}
					</div>
				</div>
			</section>
		</div>
	);
}
