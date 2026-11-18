/**
 * The config PHP passes as `window.wpalConfig` (see src/Admin/Assets.php), with safe defaults
 * for tests and a missing config.
 */
const defaults = {
  restUrl: '/wp-json/wpal/v1/',
  nonce: '',
  nonceUrl: '/wp-admin/admin-ajax.php?action=rest-nonce',
  adminUrl: '/wp-admin/',
  pageUrl: '/wp-admin/admin.php?page=wp-activity-ledger',
  timezone: 'UTC',
  dateFormat: 'F j, Y',
  timeFormat: 'g:i a',
  version: '',
  userId: 0,
  caps: { view: false, export: false, manage_settings: false, delete: false, manage_sessions: false },
};

export const config = {
  ...defaults,
  ...(typeof window !== 'undefined' && window.wpalConfig ? window.wpalConfig : {}),
};
