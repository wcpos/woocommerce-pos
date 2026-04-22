import * as React from 'react';

import classNames from 'classnames';

import { Card, Chip, DropdownMenu, DropdownMenuItem, Tooltip } from '@wcpos/ui';

import MoreVerticalIcon from '../../../assets/more-vertical-icon.svg';
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
			className="wcpos:w-12 wcpos:h-12 wcpos:text-gray-400"
		>
			<path
				strokeLinecap="round"
				strokeLinejoin="round"
				d="M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959V6.75m-1.5 0H5.625c-.621 0-1.125.504-1.125 1.125v3.026a2.999 2.999 0 010 5.198v2.776c0 .621.504 1.125 1.125 1.125h3.026a2.999 2.999 0 015.198 0h2.776c.621 0 1.125-.504 1.125-1.125v-3.026a2.999 2.999 0 010-5.198V7.875c0-.621-.504-1.125-1.125-1.125h-3.026"
			/>
		</svg>
	);
}

function isGitHubUrl(url: string): boolean {
	try {
		const host = new URL(url).hostname.toLowerCase();
		return host === 'github.com' || host === 'www.github.com';
	} catch {
		return false;
	}
}

/**
 * Build a GitHub release URL from homepage + version.
 * Returns null if homepage isn't a GitHub URL.
 */
function releaseUrl(homepage: string, version: string): string | null {
	try {
		const parsed = new URL(homepage);
		const host = parsed.hostname.toLowerCase();
		if (host !== 'github.com' && host !== 'www.github.com') {
			return null;
		}

		const [owner, repo] = parsed.pathname.replace(/^\/+|\/+$/g, '').split('/');
		if (!owner || !repo) {
			return null;
		}

		return `https://github.com/${owner}/${repo}/releases/tag/v${encodeURIComponent(version)}`;
	} catch {
		return null;
	}
}

/**
 * Version line with clickable release links.
 */
function VersionLine({ extension }: { extension: Extension }) {
	const { homepage, installed_version, latest_version, status } = extension;

	if ((status === 'update_available' || extension.has_update) && installed_version) {
		const currentUrl = releaseUrl(homepage, installed_version);
		const updateUrl = releaseUrl(homepage, latest_version);
		return (
			<span className="wcpos:text-xs wcpos:text-gray-400">
				{currentUrl ? (
					<a href={currentUrl} target="_blank" rel="noopener noreferrer" className="hover:wcpos:text-gray-600 wcpos:transition-colors">
						v{installed_version}
					</a>
				) : (
					<>v{installed_version}</>
				)}
				{' \u2192 '}
				{updateUrl ? (
					<a href={updateUrl} target="_blank" rel="noopener noreferrer" className="wcpos:text-yellow-700 hover:wcpos:text-yellow-800 wcpos:transition-colors">
						v{latest_version}
					</a>
				) : (
					<span className="wcpos:text-yellow-700">v{latest_version}</span>
				)}
			</span>
		);
	}

	const version = installed_version || latest_version;
	const url = releaseUrl(homepage, version);

	return (
		<span className="wcpos:text-xs wcpos:text-gray-400">
			{url ? (
				<a href={url} target="_blank" rel="noopener noreferrer" className="hover:wcpos:text-gray-600 wcpos:transition-colors">
					v{version}
				</a>
			) : (
				<>v{version}</>
			)}
		</span>
	);
}

/**
 * Status badge shown in the card body header.
 */
function StatusBadge({ status }: { status: Extension['status'] }) {
	switch (status) {
		case 'active':
			return (
				<Chip
					variant="success"
					icon={
						<svg
							xmlns="http://www.w3.org/2000/svg"
							viewBox="0 0 20 20"
							fill="currentColor"
							className="wcpos:h-3 wcpos:w-3"
						>
							<path
								fillRule="evenodd"
								d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
								clipRule="evenodd"
							/>
						</svg>
					}
				>
					{t('extensions.active', 'Active')}
				</Chip>
			);
		case 'update_available':
			return <Chip variant="warning">{t('extensions.update_available', 'Update available')}</Chip>;
		case 'inactive':
			return <Chip variant="neutral">{t('extensions.inactive', 'Inactive')}</Chip>;
		default:
			return null;
	}
}

/**
 * Footer action for the extension (free plugin fallback).
 */
function FooterAction({ extension }: { extension: Extension }) {
	if (extension.status === 'not_installed') {
		return (
			<div className="wcpos:flex wcpos:items-center wcpos:justify-end">
				<Tooltip text={t('extensions.requires_pro', 'Requires Pro to install')}>
					<span>
						<button
							type="button"
							disabled
							className="wcpos:inline-flex wcpos:items-center wcpos:rounded-md wcpos:border wcpos:border-gray-300 wcpos:bg-white wcpos:px-3 wcpos:py-1.5 wcpos:text-xs wcpos:font-medium wcpos:text-gray-700 disabled:wcpos:opacity-60 disabled:wcpos:cursor-not-allowed"
						>
							{t('extensions.install', 'Install')}
						</button>
					</span>
				</Tooltip>
			</div>
		);
	}
	return null;
}

/**
 * Returns a registered Pro action component, or null if Pro is not active.
 */
function getActionSlot(): React.ComponentType<{ extension: Extension }> | null {
	return (window as any).wcpos?.settings?.getComponent?.('extensions.action') ?? null;
}

/**
 * Builds the card footer contents. Returns null when no footer should be
 * shown (so the parent can skip rendering Card.Footer entirely and avoid an
 * empty gray bar).
 */
function buildFooterContent(extension: Extension): React.ReactNode {
	const ActionSlot = getActionSlot();

	// Pro plugin provides the action controls.
	if (ActionSlot) {
		return <ActionSlot extension={extension} />;
	}

	// Free plugin: only show footer for not-installed extensions.
	if (extension.status === 'not_installed') {
		return <FooterAction extension={extension} />;
	}

	return null;
}

function ExtensionCard({ extension }: ExtensionCardProps) {
	const rawCategory = (extension.category || '').trim();
	const displayCategory = rawCategory
		? rawCategory.charAt(0).toUpperCase() + rawCategory.slice(1)
		: null;

	const footerContent = buildFooterContent(extension);

	const showSettingsItem =
		!!extension.settings_url && extension.status !== 'not_installed';
	const showGitHubItem =
		!!extension.homepage && isGitHubUrl(extension.homepage);
	const showKebab = showSettingsItem || showGitHubItem;

	return (
		<Card>
			<Card.Body className="wcpos:relative wcpos:flex wcpos:gap-4">
				{showKebab && (
					<DropdownMenu
						label={extension.name}
						className="wcpos:absolute wcpos:top-2 wcpos:right-2"
						trigger={
							<span
								className="wcpos:inline-flex wcpos:items-center wcpos:justify-center wcpos:w-7 wcpos:h-7 wcpos:rounded-md wcpos:text-gray-500 wcpos:hover:bg-gray-100 wcpos:hover:text-gray-700 wcpos:cursor-pointer"
								aria-label={t('extensions.more_actions', 'More actions')}
							>
								<MoreVerticalIcon className="wcpos:w-4 wcpos:h-4 wcpos:fill-current" />
							</span>
						}
					>
						{showSettingsItem && (
							<DropdownMenuItem href={extension.settings_url}>
								{t('extensions.settings', 'Settings')}
							</DropdownMenuItem>
						)}
						{showGitHubItem && (
							<DropdownMenuItem
								href={extension.homepage}
								target="_blank"
								rel="noopener noreferrer"
							>
								{t('extensions.view_on_github', 'View on GitHub')}
							</DropdownMenuItem>
						)}
					</DropdownMenu>
				)}
				{/* Icon */}
				<div className="wcpos:shrink-0 wcpos:flex wcpos:items-start">
					{extension.icon ? (
						<img
							src={extension.icon}
							alt={extension.name}
							className="wcpos:w-12 wcpos:h-12 wcpos:rounded"
						/>
					) : (
						<FallbackIcon />
					)}
				</div>

				{/* Content */}
				<div className="wcpos:flex-1 wcpos:min-w-0">
					<h3
						className={classNames(
							'wcpos:text-sm wcpos:font-semibold wcpos:text-gray-900',
							showKebab && 'wcpos:pr-8'
						)}
					>
						{extension.name}
					</h3>
					<div className="wcpos:flex wcpos:flex-wrap wcpos:items-center wcpos:gap-x-1.5 wcpos:gap-y-1 wcpos:mt-1">
						<StatusBadge status={extension.status} />
						<VersionLine extension={extension} />
						{displayCategory && (
							<span className="wcpos:inline-flex wcpos:items-center wcpos:gap-1.5 wcpos:whitespace-nowrap">
								<span className="wcpos:w-0.5 wcpos:h-0.5 wcpos:rounded-full wcpos:bg-gray-300" />
								<span className="wcpos:text-xs wcpos:text-gray-500">{displayCategory}</span>
							</span>
						)}
					</div>

					<p className="wcpos:mt-2 wcpos:text-sm wcpos:text-gray-500">
						{extension.description}
					</p>
				</div>
			</Card.Body>

			{footerContent != null && <Card.Footer>{footerContent}</Card.Footer>}
		</Card>
	);
}

export default ExtensionCard;
