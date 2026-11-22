import { useState } from 'react';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import { getErrorMessage } from '../get-error-message';
import { t } from '../translations';

interface EmailDesignSettings {
	template_id: number;
	designs: { id: number; title: string; status: 'publish' | 'draft' }[];
}

const queryKey = ['customer-email-design'];
const path = 'wcpos/v1/templates/customer-email-design?wcpos=1';

export function CustomerEmailDesigns() {
	const client = useQueryClient();
	const { data, error, isPending, refetch } = useQuery({
		queryKey,
		queryFn: () => apiFetch<EmailDesignSettings>({ path, method: 'GET' }),
	});
	const [selection, setSelection] = useState<number | null>(null);
	const save = useMutation({
		mutationFn: (template_id: number) =>
			apiFetch<EmailDesignSettings>({
				path,
				method: 'POST',
				data: { template_id },
			}),
		onSuccess: (settings) => {
			client.setQueryData(queryKey, settings);
			setSelection(null);
		},
	});
	const adminUrl =
		(window as any).wcpos?.templateGallery?.adminUrl ?? `${window.location.origin}/wp-admin`;

	if (isPending) return <p role="status">{t('layout.loading')}</p>;
	if (!data) {
		return (
			<div>
				<p role="alert">
					{t('layout.error')} {getErrorMessage(error)}
				</p>
				<button type="button" className="button" onClick={() => refetch()}>
					{t('layout.try_again')}
				</button>
			</div>
		);
	}

	const selected = selection ?? data.template_id;
	return (
		<>
			<form
				className="wcpos:mb-6 wcpos:max-w-2xl wcpos:rounded wcpos:border wcpos:border-gray-200 wcpos:bg-white wcpos:p-4"
				onSubmit={(event) => {
					event.preventDefault();
					if (selected !== data.template_id && !save.isPending) save.mutate(selected);
				}}
			>
				<label
					htmlFor="wcpos-customer-email-design"
					className="wcpos:block wcpos:font-semibold wcpos:mb-2"
				>
					{t('email.design_for_completed_orders')}
				</label>
				<div className="wcpos:flex wcpos:flex-wrap wcpos:items-center wcpos:gap-2">
					<select
						id="wcpos-customer-email-design"
						className="wcpos:border wcpos:border-gray-300 wcpos:rounded"
						value={selected}
						disabled={save.isPending}
						onChange={(event) => {
							setSelection(Number(event.target.value));
							save.reset();
						}}
					>
						<option value={0}>{t('email.woocommerce_default')}</option>
						{data.designs
							.filter((design) => design.status === 'publish')
							.map((design) => (
								<option key={design.id} value={design.id}>
									{design.title}
								</option>
							))}
					</select>
					<button
						type="submit"
						className="button button-primary"
						disabled={selected === data.template_id || save.isPending}
					>
						{t(save.isPending ? 'email.saving' : 'email.save')}
					</button>
				</div>
				{save.isError && (
					<p role="alert">
						{t('email.save_failed')} {getErrorMessage(save.error)}
					</p>
				)}
				{save.isSuccess && <p role="status">{t('email.saved')}</p>}
			</form>
			<section className="wcpos:max-w-2xl" aria-labelledby="wcpos-customer-email-designs">
				<div className="wcpos:flex wcpos:items-center wcpos:gap-3 wcpos:mb-3">
					<h2
						id="wcpos-customer-email-designs"
						className="wcpos:text-base wcpos:font-medium wcpos:m-0"
					>
						{t('email.your_designs')}
					</h2>
					<a
						href={`${adminUrl}/post-new.php?post_type=wcpos_template&wcpos_type=email`}
						className="page-title-action"
					>
						{t('email.add_design')}
					</a>
				</div>
				{data.designs.length === 0 ? (
					<p>{t('email.no_designs')}</p>
				) : (
					<ul className="wcpos:my-0 wcpos:divide-y wcpos:divide-gray-200 wcpos:border wcpos:border-gray-200 wcpos:rounded wcpos:bg-white">
						{data.designs.map(({ id, title, status }) => (
							<li
								key={id}
								className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:gap-2 wcpos:m-0 wcpos:px-4 wcpos:py-3"
							>
								<a href={`${adminUrl}/post.php?post=${id}&action=edit`}>{title}</a>
								<span className="wcpos:text-gray-500">
									{t(status === 'publish' ? 'email.published' : 'email.draft')}
								</span>
							</li>
						))}
					</ul>
				)}
			</section>
		</>
	);
}
