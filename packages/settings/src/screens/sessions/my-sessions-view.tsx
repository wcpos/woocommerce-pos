import * as React from 'react';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';
import { map } from 'lodash';

import SessionCard from './session-card';
import Notice from '../../components/notice';
import { Button } from '../../components/ui';
import useNotices from '../../hooks/use-notices';
import { t } from '../../translations';

interface Session {
	jti: string;
	created: number;
	last_active: number;
	expires: number;
	ip_address: string;
	user_agent: string;
	device_info: {
		device_type: string;
		browser: string;
		browser_version: string;
		os: string;
		app_type?: string;
	};
	is_current?: boolean;
}

interface MySessionsResponse {
	user_id: number;
	sessions: Session[];
}

const MySessionsView: React.FC = () => {
	const queryClient = useQueryClient();
	const { setNotice } = useNotices();

	// Fetch current user's sessions with suspense
	const { data: mySessions } = useQuery<MySessionsResponse>({
		queryKey: ['sessions', 'my'],
		queryFn: async () => {
			const response = await apiFetch({
				path: '/wcpos/v1/auth/sessions?wcpos=1',
				method: 'GET',
			});
			return response as MySessionsResponse;
		},
		suspense: true,
	});

	// Delete session mutation
	const deleteSessionMutation = useMutation({
		mutationFn: async ({ userId, jti }: { userId: number; jti: string }) => {
			return await apiFetch({
				path: `/wcpos/v1/auth/sessions/${jti}?user_id=${userId}&wcpos=1`,
				method: 'DELETE',
			});
		},
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['sessions'] });
			setNotice({
				type: 'success',
				message: t('sessions.session_terminated'),
			});
		},
		onError: (error: any) => {
			setNotice({
				type: 'error',
				message: error?.message || t('sessions.failed_terminate_session'),
			});
		},
	});

	// Delete all sessions mutation
	const deleteAllSessionsMutation = useMutation({
		mutationFn: async ({
			userId,
			exceptCurrent,
		}: {
			userId: number;
			exceptCurrent?: boolean;
		}) => {
			const params = new URLSearchParams({
				user_id: userId.toString(),
				wcpos: '1',
			});
			if (exceptCurrent) {
				params.append('except_current', 'true');
			}
			return await apiFetch({
				path: `/wcpos/v1/auth/sessions?${params.toString()}`,
				method: 'DELETE',
			});
		},
		onSuccess: () => {
			queryClient.invalidateQueries({ queryKey: ['sessions'] });
			setNotice({
				type: 'success',
				message: t('sessions.sessions_terminated'),
			});
		},
		onError: (error: any) => {
			setNotice({
				type: 'error',
				message:
					error?.message ||
					t('sessions.failed_terminate_sessions'),
			});
		},
	});

	const handleDeleteSession = (userId: number, jti: string) => {
		if (
			confirm(
				t('sessions.confirm_terminate_session')
			)
		) {
			deleteSessionMutation.mutate({ userId, jti });
		}
	};

	const handleDeleteAllSessions = (userId: number, exceptCurrent: boolean = false) => {
		const message = exceptCurrent
			? t('sessions.confirm_logout_other_devices')
			: t('sessions.confirm_logout_all_devices');

		if (confirm(message)) {
			deleteAllSessionsMutation.mutate({ userId, exceptCurrent });
		}
	};

	return (
		<div>
			<div className="wcpos:flex wcpos:justify-between wcpos:items-center wcpos:mb-3">
				<h2 className="wcpos:text-base wcpos:font-medium">
					{t('sessions.active_sessions')} (
					{mySessions?.sessions?.length || 0})
				</h2>
				{mySessions?.sessions && mySessions.sessions.length > 1 && (
					<Button
						variant="destructive"
						onClick={() => handleDeleteAllSessions(mySessions.user_id, true)}
						disabled={deleteAllSessionsMutation.isPending}
						className="wcpos:text-xs wcpos:px-2 wcpos:py-1"
					>
						{t('sessions.logout_other_devices')}
					</Button>
				)}
			</div>

			{mySessions?.sessions && mySessions.sessions.length > 0 ? (
				<div className="wcpos:space-y-2">
					{map(mySessions.sessions, (session) => (
						<SessionCard
							key={session.jti}
							session={session}
							onDelete={() => handleDeleteSession(mySessions.user_id, session.jti)}
							isDeleting={deleteSessionMutation.isPending}
						/>
					))}
				</div>
			) : (
				<Notice status="info">
					{t('sessions.no_active_sessions')}
				</Notice>
			)}
		</div>
	);
};

export default MySessionsView;
