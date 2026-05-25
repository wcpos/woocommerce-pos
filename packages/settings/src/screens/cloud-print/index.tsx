import { useCloudPrintSettings } from '../../hooks/use-cloud-print-settings';
import { t } from '../../translations';

function CloudPrint() {
	const { settings } = useCloudPrintSettings();

	return (
		<div className="wcpos:px-4 wcpos:pb-5">
			<h2 className="wcpos:text-base">{t('cloud_print.printers', 'Cloud printers')}</h2>
			<p>{t('cloud_print.printers_description', 'Printers that pull jobs from this site.')}</p>

			{settings.printers.length === 0 ? (
				<p data-testid="cloud-print-empty">{t('cloud_print.no_printers', 'No cloud printers yet.')}</p>
			) : (
				<ul data-testid="cloud-print-list" className="wcpos:flex wcpos:flex-col wcpos:gap-2">
					{settings.printers.map((printer) => (
						<li key={printer.id} data-testid={`cloud-printer-${printer.id}`}>
							<span className="wcpos:font-semibold">{printer.name}</span>{' '}
							<span className="wcpos:text-gray-500">({printer.protocol})</span>
						</li>
					))}
				</ul>
			)}
		</div>
	);
}

export default CloudPrint;
