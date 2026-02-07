import * as React from 'react';

import { Dialog, DialogPanel, DialogTitle, Description } from '@headlessui/react';
import classNames from 'classnames';

interface ModalProps {
	open: boolean;
	onClose: (value: boolean) => void;
	title?: string;
	description?: string;
	children: React.ReactNode;
	className?: string;
}

export function Modal({ open, onClose, title, description, children, className }: ModalProps) {
	return (
		<Dialog open={open} onClose={onClose} className="wcpos:relative wcpos:z-50">
			{/* Backdrop */}
			<div className="wcpos:fixed wcpos:inset-0 wcpos:bg-black/30" aria-hidden="true" />

			{/* Full-screen container to center the panel */}
			<div className="wcpos:fixed wcpos:inset-0 wcpos:flex wcpos:items-center wcpos:justify-center wcpos:p-4">
				<DialogPanel
					className={classNames(
						'wcpos:mx-auto wcpos:max-w-lg wcpos:w-full wcpos:rounded-lg wcpos:bg-white wcpos:p-6 wcpos:shadow-xl',
						className
					)}
				>
					{title && (
						<DialogTitle className="wcpos:text-lg wcpos:font-semibold wcpos:text-gray-900 wcpos:mb-2">
							{title}
						</DialogTitle>
					)}
					{description && (
						<Description className="wcpos:text-sm wcpos:text-gray-500 wcpos:mb-4">
							{description}
						</Description>
					)}
					{children}
				</DialogPanel>
			</div>
		</Dialog>
	);
}
