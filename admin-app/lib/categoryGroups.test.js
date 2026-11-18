import { describe, expect, it } from 'vitest';
import { bucketKeys, bucketLabel, chartRows, groupOf } from './categoryGroups.js';

describe('category groups', () => {
  it('maps categories to fixed groups', () => {
    expect(groupOf('auth')).toBe('logins');
    expect(groupOf('media')).toBe('content');
    expect(groupOf('woocommerce')).toBe('store');
    expect(groupOf('unknown')).toBe('settings');
  });

  it('lists hour and day buckets in the site timezone', () => {
    expect(bucketKeys('2026-01-01 22:00:00', '2026-01-02 01:30:00', 'hour', 'UTC')).toEqual([
      '2026-01-01 22:00',
      '2026-01-01 23:00',
      '2026-01-02 00:00',
      '2026-01-02 01:00',
    ]);
    expect(bucketKeys('2026-01-01 16:00:00', '2026-01-03 15:59:59', 'day', 'Asia/Manila')).toEqual(['2026-01-02', '2026-01-03']);
  });

  it('fills every bucket and sums groups', () => {
    const rows = chartRows(
      [
        { day: '2026-01-02', category: 'auth', count: 2 },
        { day: '2026-01-02', category: 'plugin', count: 1 },
        { day: '2026-01-02', category: 'theme', count: 3 },
      ],
      ['2026-01-01', '2026-01-02']
    );

    expect(rows[0]).toMatchObject({ bucket: '2026-01-01', total: 0, logins: 0 });
    expect(rows[1]).toMatchObject({ total: 6, logins: 2, software: 4 });
  });

  it('labels buckets', () => {
    expect(bucketLabel('2026-11-18 14:00')).toBe('14:00');
    expect(bucketLabel('2026-11-18')).toBe('Nov 18');
  });
});
