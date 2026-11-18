import { config } from '@/lib/config';

let nonce = config.nonce;

/**
 * An error returned by the REST API.
 */
export class ApiError extends Error {
  /**
   * @param {string} message Message.
   * @param {number} status  HTTP status.
   * @param {string} code    WP error code.
   * @param {object} data    Error data (e.g. `errors` by field).
   */
  constructor(message, status, code = '', data = {}) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.code = code;
    this.data = data;
  }
}

/**
 * Builds a query string; arrays become `key[]=a&key[]=b`, empty values are left out.
 *
 * @param {object} query Parameters.
 * @returns {string} Query string (without "?").
 */
export function toQuery(query = {}) {
  const params = new URLSearchParams();

  for (const [key, value] of Object.entries(query)) {
    if (value === undefined || value === null || value === '') continue;

    if (Array.isArray(value)) {
      value.forEach((item) => params.append(`${key}[]`, String(item)));
    } else {
      params.append(key, String(value));
    }
  }

  return params.toString();
}

/**
 * Builds a REST URL for a route below the plugin namespace, for pretty and plain permalinks.
 *
 * @param {string} restUrl Namespace URL, e.g. ".../wp-json/wpal/v1/" or ".../?rest_route=/wpal/v1/".
 * @param {string} path    Route, e.g. "/events".
 * @param {object} query   Query parameters; arrays become `key[]=a&key[]=b`.
 * @returns {string} URL.
 */
export function buildUrl(restUrl, path, query = {}) {
  const route = path.replace(/^\/+/, '');
  const qs = toQuery(query);

  if (restUrl.includes('rest_route=')) {
    return `${restUrl}${route}${qs ? `&${qs}` : ''}`;
  }

  return `${restUrl.replace(/\/?$/, '/')}${route}${qs ? `?${qs}` : ''}`;
}

/**
 * Fetches a fresh REST nonce (after the old one expired in a long-open tab).
 *
 * @returns {Promise<string>} Nonce.
 */
async function refreshNonce() {
  const response = await fetch(config.nonceUrl, { credentials: 'same-origin' });
  const text = (await response.text()).trim();

  if (!response.ok || !/^[a-f0-9]+$/i.test(text)) {
    throw new ApiError('Your session has expired. Please reload the page.', 401, 'rest_cookie_invalid_nonce');
  }

  nonce = text;

  return nonce;
}

/**
 * Calls the plugin's REST API.
 *
 * @param {string} path Route, e.g. "/events".
 * @param {object} options { method, query, body }.
 * @returns {Promise<{data: any, total: number|null, totalPages: number|null}>} Result.
 */
export async function api(path, { method = 'GET', query = {}, body, retried = false } = {}) {
  const headers = { 'X-WP-Nonce': nonce, Accept: 'application/json' };

  if (body !== undefined) headers['Content-Type'] = 'application/json';

  const response = await fetch(buildUrl(config.restUrl, path, query), {
    method,
    headers,
    credentials: 'same-origin',
    body: body === undefined ? undefined : JSON.stringify(body),
  });

  const data = await response.json().catch(() => null);

  if (!response.ok) {
    if (data?.code === 'rest_cookie_invalid_nonce' && !retried) {
      await refreshNonce();

      return api(path, { method, query, body, retried: true });
    }

    throw new ApiError(data?.message || `Request failed (${response.status})`, response.status, data?.code, data?.data);
  }

  const total = response.headers.get('X-WP-Total');
  const totalPages = response.headers.get('X-WP-TotalPages');

  return {
    data,
    total: total === null ? null : Number(total),
    totalPages: totalPages === null ? null : Number(totalPages),
  };
}
