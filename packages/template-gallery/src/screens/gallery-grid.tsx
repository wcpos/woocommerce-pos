import * as React from 'react';

import { ActiveTemplatesTable } from '../components/active-templates-table';
import { FilterSidebar, DEFAULT_FILTERS } from '../components/filter-sidebar';
import { PreviewModal } from '../components/preview-modal';
import { TemplateCard } from '../components/template-card';
import {
	useGalleryTemplates,
	useInstallGalleryTemplate,
} from '../hooks/use-gallery-templates';
import {
	useTemplates,
	useToggleTemplate,
	useReorderTemplates,
	useDeleteTemplate,
} from '../hooks/use-templates';

import type { FilterState } from '../components/filter-sidebar';
import type { AnyTemplate, GalleryTemplate, Template } from '../types';

const CATEGORIES = [
	'receipt',
	'invoice',
	'gift-receipt',
	'credit-note',
	'purchase-order',
	'kitchen-ticket',
];

function matchesFilters(
	template: { title: string; category: string; engine?: string; offline_capable?: boolean; output_type?: string; is_premade?: boolean },
	filters: FilterState,
): boolean {
	if (filters.search && !template.title.toLowerCase().includes(filters.search.toLowerCase())) {
		return false;
	}

	if (filters.categories.length > 0 && !filters.categories.includes(template.category)) {
		return false;
	}

	const isOffline =
		template.engine === 'logicless' ||
		template.engine === 'thermal' ||
		template.offline_capable === true;

	if (filters.connectivity === 'offline' && !isOffline) {
		return false;
	}
	if (filters.connectivity === 'online' && isOffline) {
		return false;
	}

	if (filters.output !== 'all' && template.output_type !== filters.output) {
		return false;
	}

	if (filters.source === 'custom' && template.is_premade !== false) {
		return false;
	}
	if (filters.source === 'builtin' && template.is_premade !== true) {
		return false;
	}

	return true;
}

export function GalleryGrid() {
	const [filters, setFilters] = React.useState<FilterState>({ ...DEFAULT_FILTERS });
	const [sidebarCollapsed, setSidebarCollapsed] = React.useState(false);
	const [previewId, setPreviewId] = React.useState<number | string | null>(null);

	const { data: templates = [] } = useTemplates('receipt');
	const { data: galleryTemplates = [] } = useGalleryTemplates('receipt');
	const toggleTemplate = useToggleTemplate();
	const installGallery = useInstallGalleryTemplate();
	const reorderTemplates = useReorderTemplates();
	const deleteTemplate = useDeleteTemplate();

	const filteredGallery = galleryTemplates.filter((t: GalleryTemplate) =>
		matchesFilters(t, filters),
	);

	const customTemplates = templates.filter(
		(t: AnyTemplate): t is Template => typeof t.id === 'number',
	);

	const filteredCustom = customTemplates.filter((t: Template) =>
		matchesFilters(t, filters),
	);

	const adminUrl = (window as any).wcpos?.templateGallery?.adminUrl ?? `${window.location.origin}/wp-admin`;

	const editUrl = (id: number) =>
		`${adminUrl}/post.php?post=${id}&action=edit`;

	// Find the template being previewed
	const previewTemplate = previewId !== null
		? (templates.find((t: AnyTemplate) => t.id === previewId) ??
			galleryTemplates.find((t: GalleryTemplate) => t.key === previewId))
		: null;

	const previewIsGallery = previewTemplate ? 'key' in previewTemplate : false;
	const previewTemplateId = previewTemplate
		? ('key' in previewTemplate ? previewTemplate.key : previewTemplate.id)
		: null;

	return (
		<div className="wcpos:space-y-6">
			{/* Active Templates Table section */}
			<section>
				<h2 className="wcpos:text-base wcpos:font-medium wcpos:text-gray-700 wcpos:mb-3 wcpos:m-0">
					Active Templates
				</h2>
				<ActiveTemplatesTable
					templates={templates}
					onPreview={setPreviewId}
					onDisable={(id) => { if (typeof id === 'number') toggleTemplate.mutate({ id, status: 'draft' }); }}
					onDelete={(id) => deleteTemplate.mutate(id)}
					onReorder={(updates) => reorderTemplates.mutate(updates)}
					togglingId={toggleTemplate.isPending && typeof toggleTemplate.variables === 'object' ? toggleTemplate.variables?.id ?? null : null}
					deletingId={deleteTemplate.isPending ? deleteTemplate.variables ?? null : null}
				/>
			</section>

			{/* Receipt Template Gallery */}
			<section>
				<h2 className="wcpos:text-base wcpos:font-medium wcpos:text-gray-700 wcpos:mb-3 wcpos:m-0">
					Receipt Template Gallery
				</h2>
				<div className="wcpos:flex wcpos:gap-6">
					<FilterSidebar
						filters={filters}
						onChange={setFilters}
						availableCategories={CATEGORIES}
						collapsed={sidebarCollapsed}
						onToggleCollapse={() => setSidebarCollapsed((prev) => !prev)}
					/>

					<div className="wcpos:flex-1 wcpos:min-w-0 wcpos:space-y-6">
						{/* Gallery templates section */}
						{galleryTemplates.length > 0 && (
							<section>
								<h3 className="wcpos:text-sm wcpos:font-semibold wcpos:text-gray-600 wcpos:mb-3 wcpos:m-0">
									Gallery Templates
								</h3>
									{filteredGallery.length > 0 ? (
									<div className="wcpos:grid wcpos:grid-cols-2 wcpos:sm:grid-cols-3 wcpos:gap-4">
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
								) : (
									<p className="wcpos:text-sm wcpos:text-gray-400 wcpos:py-4">
										No gallery templates match your filters.
									</p>
								)}
							</section>
						)}

						{/* Custom templates section */}
						<section>
							<h3 className="wcpos:text-sm wcpos:font-semibold wcpos:text-gray-600 wcpos:mb-3 wcpos:m-0">
								Your Templates
							</h3>
							{customTemplates.length === 0 && (
								<p className="wcpos:text-sm wcpos:text-gray-400 wcpos:mb-3">
									No custom templates yet. Create one or customise a gallery template to get started.
								</p>
							)}
							<div className="wcpos:grid wcpos:grid-cols-2 wcpos:sm:grid-cols-3 wcpos:gap-4">
								{filteredCustom.map((t: Template) => (
									<TemplateCard
										key={t.id}
										template={t}
										isGallery={false}
										onPreview={() => setPreviewId(t.id)}
										onActivate={() =>
											toggleTemplate.mutate({
												id: t.id,
												status: t.status === 'publish' ? 'draft' : 'publish',
											})
										}
										onEdit={() => {
											window.location.href = editUrl(t.id);
										}}
										isToggling={
											toggleTemplate.isPending &&
											typeof toggleTemplate.variables === 'object' &&
											toggleTemplate.variables?.id === t.id
										}
									/>
								))}

								{/* New template card */}
								<a
									href={`${adminUrl}/post-new.php?post_type=wcpos_template`}
									className="wcpos:border-2 wcpos:border-dashed wcpos:border-gray-300 wcpos:rounded-lg wcpos:flex wcpos:items-center wcpos:justify-center wcpos:min-h-48 hover:wcpos:border-gray-400 wcpos:text-gray-400 hover:wcpos:text-gray-500 wcpos:no-underline"
								>
									<span className="wcpos:text-sm wcpos:font-medium">
										+ New Template
									</span>
								</a>
							</div>
						</section>
					</div>
				</div>
			</section>

			{/* Preview modal */}
			{previewTemplate && (
				<PreviewModal
					templateId={previewTemplateId ?? ''}
					templateName={previewTemplate.title}
					templateDescription={previewTemplate.description}
					isGallery={previewIsGallery}
					onClose={() => setPreviewId(null)}
					onActivate={() => {
						if (typeof previewId !== 'number') return;
						const latestTemplate = templates.find((template): template is Template =>
							typeof template.id === 'number' && template.id === previewId,
						);
						if (!latestTemplate) return;

						toggleTemplate.mutate({
							id: latestTemplate.id,
							status: latestTemplate.status === 'publish' ? 'draft' : 'publish',
						});
					}}
					onCustomize={() => {
						if (!previewIsGallery) return;
						const t = previewTemplate as GalleryTemplate;
						installGallery.mutate(t.key);
					}}
				/>
			)}
		</div>
	);
}
