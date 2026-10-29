=== Advanced Notifications for WooCommerce ===
Contributors: yoohw
Tags: woocommerce, email, notifications, email marketing, customers
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 0.3.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Create targeted WooCommerce email campaigns with reusable drafts, recipient previews, scheduling, send controls and unsubscribe management.

== Description ==

Advanced Notifications for WooCommerce helps store managers send product updates, post-purchase guidance and service announcements from the WordPress dashboard.

Choose an audience, write your message using the rich-text editor, send a test email, review the recipient list and confirm the campaign. Manage everything from Marketing > Email campaigns.

= Choose and review your audience =

* Select product or variation purchasers, WooCommerce customers with matching orders, registered users or a pasted email list. Order-based sources include guest customers.
* Filter matching orders by product, category (including child categories), creation date, order status and billing country. These order conditions must match the same order.
* Filter registered users by role and profile billing country. Only filters supported by the selected source can be used.
* Select by subscription status or a previous campaign's recorded handoff outcome. Missing or unknown delivery history is not treated as proof that an email was never sent.
* Apply exclusions, remove duplicate email addresses and respect saved unsubscribes across every recipient source.
* Save audience criteria for reuse. Each use requires a fresh recipient preview and confirmation.
* Review selection and exclusion counts and inspect why an email address is included or excluded.

= Write once and reuse =

* Save incomplete drafts and return to them later without creating send jobs.
* Duplicate an existing campaign into a new draft with a separate campaign identity.
* Start with three editable templates for updates, post-purchase guidance and service announcements.
* Use WooCommerce HTML email styling, the rich-text editor and desktop/mobile previews for a sample recipient.
* Send a test email and continue editing the draft before confirming recipients.
* Personalize the message body with {customer_name} and {product_name}. A missing customer name falls back to the email address. The product variable is a campaign-wide label, not a record of each recipient's purchases. Subjects and headings do not expand these variables.

= Schedule and control sending =

* Send after confirmation, at one time in the store timezone, or at the same local time for each recipient using a clearly labelled location estimate.
* Preview the earliest/latest scheduled send times and how many recipients use the UTC fallback.
* Pause, resume or cancel a campaign. Work that already started may finish; email already handed to the mail system cannot be recalled.
* Set shared per-minute and per-day campaign admission limits for the website. Waiting for a limit or a pause does not consume retry attempts.
* Track pending, processing, handed-off, failed, skipped and unknown outcomes, with reasons and overdue queue diagnostics.
* Recover interrupted work using confirmed recipients and bounded retries for eligible known failures. Unknown handoff outcomes are not automatically retried.

= Subscription and privacy tools =

* Keep subscribed, unsubscribed and unknown states separate, with the recorded source and time where available.
* Preview subscription/suppression imports before applying them. Repeated imports do not create duplicates or remove existing opt-outs.
* Include signed unsubscribe links and check suppression again before handing off campaign mail.
* Use WordPress personal data export and erasure tools for registered users and guest email addresses.
* Configure optional retention for finished campaign content and personal logs while keeping the minimum suppression and duplicate-protection records.

= Requirements and delivery =

WooCommerce must be active. This plugin uses WooCommerce email rendering, the site's configured WordPress mail transport and Action Scheduler. A working queue runner is required for scheduled/background work. It does not provide an SMTP service, verify inbox delivery, or track opens and clicks.

Only this plugin's campaign emails are subject to its send controls and admission limits. WooCommerce transactional emails and unrelated scheduled actions keep their existing behavior. Test emails are outside the campaign admission limits.

This plugin does not include recurring marketing automation, WooCommerce Subscriptions audiences, CRM synchronization or additional messaging channels.

== Installation ==

1. Install and activate WooCommerce.
2. Install and activate Advanced Notifications for WooCommerce. For a manual installation, upload the yoohw-advanced-notifications folder to wp-content/plugins/.
3. Open Marketing > Email campaigns > Settings and review the sender, Reply-To, limits and retention settings.
4. Open Compose, choose an audience and write your message. Save a draft or send a test email as needed.
5. Select the schedule, preview the recipients, review the counts and confirm the campaign.
6. Follow progress and use campaign controls in Campaigns & Logs.

== Frequently Asked Questions ==

= Does an order or account mean a customer has subscribed? =

No. An email address in an order, account or pasted list is not opt-in evidence. Without recorded evidence the status remains unknown. You can filter audiences by subscription status. Store managers are responsible for recording the appropriate permission for their messages. An existing unsubscribe always takes priority over a new subscription import.

= What does a sent or handed-off result mean? =

It means the site's mail system accepted the handoff. It does not prove delivery to an inbox. Failed and unknown outcomes are shown separately. Only eligible known failures can be retried; unknown results are not automatically replayed.

= Which timezone is used? =

Store-time scheduling uses the store timezone shown when composing the campaign. Recipient-local scheduling estimates a timezone only when the billing country has one supported timezone; missing or ambiguous location data uses UTC. This is a location estimate, not a confirmed customer preference. Manual lists use UTC.

New schedules reject past times and local times that are missing or repeated because of daylight saving changes. They are not silently moved to another day. Confirmed UTC due times are preserved through pauses, retries and recovery; queue delays and limits may make actual sending later.

= Can I change a confirmed campaign? =

Confirmed content, recipients and scheduled times are saved for that campaign. Editing a draft or saved audience does not change it. Duplicate the campaign into a new draft to prepare another mailing, then preview and confirm again. Cancelled campaigns cannot be resumed.

= Why is a scheduled campaign waiting? =

Check its pause state, admission limit and queue diagnostics in Campaigns & Logs. Shared limits use fixed UTC minute/day windows; a reserved send slot may remain counted after an interrupted attempt. A working WordPress cron or queue worker is still required. The controls cannot recall mail already handed off.

= What data does the plugin store and erase? =

The plugin stores campaign content, confirmed recipients, scheduling and delivery records, drafts, saved audience criteria, subscription evidence and opt-outs in your WordPress database. Emails are sent through the mail transport configured on your site.

WordPress Tools > Export Personal Data and Erase Personal Data include this plugin's records for the requested email address, including guests. Erasure retains protective hashes and opt-out evidence to prevent reconstruction or another send. In-flight work or shared content in an active campaign may require another erasure pass after completion; the erasure result reports retained data.

Retention is off by default (0 days). When enabled, hourly cleanup removes content, personal recipient details, snapshots and plugin queue arguments/logs from eligible finished campaigns after the configured period. Active campaigns, unfinished preparation and retryable work are excluded. Minimal campaign identifiers, email hashes, outcomes, claims, counters and suppression remain. Drafts and saved criteria remain until explicitly deleted or erased. This cleanup does not remove records held by mail providers, server logs or backups.

= What happens when I deactivate or upgrade? =

Deactivation cancels pending campaign work and removes the reconciliation event while retaining campaign records and opt-outs. Reactivation does not automatically restart cancelled work.

When upgrading, stop background workers that have already loaded the old plugin code before replacing files. Version 0.3.0 keeps the existing plugin slug, text domain, settings, campaigns and opt-outs. Do not downgrade directly to 0.2.2, which does not understand the new cancellation and erasure protections. Data from the separate YoOhw Extra Code plugin is not imported automatically.

= Can I translate the interface? =

Yes. Use the yoohw-advanced-notifications text domain and the included languages/yoohw-advanced-notifications.pot template with Poedit or Loco Translate. Store compiled locale files in wp-content/languages/plugins/ to preserve them across updates. Administrator-written email content and saved messages are not translated automatically. Preserve placeholders such as %s, {customer_name} and {product_name}.

== Changelog ==

= 0.3.0 =
* Rename the plugin to Advanced Notifications for WooCommerce; keep the existing slug, text domain and stored data.
* Add individual campaign controls and shared sending admission limits.
* Add durable drafts, duplication, content templates and personalized previews.
* Add source-aware saved audiences and explicit timezone scheduling.
* Add subscription/suppression administration, privacy callbacks and terminal retention.
* Preserve erased data boundaries, known-unsent history and cancellation during concurrent recovery.
* Unify campaign forms with the admin color scheme, SelectWoo fields and desktop/mobile preview controls.
* Harden confirmed recipient snapshots, duplicate protection, bounded retries and interrupted-work recovery.

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

== Upgrade Notice ==

= 0.3.0 =
Campaign controls, reusable drafts, audience filters, scheduling and privacy tools. Existing data is retained. Stop old background workers before upgrading. Do not downgrade to 0.2.2 because it lacks the new cancellation and erasure protections.
