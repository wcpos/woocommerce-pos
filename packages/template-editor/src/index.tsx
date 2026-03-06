import { createRoot } from 'react-dom/client';

import './index.css';

function App() {
	const config = window.wcposTemplateEditor;
	if (!config) {
		return <div>Template editor configuration not found.</div>;
	}

	return (
		<div className="wcpos:flex wcpos:gap-4 wcpos:mt-4" style={{ minHeight: 500 }}>
			<div className="wcpos:flex-1 wcpos:border wcpos:border-gray-300 wcpos:bg-white wcpos:p-4">
				Code editor placeholder (engine: {config.engine})
			</div>
		</div>
	);
}

const el = document.getElementById('wcpos-template-editor');
if (el) {
	createRoot(el).render(<App />);
}
