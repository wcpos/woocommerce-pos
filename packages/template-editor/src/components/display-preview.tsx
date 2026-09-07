import { useState, useRef, useCallback, useEffect } from 'react';

import { t } from '../translations';
import { getPreviewBodyClassName } from './live-preview';

export const DISPLAY_PREVIEW_MESSAGE_TYPE = 'display.preview.template';

const DISPLAY_STATES = [
	'idle',
	'cart.empty',
	'cart',
	'payment.started',
	'payment.approved',
	'payment.declined',
	'payment.complete',
];

interface DisplayPreviewProps {
	content: string;
	templateId: number;
	previewUrl: string;
	isProActive: boolean;
}

export function buildDisplayPreviewUrl(
	previewUrl: string,
	templateId: number,
	state: string
): string {
	const url = new URL(previewUrl);
	url.searchParams.set('preview', state);
	url.searchParams.set('template', String(templateId));
	return url.toString();
}

export function DisplayPreview({
	content,
	templateId,
	previewUrl,
	isProActive,
}: DisplayPreviewProps) {
	const [state, setState] = useState('cart');
	const [viewport, setViewport] = useState('screen');
	const iframeRef = useRef<HTMLIFrameElement>(null);
	const postContent = useCallback(() => {
		iframeRef.current?.contentWindow?.postMessage(
			{ wcpos: 1, type: DISPLAY_PREVIEW_MESSAGE_TYPE, content },
			new URL(previewUrl).origin
		);
	}, [content, previewUrl]);

	useEffect(() => {
		if (!isProActive) return;
		const timeout = setTimeout(postContent, 300);
		return () => clearTimeout(timeout);
	}, [postContent, isProActive]);

	return (
		<div className="wcpos:border wcpos:border-gray-200 wcpos:bg-white wcpos:flex wcpos:flex-col wcpos:rounded-lg wcpos:overflow-hidden">
			<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:px-3 wcpos:py-2 wcpos:border-b wcpos:border-gray-200 wcpos:bg-gray-50">
				<span className="wcpos:text-xs wcpos:font-semibold wcpos:text-gray-500 wcpos:uppercase">
					{t('editor.preview')}
				</span>
				{isProActive && (
					<div className="wcpos:flex wcpos:items-center wcpos:gap-3">
						<select
							value={state}
							onChange={(event) => setState(event.target.value)}
							aria-label={t('editor.preview')}
						>
							{DISPLAY_STATES.map((value) => (
								<option key={value} value={value}>
									{t(`editor.state_${value.replace('.', '_')}`)}
								</option>
							))}
						</select>
						<div
							className="wcpos:flex wcpos:bg-slate-100 wcpos:rounded wcpos:border wcpos:border-slate-200 wcpos:overflow-hidden"
							role="radiogroup"
							aria-label={t('editor.template_preview')}
						>
							{['phone', 'screen'].map((value) => (
								<button
									key={value}
									type="button"
									role="radio"
									aria-checked={viewport === value}
									className={`wcpos:px-2.5 wcpos:py-1 wcpos:text-xs wcpos:font-medium wcpos:transition-colors ${viewport === value ? 'wcpos:text-white' : 'wcpos:text-slate-500 wcpos:cursor-pointer'}`}
									style={
										viewport === value
											? { backgroundColor: 'var(--wp-admin-theme-color, #007cba)' }
											: undefined
									}
									onClick={() => setViewport(value)}
								>
									{t(`editor.viewport_${value}`)}
								</button>
							))}
						</div>
					</div>
				)}
			</div>
			<div className={getPreviewBodyClassName()}>
				{isProActive ? (
					<iframe
						ref={iframeRef}
						src={buildDisplayPreviewUrl(previewUrl, templateId, state)}
						title={t('editor.template_preview')}
						onLoad={postContent}
						style={{
							display: 'block',
							border: 0,
							background: '#fff',
							alignSelf: 'center',
							width: viewport === 'phone' ? 390 : '100%',
							height: viewport === 'phone' ? 844 : undefined,
							aspectRatio: viewport === 'screen' ? '16 / 9' : undefined,
						}}
					/>
				) : (
					<p className="wcpos:p-3 wcpos:text-sm wcpos:text-gray-700">
						{t('editor.display_needs_pro')}{' '}
						<a
							href="https://docs.wcpos.com/customer-display"
							target="_blank"
							rel="noopener noreferrer"
							className="wcpos:text-wp-admin-theme-color hover:wcpos:underline"
						>
							{t('editor.learn_more')}
						</a>
					</p>
				)}
			</div>
		</div>
	);
}
