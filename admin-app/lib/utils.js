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

/**
 * Classes for one item of a segmented tab group (main nav, range tabs).
 *
 * @param {boolean} active Whether the item is selected.
 * @param {string}  size   'md' for the main nav, 'sm' for compact groups.
 * @returns {string} Class string.
 */
export function segmentClass(active, size = 'md') {
  return cn(
    'inline-flex items-center gap-1.5 rounded-md font-medium transition-colors',
    size === 'sm' ? 'px-3 py-1 text-sm' : 'px-3 py-1.5 text-sm',
    active ? 'bg-primary text-primary-foreground shadow-sm hover:text-primary-foreground' : 'text-muted-foreground hover:bg-muted hover:text-foreground'
  );
}

/** Container classes for a segmented tab group. */
export const segmentGroupClass = 'flex flex-wrap gap-1 rounded-lg border bg-background p-1 shadow-sm';
