import { localToUtc, toUtcString } from '@/lib/format';

/**
 * Date range presets: key => hours back from now.
 */
export const RANGES = { '24h': 24, '7d': 24 * 7, '30d': 24 * 30 };

const LISTS = ['user', 'category', 'type'];
const TEXTS = ['range', 'from', 'to', 'role', 'severity', 'object_type', 'object_id', 'ip', 'session', 'search', 'orderby', 'order'];

/**
 * The empty filter state.
 */
export const EMPTY = {
  range: '',
  from: '',
  to: '',
  user: [],
  role: '',
  category: [],
  type: [],
  severity: '',
  object_type: '',
  object_id: '',
  ip: '',
  session: '',
  search: '',
  orderby: '',
  order: '',
  page: 1,
  per_page: 0,
};

/**
 * Reads the filter state from URL search params (lists are comma-separated).
 *
 * @param {URLSearchParams} params Params.
 * @returns {object} State.
 */
export function parseFilters(params) {
  const state = { ...EMPTY };

  for (const key of TEXTS) {
    state[key] = params.get(key) ?? '';
  }

  for (const key of LISTS) {
    const value = params.get(key);
    state[key] = value ? value.split(',').filter(Boolean) : [];
  }

  state.page = Math.max(1, Number(params.get('page')) || 1);
  state.per_page = [25, 50, 100].includes(Number(params.get('per_page'))) ? Number(params.get('per_page')) : 0;

  return state;
}

/**
 * Writes the filter state to URL search params, leaving out empty values and page 1.
 *
 * @param {object} state State.
 * @returns {URLSearchParams} Params.
 */
export function serializeFilters(state) {
  const params = new URLSearchParams();

  for (const key of TEXTS) {
    if (state[key]) params.set(key, state[key]);
  }

  for (const key of LISTS) {
    if (state[key]?.length) params.set(key, state[key].join(','));
  }

  if (state.page > 1) params.set('page', String(state.page));
  if (state.per_page) params.set('per_page', String(state.per_page));

  return params;
}

/**
 * Tells whether any filter (not paging or sorting) is set.
 *
 * @param {object} state State.
 * @returns {boolean} True when filtered.
 */
export function hasFilters(state) {
  return [...TEXTS.filter((k) => !['orderby', 'order'].includes(k)), ...LISTS].some((key) =>
    Array.isArray(state[key]) ? state[key].length > 0 : Boolean(state[key])
  );
}

/**
 * Turns the filter state into GET /events parameters (dates in UTC).
 *
 * @param {object} state           State.
 * @param {object} options         { timeZone, now, perPage }.
 * @returns {object} Query parameters.
 */
export function toApiQuery(state, { timeZone = 'UTC', now = new Date(), perPage = 25 } = {}) {
  const query = {
    page: state.page,
    per_page: state.per_page || perPage,
    user: state.user,
    role: state.role,
    category: state.category,
    type: state.type,
    severity: state.severity,
    object_type: state.object_type,
    object_id: state.object_id,
    ip: state.ip,
    session: state.session,
    search: state.search,
    orderby: state.orderby,
    order: state.order,
  };

  if (RANGES[state.range]) {
    query.from = toUtcString(new Date(now.getTime() - RANGES[state.range] * 3600000));
  } else if (state.range === 'custom') {
    if (state.from) query.from = localToUtc(`${state.from} 00:00:00`, timeZone);
    if (state.to) query.to = localToUtc(`${state.to} 23:59:59`, timeZone);
  }

  for (const key of Object.keys(query)) {
    const value = query[key];

    if (value === '' || value === undefined || (Array.isArray(value) && value.length === 0)) delete query[key];
  }

  return query;
}
