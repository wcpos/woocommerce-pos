import { fetchStarDevices } from './fetch-star-devices';

it('POSTs cloudprnt_url + api_key and returns the device list', async () => {
	const fetch = vi.fn().mockResolvedValue({
		devices: [{ id: 'abc', name: 'Star mC-Print2', state: 'online' }],
	});
	const out = await fetchStarDevices('https://eu-device.stario.online/cloudprnt/kilbot', 'KEY', fetch as never);
	expect(fetch).toHaveBeenCalledWith({
		path: 'wcpos/v1/star-online/devices?wcpos=1',
		method: 'POST',
		data: { cloudprnt_url: 'https://eu-device.stario.online/cloudprnt/kilbot', api_key: 'KEY' },
	});
	expect(out[0].id).toBe('abc');
});
