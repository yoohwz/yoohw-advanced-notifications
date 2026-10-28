=== YoOhw Advanced Notifications ===
Contributors: yoohw
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.2.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Tags: woocommerce, email, notifications, marketing

Compose, preview, schedule and track WooCommerce customer email campaigns.

== Description ==

Open Marketing > Email campaigns to create a campaign, view Campaigns & Logs, or configure sender settings.

* Recipients: selected product purchasers, WooCommerce customers with matching orders (processing/completed by default, including guests), registered users with an optional role filter, or a pasted email list.
* Role selection matches any selected role. Leaving it empty includes all registered users.
* Manual email lists reject invalid addresses and deduplicate emails without regard to letter case. Names fall back to email addresses; recipient-local scheduling uses UTC, while store-time scheduling uses the store timezone.
* Exclusions: order statuses, product purchasers, manually entered email addresses, and previous unsubscribes.
* WooCommerce HTML email styling, rich-text editor and live preview.
* Test email, preview and confirmation before queuing a campaign.
* Immediate delivery, one store-timezone instant, or the same local time per recipient using an explicitly labelled location estimate with UTC fallback. New schedules reject past and DST-ambiguous/nonexistent times.
* Batched dispatch, shared campaign content, duplicate protection, recipient status and campaign logs.
* Recover interrupted preparation and missing jobs; bounded retries for queue failures and known pre-transport failures.
* Configurable From, Reply-To and automated-reply suppression headers.
* Pause, Resume and Cancel individual campaigns; site-wide minute/day admission limits across campaigns.
* Durable drafts, campaign duplication, three editable templates and personalized desktop/mobile previews.
* Source-aware order/date/category/country/subscription/handoff filters, saved audiences and address inspection.
* Durable opt-outs and signed unsubscribe links checked again before delivery.
* Subscription evidence and previewed suppression imports, WordPress personal data export/erasure and configurable terminal retention.
* Handed-to-mail-system, failed, skipped and unknown outcomes are shown separately; inbox delivery is not verified.

WooCommerce Subscriptions integration and Audience sync are not included.
Default sender details come from WooCommerce email settings. Configure an appropriate mail transport for the site.

This plugin uses its own yoohw_an_* options, hooks and recipient table. It does not import campaign history, sender settings, unsubscribes or queued jobs from YoOhw Extra Code. Review existing opt-outs before starting campaigns on a migrated site.

Deactivation cancels pending delivery/dispatch jobs, cancels retryable queue/schedule failures and removes the reconciliation cron event. Campaign data and opt-outs are retained. Reactivation does not automatically requeue cancelled jobs. See docs/reliability-review.md for upgrade and recovery behavior, and docs/operator-features.md for controls, audience semantics, scheduling and privacy decisions.

== Installation ==

1. Install and activate WooCommerce.
2. Install this folder as wp-content/plugins/yoohw-advanced-notifications and activate the plugin.
3. Open Marketing > Email campaigns > Settings to review sender details.
4. Compose a campaign, send a test email, then preview and confirm the recipient list.

== Translation ==

The interface, validation messages, status labels and built-in unsubscribe text use the yoohw-advanced-notifications text domain. WordPress selects the locale for each request (the user's admin language in the dashboard, and the site language for background jobs).

1. Open languages/yoohw-advanced-notifications.pot in Poedit or a compatible translation editor. Loco Translate can also use this template.
2. Create a translation for the WordPress locale, for example fr_FR (French) or vi (Vietnamese). Preserve placeholders such as %s, %1$d, {customer_name} and {product_name}.
3. Save and compile matching PO/MO files, for example yoohw-advanced-notifications-fr_FR.po and yoohw-advanced-notifications-fr_FR.mo.
4. Place them in wp-content/languages/plugins/ to keep them outside the plugin's update directory. Bundled translations in this plugin's languages/ directory are also supported.
5. Select that language in WordPress Settings > General, or the administrator's profile language for the dashboard.

The POT is a translation template; language translations are supplied separately. Campaign subjects, headings and messages written by the administrator, product names and previously stored log messages are not automatically translated. Existing WordPress and WooCommerce language packs provide their own strings.

Regenerate the template after changing source strings:

    wp i18n make-pot wp-content/plugins/yoohw-advanced-notifications wp-content/plugins/yoohw-advanced-notifications/languages/yoohw-advanced-notifications.pot --domain=yoohw-advanced-notifications --exclude=tests

== Development ==

The campaign implementation is adapted from YoOhw Extra Code 1.1.43 on the local yoohw site. Only the Marketing > Email notify feature is included; other site-specific extensions are excluded.

Run the integration checks in a disposable WordPress + WooCommerce environment:

    wp eval-file wp-content/plugins/yoohw-advanced-notifications/tests/integration.php

The checks intercept outgoing mail and scheduler submissions, use synthetic fixtures, and remove their test data.

To check translation loading in a local English-language installation:

    wp --skip-plugins --skip-themes eval-file wp-content/plugins/yoohw-advanced-notifications/tests/internationalization.php

This creates and removes a temporary French MO file. It does not change the site or account language and stops if a French language pack already exists.

Additional reliability checks use separate PHP processes, real database/Action Scheduler jobs, controlled transport and process termination:

    python3 tests/reliability.py --wp-command /path/to/disposable-wp-wrapper --output /tmp/an-evidence-unique

The wrapper selects a disposable WordPress installation with WooCommerce and this checkout active. The database name must contain reliability. This suite resets the plugin fixtures and queue hooks in that database. Never run it against store data. Evidence contains the tested commit, worker logs, recipient states, opt-outs and handoff counts.

See docs/reliability-review.md for the implementation, compatibility decisions and limits.

== Changelog ==

= Unreleased =
* Add individual campaign controls and shared sending admission limits.
* Add durable drafts, duplication, content templates and personalized previews.
* Add source-aware saved audiences and explicit timezone scheduling.
* Add subscription/suppression administration, privacy callbacks and terminal retention.

= 0.2.2 =
* Align the translation domain with the WordPress.org slug yoohw-advanced-notifications.
* Resolve Plugin Check findings for SQL identifiers, input handling, test fixtures and plugin metadata.
* Load admin JavaScript and CSS from separate files with WordPress dependencies.

= 0.2.1 =
* Register the languages directory on init and include a POT translation template.
* Make remaining delivery messages and status labels translatable.
* Add translator notes and translation setup instructions.

= 0.2.0 =
* Add WooCommerce customers and Manual email list recipient sources.
* Add multi-role filtering for registered users, including preview and campaign history.
* Preserve recipient selections when restoring drafts and retrying failed schedules.
* Use native JavaScript trim() for the live preview.

= 0.1.0 =
* Extract standalone customer email campaigns from YoOhw Extra Code.
* Remove WooCommerce Subscriptions audiences and exclusions, and Audience sync integration.
* Isolate plugin storage/hooks and use the current store's sender defaults.
