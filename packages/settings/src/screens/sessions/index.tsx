import * as React from 'react';

import { useMutation, useQueryClient, useSuspenseQuery } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import ConfirmDialog from './confirm-dialog';
import SessionList, { UserSessionData } from './session-list';
import UserList from './user-list';
import Notice from '../../components/notice';
import { SessionsSkeleton } from '../../components/skeleton';
import useNotices from '../../hooks/use-notices';
import { t } from '../../translations';

interface AllUsersSessionsResponse {
	users: UserSessionData[];
	total: number;
}

const ACTIVE_NOW_THRESHOLD_SECONDS = 5 * 60;

type PendingConfirm =
	| { kind: 'session'; userId: number; jti: string; isCurrent: boolean }
	| { kind: 'all'; userId: number; exceptCurrent: boolean }
	| null;

function getCurrentUserId(): number | null {
	const id = (window as any)?.wcpos?.settings?.currentUserId;
	if (typeof id === 'number' && id > 0) return id;
	return null;
}

function sortUsers(users: UserSessionData[], currentUserId: number | null): UserSessionData[] {
	return [...users].sort((a, b) => {
		if (currentUserId !== null) {
			if (a.user_id === currentUserId) return -1;
			if (b.user_id === currentUserId) return 1;
		}
		return b.last_active - a.last_active;
	});
}

function Sessions() {
	const queryClient = useQueryClient();
	const { setNotice } = useNotices();
	const currentUserId = React.useMemo(getCurrentUserId, []);

	const { data } = useSuspenseQuery<AllUsersSessionsResponse>({
		queryKey: ['sessions', 'all'],
		queryFn: async () => {
			const response = await apiFetch({
				path: '/wcpos/v1/auth/users/sessions?wcpos=1',
				method: 'GET',
			});
			return response as AllUsersSessionsResponse;
		},
	});

	const users = React.useMemo(
		() => sortUsers(data?.users ?? [], currentUserId),
		[data?.users, currentUserId]
	);

	const [selectedUserId, setSelectedUserId] = React.useState<number | null>(null);
	const [pendingConfirm, setPendingConfirm] = React.useState<PendingConfirm>(null);

	// Ensure a valid selection: prefer current user, else first user.
	React.useEffect(() => {
		if (users.length === 0) {
			if (selectedUserId !== null) setSelectedUserId(null);
			return;
		}
		const stillExists =
			selectedUserId !== null && users.some((u) => u.user_id === selectedUserId);
		if (!stillExists) {
			const defaultId =
				currentUserId !== null && users.some((u) => u.user_id === currentUserId)
					? currentUserId
					: users[0].user_id;
			setSelectedUserId(defaultId);
		}
	}, [users, selectedUserId, currentUserId]);

	const selectedUser = React.useMemo(
		() => users.find((u) => u.user_id === selectedUserId) || null,
		[users, selectedUserId]
	);

	const deleteSessionMutation = useMutation({
		mutationFn: async ({ userId, jti }: { userId: number; jti: string }) =>
			apiFetch({
				path: `/wcpos/v1/auth/sessions/${jti}?user_id=${userId}&wcpos=1`,
				method: 'DELETE',
			}),
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['sessions'] });
			setNotice({ type: 'success', message: t('sessions.session_terminated') });
		},
		onError: (error: any) => {
			setNotice({
				type: 'error',
				message: error?.message || t('sessions.failed_terminate_session'),
			});
		},
	});

	const deleteAllSessionsMutation = useMutation({
		mutationFn: async ({
			userId,
			exceptCurrent,
		}: {
			userId: number;
			exceptCurrent: boolean;
		}) => {
			const params = new URLSearchParams({
				user_id: userId.toString(),
				wcpos: '1',
			});
			if (exceptCurrent) params.append('except_current', 'true');
			return apiFetch({
				path: `/wcpos/v1/auth/sessions?${params.toString()}`,
				method: 'DELETE',
			});
		},
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['sessions'] });
			setNotice({ type: 'success', message: t('sessions.sessions_terminated') });
		},
		onError: (error: any) => {
			setNotice({
				type: 'error',
				message: error?.message || t('sessions.failed_terminate_sessions'),
			});
		},
	});

	const isDeleting =
		deleteSessionMutation.isPending || deleteAllSessionsMutation.isPending;

	const handleConfirm = () => {
		if (!pendingConfirm) return;
		if (pendingConfirm.kind === 'session') {
			deleteSessionMutation.mutate(
				{ userId: pendingConfirm.userId, jti: pendingConfirm.jti },
				{ onSettled: () => setPendingConfirm(null) }
			);
		} else {
			deleteAllSessionsMutation.mutate(
				{
					userId: pendingConfirm.userId,
					exceptCurrent: pendingConfirm.exceptCurrent,
				},
				{ onSettled: () => setPendingConfirm(null) }
			);
		}
	};

	const nowSeconds = Math.floor(Date.now() / 1000);
	const selectedIsActiveNow =
		selectedUser !== null &&
		nowSeconds - selectedUser.last_active <= ACTIVE_NOW_THRESHOLD_SECONDS;

	const confirmCopy = React.useMemo(() => {
		if (!pendingConfirm) return null;
		if (pendingConfirm.kind === 'session') {
			return {
				title: t('sessions.terminate_session_title'),
				description: pendingConfirm.isCurrent
					? t('sessions.confirm_terminate_current')
					: t('sessions.confirm_terminate_session'),
				confirmLabel: t('sessions.terminate'),
			};
		}
		return {
			title: pendingConfirm.exceptCurrent
				? t('sessions.terminate_other_title')
				: t('sessions.terminate_all_title'),
			description: pendingConfirm.exceptCurrent
				? t('sessions.confirm_logout_other_devices')
				: t('sessions.confirm_logout_all_devices'),
			confirmLabel: pendingConfirm.exceptCurrent
				? t('sessions.logout_other_devices')
				: t('sessions.logout_all'),
		};
	}, [pendingConfirm]);

	return (
		<div className="wcpos:p-4">
			<div className="wcpos:mb-3">
				<Notice status="info" isDismissible={false}>
					{t('sessions.manage_description')}
				</Notice>
			</div>

			{users.length === 0 ? (
				<Notice status="info">{t('sessions.no_active_sessions')}</Notice>
			) : (
				<div className="wcpos:grid wcpos:grid-cols-[minmax(0,18rem)_1fr] wcpos:gap-4 wcpos:min-h-[32rem]">
					<aside className="wcpos:border-r wcpos:border-gray-200 wcpos:pr-4">
						<UserList
							users={users}
							selectedUserId={selectedUserId}
							currentUserId={currentUserId}
							activeNowThresholdSeconds={ACTIVE_NOW_THRESHOLD_SECONDS}
							onSelect={setSelectedUserId}
						/>
					</aside>
					<section>
						{selectedUser ? (
							<SessionList
								user={selectedUser}
								isYou={currentUserId !== null && selectedUser.user_id === currentUserId}
								isActiveNow={selectedIsActiveNow}
								isDeleting={isDeleting}
								onDeleteSession={(jti) => {
									const target = selectedUser.sessions.find((s) => s.jti === jti);
									setPendingConfirm({
										kind: 'session',
										userId: selectedUser.user_id,
										jti,
										isCurrent: Boolean(target?.is_current),
									});
								}}
								onDeleteAll={(exceptCurrent) =>
									setPendingConfirm({
										kind: 'all',
										userId: selectedUser.user_id,
										exceptCurrent,
									})
								}
							/>
						) : (
							<div className="wcpos:flex wcpos:h-full wcpos:items-center wcpos:justify-center wcpos:text-sm wcpos:text-gray-500">
								{t('sessions.select_user')}
							</div>
						)}
					</section>
				</div>
			)}

			{pendingConfirm && confirmCopy && (
				<ConfirmDialog
					open={Boolean(pendingConfirm)}
					title={confirmCopy.title}
					description={confirmCopy.description}
					confirmLabel={confirmCopy.confirmLabel}
					isSubmitting={isDeleting}
					onConfirm={handleConfirm}
					onClose={() => {
						if (!isDeleting) setPendingConfirm(null);
					}}
				/>
			)}
		</div>
	);
}

function SessionsWithSuspense() {
	return (
		<React.Suspense fallback={<SessionsSkeleton />}>
			<Sessions />
		</React.Suspense>
	);
}

export default SessionsWithSuspense;
