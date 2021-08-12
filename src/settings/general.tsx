import * as React from 'react';
import { __ } from '@wordpress/i18n';
import {
	PanelRow,
	ToggleControl,
	CheckboxControl,
	TextControl,
	Button,
	// @ts-ignore
	ComboboxControl,
	Tooltip,
	Icon,
} from '@wordpress/components';
import { get } from 'lodash';
import UserSelect from '../components/user-select';
import useSettingsApi from '../hooks/use-settings-api';

export interface GeneralSettingsProps {
	pos_only_products: boolean;
	decimal_qty: boolean;
	force_ssl: boolean;
	generate_username: boolean;
	default_customer: number;
	default_customer_is_cashier: boolean;
	barcode_field: string;
}

interface GeneralProps {
	hydrate: import('../settings').HydrateProps;
}

const General = ({ hydrate }: GeneralProps) => {
	const { settings, dispatch } = useSettingsApi('general', get(hydrate, ['settings', 'general']));
	const [newBarcodeField, setNewBarcodeField] = React.useState<string>('');
	const [showNewBarcodeField, setShowNewBarcodeField] = React.useState<boolean>(false);

	const barcodeFields = React.useMemo(() => {
		const fields = Object.values(get(hydrate, 'barcode_fields'));
		if (!fields.includes(settings.barcode_field)) {
			fields.push(settings.barcode_field);
		}
		return fields;
	}, [hydrate, settings]);

	return (
		<>
			<PanelRow>
				<ToggleControl
					label="Force SSL"
					checked={settings.force_ssl}
					onChange={(force_ssl: boolean) => {
						dispatch({
							type: 'update',
							payload: { force_ssl },
						});
					}}
				/>
				<Tooltip text="More information" position="top center">
					<Icon icon="editor-help" />
				</Tooltip>
			</PanelRow>
			<PanelRow>
				<ToggleControl
					label="Enable POS only products"
					checked={settings.pos_only_products}
					onChange={(pos_only_products: boolean) => {
						dispatch({
							type: 'update',
							payload: { pos_only_products },
						});
					}}
				/>
				<Tooltip text="More information" position="top center">
					<Icon icon="editor-help" />
				</Tooltip>
			</PanelRow>
			<PanelRow>
				<ToggleControl
					label="Enable decimal quantities"
					checked={settings.decimal_qty}
					onChange={(decimal_qty: boolean) => {
						dispatch({
							type: 'update',
							payload: { decimal_qty },
						});
					}}
				/>
				<Tooltip
					text="Allows items to have decimal values in the quantity field, eg: 0.25"
					position="top center"
				>
					<Icon icon="editor-help" />
				</Tooltip>
			</PanelRow>
			<PanelRow>
				<ToggleControl
					label="Automatically generate username from customer email"
					checked={settings.generate_username}
					onChange={(generate_username: boolean) => {
						dispatch({
							type: 'update',
							payload: { generate_username },
						});
					}}
				/>
				<Tooltip
					text="More Allows items to have decimal values in the quantity field, eg: 0.25"
					position="top center"
				>
					<Icon icon="editor-help" />
				</Tooltip>
			</PanelRow>
			<PanelRow>
				<UserSelect
					selectedUserId={settings.default_customer}
					initialOption={get(hydrate, ['default_customer'])}
					dispatch={dispatch}
					disabled={!settings.default_customer_is_cashier}
				/>
				<CheckboxControl
					label="Use cashier account"
					checked={settings.default_customer_is_cashier}
					onChange={(default_customer_is_cashier: boolean) => {
						dispatch({
							type: 'update',
							payload: { default_customer_is_cashier },
						});
					}}
				/>
			</PanelRow>
			<PanelRow>
				<ComboboxControl
					label="Barcode Field"
					// help="Select a meta field to use as the product barcode"
					value={settings.barcode_field}
					onChange={(value: string) => {
						if (value) {
							dispatch({
								type: 'update',
								payload: { barcode_field: value },
							});
						}
					}}
					options={barcodeFields.map((field: string) => ({ label: field, value: field }))}
					allowReset={true}
				/>
				{showNewBarcodeField ? (
					<>
						<TextControl
							value={newBarcodeField}
							onChange={(nextValue: string) => setNewBarcodeField(nextValue)}
						/>
						<Button
							disabled={!newBarcodeField}
							isPrimary
							onClick={() => {
								dispatch({
									type: 'update',
									payload: { barcode_field: newBarcodeField },
								});
							}}
						>
							{__('Add')}
						</Button>
						<Button
							onClick={() => {
								setShowNewBarcodeField(false);
							}}
						>
							{__('Cancel')}
						</Button>
					</>
				) : (
					<Button
						onClick={() => {
							setShowNewBarcodeField(true);
						}}
					>
						Add new meta field
					</Button>
				)}
			</PanelRow>
		</>
	);
};

export default General;
