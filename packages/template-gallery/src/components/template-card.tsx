import classnames from 'classnames';

import { Button, Card } from '@wcpos/ui';

import { TemplateTags } from './template-tags';
import { getGalleryPreviewSrc } from '../preview-assets';
import { t } from '../translations';
import type { AnyTemplate, GalleryTemplate } from '../types';

interface BaseProps {
	onPreview: () => void;
}

interface GalleryCardProps extends BaseProps {
	template: GalleryTemplate;
	isGallery: true;
	onCustomize: () => void;
	onActivate?: never;
	onEdit?: never;
	isToggling?: never;
}

interface CustomCardProps extends BaseProps {
	template: AnyTemplate;
	isGallery: false;
	onActivate: () => void;
	onEdit: () => void;
	onCustomize?: never;
	isToggling?: boolean;
}

type TemplateCardProps = GalleryCardProps | CustomCardProps;

export function TemplateCard(props: TemplateCardProps) {
	const { template, isGallery, onPreview } = props;

	const name = template.title;
	const description = template.description;
	const isActive = !isGallery && 'status' in template && template.status === 'publish';
	const previewSrc = isGallery ? getGalleryPreviewSrc(template.key) : undefined;

	return (
		<Card active={isActive}>
			{/* Thumbnail area — flush to card edges */}
			<button
				type="button"
				onClick={onPreview}
				aria-label={t('common.preview')}
				className="wcpos:aspect-[4/3] wcpos:bg-gray-50 wcpos:flex wcpos:items-center wcpos:justify-center wcpos:cursor-pointer wcpos:border-0 wcpos:p-0 wcpos:overflow-hidden"
			>
				{previewSrc ? (
					<img
						src={previewSrc}
						alt=""
						loading="lazy"
						className="wcpos:w-full wcpos:h-full wcpos:object-contain"
					/>
				) : (
					<span className="wcpos:text-gray-400 wcpos:text-sm">{t('common.preview')}</span>
				)}
			</button>

			<Card.Body className="wcpos:flex wcpos:flex-col wcpos:gap-2 wcpos:p-3">
				<div className="wcpos:flex wcpos:items-start wcpos:justify-between wcpos:gap-2">
					<h3 className="wcpos:text-sm wcpos:font-medium wcpos:text-gray-900 wcpos:m-0 wcpos:leading-tight">
						{name}
					</h3>
					{!isGallery && (
						<button
							type="button"
							onClick={props.onActivate}
							disabled={props.isToggling}
							aria-disabled={props.isToggling}
							aria-label={isActive ? t('card.deactivate_template') : t('card.activate_template')}
							aria-pressed={isActive}
							title={isActive ? t('card.deactivate') : t('card.activate')}
							className={classnames(
								'wcpos:w-3 wcpos:h-3 wcpos:rounded-full wcpos:border-2 wcpos:shrink-0 wcpos:mt-0.5 wcpos:cursor-pointer wcpos:p-0',
								isActive
									? 'wcpos:bg-green-500 wcpos:border-green-500'
									: 'wcpos:bg-white wcpos:border-gray-300',
							)}
						/>
					)}
				</div>

				{description && (
					<p className="wcpos:text-xs wcpos:text-gray-500 wcpos:m-0">
						{description}
					</p>
				)}

				{/* Tags */}
				<TemplateTags template={template} />
			</Card.Body>

			<Card.Footer
				className={classnames(
					'wcpos:flex wcpos:items-center wcpos:gap-3',
					isGallery && 'wcpos:justify-between',
				)}
			>
				<button
					type="button"
					onClick={onPreview}
					className="wcpos:text-xs wcpos:text-wp-admin-theme-color hover:wcpos:underline wcpos:bg-transparent wcpos:border-0 wcpos:p-0 wcpos:cursor-pointer"
				>
					{t('common.preview')}
				</button>
				{isGallery ? (
					<Button variant="primary" onClick={props.onCustomize} className="wcpos:max-w-full wcpos:min-w-0 wcpos:overflow-hidden wcpos:whitespace-nowrap">
						<span className="wcpos:block wcpos:max-w-full wcpos:truncate">
							{t('common.use_template')}
						</span>
					</Button>
				) : (
					<button
						type="button"
						onClick={props.onEdit}
						className="wcpos:text-xs wcpos:text-wp-admin-theme-color hover:wcpos:underline wcpos:bg-transparent wcpos:border-0 wcpos:p-0 wcpos:cursor-pointer"
					>
						{t('common.edit')}
					</button>
				)}
			</Card.Footer>
		</Card>
	);
}
