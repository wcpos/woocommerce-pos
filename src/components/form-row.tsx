import * as React from 'react';
import { get } from 'lodash';
import classnames from 'classnames';
import Tooltip from '../components/tooltip';
import QuestionMarkCircleIcon from '@heroicons/react/24/solid/QuestionMarkCircleIcon';

interface ColProps {
	children: React.ReactNode;
	className?: string;
}

const Col = ({ children, className }: ColProps) => {
	return <div className={classnames(className)}>{children}</div>;
};

interface LabelProps {
	children: string;
	help?: string;
	className?: string;
	id: string;
}

const Label = ({ children, help, className, id }: LabelProps) => {
	return (
		<label
			className={classnames('wcpos-block wcpos-flex-1 wcpos-font-medium wcpos-text-sm', className)}
			htmlFor={id}
		>
			{children}
			{help && (
				<Tooltip label={help}>
					<button className="wcpos-w-5 wcpos-h-5 wcpos-text-gray-300 wcpos-cursor-help wcpos-align-bottom wcpos-ml-2">
						<QuestionMarkCircleIcon />
					</button>
				</Tooltip>
			)}
		</label>
	);
};

interface FormRowProps {
	children: React.ReactElement[];
}

const FormRow = ({ children }: FormRowProps) => {
	return (
		<div className="wcpos-px-4 wcpos-py-5 sm:wcpos-grid sm:wcpos-grid-cols-3 sm:wcpos-gap-4 sm:wcpos-px-6 wcpos-items-center">
			{React.Children.map(children, (child, index) => {
				const name = get(child, ['type', 'displayName']);
				let className = child.props.className || '';

				if (index > 0) {
					className += ' wcpos-mt-1 sm:wcpos-mt-0';
				}

				if (name == 'Label') {
					return <Label {...child.props} className={className} />;
				} else if (name == 'Col') {
					return <Col {...child.props} className={className} />;
				}
			})}
		</div>
	);
};

Col.displayName = 'Col';
Label.displayName = 'Label';
FormRow.displayName = 'FormRow';
FormRow.Col = Col;
FormRow.Label = Label;
export default FormRow;
