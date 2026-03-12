import type { AnyTemplate, GalleryTemplate } from '../types';

interface TemplateTagsProps {
	template: AnyTemplate | GalleryTemplate;
}

function isThermal(template: AnyTemplate | GalleryTemplate): boolean {
	return (
		template.engine === 'thermal' ||
		template.output_type === 'escpos' ||
		template.output_type === 'thermal'
	);
}

function isOffline(template: AnyTemplate | GalleryTemplate): boolean {
	return (
		template.engine === 'logicless' ||
		template.engine === 'thermal' ||
		template.offline_capable
	);
}

export function TemplateTags({ template }: TemplateTagsProps) {
	const thermal = isThermal(template);
	const offline = isOffline(template);
	const paperWidth = 'paper_width' in template && template.paper_width;

	return (
		<div className="wcpos:flex wcpos:gap-1 wcpos:flex-wrap wcpos:mt-0.5">
			{/* Print method */}
			{thermal ? (
				<span
					className="wcpos:text-xs wcpos:bg-blue-50 wcpos:text-blue-700 wcpos:px-1.5 wcpos:py-0.5 wcpos:rounded"
					title="Designed for thermal receipt printers like Epson or Star. If you don't have a receipt printer, use a Browser template instead."
				>
					Receipt Printer
				</span>
			) : (
				<span
					className="wcpos:text-xs wcpos:bg-gray-100 wcpos:text-gray-600 wcpos:px-1.5 wcpos:py-0.5 wcpos:rounded"
					title="Prints using your browser's built-in print dialog. Works with any printer but requires a browser window."
				>
					Browser
				</span>
			)}

			{/* Paper size — only for thermal templates with a paper_width */}
			{thermal && paperWidth === '80mm' && (
				<span
					className="wcpos:text-xs wcpos:bg-slate-100 wcpos:text-slate-600 wcpos:px-1.5 wcpos:py-0.5 wcpos:rounded"
					title="Formatted for 80mm (standard) thermal paper. Most receipt printers use this size."
				>
					80mm
				</span>
			)}
			{thermal && paperWidth === '58mm' && (
				<span
					className="wcpos:text-xs wcpos:bg-slate-100 wcpos:text-slate-600 wcpos:px-1.5 wcpos:py-0.5 wcpos:rounded"
					title="Formatted for 58mm (narrow) thermal paper. Check your printer specs if you're not sure which size you need."
				>
					58mm
				</span>
			)}

			{/* Availability */}
			{offline ? (
				<span
					className="wcpos:text-xs wcpos:bg-green-50 wcpos:text-green-700 wcpos:px-1.5 wcpos:py-0.5 wcpos:rounded"
					title="This template renders on the device without needing a server connection. Faster and works even if your internet goes down."
				>
					Works Offline
				</span>
			) : (
				<span
					className="wcpos:text-xs wcpos:bg-amber-50 wcpos:text-amber-700 wcpos:px-1.5 wcpos:py-0.5 wcpos:rounded"
					title="This template needs your WordPress server to generate the receipt. Requires an active internet connection and may be slower."
				>
					Server Required
				</span>
			)}

			{/* Legacy warning */}
			{template.engine === 'legacy-php' && (
				<span
					className="wcpos:text-xs wcpos:bg-amber-50 wcpos:text-amber-700 wcpos:px-1.5 wcpos:py-0.5 wcpos:rounded"
					title="This template uses the older PHP engine. Consider switching to a newer template for faster, offline-capable receipts."
				>
					&#9888; Legacy PHP
				</span>
			)}
		</div>
	);
}
