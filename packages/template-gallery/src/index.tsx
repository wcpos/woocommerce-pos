import { createRoot } from '@wordpress/element';

function App() {
	return (
		<div>
			<h1>Template Gallery</h1>
			<p>Gallery SPA is loading. The full frontend will be implemented in the next plan.</p>
		</div>
	);
}

const container = document.getElementById( 'wcpos-template-gallery' );
if ( container ) {
	const root = createRoot( container );
	root.render( <App /> );
}
