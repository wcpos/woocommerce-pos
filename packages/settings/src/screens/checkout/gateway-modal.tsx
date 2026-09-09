import * as React from 'react';

import apiFetch from '@wordpress/api-fetch';

import Notice from '../../components/notice';
import { Button, Checkbox, Modal } from '../../components/ui';
import { t, Trans } from '../../translations';

interface Reader {
	id: string;
	label?: string;
	status?: string;
}

interface GatewayModalProps {
	gateway: import('./gateways').GatewayProps;
	mutate: (data: any) => void;
	closeModal: () => void;
}

function GatewayModal({ gateway, mutate, closeModal }: GatewayModalProps) {
	const [title, setTitle] = React.useState(gateway.title);
	const [description, setDescription] = React.useState(gateway.description);
	const inputRef = React.useRef();

	const isServer = gateway.capture_mode === 'server';
	const [readers, setReaders] = React.useState<Reader[]>([]);
	const [loading, setLoading] = React.useState(isServer);
	const [error, setError] = React.useState('');
	const [defaultReader, setDefaultReader] = React.useState(gateway.default_reader);
	const [allowedReaders, setAllowedReaders] = React.useState<string[] | null>(
		gateway.allowed_readers?.length ? gateway.allowed_readers : null
	);
	const [lockToDefault, setLockToDefault] = React.useState(gateway.lock_to_default);
	const fetchReaders = React.useCallback(
		(refresh = false) => {
			setLoading(true);
			setError('');
			return apiFetch<{ readers: Reader[] }>({
				path: `wcpos/v2/settings/payment-gateways/readers?gateway_id=${encodeURIComponent(gateway.id)}&wcpos=1${refresh ? '&refresh=1' : ''}`,
			})
				.then((result) => setReaders(result.readers))
				.catch((err: { message: string }) => setError(err.message))
				.finally(() => setLoading(false));
		},
		[gateway.id]
	);
	React.useEffect(() => {
		if (isServer) void fetchReaders();
	}, [isServer, fetchReaders]);

	const isAvailable = (id: string) =>
		allowedReaders === null || allowedReaders.includes(id) || defaultReader === id;
	const availableCount = readers.filter((reader) => isAvailable(reader.id)).length;
	const setAvailable = (id: string, checked: boolean) => {
		const available = allowedReaders ?? readers.map((reader) => reader.id);
		setAllowedReaders(checked ? [...available, id] : available.filter((reader) => reader !== id));
		if (!checked && defaultReader === id) setDefaultReader('');
	};

	const handleSave = () => {
		mutate({
			gateways: {
				[gateway.id]: {
					title,
					description,
					...(isServer && {
						default_reader: defaultReader,
						allowed_readers:
							readers.length && readers.every(({ id }) => isAvailable(id))
								? []
								: (allowedReaders ?? []),
						lock_to_default: !!defaultReader && lockToDefault,
					}),
				},
			},
		});
		closeModal();
	};

	const handleChange = React.useCallback(
		(event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
			const value = event.target.value;
			const field = event.target.id;

			if (field === 'title') {
				setTitle(value);
			}
			if (field === 'description') {
				setDescription(value);
			}
		},
		[]
	);

	return (
		<Modal open onClose={() => closeModal()} title={gateway.title} className="wcpos:max-w-md">
			<Notice status="info" isDismissible={false}>
				<Trans
					i18nKey="checkout.gateway_settings_pos_only"
					components={{
						link: (
							<a href="admin.php?page=wc-settings&tab=checkout" target="_blank" rel="noreferrer" />
						),
					}}
				/>
			</Notice>
			<div className="wcpos:py-2">
				<label htmlFor="title" className="wcpos:block wcpos:mb-1 wcpos:font-medium wcpos:text-sm">
					{t('common.title')}
				</label>
				<input
					// @ts-ignore
					ref={inputRef}
					id="title"
					name="title"
					type="text"
					value={title}
					onChange={handleChange}
					className="wcpos:w-full wcpos:p-2 wcpos:rounded wcpos:border wcpos:border-gray-300 wcpos:focus:border-wp-admin-theme-color"
				/>
			</div>
			<div className="wcpos:py-2">
				<label
					htmlFor="description"
					className="wcpos:block wcpos:mb-1 wcpos:font-medium wcpos:text-sm"
				>
					{t('common.description')}
				</label>
				<textarea
					id="description"
					name="description"
					value={description}
					onChange={handleChange}
					className="wcpos:w-full wcpos:h-20 wcpos:p-2 wcpos:rounded wcpos:border wcpos:border-gray-300 wcpos:focus:border-wp-admin-theme-color"
				/>
			</div>
			{isServer && (
				<div className="wcpos:py-2">
					<div className="wcpos:flex wcpos:items-center wcpos:justify-between wcpos:mb-1">
						<span className="wcpos:font-medium wcpos:text-sm">{t('checkout.terminals.title')}</span>
						<Button variant="secondary" disabled={loading} onClick={() => void fetchReaders(true)}>
							{t('checkout.terminals.refresh')}
						</Button>
					</div>
					{loading ? (
						<p className="wcpos:text-sm wcpos:text-gray-500">{t('checkout.terminals.loading')}</p>
					) : error ? (
						<p className="wcpos:text-sm wcpos:text-red-600">{error}</p>
					) : !readers.length ? (
						<p className="wcpos:text-sm wcpos:text-gray-500">{t('checkout.terminals.empty')}</p>
					) : (
						<div className="wcpos:max-h-64 wcpos:overflow-y-auto wcpos:divide-y wcpos:divide-gray-200 wcpos:rounded wcpos:border wcpos:border-gray-300">
							{readers.map((reader) => (
								<div key={reader.id} className="wcpos:flex wcpos:items-center wcpos:gap-3 wcpos:px-2 wcpos:py-1.5">
									<Checkbox
										aria-label={t('checkout.terminals.available')}
										title={t('checkout.terminals.available')}
										checked={isAvailable(reader.id)}
										// A stored empty list means "every terminal", so "none" is not a
										// state this screen can express: the last available reader stays.
										disabled={isAvailable(reader.id) && availableCount === 1}
										onChange={(event) => setAvailable(reader.id, event.target.checked)}
									/>
									<span className="wcpos:flex-1 wcpos:text-sm">
										{reader.label || reader.id}
										{reader.status && (
											<span className="wcpos:ml-2 wcpos:text-xs wcpos:text-gray-500">{reader.status}</span>
										)}
									</span>
									<label className="wcpos:flex wcpos:items-center wcpos:gap-1 wcpos:text-sm">
										<input
											type="radio"
											name="default_reader"
											checked={defaultReader === reader.id}
											onChange={() => {
												setAvailable(reader.id, true);
												setDefaultReader(reader.id);
											}}
										/>
										{t('checkout.terminals.default')}
									</label>
								</div>
							))}
						</div>
					)}
					{readers.length > 0 && !loading && !error && (
						<label className="wcpos:flex wcpos:items-center wcpos:gap-1 wcpos:mt-2 wcpos:text-sm">
							<input
								type="radio"
								name="default_reader"
								checked={!defaultReader}
								onChange={() => setDefaultReader('')}
							/>
							{t('checkout.terminals.no_default')}
						</label>
					)}
					<div className="wcpos:mt-2">
						<Checkbox
							label={t('checkout.terminals.lock')}
							disabled={!defaultReader}
							checked={!!defaultReader && lockToDefault}
							onChange={(event) => setLockToDefault(event.target.checked)}
						/>
					</div>
				</div>
			)}
			<div className="wcpos:text-right wcpos:pt-4 wcpos:flex wcpos:justify-end wcpos:gap-2">
				<Button onClick={closeModal}>{t('common.cancel')}</Button>
				<Button variant="primary" onClick={handleSave}>
					{t('common.save')}
				</Button>
			</div>
		</Modal>
	);
}

export default GatewayModal;
