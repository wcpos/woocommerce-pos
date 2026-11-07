import { Footer as UIFooter } from '@wcpos/ui';

import { t } from '../translations';

export function Footer() {
	return (
		<UIFooter
			needHelpLabel={t('settings.need_help')}
			documentationLabel={t('common.documentation')}
			faqLabel={t('common.faq')}
		/>
	);
}
