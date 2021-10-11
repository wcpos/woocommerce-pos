import * as React from 'react';
import AtSymbolIcon from '@heroicons/react/solid/AtSymbolIcon';
import ChatAlt2Icon from '@heroicons/react/solid/ChatAlt2Icon';
import DocumentTextIcon from '@heroicons/react/solid/DocumentTextIcon';
import QuestionMarkCircleIcon from '@heroicons/react/solid/QuestionMarkCircleIcon';

const Footer = () => {
	return (
		<div className="wcpos-bg-white wcpos-rounded-lg p-4">
			<div className="wcpos-px-4 sm:wcpos-grid sm:wcpos-grid-cols-3 sm:wcpos-gap-4 sm:wcpos-px-6">
				<div>
					<h3>Need help?</h3>
				</div>
				<div className="wcpos-mt-1 sm:wcpos-mt-0">
					<p className="wcpos-flex wcpos-items-center">
						<DocumentTextIcon className="wcpos-w-5 wcpos-h-5 wcpos-mr-2 wcpos-text-gray-400" />
						<a href="https://docs.wcpos.com" target="_blank" rel="noreferrer">
							Documentation
						</a>
					</p>
					<p className="wcpos-flex wcpos-items-center">
						<QuestionMarkCircleIcon className="wcpos-w-5 wcpos-h-5 wcpos-mr-2 wcpos-text-gray-400" />
						<a href="https://faq.wcpos.com" target="_blank" rel="noreferrer">
							Frequently Asked Questions
						</a>
					</p>
					<p className="wcpos-flex wcpos-items-center">
						<AtSymbolIcon className="wcpos-w-5 wcpos-h-5 wcpos-mr-2 wcpos-text-gray-400" />
						<a href="mailto:support@wcpos.com" target="_blank" rel="noreferrer">
							support@wcpos.com
						</a>
					</p>
					<p className="wcpos-flex wcpos-items-center">
						<ChatAlt2Icon className="wcpos-w-5 wcpos-h-5 wcpos-mr-2 wcpos-text-gray-400" />
						<a href="https://wcpos.com/discord" target="_blank" rel="noreferrer">
							<img
								id="discord-badge"
								src="https://img.shields.io/discord/711884517081612298?color=%232271B1&amp;logoColor=white"
								alt="Discord Chat"
							/>
						</a>
					</p>
				</div>
			</div>
		</div>
	);
};

export default Footer;
