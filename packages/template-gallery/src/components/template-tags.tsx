import { Chip } from '@wcpos/ui';

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
			{thermal ? (
				<Chip variant="info" title={t('tags.receipt_printer_tip')}>
					{t('tags.receipt_printer')}
				</Chip>
			) : (
				<Chip variant="neutral" title={t('tags.browser_tip')}>
					{t('tags.browser')}
				</Chip>
			)}

			{thermal && paperWidth === '80mm' && (
				<Chip variant="neutral" title={t('tags.80mm_tip')}>
					80mm
				</Chip>
			)}
			{thermal && paperWidth === '58mm' && (
				<Chip variant="neutral" title={t('tags.58mm_tip')}>
					58mm
				</Chip>
			)}

			{offline ? (
				<Chip variant="success" title={t('tags.offline_tip')}>
					{t('tags.works_offline')}
				</Chip>
			) : (
				<Chip variant="warning" title={t('tags.server_tip')}>
					{t('tags.server_required')}
				</Chip>
			)}

			{template.engine === 'legacy-php' && (
				<Chip variant="warning" title={t('tags.legacy_php_tip')}>
					&#9888; {t('tags.legacy_php')}
				</Chip>
			)}
		</div>
	);
}
