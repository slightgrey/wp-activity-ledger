import { describe, expect, it } from 'vitest';
import { categoryLabel, formatDateTime, relativeTime, severity, siteDate, typeLabel } from './format.js';
import { buildUrl } from './api.js';

describe('formatDateTime', () => {
  it('converts UTC to an IANA timezone, including DST', () => {
    expect(formatDateTime('2026-07-01 12:00:00', 'Europe/London')).toBe('2026-07-01 13:00');
    expect(formatDateTime('2026-01-01 12:00:00', 'Europe/London')).toBe('2026-01-01 12:00');
    expect(formatDateTime('2026-01-01 23:30:00', 'Asia/Manila')).toBe('2026-01-02 07:30');
  });

  it('converts UTC to a fixed offset', () => {
    expect(formatDateTime('2026-01-01 23:30:00', '+02:00')).toBe('2026-01-02 01:30');
    expect(formatDateTime('2026-01-01 01:00:00', '-05:30')).toBe('2025-12-31 19:30');
  });

  it('returns an empty string for invalid input', () => {
    expect(formatDateTime('nope', 'UTC')).toBe('');
  });
});

describe('siteDate', () => {
  it('returns the local day, shifted by days', () => {
    const now = new Date('2026-01-01T23:30:00Z');
    expect(siteDate('Asia/Manila', 0, now)).toBe('2026-01-02');
    expect(siteDate('UTC', -6, now)).toBe('2025-12-26');
  });
});

describe('relativeTime', () => {
  it('describes past times', () => {
    expect(relativeTime('2026-01-01 12:00:00', new Date('2026-01-01T12:05:00Z'))).toBe('5 minutes ago');
  });
});

describe('labels', () => {
  const meta = {
    categories: [{ slug: 'auth', label: 'Logins' }],
    event_types: [{ key: 'auth.login', label: 'Logged in' }],
  };

  it('finds labels and falls back to the key', () => {
    expect(categoryLabel('auth', meta)).toBe('Logins');
    expect(categoryLabel('x', meta)).toBe('x');
    expect(typeLabel('auth.login', meta)).toBe('Logged in');
    expect(severity(3).slug).toBe('critical');
    expect(severity(9).slug).toBe('info');
  });
});

describe('buildUrl', () => {
  it('builds pretty-permalink URLs with array params', () => {
    expect(buildUrl('http://x.test/wp-json/wpal/v1/', '/events', { type: ['a.b', 'c.d'], page: 2, search: '' })).toBe(
      'http://x.test/wp-json/wpal/v1/events?type%5B%5D=a.b&type%5B%5D=c.d&page=2'
    );
  });

  it('builds plain-permalink URLs', () => {
    expect(buildUrl('http://x.test/?rest_route=/wpal/v1/', '/meta', { a: 1 })).toBe('http://x.test/?rest_route=/wpal/v1/meta&a=1');
  });
});
