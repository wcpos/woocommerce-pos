import * as React from 'react';
import apiFetch from '@wordpress/api-fetch';
// @ts-ignore
import { ComboboxControl } from '@wordpress/components';

interface BarcodeFieldSelectProps {
	selectedBarcodeField: string;
	dispatch: any;
}

interface FieldOptionProps {
	value?: string;
	label?: string;
}

const BarcodeFieldSelect = ({ selectedBarcodeField, dispatch }: BarcodeFieldSelectProps) => {
	const [barcodeFields, setBarcodeFields] = React.useState<FieldOptionProps[]>([]);

	React.useEffect(() => {
		async function getBarcodeFields() {
			const fields = await apiFetch({
				path: 'wcpos/v1/settings/barcode-fields?wcpos=1',
				method: 'GET',
			})
				.catch((err) => {
					console.log(err);
				})
				.finally(() => {
					// setUsers([]);
				});

			if (Array.isArray(fields)) {
				const fieldOptions = fields.map((field) => {
					return {
						value: field,
						label: field,
					};
				});
				setBarcodeFields(fieldOptions);
			}
		}

		getBarcodeFields();
	}, []);

	return (
		<ComboboxControl
			label="Barcode Field"
			help="Select a meta field to use as the product barcode"
			value={'_sku'}
			onChange={(val) => {
				console.log(val);
			}}
			options={barcodeFields}
			onFilterValueChange={(val) => {
				console.log(val);
			}}
			allowReset={true}
		/>
	);
};

export default BarcodeFieldSelect;
