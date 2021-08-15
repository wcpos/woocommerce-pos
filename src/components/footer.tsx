import * as React from 'react';
import {
	AtSymbolIcon,
	ChatAlt2Icon,
	DocumentTextIcon,
	QuestionMarkCircleIcon,
} from '@heroicons/react/solid';

const Footer = () => {
	return (
		<div className="bg-white rounded-lg p-4">
			<div className="px-4 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
				<div>
					<h3>Need help?</h3>
				</div>
				<div className="mt-1 sm:mt-0">
					<p className="flex items-center">
						<DocumentTextIcon className="w-5 h-5 mr-2 text-gray-400" />
						<a href="https://docs.wcpos.com" target="_blank" rel="noreferrer">
							Documentation
						</a>
					</p>
					<p className="flex items-center">
						<QuestionMarkCircleIcon className="w-5 h-5 mr-2 text-gray-400" />
						<a href="https://faq.wcpos.com" target="_blank" rel="noreferrer">
							Frequently Asked Questions
						</a>
					</p>
					<p className="flex items-center">
						<AtSymbolIcon className="w-5 h-5 mr-2 text-gray-400" />
						<a href="mailto:support@wcpos.com" target="_blank" rel="noreferrer">
							support@wcpos.com
						</a>
					</p>
					<p className="flex items-center">
						<ChatAlt2Icon className="w-5 h-5 mr-2 text-gray-400" />
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
