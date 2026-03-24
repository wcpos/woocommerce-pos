import * as React from 'react';

import { FilterSidebar, DEFAULT_FILTERS } from '../components/filter-sidebar';
import { PreviewModal } from '../components/preview-modal';
import { TemplateCard } from '../components/template-card';
import { TemplatesTable } from '../components/active-templates-table';
import {
	useGalleryTemplates,
	useInstallGalleryTemplate,
} from '../hooks/use-gallery-templates';
import {
	useTemplates,
	useToggleTemplate,
	useToggleVirtualTemplate,
	useReorderTemplates,
	useDeleteTemplate,
} from '../hooks/use-templates';

import { t } from '../translations';
import type { FilterState } from '../components/filter-sidebar';
import type { AnyTemplate, GalleryTemplate, Template, VirtualTemplate } from '../types';

function matchesFilters(
	template: { title: string; category: string; engine?: string; output_type?: string },
	filters: FilterState,
): boolean {
	if (filters.search && !template.title.toLowerCase().includes(filters.search.toLowerCase())) {
		return false;
	}

	if (filters.categories.length > 0 && !filters.categories.includes(template.category)) {
		return false;
	}

	if (filters.output !== 'all') {
		const isThermal =
			template.output_type === 'escpos' ||
			template.output_type === 'thermal' ||
			template.engine === 'thermal';
		if (filters.output === 'escpos' && !isThermal) return false;
		if (filters.output === 'html' && isThermal) return false;
	}

	return true;
}

export function GalleryGrid() {
	const [filters, setFilters] = React.useState<FilterState>({ ...DEFAULT_FILTERS });
	const [sidebarCollapsed, setSidebarCollapsed] = React.useState(false);
	const [previewId, setPreviewId] = React.useState<number | string | null>(null);

	const type = 'receipt';

	const { data: templates = [] } = useTemplates(type);
	const { data: galleryTemplates = [] } = useGalleryTemplates(type);
	const toggleTemplate = useToggleTemplate();
	const toggleVirtualTemplate = useToggleVirtualTemplate(type);
	const installGallery = useInstallGalleryTemplate();
	const reorderTemplates = useReorderTemplates(type);
	const deleteTemplate = useDeleteTemplate();

	const filteredGallery = galleryTemplates.filter((t: GalleryTemplate) =>
		matchesFilters(t, filters),
	);

	const adminUrl = (window as any).wcpos?.templateGallery?.adminUrl ?? `${window.location.origin}/wp-admin`;

	// Find the template being previewed
	const previewTemplate = previewId !== null
		? (templates.find((t: AnyTemplate) => t.id === previewId) ??
			galleryTemplates.find((t: GalleryTemplate) => t.key === previewId))
		: null;

	const previewIsGallery = previewTemplate ? 'key' in previewTemplate : false;
	const previewTemplateId = previewTemplate
		? ('key' in previewTemplate ? previewTemplate.key : previewTemplate.id)
		: null;

	const handleToggle = (id: number | string) => {
		if (typeof id === 'string') {
			const vt = templates.find((t): t is VirtualTemplate => t.is_virtual && t.id === id);
			if (vt) {
				const isCurrentlyDisabled = 'is_disabled' in vt && vt.is_disabled;
				toggleVirtualTemplate.mutate({ id, disabled: !isCurrentlyDisabled });
			}
		} else {
			const t = templates.find((tmpl): tmpl is Template => !tmpl.is_virtual && tmpl.id === id);
			if (t) {
				toggleTemplate.mutate({
					id,
					status: t.status === 'publish' ? 'draft' : 'publish',
				});
			}
		}
	};

	const togglingId: number | string | null = (() => {
		if (toggleTemplate.isPending && toggleTemplate.variables != null) {
			return toggleTemplate.variables.id ?? null;
		}
		if (toggleVirtualTemplate.isPending && toggleVirtualTemplate.variables != null) {
			return toggleVirtualTemplate.variables.id ?? null;
		}
		return null;
	})();

	return (
		<div className="wcpos:flex wcpos:flex-col wcpos:gap-6">
			{/* Your Templates section */}
			<section>
				<div className="wcpos:flex wcpos:items-center wcpos:gap-3 wcpos:mb-3">
					<h2 className="wcpos:text-base wcpos:font-medium wcpos:text-gray-700 wcpos:m-0">
						{t('gallery.your_templates')}
					</h2>
					<a
						href={`${adminUrl}/post-new.php?post_type=wcpos_template`}
						className="page-title-action"
					>
						{t('gallery.add_new')}
					</a>
				</div>
				<TemplatesTable
					templates={templates}
					onPreview={setPreviewId}
					onToggle={handleToggle}
					onDelete={(id) => deleteTemplate.mutate(id)}
					onReorder={(orderedIds) => reorderTemplates.mutate(orderedIds)}
					togglingId={togglingId}
					deletingId={deleteTemplate.isPending ? deleteTemplate.variables ?? null : null}
				/>
			</section>

			{/* Template Gallery section */}
			{galleryTemplates.length > 0 && (
				<section>
					<h2 className="wcpos:text-base wcpos:font-medium wcpos:text-gray-700 wcpos:m-0">
						{t('gallery.template_gallery')}
					</h2>
					<p className="wcpos:text-sm wcpos:text-gray-500 wcpos:mt-1 wcpos:mb-3">
						{t('gallery.description')}
					</p>
					<div className="wcpos:flex wcpos:gap-6">
						<FilterSidebar
							filters={filters}
							onChange={setFilters}
							availableCategories={Array.from(
								new Set(
									galleryTemplates
										.map((t) => t.category)
										.filter((c) => c.length > 0),
								),
							)}
							collapsed={sidebarCollapsed}
							onToggleCollapse={() => setSidebarCollapsed((prev) => !prev)}
						/>

						<div className="wcpos:flex-1 wcpos:min-w-0">
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
									{t('gallery.no_matches')}
								</p>
							)}
						</div>
					</div>
				</section>
			)}

			{/* Preview modal */}
			{previewTemplate && (
				<PreviewModal
					templateId={previewTemplateId ?? ''}
					templateName={previewTemplate.title}
					templateDescription={previewTemplate.description}
					isGallery={previewIsGallery}
					onClose={() => setPreviewId(null)}
					onActivate={() => {
						if (previewId == null) return;
						handleToggle(previewId);
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
