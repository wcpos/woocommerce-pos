import * as React from 'react';

import { Tooltip, Icon } from '@wordpress/components';

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
					<span>
						<Icon icon="editor-help" className="wcpos:text-gray-300" />
					</span>
				</Tooltip>
			)}
		</div>
	);
};

export default Label;
