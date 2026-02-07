import * as React from 'react';

import { render, screen } from '@testing-library/react';

import { Modal } from '../modal';

describe('Modal', () => {
	it('renders content when open', () => {
		render(
			<Modal open={true} onClose={() => {}} title="Test Modal">
				<p>Modal content</p>
			</Modal>
		);
		expect(screen.getByText('Test Modal')).toBeInTheDocument();
		expect(screen.getByText('Modal content')).toBeInTheDocument();
	});

	it('does not render when closed', () => {
		render(
			<Modal open={false} onClose={() => {}} title="Test Modal">
				<p>Modal content</p>
			</Modal>
		);
		expect(screen.queryByText('Test Modal')).not.toBeInTheDocument();
	});

	it('renders description when provided', () => {
		render(
			<Modal open={true} onClose={() => {}} title="Title" description="Some description">
				<p>Body</p>
			</Modal>
		);
		expect(screen.getByText('Some description')).toBeInTheDocument();
	});

	it('renders without a title', () => {
		render(
			<Modal open={true} onClose={() => {}}>
				<p>Just content</p>
			</Modal>
		);
		expect(screen.getByText('Just content')).toBeInTheDocument();
	});
});
