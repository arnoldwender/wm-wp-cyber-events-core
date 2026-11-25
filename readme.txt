=== WM Cyber Events Core ===
Contributors: arnoldwender
Tags: events, registration, double opt-in, invoice xml, datev
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 1.2.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Events, sessions and registrations with Brevo double opt-in, capacity-checked session booking, check-in, invoice XML (EN 16931) and DATEV export.

== Description ==

WM Cyber Events Core manages events, sessions and venues, takes registrations with double opt-in, lets confirmed participants book sessions with a capacity check, checks people in and exports attendee lists, invoice data and calendar files.

What the plugin does:

* Registration form (block or shortcode) posting to a REST route; the registration stays unconfirmed until the participant follows the link in a confirmation mail sent by Brevo (API key required).
* Session selection for confirmed participants; each seat is taken with one conditional database update, so a session cannot be overbooked.
* Check-in route for administrators or scanners with a staff key; ticket pass block for confirmed registrations.
* 11 Gutenberg blocks in the Block Inserter with live previews and translated controls: event header with countdown, registration form, agenda, speaker grid, calendar widget (.ics, Google Calendar, Outlook Web, Microsoft 365), session selector, seat map, ticket pass, trust block, events grid and calendar views.
* Ecosystem integration: WordPress 6.5+ Block Bindings source (`wm-cybevents/metrics`), GenerateBlocks 2.x Dynamic Tag (`wm_cybevents_metric`), and developer hooks (`wm_cybevents_ready`, `wm_cybevents_metric_value`, `wm_cybevents_public_rest_rate_limit`, `wm_cybevents_ics_output`).
* Invoice and credit note XML (Cross Industry Invoice, EN 16931 guideline ID), validated with Mustang-CLI in the tests; no PDF. The registration flow creates no invoice.
* DATEV EXTF 700 booking batch (category 21), attendee CSV export, CSV import, WordPress privacy exporter and eraser.
* Opt-in uninstallation (`wm_cybevents_delete_data_on_uninstall`): preserves all event, registration, invoice and audit tables by default when uninstalled unless deletion is explicitly enabled in settings.
* Daily deletion of registrations unconfirmed after 14 days; HMAC-SHA256 audit chain, pseudonymised after 90 days; precompiled translations across 28 locales.
* WP-CLI: `wp cybevents list`, `queue-run`, `verify-audit`, `admission`, `demodaten`.

Known issues: the registration flow creates no invoice and takes no payment; the Friendly Captcha settings have no effect because no widget is bundled. Details in README.md.

**No Support & Zero Liability ("AS IS"):** This plugin is provided free of charge as personal open-source software with **zero support** and **no private support**. Do not contact the author via email, `arnoldwender.com`, or direct messages. Report reproducible defects exclusively at `https://github.com/arnoldwender/wm-wp-cyber-events-core/issues` (no SLA or response guarantee). Under GPLv2+ Sections 11-12 it is distributed **"AS IS"** without warranty, legal advice, or author liability.

== Installation ==

1. Upload `wm-cyber-events-core` to `/wp-content/plugins/` and activate it.
2. Under Cyber Events > Einstellungen enter the Brevo API key, DOI template ID, list ID and webhook secret, and the invoice seller data if invoices are used.
3. Place the registration form block on a page and the session selector block on a published page.
4. Make sure WP-Cron runs every minute or run `wp cybevents queue-run`.

= Seat map with live updates =

The occupancy of every session as an SVG, one cell per seat, updated over ETag-cached snapshot polling
(`/cybevents/v1/seat-snapshot`, pausing via Page Visibility API when the tab is hidden) by default,
or via Server-Sent Events (`/cybevents/v1/seat-stream`) when `transport="sse"` is configured. Responses
carry occupancy numbers only, and SSE streams close after 30 seconds so one viewer does not hold a PHP
process indefinitely. Block "Cyber Events Sitzplan" or `[cybevents_seat_map event_id="…"]`. There is no
WebSocket: WordPress has no socket server.

= Admission waiting room =

Off by default. With `admission_per_minute` set, requests to registration and session booking over that
limit are answered with HTTP 503 and a Retry-After before any database query runs. It is a throttle, not
a queue: a refused request holds no seat. With a persistent object cache the counter lives there; without
one it is a transient.

== Frequently Asked Questions ==

= Is technical or private support provided? =

No. This plugin comes with zero support (`0` support). Private inquiries sent via email, `arnoldwender.com`, or social/chat channels are not answered. Reproducible bugs belong solely on GitHub Issues (`https://github.com/arnoldwender/wm-wp-cyber-events-core/issues`) with no guarantee of reply or resolution. The software is provided **"AS IS"** at your sole risk with zero warranty or liability (see `SUPPORT.md` in the repository).

== Screenshots ==

1. Cyber Events command console showing scheduled events, registrations and capacity
2. Attendee management table with double opt-in confirmation state and booking status
3. Block editor with the agenda block of the plugin and its sidebar settings
4. Fictional demo event page: hero, calendar links, speakers, agenda and registration form from the plugin's blocks

== Changelog ==

= 1.2.3 =
* Fixed: The free-seat count shown on event and session pages («%d freier Platz») repeated one form for several numbers in Arabic, Croatian, Czech, Irish, Latvian, Lithuanian, Maltese, Polish, Romanian, Slovak and Slovenian, for example Czech «2 volných míst» and Polish «2 wolnych miejsc»; each number now gets the form its language uses (Czech «2 volná místa», Polish «2 wolne miejsca»). The Arabic plural forms said «أماكن مجانية» (places free of charge) and the Irish forms «saor» (free of charge); both now say vacant seats («مقعد شاغر», «ar fáil»). The plugin's code is unchanged.

= 1.2.2 =
* Fixed: On the plugin's admin screens, which carry WordPress's help tab, the header was shorter than the page by the width of the «Hilfe» toggle (measured 1155 px of 1238 px): WordPress floats the toggle above the page and the header may not overlap it. The bundled family stylesheet, now identical to WM Suite Hub 1.2.5, starts the header below the toggle, so it spans the page.

= 1.2.1 =
* Fixed: `bom.json` carries the SHA-256 of the main plugin file this package ships; the 1.2.0 package carried the hash of an earlier revision of that file.

= 1.2.0 =
* The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
* The `.wordpress-org/` banners show the current version.
* Fixed: Calendar Views showed 46 emoji characters (41 pictographs and five diamond separators), which every system draws differently and screen readers announce by their Unicode names. They are now the plugin's own decorative inline SVG icons (`CyberEvents_Icons`), coloured by the text next to them.
* Fixed: The block editor drew Calendar Views and the seat map, which it renders on the server, without the frontend stylesheet, so both showed as unstyled text. The editor canvas now loads that stylesheet with the colours of the selected theme preset.
* Fixed: Printing any page of a site that uses the plugin gave an empty sheet: the print rule of the ticket pass hid everything except the pass, also on pages without one. It now applies only to a page that shows a ticket pass.
* Fixed: Calendar Views no longer adds a second `<main>` landmark to the page, and the heading of its room list takes its colour from the stylesheet instead of a fixed value.
* Fixed: In a narrow column such as the block editor, the day cells of the Calendar Views month grid grew to their longest event title and no longer lined up with the weekday header. The seven columns now stay equal, and a long title ends in an ellipsis.
* Removed: The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

= 1.1.7 =
* Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.
* Added root `SUPPORT.md`, GitHub Issue templates, explicit zero-support & "AS IS" liability notices in READMEs and `readme.txt`, and normalized canonical GPLv2+ `arnoldwender.com` / `arnoldwender/wm-wp-cyber-events-core` links.
* Updated `.wordpress-org/` visual banners to personal `ARNOLD WENDER · OPEN SOURCE` branding.
* Demo data is fictional (invented names, reserved example.com/.org/.net domains); the demo purge now also deletes the pages the seeder created; new screenshots from a clean WordPress.

= 1.1.6 =
* Add `.wordpress-org/` vector icons, banners (`772x250`, `1544x500`), live WordPress screenshots (`screenshot-1..4.png`) and `== Screenshots ==` descriptions.
* Update trilingual READMEs, `docs/translations/README.*.md`, and `llms.txt` with live previews and direct plugin catalog link (`https://arnoldwender.com/wordpress-plugins/wm-cyber-events-core/`).

= 1.1.5 =
* Synchronized `CYBEREVENTS_VERSION` constant with `Version: 1.1.5` header and regression test.

= 1.1.4 =
* Point Plugin URI to the official plugin page.

= 1.1.3 =
* Switched default seat-map live updates from blocking SSE to ETag + 304 cached polling (`/cybevents/v1/seat-snapshot`, 3s object-cache/transient snapshot, Page Visibility API pause when hidden, filterable via `cybevents_seatmap_transport` with SSE still available).
* Added Multisite `wp_initialize_site` provisioning for network-activated installs and disabled autoload on internal state option `wm_cybevents_delete_data_on_uninstall`.

= 1.1.2 =
* Synchronized `CYBEREVENTS_VERSION` fallback in `CyberEvents_Spoke_Adapter` and standalone test harness with the `1.1.2` release header.

= 1.1.1 =
* Added real `mysql:8.0` integration matrix (`mysql-integration`) to GitHub Actions CI verifying InnoDB atomic row-level capacity predicates (`belegt < kapazitaet`) and `GET_LOCK()` invoice sequence locks against a live MySQL 8.0 engine.

= 1.1.0 =
* Added `editorScript` and Block Inserter controls for all 11 Gutenberg blocks (`wm-cyber-events/seat-map` and `wm-cyber-events/calendar-views` included) with JED script translations.
* Added opt-in uninstallation (`wm_cybevents_delete_data_on_uninstall`) preserving event, registration, invoice and audit tables by default.
* Added WordPress 6.5+ Block Bindings source (`wm-cybevents/metrics`), GenerateBlocks 2.x Dynamic Tag (`wm_cybevents_metric`), and developer hooks (`wm_cybevents_ready`, `wm_cybevents_metric_value`, `wm_cybevents_public_rest_rate_limit`, `wm_cybevents_ics_output`).
* Hardened all 10 public REST routes with HMAC-SHA256 blinded-IP rate limiting, tightened `/upload-avatar` token/nonce validation, parameterized all remaining `$wpdb` queries, escaped all admin and block outputs, enabled `declare(strict_types=1)` across every PHP file, and tokenized public and editor CSS colours.
* Added precompiled `.po`, `.mo` and JED `.json` translations across all 28 locales, CycloneDX 1.6 `bom.json`, and WordPress Playground blueprint.
* Registration flow, EN 16931 invoice and credit note XML, DATEV EXTF 700 export, admission waiting room, live SSE seat map, and WM Suite admin design family.

= 1.0.0 =
* Initial version.

== Upgrade Notice ==

= 1.2.3 =
Translation fix only: plural forms in 11 languages. No code, data or settings change.

= 1.2.2 =
Stylesheet fix only: the admin header spans the page on screens with a help tab. No data or settings change.

= 1.2.1 =
Corrects the SHA-256 in the bundled SBOM (bom.json). No functional change.

= 1.2.0 =
Hub users: update WM Suite Hub to 1.2.0 together with this plugin. The hub contract moved to a new PHP namespace; next to an older hub the plugin keeps working, but it is not listed in the cockpit and its admin page has no menu entry until the hub is updated.
