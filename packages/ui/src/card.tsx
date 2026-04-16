import * as React from 'react';

import classNames from 'classnames';

export interface CardProps extends React.HTMLAttributes<HTMLDivElement> {
	/**
	 * Highlight the card with a themed ring/border (used for "active" states
	 * like the currently-active template in the gallery).
	 */
	active?: boolean;
}

export interface CardBodyProps extends React.HTMLAttributes<HTMLDivElement> {
	/**
	 * Remove the default padding (useful when the body content needs to bleed
	 * to the card edges, e.g. a thumbnail image).
	 */
	noPadding?: boolean;
}

export type CardFooterProps = React.HTMLAttributes<HTMLDivElement>;

function Card({ active = false, className, children, ...props }: CardProps) {
	return (
		<div
			className={classNames(
				'wcpos:bg-white wcpos:border wcpos:rounded-lg wcpos:overflow-hidden wcpos:flex wcpos:flex-col',
				active
					? 'wcpos:border-wp-admin-theme-color wcpos:ring-1 wcpos:ring-wp-admin-theme-color'
					: 'wcpos:border-gray-200',
				className
			)}
			{...props}
		>
			{children}
		</div>
	);
}

function CardBody({ noPadding = false, className, children, ...props }: CardBodyProps) {
	return (
		<div
			className={classNames(
				'wcpos:flex-1',
				!noPadding && 'wcpos:p-4',
				className
			)}
			{...props}
		>
			{children}
		</div>
	);
}

function CardFooter({ className, children, ...props }: CardFooterProps) {
	return (
		<div
			className={classNames(
				'wcpos:border-t wcpos:border-gray-200 wcpos:bg-gray-50 wcpos:px-4 wcpos:py-2.5',
				className
			)}
			{...props}
		>
			{children}
		</div>
	);
}

Card.Body = CardBody;
Card.Footer = CardFooter;

export { Card, CardBody, CardFooter };
