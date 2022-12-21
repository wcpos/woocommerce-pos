import { tx, t } from '@transifex/native';

import CustomCache from './cache';

tx.init({
	token: '1/53ff5ea9a168aa4e7b8a72157b83537886a51938',
	filterTags: 'wp-admin-settings',
	cache: new CustomCache(),
});

tx.setCurrentLocale('es')
	.then(() => console.log('content loaded'))
	.catch(console.log);

export { tx, t };
