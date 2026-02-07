import * as React from 'react';

import { Tooltip } from './ui/tooltip';

import HelpIcon from '../../assets/comment-question.svg';

interface LabelProps {
	children: React.ReactNode;
	tip?: string;
}

const Label = ({ children, tip }: LabelProps) => {
	return (
		<div className="wcpos:flex wcpos:items-center wcpos:gap-2">
			{children}
			{tip && (
				<Tooltip text={tip}>
					<span className="wcpos:inline-flex wcpos:text-gray-300">
						<HelpIcon className="wcpos:h-5 wcpos:w-5" fill="currentColor" />
					</span>
				</Tooltip>
			)}
		</div>
	);
};

export default Label;
