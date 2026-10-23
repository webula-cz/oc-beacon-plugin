# Webula.Beacon

Read-only status endpoint for October CMS websites managed by [Webula](https://webula.cz). It answers only
signed requests from the Webula Lighthouse hub and never executes anything. It is of little use outside Webula.

Supports October CMS 1 to 4 (PHP 7.0+).

## Installation

### October CMS 3 / 4 (Composer)

The package is on [Packagist](https://packagist.org/packages/webula/beacon-plugin), so no extra repository entry
is needed. Add it to the project `composer.json`:

```json
"require": {
    "webula/beacon-plugin": "^2.0"
}
```

Then run `composer update webula/beacon-plugin` (`--no-dev` on production) and `php artisan october:migrate`,
or use Settings → Updates → Check for updates → Force update in the backend.

### October CMS 1

Copy the repository contents to `plugins/webula/beacon`. The migration runs on the next backend login
(with `cms.runMigrationsOnLogin`) or with `php artisan october:up`.

## Configuration

The shared secret is never stored in the database:

- October CMS 3 / 4: `BEACON_SECRET=...` in `.env`
- October CMS 1: `config/webula/beacon/config.php` returning `['secret' => '...']`

Without a valid secret (at least 32 characters) the endpoint always answers 404. On sites behind a CDN or a page
cache, exclude `/beacon/*` from caching.

## Updating

Composer installs follow the version constraint, so a regular update (backend or `composer update`) picks up
new tagged releases. Use `--no-dev` on production.
