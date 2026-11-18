/**
 * Returns the element Radix popups render into: #wpal-portal inside #wpal-root, so the
 * scoped Tailwind styles (prefixed with #wpal-root) also apply to dialogs and menus.
 *
 * @returns {HTMLElement|undefined} Container, or undefined (document body) when missing.
 */
export function portalContainer() {
  return document.getElementById('wpal-portal') ?? undefined;
}
