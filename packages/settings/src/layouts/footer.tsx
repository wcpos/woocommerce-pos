import Book from '../../assets/book.svg';
import Question from '../../assets/comment-question.svg';
import Email from '../../assets/email.svg';
import { t } from '../translations';

export function Footer() {
	return (
		<footer className="wcpos:border-t wcpos:border-gray-200 wcpos:shrink-0 wcpos:px-6 wcpos:py-2 wcpos:grid wcpos:grid-cols-3 wcpos:lg:flex wcpos:items-center wcpos:gap-x-4 wcpos:gap-y-2 wcpos:text-xs">
			<span className="wcpos:font-semibold wcpos:text-gray-500">
				{t('settings.need_help')}
			</span>
			<div className="wcpos:flex wcpos:flex-col wcpos:lg:flex-row wcpos:gap-1 wcpos:lg:gap-4">
				<a
					href="https://docs.wcpos.com"
					target="_blank"
					rel="noreferrer"
					className="wcpos:flex wcpos:items-center wcpos:gap-1 wcpos:text-gray-500 hover:wcpos:text-gray-900 wcpos:no-underline"
				>
					<span className="wcpos:h-3.5 wcpos:w-3.5">
						<Book fill="#6b7280" />
					</span>
					{t('common.documentation')}
				</a>
				<a
					href="https://faq.wcpos.com"
					target="_blank"
					rel="noreferrer"
					className="wcpos:flex wcpos:items-center wcpos:gap-1 wcpos:text-gray-500 hover:wcpos:text-gray-900 wcpos:no-underline"
				>
					<span className="wcpos:h-3.5 wcpos:w-3.5">
						<Question fill="#6b7280" />
					</span>
					{t('common.faq')}
				</a>
			</div>
			<div className="wcpos:flex wcpos:flex-col wcpos:lg:flex-row wcpos:gap-1 wcpos:lg:gap-4">
				<a
					href="mailto:support@wcpos.com"
					target="_blank"
					rel="noreferrer"
					className="wcpos:flex wcpos:items-center wcpos:gap-1 wcpos:text-gray-500 hover:wcpos:text-gray-900 wcpos:no-underline"
				>
					<span className="wcpos:h-3.5 wcpos:w-3.5">
						<Email fill="#6b7280" />
					</span>
					support@wcpos.com
				</a>
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
					/>
				</a>
			</div>
		</footer>
	);
}
