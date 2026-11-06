import * as React from 'react';

import Notice from '../../components/notice';
import { t } from '../../translations';

const ORDER_STORAGE_DOCS_URL = 'https://docs.wcpos.com/support/performance/order-storage';

/**
 * Recommends High-Performance Order Storage on stores still using legacy
 * order storage. Renders nothing on HPOS stores, or when an older cached
 * inline script carries no `order_storage` fact.
 */
export function HposNotice() {
	const orderStorage = window.wcpos?.settings?.environment?.order_storage;
	if (orderStorage?.hpos !== false) {
		return null;
	}

	return (
		<div data-testid="hpos-notice" className="wcpos:px-4 wcpos:pt-4">
			<Notice status="warning" isDismissible={false}>
				<p>
					{t(
						'checkout.hpos_notice',
						'Checkout in WCPOS gets slower as your catalog and order history grow, because this store uses legacy order storage. We strongly recommend switching to High-Performance Order Storage.'
					)}
				</p>
				<p className="wcpos:mt-2 wcpos:flex wcpos:gap-4">
					<a
						href={orderStorage.settings_url}
						target="_blank"
						rel="noreferrer"
						className="wcpos:underline"
					>
						{t('checkout.hpos_notice_settings_link', "Open WooCommerce's order storage setting")}
					</a>
					<a
						href={ORDER_STORAGE_DOCS_URL}
						target="_blank"
						rel="noreferrer"
						className="wcpos:underline"
					>
						{t('checkout.hpos_notice_docs_link', 'How to switch')}
					</a>
				</p>
			</Notice>
		</div>
	);
}
