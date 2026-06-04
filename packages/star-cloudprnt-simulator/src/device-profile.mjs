const DEFAULT_ACCEPTED_ENCODINGS = 'application/vnd.star.starprnt; application/vnd.star.starconfiguration; application/vnd.star.starprntcore; image/png; text/plain';

/**
 * Create a fake Star CloudPRNT printer profile.
 *
 * @param {object} [options]
 * @param {string} [options.mac]
 * @param {string} [options.serialNumber]
 * @param {string} [options.clientType]
 * @param {string} [options.clientVersion]
 * @param {string} [options.cloudprntVersion]
 * @returns {object}
 */
export function createDeviceProfile(options = {}) {
  const profile = {
    mac: options.mac ?? '02:11:62:1d:e8:30',
    serialNumber: options.serialNumber ?? 'SIM0000001',
    clientType: options.clientType ?? 'Star mC-Print3',
    clientVersion: options.clientVersion ?? '5.1',
    cloudprntVersion: options.cloudprntVersion ?? '3.0',
    uniqueID: options.uniqueID,
  };

  profile.userAgent = options.userAgent ?? `CloudPRNT/${profile.cloudprntVersion} mC-Print3/${profile.clientVersion}`;

  return profile;
}

/**
 * Build request headers common to Star CloudPRNT HTTP calls.
 *
 * @param {object} profile
 * @param {Record<string, string>} [extra]
 * @returns {Record<string, string>}
 */
export function buildRequestHeaders(profile, extra = {}) {
  const headers = {
    'User-Agent': profile.userAgent,
    'X-Star-Mac': profile.mac,
    'X-Star-Serial-Number': profile.serialNumber,
    'X-Star-Support-Protocols': 'HTTP',
  };

  if (profile.uniqueID) {
    headers['X-Star-Id'] = profile.uniqueID;
  }

  return { ...headers, ...extra };
}

/**
 * Build the JSON body for a CloudPRNT POST poll.
 *
 * @param {object} profile
 * @param {Array<object>|null} [clientAction]
 * @returns {object}
 */
export function buildPollBody(profile, clientAction = null) {
  const body = {
    status: '23 6 0 0 0 0 0 0 0 ',
    printerMAC: profile.mac,
    statusCode: '200%20OK',
    clientAction,
  };

  if (profile.uniqueID) {
    body.uniqueID = profile.uniqueID;
  }

  return body;
}

/**
 * Convert server-requested CloudPRNT client actions into simulator responses.
 *
 * @param {object} profile
 * @param {Array<{request: string, options?: string}>} actions
 * @param {object} [options]
 * @param {number} [options.pollSeconds]
 * @returns {Array<{request: string, result: string}>}
 */
export function handleClientActions(profile, actions, options = {}) {
  const pollSeconds = options.pollSeconds ?? 5;

  return actions.map((action) => {
    const request = action.request;
    const actionOptions = action.options ?? '';

    switch (request) {
      case 'SetID':
        profile.uniqueID = actionOptions;
        return { request, result: actionOptions };
      case 'ClientType':
        return { request, result: profile.clientType };
      case 'ClientVersion':
        return { request, result: profile.clientVersion };
      case 'GetPollInterval':
        return { request, result: String(pollSeconds) };
      case 'Encodings':
        return { request, result: DEFAULT_ACCEPTED_ENCODINGS };
      case 'PageInfo':
        return {
          request,
          result: JSON.stringify({
            paperWidth: '80',
            printWidth: '72',
            horizontalResolution: '8',
            verticalResolution: '8',
          }),
        };
      default:
        return { request, result: 'n/a' };
    }
  });
}
