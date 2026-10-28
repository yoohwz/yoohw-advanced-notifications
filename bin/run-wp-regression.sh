#!/usr/bin/env bash
# CI only: all fixtures run on a fresh site and a dedicated disposable database.
set -euo pipefail

: "${RUNNER_TEMP:?Run on a disposable CI runner}"
: "${WP_VERSION:?Select an explicit WordPress version or nightly}"
plugin_source="$(pwd)"
test_root="$(mktemp -d "$RUNNER_TEMP/advanced-notifications-wp.XXXXXX")"
site_root="$test_root/public"
evidence_root="$RUNNER_TEMP/advanced-notifications-evidence"
mkdir -p "$site_root" "$evidence_root"

# Current WooCommerce needs a newer WordPress than our minimum support boundary.
case "$WP_VERSION" in
  6.6) woocommerce_version=9.3.3 ;;
  *) woocommerce_version=11.2.1 ;;
esac

wp core download --path="$site_root" --version="$WP_VERSION" --locale=en_US
wp config create --path="$site_root" --dbname=advanced_notifications_reliability --dbuser=root --dbpass=root --dbhost=127.0.0.1 --skip-check
wp db create --path="$site_root"
wp config set DISABLE_WP_CRON true --raw --path="$site_root"
wp core install --path="$site_root" --url=http://advanced-notifications.test --title='Advanced Notifications CI' --admin_user=ci --admin_password=ci-test-password --admin_email=ci@example.org --skip-email

# Block real delivery throughout site setup and fixture execution. Individual
# tests override this filter to inspect success, failure, and unknown outcomes.
mkdir -p "$site_root/wp-content/mu-plugins"
cat > "$site_root/wp-content/mu-plugins/ci-mail.php" <<'PHP'
<?php
add_filter( 'pre_wp_mail', '__return_true', -9999 );
PHP
wp plugin install woocommerce --version="$woocommerce_version" --activate --path="$site_root"
ln -s "$plugin_source" "$site_root/wp-content/plugins/yoohw-advanced-notifications"
wp plugin activate yoohw-advanced-notifications --path="$site_root"

{
  wp core version --path="$site_root"
  wp plugin list --path="$site_root" --format=json
  php --version
} > "$evidence_root/environment.txt"

wp eval-file "$plugin_source/tests/integration.php" --path="$site_root" | tee "$evidence_root/integration.log"
wp --skip-plugins --skip-themes eval-file "$plugin_source/tests/internationalization.php" --path="$site_root" | tee "$evidence_root/internationalization.log"

# reliability.py launches independent WP-CLI processes through this wrapper.
printf '#!/usr/bin/env bash\nexec wp --path=%q "$@"\n' "$site_root" > "$test_root/wp"
chmod +x "$test_root/wp"
python3 tests/reliability.py --wp-command "$test_root/wp" --output "$evidence_root/reliability" | tee "$evidence_root/reliability.log"
