import { t } from '../translations';
import type { AnyTemplate, GalleryTemplate } from '../types';

interface TemplateTagsProps {
	template: AnyTemplate | GalleryTemplate;
}

function isThermal(template: AnyTemplate | GalleryTemplate): boolean {
	return (
		template.engine === 'thermal' ||
		template.output_type === 'escpos' ||
		template.output_type === 'thermal'
	);
}

function isOffline(template: AnyTemplate | GalleryTemplate): boolean {
	return (
		template.engine === 'logicless' ||
		template.engine === 'thermal' ||
		template.offline_capable
	);
}

export function TemplateTags({ template }: TemplateTagsProps) {
	const thermal = isThermal(template);
	const offline = isOffline(template);
	const paperWidth = 'paper_width' in template && template.paper_width;

	return (
		<div className="wcpos:flex wcpos:gap-1 wcpos:flex-wrap wcpos:mt-0.5">
			{/* Print method */}
			{thermal ? (
				<span
					className="wcpos:text-xs wcpos:bg-blue-50 wcpos:text-blue-700 wcpos:px-1.5 wcpos:py-0.5 wcpos:rounded"
					title={t('tags.receipt_printer_tip')}
				>
					{t('tags.receipt_printer')}
				</span>
			) : (
				<span
					className="wcpos:text-xs wcpos:bg-gray-100 wcpos:text-gray-600 wcpos:px-1.5 wcpos:py-0.5 wcpos:rounded"
					title={t('tags.browser_tip')}
				>
					{t('tags.browser')}
				</span>
			)}

			{/* Paper size — only for thermal templates with a paper_width */}
			{thermal && paperWidth === '80mm' && (
				<span
					className="wcpos:text-xs wcpos:bg-slate-100 wcpos:text-slate-600 wcpos:px-1.5 wcpos:py-0.5 wcpos:rounded"
					title={t('tags.80mm_tip')}
				>
					80mm
				</span>
			)}
			{thermal && paperWidth === '58mm' && (
				<span
					className="wcpos:text-xs wcpos:bg-slate-100 wcpos:text-slate-600 wcpos:px-1.5 wcpos:py-0.5 wcpos:rounded"
					title={t('tags.58mm_tip')}
				>
					58mm
				</span>
			)}

			{/* Availability */}
			{offline ? (
				<span
					className="wcpos:text-xs wcpos:bg-green-50 wcpos:text-green-700 wcpos:px-1.5 wcpos:py-0.5 wcpos:rounded"
					title={t('tags.offline_tip')}
				>
					{t('tags.works_offline')}
				</span>
			) : (
				<span
					className="wcpos:text-xs wcpos:bg-amber-50 wcpos:text-amber-700 wcpos:px-1.5 wcpos:py-0.5 wcpos:rounded"
					title={t('tags.server_tip')}
				>
					{t('tags.server_required')}
				</span>
			)}

			{/* Legacy warning */}
			{template.engine === 'legacy-php' && (
				<span
					className="wcpos:text-xs wcpos:bg-amber-50 wcpos:text-amber-700 wcpos:px-1.5 wcpos:py-0.5 wcpos:rounded"
					title={t('tags.legacy_php_tip')}
				>
					&#9888; {t('tags.legacy_php')}
				</span>
			)}
		</div>
	);
}
