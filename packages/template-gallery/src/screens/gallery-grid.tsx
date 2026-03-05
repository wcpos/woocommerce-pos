import * as React from 'react';

import { monitorForElements } from '@atlaskit/pragmatic-drag-and-drop/element/adapter';
import { extractClosestEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/closest-edge';
import { reorderWithEdge } from '@atlaskit/pragmatic-drag-and-drop-hitbox/util/reorder-with-edge';

import { CategoryPills } from '../components/category-pills';
import { DraggableCard } from '../components/draggable-card';
import { PreviewModal } from '../components/preview-modal';
import { SearchField } from '../components/search-field';
import { TemplateCard } from '../components/template-card';
import {
	useGalleryTemplates,
	useInstallGalleryTemplate,
} from '../hooks/use-gallery-templates';
import {
	useTemplates,
	useToggleTemplate,
	useReorderTemplates,
} from '../hooks/use-templates';

import type { AnyTemplate, GalleryTemplate, Template } from '../types';

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
	const reorderTemplates = useReorderTemplates();

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

	// Only database templates (with numeric IDs) are draggable
	const customTemplates = templates.filter(
		(t: AnyTemplate): t is Template => typeof t.id === 'number',
	);

	const filteredCustom = customTemplates.filter((t: Template) =>
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

	const previewIsGallery = previewTemplate ? 'key' in previewTemplate : false;

	// Monitor drag-and-drop reordering for custom templates
	React.useEffect(() => {
		return monitorForElements({
			onDrop: ({ source, location }) => {
				const target = location.current.dropTargets[0];
				if (!target) return;

				const sourceIndex = source.data.index as number;
				const targetIndex = target.data.index as number;
				const edge = extractClosestEdge(target.data);

				const reordered = reorderWithEdge({
					list: filteredCustom,
					startIndex: sourceIndex,
					indexOfTarget: targetIndex,
					closestEdgeOfTarget: edge,
					axis: 'horizontal',
				});

				const updates = reordered.map((t, idx) => ({
					id: t.id,
					menu_order: idx,
				}));

				reorderTemplates.mutate(updates);
			},
		});
	}, [filteredCustom, reorderTemplates]);

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
					{filteredCustom.map((t: Template, index: number) => (
						<DraggableCard key={t.id} id={t.id} index={index}>
							<TemplateCard
								template={t}
								isGallery={false}
								onPreview={() => setPreviewId(t.id)}
								onActivate={() =>
									toggleTemplate.mutate({
										id: t.id,
										status: t.is_active ? 'draft' : 'publish',
									})
								}
								onEdit={() => {
									window.location.href = editUrl(t.id);
								}}
								isToggling={toggleTemplate.isPending}
							/>
						</DraggableCard>
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

			{/* Preview modal */}
			{previewTemplate && (
				<PreviewModal
					templateId={
						previewIsGallery
							? (previewTemplate as GalleryTemplate).key
							: previewTemplate.id
					}
					templateName={previewTemplate.title}
					templateDescription={previewTemplate.description}
					isGallery={previewIsGallery}
					onClose={() => setPreviewId(null)}
					onActivate={() => {
						const t = previewTemplate as AnyTemplate;
						if (typeof t.id === 'number') {
							toggleTemplate.mutate({
								id: t.id,
								status: t.is_active ? 'draft' : 'publish',
							});
						}
					}}
					onCustomize={() => {
						const t = previewTemplate as GalleryTemplate;
						installGallery.mutate(t.key);
					}}
				/>
			)}
		</div>
	);
}
