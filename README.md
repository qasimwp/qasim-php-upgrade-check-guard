# Qasim PHP Upgrade Check & Guard

Source code and build scripts of **Qasim PHP Upgrade Check & Guard**, a WordPress plugin that checks plugins and themes for code that breaks on a newer PHP version (8.0 to 8.5), optionally shows which of that code really runs on the site, and warns when a plugin starts failing after a PHP change.

- Plugin slug and text domain: `qasim-php-upgrade-check-guard`
- Code prefix: `qpucg` (namespace `Qpucg\`, constants `QPUCG_*`, options and tables `qpucg_*`)
- WordPress.org plugin page (live once the plugin is approved): https://wordpress.org/plugins/qasim-php-upgrade-check-guard/
- The plugin makes no remote requests.

## What is in this repository

| Path | What it is |
|---|---|
| `qasim-php-upgrade-check-guard.php`, `uninstall.php` | Plugin bootstrap and uninstall |
| `src/` | The plugin's own code (namespace `Qpucg\`, PSR-4) |
| `assets/` | Admin CSS and JavaScript (plain files, no build step) |
| `languages/` | Translation template (`.pot`) |
| `readme.txt`, `LICENSE` | WordPress.org readme and license |
| `vendor-prefixed/` | The scan engine as shipped in the plugin: PHP_CodeSniffer and PHPCompatibility, renamed into the `Qpucg\Vendor` namespace. It is build output, committed so the shipped code can be reviewed. `bin/build-engine.php` recreates it |
| `engine/composer.json`, `engine/composer.lock` | The pinned versions of the engine packages |
| `scoper.inc.php` | PHP-Scoper configuration: renames the engine into `Qpucg\Vendor` and removes its shell and `eval()` calls |
| `bin/build-engine.php` | Builds `vendor-prefixed/` from `engine/` |
| `bin/build-zip.php` | Builds the release zip |
| `bin/engine-inputs.php` | Helper shared by the two build scripts |
| `composer.json`, `.phpcs.xml.dist` | Dev tools: WordPress Coding Standards and PHP compatibility linting of the plugin's own code |

Two files are in `src/` but are **not** in the release zip (`bin/build-zip.php` leaves them out): `src/Admin/Report.php` and `src/Cli/DevCommand.php`. They are developer tools (a findings report and a `wp qpucg simulate-traffic` command that makes a loopback HTTP request).

## Requirements for building

- PHP 8.2 or newer on the command line (PHP-Scoper needs it; the plugin itself runs on PHP 7.4 and newer, see `readme.txt`)
- Composer 2
- PHP extensions: `zip`, `tokenizer`, `simplexml`, `xmlwriter`, `libxml`, `mbstring`
- [PHP-Scoper](https://github.com/humbug/php-scoper) as a PHAR at `tools/php-scoper.phar` (version 0.18.20 was used)

## Build steps

```sh
# 1. PHP-Scoper
mkdir tools
curl -L -o tools/php-scoper.phar https://github.com/humbug/php-scoper/releases/download/0.18.20/php-scoper.phar

# 2. The scoped engine: installs engine/ with Composer, scopes it, strips the parts the plugin never loads,
#    checks that no exec-family call or eval() is left and that every patch landed, then writes vendor-prefixed/
php bin/build-engine.php

# 3. The release zip: dist/qasim-php-upgrade-check-guard.zip with the folder qasim-php-upgrade-check-guard/ inside
php bin/build-zip.php

# Optional: lint the plugin's own code
composer install
composer lint
```

`bin/build-zip.php` refuses to build when `vendor-prefixed/` is missing or was built from other inputs. The engine packages and their exact commits are fixed by `engine/composer.lock`, so the same inputs give the same `vendor-prefixed/`.

## Bundled third-party code

| Package | Version | License |
|---|---|---|
| squizlabs/php_codesniffer | 4.0.4 | BSD-3-Clause |
| phpcompatibility/php-compatibility | 10.0.0-alpha2 | LGPL-3.0-or-later |
| phpcompatibility/phpcompatibility-wp | 3.0.0-alpha2 | LGPL-3.0-or-later |
| phpcompatibility/phpcompatibility-paragonie | 2.0.0-alpha2 | LGPL-3.0-or-later |
| phpcsstandards/phpcsutils | 1.2.3 | LGPL-3.0-or-later |

The exact changes made to the bundled code are the patchers in `scoper.inc.php` and the strip lists in `bin/build-engine.php`.

## License

GPL-3.0-or-later. See `LICENSE`.
