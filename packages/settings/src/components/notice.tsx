import * as React from 'react';

import { Notice as SharedNotice, type NoticeProps } from '@wcpos/ui';

import { t } from '../translations';

/**
 * Settings-local Notice wrapper. Provides the translated dismiss label so
 * individual call sites don't have to thread the translation themselves.
 * For new code, prefer importing `Notice` directly from `@wcpos/ui` and
 * passing `dismissLabel` explicitly.
 */
function Notice(props: NoticeProps) {
	return <SharedNotice dismissLabel={t('common.dismiss')} {...props} />;
}

export default Notice;
