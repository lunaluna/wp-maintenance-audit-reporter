# WP Maintenance Audit Reporter

WordPress plugin: scheduled maintenance reports for core, themes, and plugins (development; v0.1 in progress).

See [readme.txt](readme.txt) for WordPress-style metadata.

## Development

WordPress/runtime target: **PHP 7.4+**.

Composer dev tooling (PHPCS / PHPUnit dependency tree): **PHP 8.0+** on CI and local `composer install`. The plugin bootstrap avoids PHP‑only syntax beyond 7.4 so sites may stay on PHP 7.4 until you raise the declared minimum later.

WordPress **6.0+**

Initialize Git in this folder when you are ready; use small **manual commits** locally. No remote setup is required yet.

### Composer

The **`vendor/` directory is not committed** to this repository (see `.gitignore`). Third-party libraries are listed in `composer.json` and locked in `composer.lock`; anyone who clones must run `composer install` once.

```bash
cd wp-content/plugins/wp-maintenance-audit-reporter
composer install
```

**GitHub Actions** (`.github/workflows/ci.yml`) assumes the same workflow: it runs **`composer install`** before PHPCS and PHPUnit.

### Coding standards

```bash
composer run phpcs
```

### Tests

```bash
composer run phpunit
```

## License

GPLv2 or later. See [LICENSE](LICENSE).
