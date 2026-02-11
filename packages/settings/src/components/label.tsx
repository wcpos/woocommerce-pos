import * as React from 'react';

import { Tooltip } from './ui/tooltip';
import HelpIcon from '../../assets/comment-question.svg';

interface LabelProps {
	children: React.ReactNode;
	tip?: string;
}

function Label({ children, tip }: LabelProps) {
	return (
		<div className="wcpos:flex wcpos:items-center wcpos:gap-2">
			{children}
			{tip && (
				<Tooltip text={tip}>
					<span className="wcpos:inline-flex wcpos:text-gray-300">
						<HelpIcon className="wcpos:h-4 wcpos:w-4" fill="currentColor" />
					</span>
				</Tooltip>
			)}
		</div>
	);
}

export default Label;
