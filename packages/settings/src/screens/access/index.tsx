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
import { Button, Checkbox, Modal } from '../../components/ui';
import useSettingsApi from '../../hooks/use-settings-api';
import { t, Trans } from '../../translations';

const GROUP_LABELS: Record<string, string> = {
	wcpos: 'WCPOS',
	wc: 'WooCommerce',
	wp: 'WordPress',
};

interface AccessRole {
	name?: string;
	capabilities: CapabilityGroups;
	defaults: CapabilityGroups;
}

function isAtDefaults({ capabilities, defaults }: AccessRole) {
	return Object.entries(defaults ?? {}).every(([group, caps]) =>
		Object.entries(caps).every(([name, granted]) => capabilities[group]?.[name] === granted)
	);
}

const changedDotClass =
	'wcpos:inline-block wcpos:size-2 wcpos:rounded-full wcpos:bg-amber-500 wcpos:ml-2';

function Access() {
	const { data, mutate } = useSettingsApi('access');
	const [selected, setSelected] = React.useState('administrator');
	const [advancedOpen, setAdvancedOpen] = React.useState(false);
	const [restoreOpen, setRestoreOpen] = React.useState(false);
	const role = get(data, [selected]) as AccessRole | undefined;
	const defaults = role?.defaults;
	const atDefaults = role ? isAtDefaults(role) : true;
	const removeOnly =
		defaults && !Object.values(defaults).some((caps) => Object.values(caps).some(Boolean));
	const capabilities = get(data, [selected, 'capabilities'], null) as CapabilityGroups | null;

	const taskGroups = React.useMemo(
		() => (capabilities ? resolveTaskGroups(capabilities) : []),
		[capabilities]
	);

	const changes: Record<'grant' | 'remove' | 'keep', string[]> = {
		grant: [],
		remove: [],
		keep: [],
	};
	taskGroups.forEach(({ task, members, allGranted, grantedCount }) => {
		if (!defaults || !members.every(({ group, name }) => defaults[group]?.[name] !== undefined))
			return;
		const nextCount = members.filter(({ group, name }) => defaults[group][name]).length;
		// Only tasks whose state the restore touches, or keeps granted, are worth
		// listing; a task that is off and stays off would read as "kept" access.
		if (nextCount === members.length && !allGranted) {
			changes.grant.push(t(task.labelKey));
		} else if (nextCount === 0 && grantedCount > 0) {
			changes.remove.push(t(task.labelKey));
		} else if (nextCount === members.length && allGranted) {
			changes.keep.push(t(task.labelKey));
		}
	});
	// Capabilities the restore touches that no task covers, so the dialog never
	// promises "what will change" while silently flipping an Advanced-only one.
	const covered = new Set(taskGroups.flatMap(({ members }) => members.map(({ name }) => name)));
	const individual: Record<'grant' | 'remove', string[]> = { grant: [], remove: [] };
	Object.entries(defaults ?? {}).forEach(([group, caps]) => {
		Object.entries(caps).forEach(([name, granted]) => {
			if (covered.has(name) || capabilities?.[group]?.[name] === granted) return;
			individual[granted ? 'grant' : 'remove'].push(name);
		});
	});

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
						{map(data, (role: AccessRole, id) => (
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
								{!isAtDefaults(role) && (
									<span
										aria-hidden="true"
										title={t('access.defaults_changed')}
										className={changedDotClass}
									/>
								)}
							</li>
						))}
					</ul>
				</div>
				{capabilities && (
					<div className="wcpos:sm:col-span-2">
						<div className="wcpos:flex wcpos:items-start wcpos:justify-between wcpos:gap-4 wcpos:mb-2">
							<div>
								<h2 className="wcpos:text-base">
									{role?.name
										? t('access.tasks_title_role', { role: role.name })
										: t('access.tasks_title')}
								</h2>
								<p className="wcpos:text-xs wcpos:text-gray-500">
									{!atDefaults && <span aria-hidden="true" className={changedDotClass} />}{' '}
									{t(atDefaults ? 'access.defaults_in_use' : 'access.defaults_changed')}
								</p>
							</div>
							<Button
								variant="secondary"
								data-testid="access-restore-defaults"
								disabled={atDefaults}
								onClick={() => setRestoreOpen(true)}
							>
								{t('access.restore_defaults')}
							</Button>
						</div>
						<div data-testid="access-task-groups">
							{taskGroups.map(({ task, members, allGranted, partiallyGranted, grantedCount }) => (
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
										<>
											<span
												className="wcpos:bg-amber-50 wcpos:text-amber-800 wcpos:border wcpos:border-amber-200 wcpos:rounded-full wcpos:px-2 wcpos:text-xs wcpos:font-semibold"
												title={t('access.task_partial_tooltip', {
													granted: grantedCount,
													total: members.length,
												})}
											>
												{t('access.task_partial_count', {
													granted: grantedCount,
													total: members.length,
												})}
											</span>
											<Button variant="text" onClick={() => setAdvancedOpen(true)}>
												{t('access.task_review')}
											</Button>
										</>
									)}
								</div>
							))}
						</div>

						<Modal
							open={restoreOpen}
							onClose={setRestoreOpen}
							title={t(removeOnly ? 'access.remove_title' : 'access.restore_title', {
								role: role?.name,
							})}
							description={t(removeOnly ? 'access.remove_body' : 'access.restore_body', {
								role: role?.name,
							})}
						>
							<strong>{t('access.restore_changes')}</strong>
							{(['grant', 'remove', 'keep'] as const).map(
								(action) =>
									changes[action].length > 0 && (
										<p key={action} className="wcpos:my-2 wcpos:text-sm">
											<span
												className={classNames(
													'wcpos:text-xs wcpos:uppercase wcpos:font-semibold wcpos:mr-2',
													{
														'wcpos:text-green-700': action === 'grant',
														'wcpos:text-red-700': action === 'remove',
														'wcpos:text-gray-500': action === 'keep',
													}
												)}
											>
												{t(`access.change_${action}`)}
											</span>
											{changes[action].join(', ')}
										</p>
									)
							)}
							{(['grant', 'remove'] as const).map(
								(action) =>
									individual[action].length > 0 && (
										<p
											key={`individual-${action}`}
											className="wcpos:my-2 wcpos:text-sm"
											data-testid={`access-restore-individual-${action}`}
										>
											<span
												className={classNames(
													'wcpos:text-xs wcpos:uppercase wcpos:font-semibold wcpos:mr-2',
													action === 'grant' ? 'wcpos:text-green-700' : 'wcpos:text-red-700'
												)}
											>
												{t(`access.change_${action}`)}
											</span>
											{t('access.restore_individual')} <code>{individual[action].join(', ')}</code>
										</p>
									)
							)}
							<div className="wcpos:flex wcpos:justify-end wcpos:gap-2 wcpos:mt-4">
								<Button onClick={() => setRestoreOpen(false)}>{t('common.cancel')}</Button>
								<Button
									variant="primary"
									data-testid="access-restore-confirm"
									onClick={() => {
										mutate({ [selected]: { capabilities: defaults } });
										setRestoreOpen(false);
									}}
								>
									{t('access.restore_defaults')}
								</Button>
							</div>
						</Modal>

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
