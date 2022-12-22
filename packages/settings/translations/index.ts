import * as Transifex from '@transifex/native';

const tx = Transifex.tx;
const t = Transifex.t;

tx.init({
	token: '1/09853773ef9cda3be96c8c451857172f26927c0f',
	filterTags: 'wp-admin-settings',
});

export { tx, t };
