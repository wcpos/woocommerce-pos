import {
  buildPollBody,
  buildRequestHeaders,
  createDeviceProfile,
  handleClientActions,
} from './device-profile.mjs';

const DEFAULT_ACCEPT_HEADER = 'application/vnd.star.starprnt, application/vnd.star.starconfiguration, application/vnd.star.starprntcore; q=0.9, image/png; q=0.1, text/plain; q=0.7';

/**
 * Small Star CloudPRNT HTTP client simulator.
 */
export class CloudPrntSimulator {
  /**
   * @param {object} options
   * @param {string} options.cloudprntUrl
   * @param {typeof fetch} [options.fetchImpl]
   * @param {object} [options.profile]
   * @param {number} [options.pollSeconds]
   */
  constructor(options) {
    if (!options?.cloudprntUrl) {
      throw new Error('cloudprntUrl is required');
    }

    this.cloudprntUrl = options.cloudprntUrl;
    this.fetchImpl = options.fetchImpl ?? globalThis.fetch;
    this.profile = options.profile ?? createDeviceProfile();
    this.pollSeconds = options.pollSeconds ?? 5;
    this.pendingClientAction = null;
  }

  /**
   * Execute one CloudPRNT POST poll and any immediately required job requests.
   *
   * @returns {Promise<object>}
   */
  async pollOnce() {
    const pollResponse = await this.fetchImpl(this.cloudprntUrl, {
      method: 'POST',
      headers: buildRequestHeaders(this.profile, { 'Content-Type': 'application/json' }),
      body: JSON.stringify(buildPollBody(this.profile, this.consumeClientAction())),
    });

    const pollData = await readJson(pollResponse);

    if (Array.isArray(pollData.clientAction) && pollData.clientAction.length > 0) {
      const actions = handleClientActions(this.profile, pollData.clientAction, { pollSeconds: this.pollSeconds });
      this.pendingClientAction = actions;
      return { type: 'clientAction', actions };
    }

    if (!pollData.jobReady) {
      return { type: 'idle' };
    }

    return this.fetchAndConfirmJob(pollData);
  }

  consumeClientAction() {
    const value = this.pendingClientAction;
    this.pendingClientAction = null;
    return value;
  }

  async fetchAndConfirmJob(pollData) {
    const token = pollData.jobToken ? String(pollData.jobToken) : '';
    const mediaTypes = Array.isArray(pollData.mediaTypes)
      ? pollData.mediaTypes
      : pollData.mediaType
        ? [pollData.mediaType]
        : ['text/plain'];
    const contentType = String(mediaTypes[0] ?? 'text/plain');

    const jobUrl = this.buildJobUrl(pollData.jobGetUrl || this.cloudprntUrl, contentType, token);
    const jobResponse = await this.fetchImpl(jobUrl.toString(), {
      method: 'GET',
      headers: buildRequestHeaders(this.profile, {
        Accept: DEFAULT_ACCEPT_HEADER,
        'X-Star-Paper-Width': '80',
        'X-Star-Print-Width': '72',
        'X-Star-Horizontal-Resolution': '8',
        'X-Star-Vertical-Resolution': '8',
        'X-Star-Accept-Codepages': 'utf8,std',
        ...(token ? { 'X-Star-Token': token } : {}),
      }),
    });

    const bodyBytes = Buffer.from(await jobResponse.arrayBuffer());
    const confirmationMethod = pollData.deleteMethod === 'GET' ? 'GET' : 'DELETE';
    const confirmationUrl = this.buildConfirmationUrl(
      pollData.jobConfirmationUrl || this.cloudprntUrl,
      jobResponse.ok ? '200 OK' : '520 Data Error',
      token,
      confirmationMethod
    );

    await this.fetchImpl(confirmationUrl.toString(), {
      method: confirmationMethod,
      headers: buildRequestHeaders(this.profile, token ? { 'X-Star-Token': token } : {}),
    });

    return {
      type: 'job',
      token,
      contentType: jobResponse.headers.get('content-type') || contentType,
      bodyBytes,
      bodyText: bodyBytes.toString('utf8'),
    };
  }

  buildJobUrl(baseUrl, contentType, token) {
    const url = new URL(baseUrl);
    if (this.profile.uniqueID) {
      url.searchParams.set('uid', this.profile.uniqueID);
    }
    url.searchParams.set('mac', this.profile.mac);
    url.searchParams.set('type', contentType);
    if (token) {
      url.searchParams.set('token', token);
    }
    return url;
  }

  buildConfirmationUrl(baseUrl, code, token, method = 'DELETE') {
    const url = new URL(baseUrl);
    if (this.profile.uniqueID) {
      url.searchParams.set('uid', this.profile.uniqueID);
    }
    url.searchParams.set('mac', this.profile.mac);
    url.searchParams.set('code', code);
    if (token) {
      url.searchParams.set('token', token);
    }
    if (method === 'GET') {
      url.searchParams.set('delete', '');
    }
    return url;
  }
}

async function readJson(response) {
  const text = await response.text();
  if (!text) {
    return {};
  }

  return JSON.parse(text);
}
