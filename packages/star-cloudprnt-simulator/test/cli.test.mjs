import assert from 'node:assert/strict';
import test from 'node:test';

import { parseArgs } from '../src/cli.mjs';

test('parseArgs reads CloudPRNT URL from flags and applies defaults', () => {
  const config = parseArgs(['--url', 'https://eu-device.stario.online/cloudprnt/shop'], {});

  assert.equal(config.cloudprntUrl, 'https://eu-device.stario.online/cloudprnt/shop');
  assert.equal(config.mac, '02:11:62:1d:e8:30');
  assert.equal(config.pollSeconds, 5);
});

test('parseArgs allows env defaults and flag overrides', () => {
  const config = parseArgs([
    '--mac', '00:11:62:1d:e8:30',
    '--serial', '2602319010600001',
    '--poll-seconds', '2',
    '--once',
  ], {
    CLOUDPRNT_URL: 'https://device.stario.online/cloudprnt/shop',
    PRINTER_MAC: 'env-mac',
    PRINTER_SERIAL: 'env-serial',
    POLL_SECONDS: '9',
  });

  assert.deepEqual(config, {
    cloudprntUrl: 'https://device.stario.online/cloudprnt/shop',
    mac: '00:11:62:1d:e8:30',
    serialNumber: '2602319010600001',
    pollSeconds: 2,
    once: true,
  });
});

test('parseArgs rejects missing CloudPRNT URL', () => {
  assert.throws(() => parseArgs([], {}), /CloudPRNT URL is required/);
});


test('parseArgs rejects invalid poll intervals before polling starts', () => {
  assert.throws(() => parseArgs(['--url', 'https://device.stario.online/cloudprnt/shop', '--poll-seconds', 'abc'], {}), /positive number/);
  assert.throws(() => parseArgs(['--url', 'https://device.stario.online/cloudprnt/shop', '--poll-seconds', '0'], {}), /positive number/);
  assert.throws(() => parseArgs(['--url', 'https://device.stario.online/cloudprnt/shop', '--poll-seconds', '-1'], {}), /positive number/);
});


test('parseArgs ignores a leading separator forwarded by package scripts', () => {
  const config = parseArgs(['--', '--url', 'https://device.stario.online/cloudprnt/shop', '--once'], {});

  assert.equal(config.cloudprntUrl, 'https://device.stario.online/cloudprnt/shop');
  assert.equal(config.once, true);
});
