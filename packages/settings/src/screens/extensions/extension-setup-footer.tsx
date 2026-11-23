import * as React from 'react';

import { Chip, LinkButton, Notice, Toggle } from '@wcpos/ui';

import { CheckIcon } from './check-icon';
import { getSlot, renderSlot } from './extension-slots';
import useSettingsApi from '../../hooks/use-settings-api';
import { t } from '../../translations';

import type { Extension } from './index';

/**
 * An active payment extension whose gateway the POS lists, on a site with Pro:
 * its card shows whether cashiers can use it and lets the owner fix that from the
 * card. Without Pro, Free's tier rule keeps the gateway off in the POS, so the card
 * stays as it was.
 */
export function showsPosSetup(extension: Extension): boolean {
	return (
		(extension.status === 'active' || extension.status === 'update_available') &&
		!!extension.gateway_id &&
		!!getSlot('extensions.setup_action')
	);
}

/**
 * The extension's gateway in the POS payment-gateways settings, the same data the
 * Gateways table reads and writes.
 */
function usePosGateway(gatewayId: string, options?: { quietErrors?: boolean }) {
	const { data, mutate } = useSettingsApi('payment-gateways', options);
	const gateways = data?.gateways as
		Record<string, { enabled?: boolean; needs_setup?: boolean | null }> | undefined;
	const gateway = gateways?.[gatewayId];

	return { enabled: !!gateway?.enabled, needsSetup: gateway?.needs_setup ?? null, mutate };
}

/**
 * The one status chip of an active payment extension (with no update waiting):
 * how many steps are left before cashiers can use it.
 */
export function SetupChip({ extension }: { extension: Extension }) {
	const { enabled, needsSetup } = usePosGateway(extension.gateway_id as string);
	const remaining = (enabled ? 0 : 1) + (needsSetup === true ? 1 : 0);

	if (remaining === 2) {
		return <Chip variant="warning">{t('extensions.steps_left_2', '2 steps left')}</Chip>;
	}
	if (remaining === 1) {
		return <Chip variant="warning">{t('extensions.steps_left_1', '1 step left')}</Chip>;
	}
	if (needsSetup === false) {
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
 * left, the settings state (and Pro's setup action) on the right. The switch saves
 * through the settings hook, which updates it at once and rolls it back if the save
 * fails; the card then says so itself, as the one message for that failure.
 */
export function ExtensionSetupFooter({ extension }: { extension: Extension }) {
	const gatewayId = extension.gateway_id as string;
	const { enabled, needsSetup, mutate } = usePosGateway(gatewayId, { quietErrors: true });
	// A change while a save is in flight is ignored, so a second click cannot race the
	// first. The switch is not disabled: that would drop keyboard focus.
	const saving = React.useRef(false);
	// The direction of the switch that just failed to save, until the next attempt.
	const [failedTo, setFailedTo] = React.useState<'on' | 'off' | null>(null);

	const onToggle = (next: boolean) => {
		if (saving.current) {
			return;
		}
		saving.current = true;
		setFailedTo(null);
		mutate(
			{ gateways: { [gatewayId]: { enabled: next } } },
			{
				onError: () => setFailedTo(next ? 'on' : 'off'),
				onSettled: () => {
					saving.current = false;
				},
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
					{needsSetup !== false && (
						<LinkButton href={extension.settings_url ?? ''}>
							{needsSetup
								? t('extensions.finish_settings', 'Finish settings')
								: t('extensions.check_settings', 'Check settings')}
						</LinkButton>
					)}
					{needsSetup === false && (
						<span className="wcpos:flex wcpos:items-center wcpos:gap-1.5 wcpos:text-sm wcpos:text-gray-700">
							<CheckIcon className="wcpos:h-3.5 wcpos:w-3.5 wcpos:text-green-700" />
							{t('extensions.settings_finished', 'Settings finished')}
						</span>
					)}
					{renderSlot('extensions.setup_action', extension)}
				</div>
			</div>
			{needsSetup === null && (
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
