import { execFileSync } from 'node:child_process';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));
const packageDir = resolve(__dirname, '..');
const rootDir = resolve(packageDir, '../..');
const check = process.argv.includes('--check');

const phpScript = resolve(packageDir, 'scripts/export-receipt-schema.php');
let schemaJson = execFileSync('php', [phpScript], {
	cwd: rootDir,
	encoding: 'utf8',
});

schemaJson = `${JSON.stringify(JSON.parse(schemaJson), null, '\t')}\n`;
const schema = JSON.parse(schemaJson);
const schemaPath = resolve(packageDir, 'src/receipt-data.schema.json');
const typesPath = resolve(packageDir, 'src/receipt-data.types.ts');

const requiredKeys = schema.required ?? [];
const typeSource = `// Generated from WCPOS\\WooCommercePOS\\Services\\Receipt_Data_Schema::get_json_schema().\n// Do not edit by hand. Run \`pnpm --filter @wcpos/receipt-schema build\`.\n\nexport type ReceiptScalar = string | number | boolean | null;\nexport type ReceiptValue = ReceiptScalar | ReceiptValue[] | { [key: string]: ReceiptValue };\nexport type ReceiptObject = { [key: string]: ReceiptValue };\n\nexport interface ReceiptData {\n${requiredKeys.map((key) => `\t${JSON.stringify(key)}: ReceiptObject | ReceiptObject[];`).join('\n')}\n}\n\nexport const receiptDataRequiredKeys = ${JSON.stringify(requiredKeys, null, '\t')} as const;\nexport type ReceiptDataRequiredKey = (typeof receiptDataRequiredKeys)[number];\nexport const receiptDataSchemaVersion = ${JSON.stringify(schema.properties?.meta?.properties?.schema_version?.const ?? '')} as const;\n`;

function assertCurrent(path, expected) {
	let actual = '';
	try {
		actual = readFileSync(path, 'utf8');
	} catch {
		throw new Error(`${path} is missing. Run pnpm --filter @wcpos/receipt-schema build.`);
	}

	if (actual !== expected) {
		throw new Error(`${path} is stale. Run pnpm --filter @wcpos/receipt-schema build.`);
	}
}

if (check) {
	assertCurrent(schemaPath, schemaJson);
	assertCurrent(typesPath, typeSource);
	console.log('receipt-schema artifacts are current');
} else {
	writeFileSync(schemaPath, schemaJson);
	writeFileSync(typesPath, typeSource);
	console.log('receipt-schema artifacts generated');
}
