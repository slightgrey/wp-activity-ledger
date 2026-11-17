# WP Activity Ledger

Records every meaningful action on a WordPress site (who did it, what changed, when and from where) with field-level before/after diffs. Administrators get a React + Tailwind admin to review, filter, export and get alerts on activity.

> Status: in development (0.1.0). Full documentation lands before the first release.

## Requirements

- PHP 8.1+
- WordPress 6.5+ (single site or multisite)

## Development

```bash
composer install          # PHP dev tools + autoloader
npm install               # admin app dependencies
npm run build             # build the React admin into dist/admin

composer lint             # PHPCS (WordPress coding standards)
composer analyse          # PHPStan level 6
composer test             # unit tests (no database)
composer test:integration # integration tests against the local WordPress (plugin must be active)
npm run lint              # ESLint for the admin app
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
