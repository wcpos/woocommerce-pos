import { createRoot } from 'react-dom/client';

import { App } from './app';
import './index.css';

const el = document.getElementById('wcpos-template-editor');
if (el) {
	const config = window.wcposTemplateEditor;
	if (config) {
		createRoot(el).render(<App config={config} />);
	} else {
		el.textContent = 'Template editor configuration not found.';
	}
}
