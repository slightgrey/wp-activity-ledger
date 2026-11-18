import { clsx } from 'clsx';
import { twMerge } from 'tailwind-merge';

/**
 * Joins class names, letting later Tailwind classes override earlier ones.
 *
 * @param {...any} inputs Class names, arrays or condition maps.
 * @returns {string} Class string.
 */
export function cn(...inputs) {
  return twMerge(clsx(inputs));
}
