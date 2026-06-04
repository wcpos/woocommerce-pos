import assert from 'node:assert/strict';
import test from 'node:test';

import { CloudPrntSimulator } from '../src/cloudprnt-client.mjs';
import { createDeviceProfile } from '../src/device-profile.mjs';

function jsonResponse(body, init = {}) {
  return new Response(JSON.stringify(body), {
    status: init.status ?? 200,
    headers: { 'Content-Type': 'application/json', ...(init.headers ?? {}) },
  });
}

test('pollOnce posts printer status and returns idle when no job is ready', async () => {
  const calls = [];
  const fetchImpl = async (url, init) => {
    calls.push({ url: String(url), init });
    return jsonResponse({ jobReady: false });
  };
  const simulator = new CloudPrntSimulator({
    cloudprntUrl: 'https://eu-device.stario.online/cloudprnt/shop',
    fetchImpl,
    profile: createDeviceProfile({ mac: '00:11:62:1d:e8:30' }),
  });

  const result = await simulator.pollOnce();

  assert.deepEqual(result, { type: 'idle' });
  assert.equal(calls.length, 1);
  assert.equal(calls[0].url, 'https://eu-device.stario.online/cloudprnt/shop');
  assert.equal(calls[0].init.method, 'POST');
  assert.equal(calls[0].init.headers['Content-Type'], 'application/json');
  assert.deepEqual(JSON.parse(calls[0].init.body), {
    status: '23 6 0 0 0 0 0 0 0 ',
    printerMAC: '00:11:62:1d:e8:30',
    statusCode: '200%20OK',
    clientAction: null,
  });
});

test('pollOnce fetches and confirms a ready print job', async () => {
  const calls = [];
  const fetchImpl = async (url, init) => {
    calls.push({ url: String(url), init });
    if (init.method === 'POST') {
      return jsonResponse({
        jobReady: true,
        mediaTypes: ['text/vnd.star.markup'],
        jobToken: 'job-123',
      });
    }
    if (init.method === 'GET') {
      return new Response('[bold]Hello[cut]', {
        status: 200,
        headers: { 'Content-Type': 'text/vnd.star.markup' },
      });
    }
    return new Response('', { status: 200 });
  };
  const simulator = new CloudPrntSimulator({
    cloudprntUrl: 'https://eu-device.stario.online/cloudprnt/shop',
    fetchImpl,
    profile: createDeviceProfile({ mac: '00:11:62:1d:e8:30' }),
  });

  const result = await simulator.pollOnce();

  assert.equal(result.type, 'job');
  assert.equal(result.token, 'job-123');
  assert.equal(result.contentType, 'text/vnd.star.markup');
  assert.equal(result.bodyText, '[bold]Hello[cut]');
  assert.equal(calls.length, 3);
  assert.match(calls[1].url, /^https:\/\/eu-device\.stario\.online\/cloudprnt\/shop\?/);
  assert.equal(new URL(calls[1].url).searchParams.get('type'), 'text/vnd.star.markup');
  assert.equal(new URL(calls[1].url).searchParams.get('token'), 'job-123');
  assert.equal(calls[1].init.headers['X-Star-Token'], 'job-123');
  assert.equal(calls[2].init.method, 'DELETE');
  assert.equal(new URL(calls[2].url).searchParams.get('code'), '200 OK');
  assert.equal(new URL(calls[2].url).searchParams.get('token'), 'job-123');
});

test('pollOnce queues client action results for the next poll', async () => {
  const postedBodies = [];
  let count = 0;
  const fetchImpl = async (_url, init) => {
    postedBodies.push(JSON.parse(init.body));
    count += 1;
    if (count === 1) {
      return jsonResponse({
        jobReady: false,
        clientAction: [{ request: 'SetID', options: 'Front' }],
      });
    }
    return jsonResponse({ jobReady: false });
  };
  const simulator = new CloudPrntSimulator({
    cloudprntUrl: 'https://device.stario.online/cloudprnt/shop',
    fetchImpl,
    profile: createDeviceProfile(),
  });

  assert.deepEqual(await simulator.pollOnce(), { type: 'clientAction', actions: [{ request: 'SetID', result: 'Front' }] });
  assert.deepEqual(await simulator.pollOnce(), { type: 'idle' });
  assert.equal(postedBodies[0].clientAction, null);
  assert.deepEqual(postedBodies[1].clientAction, [{ request: 'SetID', result: 'Front' }]);
  assert.equal(postedBodies[1].uniqueID, 'Front');
});


test('pollOnce honors GET confirmation requested by the server', async () => {
  const calls = [];
  const fetchImpl = async (url, init) => {
    calls.push({ url: String(url), init });
    if (init.method === 'POST') {
      return jsonResponse({
        jobReady: true,
        mediaTypes: ['text/plain'],
        jobToken: 'job-get-confirm',
        deleteMethod: 'GET',
      });
    }
    if (init.method === 'GET' && !new URL(String(url)).searchParams.has('delete')) {
      return new Response('Hello', { status: 200, headers: { 'Content-Type': 'text/plain' } });
    }
    return new Response('', { status: 200 });
  };
  const simulator = new CloudPrntSimulator({
    cloudprntUrl: 'https://device.stario.online/cloudprnt/shop',
    fetchImpl,
    profile: createDeviceProfile(),
  });

  await simulator.pollOnce();

  assert.equal(calls.length, 3);
  assert.equal(calls[2].init.method, 'GET');
  const confirmationUrl = new URL(calls[2].url);
  assert.equal(confirmationUrl.searchParams.get('code'), '200 OK');
  assert.equal(confirmationUrl.searchParams.get('token'), 'job-get-confirm');
  assert.equal(confirmationUrl.searchParams.has('delete'), true);
});
