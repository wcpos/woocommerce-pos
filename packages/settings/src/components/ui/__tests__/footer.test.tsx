import * as React from 'react';

import { render, screen } from '@testing-library/react';

import { Footer } from '@wcpos/ui';

describe('Footer', () => {
	it('renders the four links with their labels and new-tab hints', () => {
		const { container } = render(<Footer />);

		expect(screen.getByText('Need help?')).toBeTruthy();
		const links = container.querySelectorAll('a');
		expect(Array.from(links).map((a) => a.getAttribute('href'))).toEqual([
			'https://docs.wcpos.com',
			'https://faq.wcpos.com',
			'mailto:support@wcpos.com',
			'https://wcpos.com/discord',
		]);
		expect(links[0].textContent).toBe('Documentation (opens in new tab)');
		expect(links[1].textContent).toBe('FAQ (opens in new tab)');
		expect(links[2].textContent).toBe('support@wcpos.com');
		expect(links[0].querySelector('svg')?.getAttribute('fill')).toBe('#6b7280');
		expect(screen.getByAltText('Discord')).toBeTruthy();
	});

	it('hides the Discord badge when showDiscord is false', () => {
		const { container } = render(<Footer showDiscord={false} />);

		expect(screen.queryByAltText('Discord')).toBeNull();
		expect(container.querySelector('a[href="https://wcpos.com/discord"]')).toBeNull();
	});

	it('uses the label props', () => {
		const { container } = render(
			<Footer
				needHelpLabel="Besoin d'aide ?"
				documentationLabel="Docs"
				faqLabel="Questions"
				opensNewTabLabel="(nouvel onglet)"
				supportEmail="help@example.com"
			/>
		);

		expect(screen.getByText("Besoin d'aide ?")).toBeTruthy();
		const links = container.querySelectorAll('a');
		expect(links[0].textContent).toBe('Docs (nouvel onglet)');
		expect(links[1].textContent).toBe('Questions (nouvel onglet)');
		expect(links[2].getAttribute('href')).toBe('mailto:help@example.com');
		expect(links[2].textContent).toBe('help@example.com');
	});
});
