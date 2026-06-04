import assert from 'node:assert/strict';
import test from 'node:test';

import {
  buildPollBody,
  buildRequestHeaders,
  createDeviceProfile,
  handleClientActions,
} from '../src/device-profile.mjs';

test('createDeviceProfile uses mC-Print3-compatible defaults', () => {
  const profile = createDeviceProfile();

  assert.equal(profile.mac, '02:11:62:1d:e8:30');
  assert.equal(profile.serialNumber, 'SIM0000001');
  assert.equal(profile.clientType, 'Star mC-Print3');
  assert.equal(profile.clientVersion, '5.1');
  assert.equal(profile.userAgent, 'CloudPRNT/3.0 mC-Print3/5.1');
});

test('buildRequestHeaders includes CloudPRNT identity headers and caller extras', () => {
  const profile = createDeviceProfile({ mac: '00:11:62:1d:e8:30', serialNumber: '2602319010600001' });

  const headers = buildRequestHeaders(profile, { Accept: 'text/plain' });

  assert.deepEqual(headers, {
    'User-Agent': 'CloudPRNT/3.0 mC-Print3/5.1',
    'X-Star-Mac': '00:11:62:1d:e8:30',
    'X-Star-Serial-Number': '2602319010600001',
    'X-Star-Support-Protocols': 'HTTP',
    Accept: 'text/plain',
  });
});

test('buildPollBody reports an online printer status and pending client action results', () => {
  const profile = createDeviceProfile({ mac: '00:11:62:1d:e8:30' });
  profile.uniqueID = 'Kitchen';

  const body = buildPollBody(profile, [{ request: 'GetPollInterval', result: '5' }]);

  assert.deepEqual(body, {
    status: '23 6 0 0 0 0 0 0 0 ',
    printerMAC: '00:11:62:1d:e8:30',
    uniqueID: 'Kitchen',
    statusCode: '200%20OK',
    clientAction: [{ request: 'GetPollInterval', result: '5' }],
  });
});

test('handleClientActions returns plausible Star responses and persists SetID', () => {
  const profile = createDeviceProfile();

  const results = handleClientActions(profile, [
    { request: 'SetID', options: 'Bar' },
    { request: 'ClientType', options: '' },
    { request: 'ClientVersion', options: '' },
    { request: 'GetPollInterval', options: '' },
    { request: 'Encodings', options: '' },
    { request: 'PageInfo', options: '' },
    { request: 'UnknownThing', options: '' },
  ], { pollSeconds: 7 });

  assert.equal(profile.uniqueID, 'Bar');
  assert.deepEqual(results, [
    { request: 'SetID', result: 'Bar' },
    { request: 'ClientType', result: 'Star mC-Print3' },
    { request: 'ClientVersion', result: '5.1' },
    { request: 'GetPollInterval', result: '7' },
    {
      request: 'Encodings',
      result: 'application/vnd.star.starprnt; application/vnd.star.starconfiguration; application/vnd.star.starprntcore; image/png; text/plain',
    },
    {
      request: 'PageInfo',
      result: JSON.stringify({
        paperWidth: '80',
        printWidth: '72',
        horizontalResolution: '8',
        verticalResolution: '8',
      }),
    },
    { request: 'UnknownThing', result: 'n/a' },
  ]);
});
