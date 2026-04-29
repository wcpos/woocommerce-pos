// Generated from WCPOS\WooCommercePOS\Services\Receipt_Data_Schema::get_json_schema().
// Do not edit by hand. Run `pnpm --filter @wcpos/receipt-schema build`.

export type ReceiptScalar = string | number | boolean | null;
export type ReceiptValue = ReceiptScalar | ReceiptValue[] | { [key: string]: ReceiptValue };
export type ReceiptObject = { [key: string]: ReceiptValue };

export interface ReceiptData {
	"receipt": ReceiptObject | ReceiptObject[];
	"order": ReceiptObject | ReceiptObject[];
	"meta": ReceiptObject | ReceiptObject[];
	"store": ReceiptObject | ReceiptObject[];
	"cashier": ReceiptObject | ReceiptObject[];
	"customer": ReceiptObject | ReceiptObject[];
	"lines": ReceiptObject | ReceiptObject[];
	"fees": ReceiptObject | ReceiptObject[];
	"shipping": ReceiptObject | ReceiptObject[];
	"discounts": ReceiptObject | ReceiptObject[];
	"totals": ReceiptObject | ReceiptObject[];
	"tax_summary": ReceiptObject | ReceiptObject[];
	"payments": ReceiptObject | ReceiptObject[];
	"fiscal": ReceiptObject | ReceiptObject[];
	"presentation_hints": ReceiptObject | ReceiptObject[];
	"i18n": ReceiptObject | ReceiptObject[];
}

export const receiptDataRequiredKeys = [
	"receipt",
	"order",
	"meta",
	"store",
	"cashier",
	"customer",
	"lines",
	"fees",
	"shipping",
	"discounts",
	"totals",
	"tax_summary",
	"payments",
	"fiscal",
	"presentation_hints",
	"i18n"
] as const;
export type ReceiptDataRequiredKey = (typeof receiptDataRequiredKeys)[number];
export const receiptDataSchemaVersion = "1.2.0" as const;
