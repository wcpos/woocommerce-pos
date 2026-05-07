import * as React from 'react';

import { CountrySelect as SharedCountrySelect, type CountrySelectProps } from '@wcpos/ui';

import { t } from '../../translations';

export type { CountrySelectProps };

/**
 * Settings-local CountrySelect wrapper. Provides translated default labels
 * for the no-results state so individual call sites don't have to thread
 * them themselves. Call sites can still override via props.
 */
export function CountrySelect(props: CountrySelectProps) {
	return <SharedCountrySelect noResultsLabel={t('common.no_results')} {...props} />;
}
