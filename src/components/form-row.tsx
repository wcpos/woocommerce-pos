import * as React from 'react';
import { get } from 'lodash';
import classnames from 'classnames';
import Tooltip from '../components/tooltip';
import { QuestionMarkCircleIcon } from '@heroicons/react/solid';

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
		<label className={classnames('block flex-1 font-medium text-sm', className)} htmlFor={id}>
			{children}
			{help && (
				<Tooltip label={help}>
					<button className="w-5 h-5 text-gray-300 cursor-help align-bottom ml-2">
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
		<div className="px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6 items-center">
			{React.Children.map(children, (child, index) => {
				const name = get(child, 'type.name');
				let className = child.props.className || '';

				if (index > 0) {
					className += ' mt-1 sm:mt-0';
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

FormRow.Col = Col;
FormRow.Label = Label;
export default FormRow;
