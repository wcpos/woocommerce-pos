#!/usr/bin/env node

import { CloudPrntSimulator } from './cloudprnt-client.mjs';
import { createDeviceProfile } from './device-profile.mjs';

/**
 * Parse CLI arguments and environment variables.
 *
 * @param {string[]} argv
 * @param {Record<string, string|undefined>} env
 * @returns {object}
 */
export function parseArgs(argv = process.argv.slice(2), env = process.env) {
  const flags = new Map();
  let once = false;

  for (let index = 0; index < argv.length; index += 1) {
    const arg = argv[index];
    if (arg === '--') {
      continue;
    }
    if (arg === '--once') {
      once = true;
      continue;
    }
    if (!arg.startsWith('--')) {
      throw new Error(`Unexpected argument: ${arg}`);
    }
    const value = argv[index + 1];
    if (!value || value.startsWith('--')) {
      throw new Error(`Missing value for ${arg}`);
    }
    flags.set(arg, value);
    index += 1;
  }

  const cloudprntUrl = flags.get('--url') ?? env.CLOUDPRNT_URL;
  if (!cloudprntUrl) {
    throw new Error('CloudPRNT URL is required. Pass --url or set CLOUDPRNT_URL.');
  }

  const pollSeconds = Number(flags.get('--poll-seconds') ?? env.POLL_SECONDS ?? 5);
  if (!Number.isFinite(pollSeconds) || pollSeconds <= 0) {
    throw new Error('Poll interval must be a positive number.');
  }

  return {
    cloudprntUrl,
    mac: flags.get('--mac') ?? env.PRINTER_MAC ?? '02:11:62:1d:e8:30',
    serialNumber: flags.get('--serial') ?? env.PRINTER_SERIAL ?? 'SIM0000001',
    pollSeconds,
    once,
  };
}

/**
 * Run the simulator loop.
 *
 * @param {object} config
 * @param {object} [deps]
 * @returns {Promise<void>}
 */
export async function runLoop(config, deps = {}) {
  const logger = deps.logger ?? console;
  const sleep = deps.sleep ?? ((ms) => new Promise((resolve) => setTimeout(resolve, ms)));
  const simulator = deps.simulator ?? new CloudPrntSimulator({
    cloudprntUrl: config.cloudprntUrl,
    pollSeconds: config.pollSeconds,
    profile: createDeviceProfile({ mac: config.mac, serialNumber: config.serialNumber }),
  });

  logger.log(`Star CloudPRNT simulator polling ${config.cloudprntUrl}`);

  do {
    let shouldSleep = true;

    try {
      const result = await simulator.pollOnce();
      logResult(logger, result);
      shouldSleep = result.type !== 'clientAction';
    } catch (error) {
      logger.error(`poll failed: ${error.message}`);
    }

    if (!config.once && shouldSleep) {
      await sleep(config.pollSeconds * 1000);
    }
  } while (!config.once);
}

function logResult(logger, result) {
  if (result.type === 'idle') {
    logger.log('poll: idle');
    return;
  }

  if (result.type === 'clientAction') {
    logger.log(`clientAction: ${JSON.stringify(result.actions)}`);
    return;
  }

  if (result.type === 'job') {
    logger.log(`job: token=${result.token || '(none)'} contentType=${result.contentType} bytes=${result.bodyBytes.length}`);
    logger.log(result.bodyText);
  }
}

if (import.meta.url === `file://${process.argv[1]}`) {
  try {
    await runLoop(parseArgs());
  } catch (error) {
    console.error(error.message);
    process.exitCode = 1;
  }
}
