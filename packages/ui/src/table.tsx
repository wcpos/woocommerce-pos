import * as React from 'react';

import classNames from 'classnames';

export interface TableProps extends React.HTMLAttributes<HTMLDivElement> {
	/** Optional className for the inner <table> element. */
	tableClassName?: string;
}

export type TableHeaderProps = React.HTMLAttributes<HTMLTableSectionElement>;
export type TableBodyProps = React.HTMLAttributes<HTMLTableSectionElement>;
export type TableRowProps = React.HTMLAttributes<HTMLTableRowElement>;
export type TableHeadProps = React.ThHTMLAttributes<HTMLTableCellElement>;
export type TableCellProps = React.TdHTMLAttributes<HTMLTableCellElement>;

/**
 * Bordered table container with horizontal-scroll fallback. Renders an
 * `<table>` element; pair with `TableHeader` / `TableBody` etc. for the
 * standard chrome (gray-50 header, divider rows).
 */
function Table({ className, tableClassName, children, ...props }: TableProps) {
	return (
		<div
			className={classNames(
				'wcpos:overflow-x-auto wcpos:rounded-md wcpos:border wcpos:border-gray-200',
				className
			)}
			{...props}
		>
			<table className={classNames('wcpos:w-full wcpos:text-sm', tableClassName)}>
				{children}
			</table>
		</div>
	);
}

function TableHeader({ className, children, ...props }: TableHeaderProps) {
	return (
		<thead className={className} {...props}>
			{children}
		</thead>
	);
}

function TableBody({ className, children, ...props }: TableBodyProps) {
	return (
		<tbody className={className} {...props}>
			{children}
		</tbody>
	);
}

/**
 * Header row. Applies the canonical gray-50 background and uppercase
 * meta-text styling so consumers don't need to remember the tokens.
 */
const TableHeaderRow = React.forwardRef<HTMLTableRowElement, TableRowProps>(
	function TableHeaderRow({ className, children, ...props }, ref) {
		return (
			<tr
				ref={ref}
				className={classNames(
					'wcpos:bg-gray-50 wcpos:text-left wcpos:text-xs wcpos:uppercase wcpos:tracking-wide wcpos:text-gray-500',
					className
				)}
				{...props}
			>
				{children}
			</tr>
		);
	}
);

const TableRow = React.forwardRef<HTMLTableRowElement, TableRowProps>(
	function TableRow({ className, children, ...props }, ref) {
		return (
			<tr
				ref={ref}
				className={classNames('wcpos:border-t wcpos:border-gray-100', className)}
				{...props}
			>
				{children}
			</tr>
		);
	}
);

const TableHead = React.forwardRef<HTMLTableCellElement, TableHeadProps>(
	function TableHead({ className, children, ...props }, ref) {
		return (
			<th
				ref={ref}
				className={classNames('wcpos:px-3 wcpos:py-2 wcpos:font-medium', className)}
				{...props}
			>
				{children}
			</th>
		);
	}
);

const TableCell = React.forwardRef<HTMLTableCellElement, TableCellProps>(
	function TableCell({ className, children, ...props }, ref) {
		return (
			<td
				ref={ref}
				className={classNames('wcpos:px-3 wcpos:py-2 wcpos:align-middle', className)}
				{...props}
			>
				{children}
			</td>
		);
	}
);

Table.Header = TableHeader;
Table.HeaderRow = TableHeaderRow;
Table.Body = TableBody;
Table.Row = TableRow;
Table.Head = TableHead;
Table.Cell = TableCell;

export {
	Table,
	TableHeader,
	TableHeaderRow,
	TableBody,
	TableRow,
	TableHead,
	TableCell,
};
