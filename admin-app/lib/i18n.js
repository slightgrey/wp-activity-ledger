import { config } from '@/lib/config';

/**
 * Translates a string of the admin app.
 *
 * Named and called like WordPress's __() so `wp i18n make-pot` extracts the strings. The
 * translations come from PHP (`wpalConfig.i18n`, read from the JSON files `wp i18n make-json`
 * writes into languages/); without them the English text is returned.
 *
 * @param {string} text   Text.
 * @param {string} domain Text domain (always "wp-activity-ledger").
 * @returns {string} Translated text.
 */
export function __(text, domain = 'wp-activity-ledger') {
  if (domain !== 'wp-activity-ledger') return text;

  const translated = config.i18n?.[text];

  return typeof translated === 'string' && translated !== '' ? translated : text;
}

/**
 * Replaces %s / %d / %1$s placeholders, like PHP's sprintf (for translated templates).
 *
 * @param {string} template Template.
 * @param {...any} values   Values.
 * @returns {string} Text.
 */
export function sprintf(template, ...values) {
  let next = 0;

  return template.replace(/%(?:(\d+)\$)?[sd]/g, (_, position) => String(values[position ? Number(position) - 1 : next++] ?? ''));
}
