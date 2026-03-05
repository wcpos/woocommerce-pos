import * as React from 'react';

import { CategoryPills } from '../components/category-pills';
import { SearchField } from '../components/search-field';
import { TemplateCard } from '../components/template-card';
import { useTemplates, useToggleTemplate } from '../hooks/use-templates';
import {
	useGalleryTemplates,
	useInstallGalleryTemplate,
} from '../hooks/use-gallery-templates';

import type { AnyTemplate, GalleryTemplate } from '../types';

const CATEGORIES = [
	'receipt',
	'invoice',
	'gift-receipt',
	'credit-note',
	'purchase-order',
	'kitchen-ticket',
];

export function GalleryGrid() {
	const [category, setCategory] = React.useState('all');
	const [search, setSearch] = React.useState('');
	const [previewId, setPreviewId] = React.useState<number | string | null>(null);

	const { data: templates = [] } = useTemplates('receipt');
	const { data: galleryTemplates = [] } = useGalleryTemplates('receipt');
	const toggleTemplate = useToggleTemplate();
	const installGallery = useInstallGalleryTemplate();

	const matchesFilter = React.useCallback(
		(name: string, cat: string) => {
			const matchesCategory = category === 'all' || cat === category;
			const matchesSearch =
				!search || name.toLowerCase().includes(search.toLowerCase());
			return matchesCategory && matchesSearch;
		},
		[category, search],
	);

	const filteredGallery = galleryTemplates.filter((t: GalleryTemplate) =>
		matchesFilter(t.title, t.category),
	);

	const filteredCustom = templates.filter((t: AnyTemplate) =>
		matchesFilter(t.title, t.category),
	);

	const activeCount = templates.filter(
		(t: AnyTemplate) => 'is_active' in t && t.is_active,
	).length;

	const editUrl = (id: number) =>
		`${window.location.origin}/wp-admin/post.php?post=${id}&action=edit`;

	// Find the template being previewed
	const previewTemplate = previewId
		? (templates.find((t: AnyTemplate) => t.id === previewId) ??
			galleryTemplates.find((t: GalleryTemplate) => t.key === previewId))
		: null;

	const previewIsGallery = previewTemplate
		? 'key' in previewTemplate
		: false;

	return (
		<div className="wcpos:space-y-6">
			{/* Filter bar */}
			<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:gap-4 wcpos:flex-wrap">
				<CategoryPills
					categories={CATEGORIES}
					active={category}
					onChange={setCategory}
				/>
				<SearchField value={search} onChange={setSearch} />
			</div>

			{/* Gallery templates section */}
			{filteredGallery.length > 0 && (
				<section>
					<h2 className="wcpos:text-base wcpos:font-medium wcpos:text-gray-700 wcpos:mb-3 wcpos:m-0">
						Gallery Templates
					</h2>
					<div className="wcpos:grid wcpos:grid-cols-2 wcpos:sm:grid-cols-3 wcpos:lg:grid-cols-4 wcpos:gap-4">
						{filteredGallery.map((t: GalleryTemplate) => (
							<TemplateCard
								key={t.key}
								template={t}
								isGallery
								onPreview={() => setPreviewId(t.key)}
								onCustomize={() => installGallery.mutate(t.key)}
							/>
						))}
					</div>
				</section>
			)}

			{/* Custom templates section */}
			<section>
				<h2 className="wcpos:text-base wcpos:font-medium wcpos:text-gray-700 wcpos:mb-3 wcpos:m-0">
					Your Templates
				</h2>
				<div className="wcpos:grid wcpos:grid-cols-2 wcpos:sm:grid-cols-3 wcpos:lg:grid-cols-4 wcpos:gap-4">
					{filteredCustom.map((t: AnyTemplate) => (
						<TemplateCard
							key={t.id}
							template={t}
							isGallery={false}
							onPreview={() => setPreviewId(t.id)}
							onActivate={() => {
								if (typeof t.id === 'number') {
									toggleTemplate.mutate({
										id: t.id,
										status: t.is_active ? 'draft' : 'publish',
									});
								}
							}}
							onEdit={() => {
								if (typeof t.id === 'number') {
									window.location.href = editUrl(t.id);
								}
							}}
							isToggling={toggleTemplate.isPending}
						/>
					))}

					{/* New template card */}
					<a
						href={`${window.location.origin}/wp-admin/post-new.php?post_type=wcpos_template`}
						className="wcpos:border-2 wcpos:border-dashed wcpos:border-gray-300 wcpos:rounded-lg wcpos:flex wcpos:items-center wcpos:justify-center wcpos:min-h-48 hover:wcpos:border-gray-400 wcpos:text-gray-400 hover:wcpos:text-gray-500 wcpos:no-underline"
					>
						<span className="wcpos:text-sm wcpos:font-medium">
							+ New Template
						</span>
					</a>
				</div>
			</section>

			{/* Status bar */}
			<div className="wcpos:text-sm wcpos:text-gray-500">
				Active templates: {activeCount} of {templates.length}
			</div>

			{/* Preview modal placeholder -- wired in Task 6 */}
			{previewTemplate && (
				<div
					className="wcpos:fixed wcpos:inset-0 wcpos:z-50 wcpos:flex wcpos:items-center wcpos:justify-center wcpos:bg-black/50"
					onClick={() => setPreviewId(null)}
					onKeyDown={(e) => e.key === 'Escape' && setPreviewId(null)}
					role="dialog"
					aria-modal="true"
				>
					<div
						className="wcpos:bg-white wcpos:rounded-lg wcpos:shadow-xl wcpos:p-6 wcpos:max-w-lg wcpos:text-center"
						onClick={(e) => e.stopPropagation()}
					>
						<h2 className="wcpos:text-lg wcpos:font-semibold wcpos:m-0 wcpos:mb-2">
							{previewIsGallery
								? (previewTemplate as GalleryTemplate).title
								: (previewTemplate as AnyTemplate).title}
						</h2>
						<p className="wcpos:text-gray-500 wcpos:text-sm">
							Full preview modal coming in the next commit.
						</p>
						<button
							type="button"
							onClick={() => setPreviewId(null)}
							className="wcpos:mt-4 wcpos:px-4 wcpos:py-2 wcpos:text-sm wcpos:bg-wp-admin-theme-color wcpos:text-white wcpos:border-0 wcpos:rounded wcpos:cursor-pointer"
						>
							Close
						</button>
					</div>
				</div>
			)}
		</div>
	);
}
