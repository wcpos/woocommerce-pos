import * as React from 'react';

import {
	Combobox as SharedCombobox,
	type ComboboxOption,
	type ComboboxProps,
} from '@wcpos/ui';

import { t } from '../../translations';

export type { ComboboxOption, ComboboxProps };

/**
 * Settings-local Combobox wrapper. Provides translated default labels for
 * loading and empty states so individual call sites don't have to thread
 * them themselves. Call sites can still override via props.
 */
export function Combobox(props: ComboboxProps) {
	return (
		<SharedCombobox
			loadingLabel={t('common.loading')}
			noResultsLabel={t('common.no_results')}
			{...props}
		/>
	);
}
