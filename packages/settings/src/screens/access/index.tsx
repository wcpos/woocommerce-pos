import * as React from 'react';

import classNames from 'classnames';
import { map, get } from 'lodash';

import {
	buildTaskGroupPayload,
	resolveTaskGroups,
	type CapabilityGroups,
	type TaskGroupMember,
} from './task-groups';
import Notice from '../../components/notice';
import { Checkbox } from '../../components/ui';
import useSettingsApi from '../../hooks/use-settings-api';
import { t, Trans } from '../../translations';

const GROUP_LABELS: Record<string, string> = {
	wcpos: 'WCPOS',
	wc: 'WooCommerce',
	wp: 'WordPress',
};

function Access() {
	const { data, mutate } = useSettingsApi('access');
	const [selected, setSelected] = React.useState('administrator');
	const [advancedOpen, setAdvancedOpen] = React.useState(false);
	const capabilities = get(data, [selected, 'capabilities'], null) as CapabilityGroups | null;

	const taskGroups = React.useMemo(
		() => (capabilities ? resolveTaskGroups(capabilities) : []),
		[capabilities]
	);

	/**
	 * Grant or revoke every capability behind a task in a single write, so the
	 * group never lands in a half-applied state.
	 */
	const toggleTaskGroup = React.useCallback(
		(members: TaskGroupMember[], granted: boolean) => {
			mutate({
				[selected]: {
					capabilities: buildTaskGroupPayload(members, granted),
				},
			});
		},
		[mutate, selected]
	);

	return (
		<>
			<div className="wcpos:p-4">
				<Notice status="info" isDismissible={false}>
					<Trans i18nKey="access.default_roles_warning" components={{ strong: <strong /> }} />
					&nbsp;
					<Trans
						i18nKey="access.visit_documentation"
						components={{
							link: <a href="https://docs.wcpos.com/pos-access" target="_blank" rel="noreferrer" />,
						}}
					/>
				</Notice>
			</div>
			<div className="wcpos:sm:grid wcpos:sm:grid-cols-3 wcpos:sm:gap-4 wcpos:p-4 wcpos:pt-0">
				<div className="">
					<ul>
						{map(data, (role: { name: string }, id) => (
							<li
								key={id}
								data-testid={`access-role-${id}`}
								className={classNames(
									'wcpos:p-4 wcpos:mb-1 wcpos:rounded wcpos:font-medium wcpos:text-sm wcpos:hover:bg-gray-100 wcpos:cursor-pointer',
									id === selected &&
										'wcpos:bg-wp-admin-theme-color-lightest wcpos:hover:bg-wp-admin-theme-color-lightest'
								)}
								onClick={() => {
									setSelected(id);
								}}
							>
								{role.name}
							</li>
						))}
					</ul>
				</div>
				{capabilities && (
					<div className="wcpos:sm:col-span-2">
						<h2 className="wcpos:text-base wcpos:mb-2">{t('access.tasks_title')}</h2>
						<div data-testid="access-task-groups">
							{taskGroups.map(({ task, members, allGranted, partiallyGranted }) => (
								<div key={task.id} className="wcpos:flex wcpos:items-center wcpos:gap-2 wcpos:py-1">
									<Checkbox
										data-testid={`access-task-${task.id}`}
										label={t(task.labelKey)}
										checked={allGranted}
										indeterminate={partiallyGranted}
										onChange={() => {
											toggleTaskGroup(members, !allGranted);
										}}
									/>
									{partiallyGranted && (
										<span className="wcpos:text-xs wcpos:text-gray-500">
											{t('access.task_partial')}
										</span>
									)}
								</div>
							))}
						</div>

						<details
							open={advancedOpen}
							data-testid="access-advanced"
							className="wcpos:mt-4 wcpos:border wcpos:border-gray-200 wcpos:rounded"
						>
							<summary
								data-testid="access-advanced-summary"
								className={classNames(
									'wcpos:px-3 wcpos:py-2 wcpos:text-sm wcpos:cursor-pointer wcpos:list-none',
									'wcpos:flex wcpos:justify-between wcpos:items-center',
									'wcpos:hover:bg-gray-50'
								)}
								onClick={(event) => {
									// Drive the disclosure from React so the raw capabilities are
									// only mounted while the section is open.
									event.preventDefault();
									setAdvancedOpen((open) => !open);
								}}
							>
								<span>
									<strong>{t('access.advanced_title')}</strong>{' '}
									<span className="wcpos:text-xs wcpos:text-gray-500">
										{t('access.advanced_subtitle')}
									</span>
								</span>
							</summary>
							{advancedOpen && (
								<div className="wcpos:border-t wcpos:border-gray-200 wcpos:px-3 wcpos:py-3">
									<p className="wcpos:text-xs wcpos:text-gray-500 wcpos:mb-3">
										{t('access.advanced_intro')}
									</p>
									{map(capabilities, (caps, group) => (
										<div key={group} className="wcpos:mb-3 wcpos:last:mb-0">
											<h3
												className="wcpos:text-sm wcpos:font-medium"
												data-testid={`access-capability-group-${group}`}
											>
												{GROUP_LABELS[group] || group}
											</h3>
											<div>
												{map(caps, (checked, label) => {
													const disabled = selected === 'administrator' && label === 'read';
													return (
														<Checkbox
															key={label}
															label={label}
															checked={checked}
															disabled={disabled}
															onChange={(e) => {
																mutate({
																	[selected]: {
																		capabilities: {
																			[group]: {
																				[label]: e.target.checked,
																			},
																		},
																	},
																});
															}}
														/>
													);
												})}
											</div>
										</div>
									))}
								</div>
							)}
						</details>
					</div>
				)}
			</div>
		</>
	);
}

export default Access;
