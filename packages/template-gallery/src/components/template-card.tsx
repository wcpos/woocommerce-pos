import classnames from 'classnames';

import { TemplateTags } from './template-tags';
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

	return (
		<div
			className={classnames(
				'wcpos:bg-white wcpos:border wcpos:rounded-lg wcpos:overflow-hidden wcpos:flex wcpos:flex-col',
				isActive
					? 'wcpos:border-wp-admin-theme-color wcpos:ring-1 wcpos:ring-wp-admin-theme-color'
					: 'wcpos:border-gray-200',
			)}
		>
			{/* Thumbnail area */}
			<button
				type="button"
				onClick={onPreview}
				className="wcpos:aspect-[4/3] wcpos:bg-gray-50 wcpos:flex wcpos:items-center wcpos:justify-center wcpos:cursor-pointer wcpos:border-0 wcpos:p-0"
			>
				<span className="wcpos:text-gray-400 wcpos:text-sm">Preview</span>
			</button>

			{/* Card body */}
			<div className="wcpos:p-3 wcpos:flex-1 wcpos:flex wcpos:flex-col wcpos:gap-2">
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
							aria-label={isActive ? 'Deactivate template' : 'Activate template'}
							aria-pressed={isActive}
							title={isActive ? 'Deactivate' : 'Activate'}
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
					<p className="wcpos:text-xs wcpos:text-gray-500 wcpos:m-0 wcpos:line-clamp-2">
						{description}
					</p>
				)}

				{/* Tags */}
				<TemplateTags template={template} />

				{/* Actions */}
				<div className="wcpos:flex wcpos:gap-2 wcpos:mt-auto wcpos:pt-1">
					<button
						type="button"
						onClick={onPreview}
						className="wcpos:text-xs wcpos:text-wp-admin-theme-color hover:wcpos:underline wcpos:bg-transparent wcpos:border-0 wcpos:p-0 wcpos:cursor-pointer"
					>
						Preview
					</button>
					{isGallery ? (
						<button
							type="button"
							onClick={props.onCustomize}
							className="wcpos:text-xs wcpos:text-wp-admin-theme-color hover:wcpos:underline wcpos:bg-transparent wcpos:border-0 wcpos:p-0 wcpos:cursor-pointer"
						>
							Customize
						</button>
					) : (
						<button
							type="button"
							onClick={props.onEdit}
							className="wcpos:text-xs wcpos:text-wp-admin-theme-color hover:wcpos:underline wcpos:bg-transparent wcpos:border-0 wcpos:p-0 wcpos:cursor-pointer"
						>
							Edit
						</button>
					)}
				</div>
			</div>
		</div>
	);
}
