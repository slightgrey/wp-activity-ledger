import { siteParts } from '@/lib/format';

/**
 * The activity chart's series: event categories folded into six fixed groups, each with a
 * fixed color (categorical slots 1–6 of the validated default palette, in order). A
 * group's color never depends on its rank or on which groups have data.
 */
export const GROUPS = [
  { key: 'logins', label: 'Logins', color: '#2a78d6', categories: ['auth'] },
  { key: 'users', label: 'Users', color: '#eb6834', categories: ['user'] },
  { key: 'content', label: 'Content', color: '#1baf7a', categories: ['content', 'media', 'comment', 'taxonomy', 'menu', 'widget'] },
  { key: 'software', label: 'Plugins & themes', color: '#eda100', categories: ['plugin', 'theme', 'core'] },
  { key: 'settings', label: 'Settings', color: '#e87ba4', categories: ['settings', 'system', 'multisite'] },
  { key: 'store', label: 'Store & builder', color: '#008300', categories: ['woocommerce', 'elementor'] },
];

const BY_CATEGORY = Object.fromEntries(GROUPS.flatMap((g) => g.categories.map((c) => [c, g.key])));

/**
 * Returns the group key of a category (unknown categories count as settings).
 *
 * @param {string} category Category slug.
 * @returns {string} Group key.
 */
export function groupOf(category) {
  return BY_CATEGORY[category] ?? 'settings';
}

/**
 * Lists every bucket key between two UTC datetimes, in the site's timezone.
 *
 * @param {string} from     UTC start ("Y-m-d H:i:s").
 * @param {string} to       UTC end.
 * @param {string} bucket   "hour" or "day".
 * @param {string} timeZone Site timezone.
 * @returns {string[]} Keys ("Y-m-d H:00" or "Y-m-d"), oldest first.
 */
export function bucketKeys(from, to, bucket, timeZone) {
  const start = new Date(`${from.replace(' ', 'T')}Z`).getTime();
  const end = new Date(`${to.replace(' ', 'T')}Z`).getTime();
  const keys = [];

  for (let time = start; time <= end + 1; time += 3600000) {
    const p = siteParts(new Date(time), timeZone);
    const key = bucket === 'hour' ? `${p.year}-${p.month}-${p.day} ${p.hour}:00` : `${p.year}-${p.month}-${p.day}`;

    if (keys[keys.length - 1] !== key) keys.push(key);
  }

  return keys;
}

/**
 * Turns /stats `by_day` rows into one row per bucket with a count per group (zeros filled).
 *
 * @param {Array<{day: string, category: string, count: number}>} rows Stats rows.
 * @param {string[]} keys Bucket keys.
 * @returns {Array<object>} Rows: { bucket, total, logins, users, … }.
 */
export function chartRows(rows, keys) {
  const empty = Object.fromEntries(GROUPS.map((g) => [g.key, 0]));
  const byBucket = new Map(keys.map((key) => [key, { bucket: key, total: 0, ...empty }]));

  for (const row of rows) {
    const target = byBucket.get(row.day);

    if (!target) continue;

    target[groupOf(row.category)] += row.count;
    target.total += row.count;
  }

  return [...byBucket.values()];
}

/**
 * Formats a bucket key for the axis: "14:00" for hours, "Nov 18" for days.
 *
 * @param {string} key Bucket key.
 * @returns {string} Label.
 */
export function bucketLabel(key) {
  if (key.length > 10) return key.slice(11);

  const [year, month, day] = key.split('-').map(Number);

  return new Intl.DateTimeFormat('en-US', { month: 'short', day: 'numeric', timeZone: 'UTC' }).format(new Date(Date.UTC(year, month - 1, day)));
}
