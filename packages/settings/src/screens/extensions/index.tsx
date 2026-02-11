import * as React from 'react';

import { useSuspenseQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import ExtensionCard from './extension-card';
import { markExtensionsSeen, setNewExtensionsCount } from './use-new-extensions-count';
import Notice from '../../components/notice';
import { t } from '../../translations';

/**
 * Extension entry from the REST API.
 */
export interface Extension {
	slug: string;
	name: string;
	description: string;
	version: string;
	author: string;
	category: string;
	tags: string[];
	requires_wp: string;
	requires_wc: string;
	requires_wcpos: string;
	requires_pro: boolean;
	icon: string;
	homepage: string;
	repository: string;
	download_url: string;
	latest_version: string;
	released_at: string;
	status: 'not_installed' | 'inactive' | 'active' | 'update_available';
	installed_version?: string;
	plugin_file?: string;
}

function Extensions() {
	const [search, setSearch] = React.useState('');
	const [category, setCategory] = React.useState('all');

	const { data: extensions = [] } = useSuspenseQuery<Extension[]>({
		queryKey: ['extensions'],
		queryFn: () => apiFetch({ path: 'wcpos/v1/extensions?wcpos=1', method: 'GET' }),
	});

	const isPro = !!(window as any)?.wcpos?.pro;

	React.useEffect(() => {
		if (extensions.length > 0) {
			setNewExtensionsCount(0);
			markExtensionsSeen();
		}
	}, [extensions]);

	const categories = React.useMemo(() => {
		const cats = new Set(extensions.map((ext) => ext.category || 'other'));
		return ['all', ...Array.from(cats).sort()];
	}, [extensions]);

	const filtered = React.useMemo(() => {
		return extensions.filter((ext) => {
			const matchesCategory = category === 'all' || (ext.category || 'other') === category;
			const q = search.toLowerCase();
			const matchesSearch =
				!search ||
				ext.name.toLowerCase().includes(q) ||
				(ext.description || '').toLowerCase().includes(q) ||
				(ext.tags || []).some((tag) => tag.toLowerCase().includes(q));
			return matchesCategory && matchesSearch;
		});
	}, [extensions, category, search]);

	return (
		<div>
			{!isPro && (
				<Notice status="info" isDismissible={false} className="wcpos:mb-4">
					{t('extensions.upgrade_to_pro', 'Upgrade to Pro to install and manage extensions.')}
				</Notice>
			)}

			{/* Search */}
			<div className="wcpos:mb-4">
				<input
					type="text"
					placeholder={t('extensions.search_placeholder', 'Search extensions...')}
					value={search}
					onChange={(e) => setSearch(e.target.value)}
					className="wcpos:block wcpos:w-full wcpos:rounded-md wcpos:border wcpos:border-gray-300 wcpos:px-3 wcpos:py-2 wcpos:text-sm focus:wcpos:outline-none focus:wcpos:ring-2 focus:wcpos:ring-wp-admin-theme-color"
				/>
			</div>

			{/* Category tabs */}
			<div className="wcpos:flex wcpos:gap-2 wcpos:mb-6 wcpos:flex-wrap">
				{categories.map((cat) => (
					<button
						key={cat}
						onClick={() => setCategory(cat)}
						className={`wcpos:px-3 wcpos:py-1 wcpos:rounded-full wcpos:text-sm wcpos:font-medium wcpos:transition-colors ${
							category === cat
								? 'wcpos:bg-wp-admin-theme-color wcpos:text-white'
								: 'wcpos:bg-gray-100 wcpos:text-gray-600 hover:wcpos:bg-gray-200'
						}`}
					>
						{cat === 'all' ? t('common.all', 'All') : cat.charAt(0).toUpperCase() + cat.slice(1)}
					</button>
				))}
			</div>

			{/* Card grid */}
			{filtered.length === 0 ? (
				<p className="wcpos:text-sm wcpos:text-gray-500">
					{t('extensions.no_results', 'No extensions found.')}
				</p>
			) : (
				<div className="wcpos:grid wcpos:grid-cols-1 wcpos:sm:grid-cols-2 wcpos:gap-4">
					{filtered.map((ext) => (
						<ExtensionCard key={ext.slug} extension={ext} />
					))}
				</div>
			)}
		</div>
	);
}

export default Extensions;
