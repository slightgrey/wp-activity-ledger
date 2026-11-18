/**
 * Reads a donation amount typed by the user: a number with up to two decimals, at least
 * `min` (US dollars). Commas are accepted as the decimal mark.
 *
 * @param {string|number} value Typed amount.
 * @param {number}        min   Smallest amount.
 * @returns {number|null} Amount rounded to cents, or null when invalid or too small.
 */
export function parseAmount(value, min = 1) {
  const text = String(value ?? '').trim().replace(',', '.');

  if (!/^\d+(\.\d{1,2})?$/.test(text)) return null;

  const amount = Math.round(Number(text) * 100) / 100;

  return amount >= min ? amount : null;
}

/**
 * Builds a PayPal.Me link with the amount in US dollars ("https://paypal.me/name/5USD").
 *
 * @param {string}      name   PayPal.Me name.
 * @param {number|null} amount Amount (left out when null).
 * @returns {string} URL.
 */
export function paypalUrl(name, amount = null) {
  const base = `https://paypal.me/${encodeURIComponent(name)}`;

  if (amount === null) return base;

  return `${base}/${Number.isInteger(amount) ? amount : amount.toFixed(2)}USD`;
}
