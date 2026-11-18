import { useEffect, useState } from 'react';

/**
 * Returns a value that follows the input after it stopped changing for `delay` ms.
 *
 * @param {any}    value Value.
 * @param {number} delay Delay in ms.
 * @returns {any} Debounced value.
 */
export function useDebounced(value, delay = 300) {
  const [debounced, setDebounced] = useState(value);

  useEffect(() => {
    const timer = setTimeout(() => setDebounced(value), delay);

    return () => clearTimeout(timer);
  }, [value, delay]);

  return debounced;
}
