import { formatDistanceStrict } from 'date-fns';

/**
 * Severity levels: value, slug and badge variant.
 */
export const SEVERITIES = [
  { value: 0, slug: 'info', variant: 'info' },
  { value: 1, slug: 'notice', variant: 'notice' },
  { value: 2, slug: 'warning', variant: 'warning' },
  { value: 3, slug: 'critical', variant: 'critical' },
];

const OFFSET = /^([+-])(\d{2}):(\d{2})$/;

/**
 * Parses a stored UTC datetime ("Y-m-d H:i:s").
 *
 * @param {string} value Datetime.
 * @returns {Date} Date (invalid when the value is not a datetime).
 */
export function parseUtc(value) {
  return new Date(`${String(value).replace(' ', 'T')}Z`);
}

/**
 * Splits a date into its parts in the site's timezone (IANA name or "+02:00" offset).
 *
 * @param {Date}   date     Date.
 * @param {string} timeZone Site timezone.
 * @returns {{year: string, month: string, day: string, hour: string, minute: string, second: string}} Parts.
 */
export function siteParts(date, timeZone) {
  const offset = OFFSET.exec(timeZone || '');
  let zone = timeZone || 'UTC';
  let shifted = date;

  if (offset) {
    const minutes = (Number(offset[2]) * 60 + Number(offset[3])) * (offset[1] === '-' ? -1 : 1);
    shifted = new Date(date.getTime() + minutes * 60000);
    zone = 'UTC';
  }

  const parts = {};
  const formatter = new Intl.DateTimeFormat('en-US', {
    timeZone: zone,
    hourCycle: 'h23',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    second: '2-digit',
  });

  for (const part of formatter.formatToParts(shifted)) {
    parts[part.type] = part.value;
  }

  return { year: parts.year, month: parts.month, day: parts.day, hour: parts.hour, minute: parts.minute, second: parts.second };
}

/**
 * Formats a stored UTC datetime in the site's timezone as "YYYY-MM-DD HH:mm".
 *
 * @param {string} value    UTC datetime.
 * @param {string} timeZone Site timezone.
 * @returns {string} Formatted datetime, or '' when invalid.
 */
export function formatDateTime(value, timeZone) {
  const date = parseUtc(value);

  if (Number.isNaN(date.getTime())) return '';

  const p = siteParts(date, timeZone);

  return `${p.year}-${p.month}-${p.day} ${p.hour}:${p.minute}`;
}

/**
 * Returns today's date in the site's timezone ("YYYY-MM-DD"), optionally shifted by days.
 *
 * @param {string} timeZone Site timezone.
 * @param {number} days     Days to add (negative for the past).
 * @param {Date}   now      Current time.
 * @returns {string} Date.
 */
export function siteDate(timeZone, days = 0, now = new Date()) {
  const p = siteParts(new Date(now.getTime() + days * 86400000), timeZone);

  return `${p.year}-${p.month}-${p.day}`;
}

/**
 * Converts a site-local datetime ("YYYY-MM-DD HH:mm:ss") to a UTC datetime string.
 *
 * The site's offset at that moment is found by formatting a first guess in the site's
 * timezone and correcting by the difference (twice, to settle around DST changes).
 *
 * @param {string} local    Local datetime.
 * @param {string} timeZone Site timezone.
 * @returns {string} UTC datetime ("YYYY-MM-DD HH:mm:ss"), or '' when invalid.
 */
export function localToUtc(local, timeZone) {
  const target = new Date(`${String(local).replace(' ', 'T')}Z`).getTime();

  if (Number.isNaN(target)) return '';

  let guess = target;

  for (let i = 0; i < 2; i++) {
    const p = siteParts(new Date(guess), timeZone);
    const shown = Date.UTC(+p.year, +p.month - 1, +p.day, +p.hour, +p.minute, +p.second);
    guess += target - shown;
  }

  return new Date(guess).toISOString().slice(0, 19).replace('T', ' ');
}

/**
 * Formats a Date as a UTC datetime string ("YYYY-MM-DD HH:mm:ss").
 *
 * @param {Date} date Date.
 * @returns {string} UTC datetime.
 */
export function toUtcString(date) {
  return date.toISOString().slice(0, 19).replace('T', ' ');
}

/**
 * Describes how long ago a stored UTC datetime was ("5 minutes ago").
 *
 * @param {string} value UTC datetime.
 * @param {Date}   now   Current time.
 * @returns {string} Relative time, or '' when invalid.
 */
export function relativeTime(value, now = new Date()) {
  const date = parseUtc(value);

  if (Number.isNaN(date.getTime())) return '';

  return formatDistanceStrict(date, now, { addSuffix: true });
}

/**
 * Returns a severity's level info.
 *
 * @param {number} value Severity value.
 * @returns {{value: number, slug: string, variant: string}} Severity.
 */
export function severity(value) {
  return SEVERITIES.find((s) => s.value === Number(value)) ?? SEVERITIES[0];
}

/**
 * Returns the label of a category from /meta.
 *
 * @param {string} slug Category slug.
 * @param {object} meta Meta data.
 * @returns {string} Label.
 */
export function categoryLabel(slug, meta) {
  return meta?.categories?.find((c) => c.slug === slug)?.label ?? slug;
}

/**
 * Returns the label of an event type from /meta.
 *
 * @param {string} key  Event type key.
 * @param {object} meta Meta data.
 * @returns {string} Label.
 */
export function typeLabel(key, meta) {
  return meta?.event_types?.find((t) => t.key === key)?.label ?? key;
}
