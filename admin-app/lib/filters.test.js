import { describe, expect, it } from 'vitest';
import { EMPTY, hasFilters, parseFilters, serializeFilters, toApiQuery } from './filters.js';

describe('filters', () => {
  it('round-trips through URL params', () => {
    const state = { ...EMPTY, type: ['post.updated', 'auth.login'], user: ['3'], search: 'hello', page: 2, per_page: 50 };
    const params = serializeFilters(state);

    expect(params.toString()).toBe('search=hello&user=3&type=post.updated%2Cauth.login&page=2&per_page=50');
    expect(parseFilters(params)).toEqual(state);
  });

  it('ignores invalid paging values', () => {
    const state = parseFilters(new URLSearchParams('page=-4&per_page=7'));
    expect(state.page).toBe(1);
    expect(state.per_page).toBe(0);
  });

  it('knows when filters are set', () => {
    expect(hasFilters(EMPTY)).toBe(false);
    expect(hasFilters({ ...EMPTY, order: 'asc', page: 3 })).toBe(false);
    expect(hasFilters({ ...EMPTY, category: ['auth'] })).toBe(true);
  });

  it('builds the API query with UTC dates', () => {
    const now = new Date('2026-01-10T12:00:00Z');

    expect(toApiQuery({ ...EMPTY, range: '24h' }, { now })).toEqual({ page: 1, per_page: 25, from: '2026-01-09 12:00:00' });
    expect(
      toApiQuery({ ...EMPTY, range: 'custom', from: '2026-01-02', to: '2026-01-03', type: ['a.b'], per_page: 100 }, { timeZone: 'Asia/Manila' })
    ).toEqual({ page: 1, per_page: 100, type: ['a.b'], from: '2026-01-01 16:00:00', to: '2026-01-03 15:59:59' });
  });
});
