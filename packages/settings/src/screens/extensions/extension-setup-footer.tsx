import * as React from 'react';

import { useQueryClient } from '@tanstack/react-query';

import { Chip, LinkButton, Toggle } from '@wcpos/ui';

import Notice from '../../components/notice';
import useSettingsApi from '../../hooks/use-settings-api';
import { t } from '../../translations';

import type { Extension } from './index';

/**
 * An active payment extension whose gateway WooCommerce knows, on a site with Pro:
 * its card shows whether cashiers can use it and lets the owner fix that from the
 * card. Without Pro, Free's tier rule keeps the gateway off in the POS, so the card
 * stays as it was.
 */
export function showsPosSetup(extension: Extension): boolean {
	return (
		(extension.status === 'active' || extension.status === 'update_available') &&
		!!extension.gateway_id &&
		!!(window as any).wcpos?.settings?.getComponent?.('extensions.setup_action')
	);
}

/**
 * Renders the component Pro registered under `key` for this extension, or null.
 */
export function renderSlot(key: string, extension: Extension): React.ReactNode {
	const Slot: React.ComponentType<{ extension: Extension }> | undefined = (
		window as any
	).wcpos?.settings?.getComponent?.(key);
	return Slot ? <Slot extension={extension} /> : null;
}

export function CheckIcon({ className }: { className: string }) {
	return (
		<svg
			xmlns="http://www.w3.org/2000/svg"
			viewBox="0 0 20 20"
			fill="currentColor"
			className={className}
			aria-hidden="true"
			focusable="false"
		>
			<path
				fillRule="evenodd"
				d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"
				clipRule="evenodd"
			/>
		</svg>
	);
}

/**
 * The one status chip of an active payment extension (with no update waiting):
 * how many steps are left before cashiers can use it.
 */
export function SetupChip({ extension }: { extension: Extension }) {
	const remaining = (extension.pos_enabled ? 0 : 1) + (extension.needs_setup === true ? 1 : 0);

	if (remaining === 2) {
		return <Chip variant="warning">{t('extensions.steps_left_2', '2 steps left')}</Chip>;
	}
	if (remaining === 1) {
		return <Chip variant="warning">{t('extensions.steps_left_1', '1 step left')}</Chip>;
	}
	if (extension.needs_setup === false) {
		return (
			<Chip variant="success" icon={<CheckIcon className="wcpos:h-3 wcpos:w-3" />}>
				{t('extensions.ready', 'Ready')}
			</Chip>
		);
	}
	return <Chip variant="neutral">{t('extensions.check_settings', 'Check settings')}</Chip>;
}

/**
 * Footer of an active payment extension's card: the "in the POS" switch on the
 * left, the settings state (and Pro's setup action) on the right. Keyed on
 * `pos_enabled` by the card, so a refreshed value resets the switch.
 */
export function ExtensionSetupFooter({ extension }: { extension: Extension }) {
	const queryClient = useQueryClient();
	const { mutate } = useSettingsApi('payment-gateways');
	const [enabled, setEnabled] = React.useState(!!extension.pos_enabled);
	// The direction of the switch that just failed to save, if any.
	const [failedTo, setFailedTo] = React.useState<'on' | 'off' | null>(null);

	const onToggle = (next: boolean) => {
		setEnabled(next);
		setFailedTo(null);
		mutate(
			{ gateways: { [extension.gateway_id as string]: { enabled: next } } },
			{
				onError: () => {
					setEnabled(!next);
					setFailedTo(next ? 'on' : 'off');
				},
				onSettled: () => queryClient.invalidateQueries({ queryKey: ['extensions'] }),
			}
		);
	};

	return (
		<div className="wcpos:flex wcpos:flex-col wcpos:gap-2">
			<div className="wcpos:flex wcpos:min-h-8 wcpos:flex-wrap wcpos:items-center wcpos:justify-between wcpos:gap-3">
				<Toggle
					checked={enabled}
					onChange={onToggle}
					label={
						enabled
							? t('extensions.on_in_pos', 'On in the POS')
							: t('extensions.off_in_pos', 'Off in the POS')
					}
				/>
				<div className="wcpos:flex wcpos:flex-wrap wcpos:items-center wcpos:gap-2">
					{extension.needs_setup !== false && (
						<LinkButton href={extension.settings_url ?? ''}>
							{extension.needs_setup
								? t('extensions.finish_settings', 'Finish settings')
								: t('extensions.check_settings', 'Check settings')}
						</LinkButton>
					)}
					{extension.needs_setup === false && (
						<span className="wcpos:flex wcpos:items-center wcpos:gap-1.5 wcpos:text-sm wcpos:text-gray-700">
							<CheckIcon className="wcpos:h-3.5 wcpos:w-3.5 wcpos:text-green-700" />
							{t('extensions.settings_finished', 'Settings finished')}
						</span>
					)}
					{renderSlot('extensions.setup_action', extension)}
				</div>
			</div>
			{extension.needs_setup == null && (
				<p className="wcpos:m-0 wcpos:text-right wcpos:text-xs wcpos:text-gray-500">
					{t('extensions.settings_unknown', "We can't tell if they're finished.")}
				</p>
			)}
			{failedTo && (
				<Notice status="error" isDismissible={false}>
					<p className="wcpos:m-0 wcpos:text-xs wcpos:leading-relaxed">
						{failedTo === 'on'
							? t('extensions.turn_on_failed', "Couldn't turn it on in the POS. Please try again.")
							: t(
									'extensions.turn_off_failed',
									"Couldn't turn it off in the POS. Please try again."
								)}
					</p>
				</Notice>
			)}
			{/* Pro's notices (a failed update, deactivate…) get their own full-width row. */}
			{renderSlot('extensions.setup_notice', extension)}
		</div>
	);
}
