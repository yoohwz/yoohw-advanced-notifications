# GitHub Actions

Adapted from `yoohwz/yoohw-media-optimizer` workflows on `main`.

- Draft pull requests run one representative WordPress/PHP pair and skip Plugin Check.
- Ready pull requests, pushes to `main`, and manual runs use the full support matrix and Plugin Check.
- The matrix reads the WordPress boundaries from `readme.txt`: minimum WordPress with PHP 8.1/8.2, and tested WordPress with PHP 8.1/8.4. PHP 8.1 is the declared minimum; update the plan if that requirement changes.
- PHP/JavaScript syntax, metadata consistency, diff whitespace, and the final gate run in both modes. Missing, skipped, cancelled, or failed required jobs fail the gate.
- Each regression job creates a fresh WordPress site and `advanced_notifications_reliability` database in an isolated MySQL service. It runs the existing integration, internationalization, and full reliability suites. Cron is disabled and outgoing mail is intercepted by an MU plugin; individual tests supply their own transport results.
- WordPress 6.6 uses WooCommerce 9.3.3; newer WordPress and nightly use WooCommerce 11.2.1. These pins preserve the minimum WordPress boundary while covering the current installed WooCommerce version.
- Logs, environment versions, and reliability evidence are uploaded for seven days, including on failure. Development files are excluded from the Plugin Check payload through `.distignore`.
- A separate weekly/manual canary tests WordPress nightly with PHP 8.4. Changes to the canary or regression runner also trigger it. It does not change published support metadata.

Local checks without a WordPress database:

```sh
python3 -B tests/ci-contract-tests.py
bash -n bin/run-wp-regression.sh bin/validate-ci-gate.sh
node --check assets/admin.js
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l
```

`bin/run-wp-regression.sh` provisions a disposable CI site; do not point it at a store database. Merge, release, deployment, rollback, and repository branch-protection changes remain with the maintainer.
