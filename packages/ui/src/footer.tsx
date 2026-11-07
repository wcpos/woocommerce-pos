export interface FooterProps {
	/** Heading before the links. Consumers should pass translated strings. */
	needHelpLabel?: string;
	documentationLabel?: string;
	faqLabel?: string;
	/** Screen-reader hint after each new-tab link label. */
	opensNewTabLabel?: string;
	supportEmail?: string;
	showDiscord?: boolean;
}

const linkClassName =
	'wcpos:flex wcpos:items-center wcpos:gap-1 wcpos:text-gray-500 wcpos:hover:text-gray-900 wcpos:no-underline';
const groupClassName = 'wcpos:flex wcpos:flex-col wcpos:md:flex-row wcpos:gap-1 wcpos:lg:gap-4';

const BOOK_ICON = {
	viewBox: '0 0 448 512',
	d: 'M96 0C43 0 0 43 0 96V416c0 53 43 96 96 96H384h32c17.7 0 32-14.3 32-32s-14.3-32-32-32V384c17.7 0 32-14.3 32-32V32c0-17.7-14.3-32-32-32H384 96zm0 384H352v64H96c-17.7 0-32-14.3-32-32s14.3-32 32-32zm32-240c0-8.8 7.2-16 16-16H336c8.8 0 16 7.2 16 16s-7.2 16-16 16H144c-8.8 0-16-7.2-16-16zm16 48H336c8.8 0 16 7.2 16 16s-7.2 16-16 16H144c-8.8 0-16-7.2-16-16s7.2-16 16-16z',
};
const QUESTION_ICON = {
	viewBox: '0 0 512 512',
	d: 'M256 31.1c-141.4 0-255.1 93.13-255.1 208c0 49.63 21.35 94.98 56.97 130.7c-12.5 50.38-54.27 95.27-54.77 95.77c-2.25 2.25-2.875 5.734-1.5 8.734c1.249 3 4.021 4.766 7.271 4.766c66.25 0 115.1-31.76 140.6-51.39c32.63 12.25 69.02 19.39 107.4 19.39c141.4 0 255.1-93.13 255.1-208S397.4 31.1 256 31.1zM249.1 352C235.4 352 224 340.6 224 326S235.4 300 249.1 300c14.63 0 26 11.38 26 26S264.6 352 249.1 352zM307.7 235.4l-38.33 23.13v1.652c0 10.74-9.168 19.83-20 19.83c-10.83 0-20-9.088-20-19.83V246.1c0-6.609 3.332-13.22 10-17.35l47.5-28.09C292.7 198.2 296 192.4 296 185.8c0-9.914-8.334-18.17-18.33-18.17H234.3c-10 0-18.33 8.26-18.33 18.17c0 10.74-9.166 19.83-20 19.83S176 196.6 176 185.8C176 153.6 201.8 128 234.3 128h43.33C310.2 128 336 153.6 336 185.8C336 205.7 325.2 224.7 307.7 235.4z',
};
const EMAIL_ICON = {
	viewBox: '0 0 512 512',
	d: 'M48 64C21.5 64 0 85.5 0 112c0 15.1 7.1 29.3 19.2 38.4L236.8 313.6c11.4 8.5 27 8.5 38.4 0L492.8 150.4c12.1-9.1 19.2-23.3 19.2-38.4c0-26.5-21.5-48-48-48H48zM0 176V384c0 35.3 28.7 64 64 64H448c35.3 0 64-28.7 64-64V176L294.4 339.2c-22.8 17.1-54 17.1-76.8 0L0 176z',
};

function Icon({ viewBox, d }: { viewBox: string; d: string }) {
	return (
		<span className="wcpos:h-3.5 wcpos:w-3.5">
			<svg xmlns="http://www.w3.org/2000/svg" viewBox={viewBox} fill="#6b7280">
				<path d={d} />
			</svg>
		</span>
	);
}

/**
 * The "Need help?" footer shared by the WCPOS admin apps. DOM strings come in
 * as props with English defaults; consumers should pass translated strings.
 */
export function Footer({
	needHelpLabel = 'Need help?',
	documentationLabel = 'Documentation',
	faqLabel = 'FAQ',
	opensNewTabLabel = '(opens in new tab)',
	supportEmail = 'support@wcpos.com',
	showDiscord = true,
}: FooterProps) {
	const newTabHint = <span className="wcpos:sr-only">{` ${opensNewTabLabel}`}</span>;

	return (
		<footer className="wcpos:border-t wcpos:border-gray-200 wcpos:shrink-0 wcpos:px-6 wcpos:py-2 wcpos:flex wcpos:flex-col wcpos:sm:flex-row wcpos:flex-wrap wcpos:items-start wcpos:gap-x-4 wcpos:gap-y-2 wcpos:text-xs">
			<span className="wcpos:font-semibold wcpos:text-gray-500">{needHelpLabel}</span>
			<div className={groupClassName}>
				<a href="https://docs.wcpos.com" target="_blank" rel="noreferrer" className={linkClassName}>
					<Icon {...BOOK_ICON} />
					{documentationLabel}
					{newTabHint}
				</a>
				<a href="https://faq.wcpos.com" target="_blank" rel="noreferrer" className={linkClassName}>
					<Icon {...QUESTION_ICON} />
					{faqLabel}
					{newTabHint}
				</a>
			</div>
			<div className={groupClassName}>
				<a
					href={`mailto:${supportEmail}`}
					target="_blank"
					rel="noreferrer"
					className={linkClassName}
				>
					<Icon {...EMAIL_ICON} />
					{supportEmail}
				</a>
				{showDiscord && (
					<a
						href="https://wcpos.com/discord"
						target="_blank"
						rel="noreferrer"
						className="wcpos:no-underline"
					>
						<img
							src="https://img.shields.io/badge/dynamic/json?url=https%3A%2F%2Fdiscord.com%2Fapi%2Finvites%2FGCEeEVpEvX%3Fwith_counts%3Dtrue&query=%24.approximate_presence_count&logo=discord&logoColor=white&label=users%20online&color=7c3aed&style=flat-square"
							alt="Discord"
							className="wcpos:h-4"
							loading="lazy"
							width="100"
							height="16"
						/>
					</a>
				)}
			</div>
		</footer>
	);
}
