import * as React from 'react';

import { Button, Tooltip } from '../../components/ui';
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

/**
 * Checks for a registered action component before falling back to the static status badge.
 */
function ActionSlotOrFallback({ extension }: { extension: Extension }) {
	const ActionSlot = (window as any).wcpos?.settings?.getComponent?.('extensions.action');
	if (ActionSlot) {
		return <ActionSlot extension={extension} />;
	}
	return <StatusAction extension={extension} />;
}

function ExtensionCard({ extension }: ExtensionCardProps) {
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
						<div className="wcpos:flex wcpos:items-center wcpos:gap-1.5">
							<span className="wcpos:text-xs wcpos:text-gray-400">v{extension.latest_version}</span>
							{extension.homepage && (
								<a
									href={extension.homepage}
									target="_blank"
									rel="noopener noreferrer"
									className="wcpos:text-gray-400 hover:wcpos:text-gray-600 wcpos:transition-colors"
									aria-label={`${extension.name} on GitHub`}
								>
									<svg
										xmlns="http://www.w3.org/2000/svg"
										viewBox="0 0 16 16"
										fill="currentColor"
										className="wcpos:w-3.5 wcpos:h-3.5"
									>
										<path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27s1.36.09 2 .27c1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0016 8c0-4.42-3.58-8-8-8z" />
									</svg>
								</a>
							)}
						</div>
					</div>
					<ActionSlotOrFallback extension={extension} />
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
}

export default ExtensionCard;
