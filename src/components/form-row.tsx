import * as React from 'react';
import Tooltip from '../components/tooltip';
import { QuestionMarkCircleIcon } from '@heroicons/react/solid';

interface FormRowProps {
	label: React.ReactNode;
	help?: string;
	children: React.ReactNode;
	extra?: React.ReactNode;
	id?: string;
}

const FormRow = ({ label, help, children, extra, id }: FormRowProps) => {
	return (
		<div className="px-4 py-5 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6 items-center">
			<div className="flex">
				<label className="block flex-1 font-medium text-sm" htmlFor={id}>
					{label}
				</label>
				{help && (
					<Tooltip label={help}>
						<button className="w-5 h-5 text-gray-400 cursor-help">
							<QuestionMarkCircleIcon />
						</button>
					</Tooltip>
				)}
			</div>
			<div className="mt-1 sm:mt-0">{children}</div>
			{extra && <div className="mt-1 sm:mt-0">{extra}</div>}
		</div>
	);
};

export default FormRow;
