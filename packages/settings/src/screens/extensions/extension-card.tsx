import * as React from 'react';

import { Button } from '../../components/ui';
import { Tooltip } from '../../components/ui';
import { t } from '../../translations';
import type { Extension } from './index';

interface ExtensionCardProps {
	extension: Extension;
}

/**
 * Puzzle piece SVG used as fallback icon.
 */
function FallbackIcon() {
	return (
		<svg
			xmlns="http://www.w3.org/2000/svg"
			viewBox="0 0 24 24"
			fill="none"
			stroke="currentColor"
			strokeWidth={1.5}
			className="wcpos:w-10 wcpos:h-10 wcpos:text-gray-400"
		>
			<path
				strokeLinecap="round"
				strokeLinejoin="round"
				d="M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959V6.75m-1.5 0H5.625c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v2.776c0 .621.504 1.125 1.125 1.125h3.026a2.999 2.999 0 015.198 0h2.776c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V7.875c0-.621-.504-1.125-1.125-1.125h-3.026"
			/>
		</svg>
	);
}

/**
 * Status badge and action button for the extension.
 */
function StatusAction({ extension }: { extension: Extension }) {
	const { status, installed_version, latest_version } = extension;

	switch (status) {
		case 'active':
			return (
				<span className="wcpos:inline-flex wcpos:items-center wcpos:rounded-full wcpos:bg-green-50 wcpos:px-2 wcpos:py-1 wcpos:text-xs wcpos:font-medium wcpos:text-green-700">
					{t('extensions.active', 'Active')}
				</span>
			);

		case 'inactive':
			return (
				<span className="wcpos:inline-flex wcpos:items-center wcpos:rounded-full wcpos:bg-gray-100 wcpos:px-2 wcpos:py-1 wcpos:text-xs wcpos:font-medium wcpos:text-gray-600">
					{t('extensions.inactive', 'Inactive')}
				</span>
			);

		case 'update_available':
			return (
				<span className="wcpos:inline-flex wcpos:items-center wcpos:rounded-full wcpos:bg-yellow-50 wcpos:px-2 wcpos:py-1 wcpos:text-xs wcpos:font-medium wcpos:text-yellow-700">
					{installed_version} &rarr; {latest_version}
				</span>
			);

		case 'not_installed':
		default:
			return (
				<Tooltip text={t('extensions.requires_pro', 'Requires Pro to install')}>
					<span>
						<Button variant="secondary" disabled>
							{t('extensions.install', 'Install')}
						</Button>
					</span>
				</Tooltip>
			);
	}
}

const ExtensionCard = ({ extension }: ExtensionCardProps) => {
	return (
		<div className="wcpos:border wcpos:border-gray-200 wcpos:rounded-lg wcpos:p-4 wcpos:flex wcpos:gap-4">
			{/* Icon */}
			<div className="wcpos:shrink-0">
				{extension.icon ? (
					<img
						src={extension.icon}
						alt={extension.name}
						className="wcpos:w-10 wcpos:h-10 wcpos:rounded"
					/>
				) : (
					<FallbackIcon />
				)}
			</div>

			{/* Content */}
			<div className="wcpos:flex-1 wcpos:min-w-0">
				<div className="wcpos:flex wcpos:items-start wcpos:justify-between wcpos:gap-2">
					<div>
						<h3 className="wcpos:text-sm wcpos:font-semibold wcpos:text-gray-900">
							{extension.name}
						</h3>
						<span className="wcpos:text-xs wcpos:text-gray-400">
							v{extension.latest_version}
						</span>
					</div>
					<StatusAction extension={extension} />
				</div>

				<p className="wcpos:mt-1 wcpos:text-sm wcpos:text-gray-500 wcpos:line-clamp-2">
					{extension.description}
				</p>

				<span className="wcpos:mt-2 wcpos:inline-flex wcpos:items-center wcpos:rounded-full wcpos:bg-gray-100 wcpos:px-2 wcpos:py-0.5 wcpos:text-xs wcpos:text-gray-600">
					{extension.category}
				</span>
			</div>
		</div>
	);
};

export default ExtensionCard;
