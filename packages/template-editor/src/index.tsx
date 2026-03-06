import { createRoot } from 'react-dom/client';

import { App } from './app';
import type { EditorConfig } from './types';

import './index.css';

const el = document.getElementById('wcpos-template-editor');
if (el) {
	const config: EditorConfig = (window as any).wcposTemplateEditor;
	if (config) {
		createRoot(el).render(<App config={config} />);
	} else {
		el.textContent = 'Template editor configuration not found.';
	}
}
