# Webula.Beacon

Read-only status endpoint for October CMS websites managed by [Webula](https://webula.cz). It answers only
signed requests from the Webula Lighthouse hub and never executes anything. It is of little use outside Webula.

Supports October CMS 1 to 4 (PHP 7.0+).

## Installation

### October CMS 3 / 4 (Composer)

Add the repository and the package to the project `composer.json`. Put the repository **before** the October
gateway entry:

```json
"require": {
    "webula/beacon-plugin": "^2.0"
},
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/webula-cz/oc-beacon-plugin"
    }
]
```

Then run `composer update webula/beacon-plugin` and `php artisan october:migrate`, or use
Settings → Updates → Check for updates → Force update in the backend.

### October CMS 1

Copy the repository contents to `plugins/webula/beacon`.

## Configuration

The shared secret is never stored in the database:

- October CMS 3 / 4: `BEACON_SECRET=...` in `.env`
- October CMS 1: `config/webula/beacon/config.php` returning `['secret' => '...']`

Without a valid secret (at least 32 characters) the endpoint always answers 404.

## Updating

Composer installs follow the version constraint, so a regular update (backend or `composer update`) picks up
new tagged releases.
