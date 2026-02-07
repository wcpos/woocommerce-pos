import Book from '../../assets/book.svg';
import Question from '../../assets/comment-question.svg';
import Discord from '../../assets/discord.svg';
import Email from '../../assets/email.svg';
import { t } from '../translations';

export function Footer() {
	return (
		<footer className="wcpos:border-t wcpos:border-gray-200 wcpos:mt-8 wcpos:py-6">
			<h4 className="wcpos:text-sm wcpos:font-semibold wcpos:text-gray-700 wcpos:mb-3">
				{t('settings.need_help')}
			</h4>
			<div className="wcpos:flex wcpos:flex-wrap wcpos:gap-4 wcpos:text-sm">
				<a
					href="https://docs.wcpos.com"
					target="_blank"
					rel="noreferrer"
					className="wcpos:flex wcpos:items-center wcpos:gap-1.5 wcpos:text-gray-600 hover:wcpos:text-gray-900 wcpos:no-underline"
				>
					<span className="wcpos:h-4 wcpos:w-4">
						<Book fill="#3c434a" />
					</span>
					{t('common.documentation')}
				</a>
				<a
					href="https://faq.wcpos.com"
					target="_blank"
					rel="noreferrer"
					className="wcpos:flex wcpos:items-center wcpos:gap-1.5 wcpos:text-gray-600 hover:wcpos:text-gray-900 wcpos:no-underline"
				>
					<span className="wcpos:h-4 wcpos:w-4">
						<Question fill="#3c434a" />
					</span>
					{t('common.faq')}
				</a>
				<a
					href="mailto:support@wcpos.com"
					target="_blank"
					rel="noreferrer"
					className="wcpos:flex wcpos:items-center wcpos:gap-1.5 wcpos:text-gray-600 hover:wcpos:text-gray-900 wcpos:no-underline"
				>
					<span className="wcpos:h-4 wcpos:w-4">
						<Email fill="#3c434a" />
					</span>
					support@wcpos.com
				</a>
				<a
					href="https://wcpos.com/discord"
					target="_blank"
					rel="noreferrer"
					className="wcpos:flex wcpos:items-center wcpos:gap-1.5 wcpos:text-gray-600 hover:wcpos:text-gray-900 wcpos:no-underline"
				>
					<span className="wcpos:h-4 wcpos:w-4">
						<Discord fill="#3c434a" />
					</span>
					Discord
				</a>
			</div>
		</footer>
	);
}
