import * as React from 'react';

import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import apiFetch from '@wordpress/api-fetch';

import UserSessionsList from './user-sessions-list';
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

interface UserSessionData {
	user_id: number;
	username: string;
	display_name: string;
	avatar_url: string;
	session_count: number;
	last_active: number;
	sessions: Session[];
}

interface AllUsersSessionsResponse {
	users: UserSessionData[];
	total: number;
}

const AllUsersView: React.FC = () => {
	const queryClient = useQueryClient();
	const { setNotice } = useNotices();

	// Fetch all users' sessions with suspense
	const { data: allSessions } = useQuery<AllUsersSessionsResponse>({
		queryKey: ['sessions', 'all'],
		queryFn: async () => {
			const response = await apiFetch({
				path: '/wcpos/v1/auth/users/sessions?wcpos=1',
				method: 'GET',
			});
			return response as AllUsersSessionsResponse;
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
				message: t('Session terminated successfully', { _tags: 'wp-admin-settings' }),
			});
		},
		onError: (error: any) => {
			setNotice({
				type: 'error',
				message: error?.message || t('Failed to terminate session', { _tags: 'wp-admin-settings' }),
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
				message: t('Sessions terminated successfully', { _tags: 'wp-admin-settings' }),
			});
		},
		onError: (error: any) => {
			setNotice({
				type: 'error',
				message:
					error?.message ||
					t('Failed to terminate sessions', { _tags: 'wp-admin-settings' }),
			});
		},
	});

	const handleDeleteSession = (userId: number, jti: string) => {
		if (
			confirm(
				t('Are you sure you want to terminate this session?', { _tags: 'wp-admin-settings' })
			)
		) {
			deleteSessionMutation.mutate({ userId, jti });
		}
	};

	const handleDeleteAllSessions = (userId: number, exceptCurrent: boolean = false) => {
		const message = exceptCurrent
			? t('Are you sure you want to logout from all other devices?', {
					_tags: 'wp-admin-settings',
			  })
			: t('Are you sure you want to logout from all devices?', {
					_tags: 'wp-admin-settings',
			  });

		if (confirm(message)) {
			deleteAllSessionsMutation.mutate({ userId, exceptCurrent });
		}
	};

	return (
		<UserSessionsList
			users={allSessions?.users || []}
			onDeleteSession={handleDeleteSession}
			onDeleteAllSessions={handleDeleteAllSessions}
			isDeleting={deleteSessionMutation.isPending || deleteAllSessionsMutation.isPending}
		/>
	);
};

export default AllUsersView;

