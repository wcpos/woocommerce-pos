import * as React from 'react';

import classNames from 'classnames';

export interface ModalProps {
	open: boolean;
	onClose: (value: boolean) => void;
	title?: string;
	'aria-label'?: string;
	description?: string;
	children: React.ReactNode;
	className?: string;
}

export function Modal({
	open,
	onClose,
	title,
	'aria-label': ariaLabel,
	description,
	children,
	className,
}: ModalProps) {
	const titleId = React.useId();
	const descriptionId = React.useId();

	React.useEffect(() => {
		if (!open) return;

		const handleKeyDown = (event: KeyboardEvent) => {
			if (event.key === 'Escape') {
				onClose(false);
			}
		};

		document.addEventListener('keydown', handleKeyDown);

		return () => {
			document.removeEventListener('keydown', handleKeyDown);
		};
	}, [open, onClose]);

	if (!open) return null;

	return (
		<div className="wcpos:relative wcpos:z-50">
			<div
				className="wcpos:fixed wcpos:inset-0 wcpos:bg-black/30"
				aria-hidden="true"
			/>
			<div
				className="wcpos:fixed wcpos:inset-0 wcpos:flex wcpos:items-center wcpos:justify-center wcpos:p-4"
				onClick={() => onClose(false)}
			>
				<div
					role="dialog"
					aria-modal="true"
					aria-labelledby={title ? titleId : undefined}
					aria-label={!title ? ariaLabel : undefined}
					aria-describedby={description ? descriptionId : undefined}
					onClick={(event) => event.stopPropagation()}
					className={classNames(
						'wcpos:mx-auto wcpos:max-w-lg wcpos:w-full wcpos:rounded-lg wcpos:bg-white wcpos:p-6 wcpos:shadow-xl',
						className
					)}
				>
					{title && (
						<h2
							id={titleId}
							className="wcpos:text-lg wcpos:font-semibold wcpos:text-gray-900 wcpos:mb-2"
						>
							{title}
						</h2>
					)}
					{description && (
						<p
							id={descriptionId}
							className="wcpos:text-sm wcpos:text-gray-500 wcpos:mb-4"
						>
							{description}
						</p>
					)}
					{children}
				</div>
			</div>
		</div>
	);
}
