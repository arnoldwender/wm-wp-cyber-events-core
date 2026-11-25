# Changelog — WM Cyber Events Core

All notable changes to **WM Cyber Events Core** are documented in this file in adherence to [Keep a Changelog](https://keepachangelog.com/en/1.0.0/) and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## [Unreleased]

### Fixed
- Switched GenerateBlocks 2.x Dynamic Tag hook from `generateblocks_dynamic_tag_output` to `generateblocks_dynamic_tag_replacement` with `mixed` safety.
- Scoped `cybevents_enqueue_frontend_assets()` to Cyber Events CPT/taxonomy archives or pages containing plugin blocks/shortcodes, with lazy asset enqueueing inside block/shortcode render callbacks for FSE templates and widgets.
- Updated `readme.txt` Seat Map documentation to describe ETag-cached `/cybevents/v1/seat-snapshot` polling (default) alongside optional SSE (`/cybevents/v1/seat-stream`), and extracted non-positional inline styles into `public/css/cybevents-public.css` classes.
- Fixed PHP 8.6 deprecation in `tests/bootstrap-harness.php` (`$thing instanceof WP_Error`) and synchronized `bom.json`.
- Removed automatic demo-data seeding from `CyberEvents_Activator::activate()` so fresh activations initialize schema, default options and cron schedules only (demo import stays opt-in via Dashboard/Settings or `wp cybevents demodaten --import`), and replaced hardcoded October 2026 / invented programme markup and JS dictionaries in `CyberEvents_Calendar_Views` (`includes/class-calendar-views.php`, `blocks/calendar-views/block.json`, `blocks/editor.js`, `public/js/cybevents-public.js`) with dynamic database-backed rendering across all 7 views and an optional `month` attribute.
- Fixed pipe-buffer deadlock in `tests/test-pot-freshness.php` (`make_pot()`) under PHP 8.6 stderr output.

## [1.2.3] - 2026-11-21

### Fixed

- The free-seat count shown on event and session pages («%d freier Platz») repeated one form for several numbers in Arabic, Croatian, Czech, Irish, Latvian, Lithuanian, Maltese, Polish, Romanian, Slovak and Slovenian, for example Czech «2 volných míst» and Polish «2 wolnych miejsc»; each number now gets the form its language uses (Czech «2 volná místa», Polish «2 wolne miejsca»). The Arabic plural forms said «أماكن مجانية» (places free of charge) and the Irish forms «saor» (free of charge); both now say vacant seats («مقعد شاغر», «ar fáil»). The plugin's code is unchanged.

## [1.2.2] - 2026-11-21

### Fixed

- On the plugin's admin screens, which carry WordPress's help tab, the header was shorter than the page by the width of the «Hilfe» toggle (measured 1155 px of 1238 px): WordPress floats the toggle above the page and the header may not overlap it. The bundled family stylesheet, now identical to WM Suite Hub 1.2.5, starts the header below the toggle, so it spans the page.
- The four theme presets "Violett & Rosé" and "Enterprise Dark" (name and description) were untranslated in all 28 catalogs and now carry their translations; the empty Plugin URI and Author URI entries are filled. The JavaScript catalogs of the blocks gain the context keys of their strings and the plural forms of languages with more than two (Arabic, Czech, Irish, Croatian, Lithuanian, Latvian, Maltese, Polish, Romanian, Slovak, Slovenian).

## [1.2.1] - 2026-11-20

### Fixed
- `bom.json` carries the SHA-256 of the main plugin file this package ships. The 1.2.0 package carried the hash of an earlier revision of that file, taken before a documentation change to it.

## [1.2.0] - 2026-11-20

### Changed
- The WM Suite Hub contract now lives in the `ArnoldWender\SuiteHub\Contracts` namespace. The spoke adapter registers only with WM Suite Hub 1.2.0 or later, so update the hub together with this plugin; used on its own, the plugin works as before.
- The `.wordpress-org/` banners show the current version.

### Fixed
- Calendar Views showed 46 emoji characters (41 pictographs and five diamond separators), which every system draws differently and screen readers announce by their Unicode names. They are now the plugin's own decorative inline SVG icons (`CyberEvents_Icons`), coloured by the text next to them.
- The block editor drew Calendar Views and the seat map, which it renders on the server, without the frontend stylesheet, so both showed as unstyled text. The editor canvas now loads that stylesheet with the colours of the selected theme preset.
- Printing any page of a site that uses the plugin gave an empty sheet: the print rule of the ticket pass hid everything except the pass, also on pages without one. It now applies only to a page that shows a ticket pass.
- Calendar Views no longer adds a second `<main>` landmark to the page, and the heading of its room list takes its colour from the stylesheet instead of a fixed value.
- In a narrow column such as the block editor, the day cells of the Calendar Views month grid grew to their longest event title and no longer lined up with the weekday header. The seven columns now stay equal, and a long title ends in an ellipsis.
- The test counts in the READMEs and `DEVELOPERS.md` were stale (135 assertions, 177 mutations); measured today: 150 and 209.

### Removed
- The archived pre-2026-11-19 marketing visuals in `versionen/`: they claimed features and conformity the plugin does not have. They were never part of the installable package.

## [1.1.7] - 2026-11-19

- Removed the agency name, links to unrelated projects and the personal mailbox from code, catalogs and documentation; the admin family badge and the hub menu read "WM Suite". The READMEs say the plugin was built with an agentic workflow (AI coding agents) and should be reviewed like any third-party code.
- **Demo data is fictional.** The seeder created a conference named after a real client, at its address, with speakers from real authorities and registrations at real authority domains; Calendar Views, the frontend script and two theme presets carried the same client, venue and a real person as speaker. The demo conference is now "[Demo] Fachkonferenz Sichere Software 2026" with invented venues, speakers, companies and attendees, every e-mail address and website on `example.com`, `example.org` or `example.net`, and the same states as before (one session fully booked, one overlapping, a pending double opt-in, a cancellation). A new test (J6) fails when the demo data names any other domain, and a mutation proves it. *Demodaten bereinigen* matches only "[Demo]" titles and the new venue slugs; demo data seeded by an older version is not recognised and has to be deleted by hand. The presets are now "Violett & Rosé" (`violet_mesh`) and "Enterprise Dark" (`enterprise_dark`); a site that had chosen one of the old two falls back to the theme default until it chooses again. The headings of the demo pages carry no emoji. Seeding also left a second, phantom event for the demo post (the post's `save_post` hook inserts its own row before the seeder links its row): 0 seats, free, listed on the dashboard. The hook's row is removed now (test J7, with a mutation).
- **Demo pages are purged.** Every seed created a landing page and a workshop page that *Demodaten bereinigen* never deleted, so each reload added another copy (`demo-fachkonferenz-2`, `-3`, ...). The seeder records the two page IDs; the purge and the factory reset delete exactly those, and only while they are still pages (test J8, three mutations). Pages from earlier seeds were not recorded and stay.
- **New screenshots.** The four screenshots come from a clean WordPress with the fictional demo data; the old design-family comparison images in `docs/screenshots/` are removed.

- Added root `SUPPORT.md`, `.github/ISSUE_TEMPLATE/{config.yml,bug_report.yml}`, and explicit zero-support / "AS IS" liability notices across `README.md`, `README.de.md`, `README.es.md`, `readme.txt`, `SECURITY.md`, and `llms.txt`.
- Migrated plugin author/catalog/repository metadata to personal open-source distribution (`Arnold Wender`, `https://arnoldwender.com/wordpress-plugins/wm-cyber-events-core/`, `https://github.com/arnoldwender/wm-wp-cyber-events-core`), normalized FSF GPL-2.0 `LICENSE` metadata where applicable, and updated `.wordpress-org/` banner branding to `ARNOLD WENDER · OPEN SOURCE`.

## [Unreleased]

## [1.1.6] - 2026-11-19

### Added
- Official `.wordpress-org/` directory visual kit (`icon.svg`, `icon-128x128.png`, `icon-256x256.png`, `banner-772x250.png`, `banner-1544x500.png`) and live Playwright screenshots (`screenshot-1.png` through `screenshot-4.png`) with `== Screenshots ==` in `readme.txt`.
- Visual previews and direct link to `https://arnoldwender.com/wordpress-plugins/wm-cyber-events-core/` in `README.md`, `README.de.md`, `README.es.md`, and `llms.txt`, plus updated image paths in `docs/translations/README.*.md`.

### Changed
- Archived superseded pre-2026-11-19 banners and mockups non-destructively in `versionen/visuals-pre-2026-11-19/` and excluded `versionen/` from distribution packages via `.distignore`.
- Updated release metadata, `CYBEREVENTS_VERSION` fallback, test harness (`tests/bootstrap-harness.php`, `tests/test-suite.php`), and SBOM (`bom.json`) to `1.1.6`.

---

## [1.1.5] - 2026-11-19

### Fixed
- Synchronized `CYBEREVENTS_VERSION` constant in `wm-cyber-events-core.php` with plugin header `1.1.5` and enforced exact constant regex verification in `tests/test-suite.php`.

---

## [1.1.4] - 2026-11-19

### Changed
- Point `Plugin URI` to `https://arnoldwender.com/wordpress-plugins/wm-cyber-events-core/` and align release metadata at `v1.1.4`.

---

## [1.1.3] - 2026-11-18

### Added & Changed

- **ETag + 304 cached seat-snapshot polling with Page Visibility API pause (`R2-B1`).** Added `CyberEvents_Seat_Map::cached_snapshot()` (3s object cache in group `cybevents_seats` with 3s transient fallback) and `invalidate_cache()`, registered public `GET /cybevents/v1/seat-snapshot` returning `ETag` and `Cache-Control: private, no-cache` (304 Not Modified on matching strong or weak `If-None-Match`), invalidated the cache on bookings/cancellations/bounces/deletions, updated `CyberEvents_Seat_Map::render()` to emit `data-snapshot` and default `data-transport="etag-poll"` (filterable via `cybevents_seatmap_transport`, keeping `/seat-stream` and SSE mode intact), and updated `public/js/cybevents-seatmap.js` to poll every 3000 ms with `If-None-Match` while pausing when `document.visibilityState === 'hidden'` and polling immediately upon returning to `'visible'`.
- **Multisite site provisioning & non-autoloaded internal state (`R2-B8`).** Added `cybevents_initialize_site()` on `wp_initialize_site` so network-activated Multisite installs provision tables and defaults on new blogs via `switch_to_blog()` -> `CyberEvents_Activator::activate()` -> `restore_current_blog()`, and set `$autoload = false` on `wm_cybevents_delete_data_on_uninstall`.

---

## [1.1.2] - 2026-11-18

### Fixed

- Synchronized `CYBEREVENTS_VERSION` fallback in `CyberEvents_Spoke_Adapter` and standalone test harness assertions (`135 PASSED | 0 FAILED`).

---

## [1.1.1] - 2026-11-18

### Added

- **MySQL 8.0 CI integration matrix (`.github/workflows/ci.yml`).** Added `mysql-integration` (`mysql:8.0`) job verifying InnoDB atomic capacity updates (`belegt < kapazitaet`) and `GET_LOCK()` sequence locks against a real MySQL 8.0 instance.

---

## [1.1.0] - 2026-11-18

### Added — Block Inserter parity, ecosystem bridges, opt-in uninstall, and 28-locale catalogs

- **All 11 Gutenberg blocks visible in the Block Inserter (`11 / 0`).** Every `blocks/*/block.json` declares `"editorScript": "cybevents-blocks-editor-js"` and `"editorStyle": "cybevents-blocks-editor-css"`; `blocks/editor.js` registers `wm-cyber-events/seat-map` and `wm-cyber-events/calendar-views` alongside the existing nine blocks with `InspectorControls` and `ServerSideRender`, wired to `wp_set_script_translations()`.
- **Opt-in uninstallation (`D-UNINSTALL`).** `uninstall.php` preserves all seven custom tables and plugin options by default when the plugin is deleted; tables, options and cron schedules are removed only when the administrator enables `wm_cybevents_delete_data_on_uninstall` on the settings screen.
- **Ecosystem & developer extensibility.** Registers WordPress 6.5+ Block Bindings source `wm-cybevents/metrics`, GenerateBlocks 2.x Dynamic Tag `wm_cybevents_metric`, action `wm_cybevents_ready`, and filters `wm_cybevents_metric_value`, `wm_cybevents_public_rest_rate_limit`, and `wm_cybevents_ics_output`.
- **Precompiled translations across all 28 locales.** Regenerated `languages/wm-cyber-events-core.pot` from source and compiled `.po`, `.mo`, and JED `.json` catalogs for all 28 locales (`ar`, `bg_BG`, `cs_CZ`, `da_DK`, `de_DE`, `el`, `en_GB`, `en_US`, `es_ES`, `et`, `fi`, `fi_FI`, `fr_FR`, `ga`, `hr_HR`, `hu_HU`, `it_IT`, `lt_LT`, `lv`, `mt_MT`, `nl_NL`, `pl_PL`, `pt_PT`, `ro_RO`, `sk_SK`, `sl_SI`, `sv_SE`, `tr_TR`), plus CycloneDX 1.6 `bom.json` and `.wordpress-org/blueprints/blueprint.json`.

### Security & Code Quality

- **Public REST rate-limiting gate.** Replaced `'__return_true'` across all 10 public `cybevents/v1` REST routes with `CyberEvents_REST_API::check_public_events_access()` (transient-based HMAC-SHA256 blinded-IP rate limiting returning HTTP 429, `manage_options` bypass, zero raw IP addresses stored) and hardened `/upload-avatar` to require an active participant token or administrator REST nonce.
- **SQL parameterization, output escaping, strict types, and CSS tokens.** Parameterized all 42 remaining `$wpdb` statements with `$wpdb->prepare()`, escaped all 22 template output expressions on the output line, enabled `declare(strict_types=1);` across all 27 PHP files, replaced deprecated `current_time('timestamp')` calls with `current_datetime()->getTimestamp()`, guarded debug logging under `WP_DEBUG`, added missing `alt` and `rel="noopener noreferrer"` attributes, neutralized third-party map placeholder URLs, and moved hardcoded hex colours in `blocks/editor.css` and `public/css/cybevents-public.css` into `:root` `--cyb-*` custom properties.
- **Tests & mutations.** Standalone suite expanded to 135 assertions and 177 mutations (`0 FAILED`, `0 SURVIVED`).

### Fixed — the SBOM declared a proprietary licence (2026-11-05)

- **The hub was told this plugin is proprietary.** `get_sbom_data()` declared `{"id": "Proprietary", "name": "Proprietary Commercial License"}`, unchanged since the adapter was written on 2026-08-27 (`5a21381`), while `LICENSE`, the plugin header and `readme.txt` say GPLv2 or later since 2026-09-16 (`95b48ee`, a change this file never recorded). `Proprietary` is not an SPDX id, and CycloneDX 1.6 takes an SPDX id or a name, not both. The component now declares `{"id": "GPL-2.0-or-later"}` alone; assertion E3 and two mutations (the old block, and a name beside the id).
- **The documentation still called the licence an open decision.** `README.md`, `README.de.md`, `README.es.md` and the 24 translated READMEs quoted `License: Proprietary`; `CLAUDE.md`, `DEVELOPERS.md` and `docs/ARCHITECTURE.md` listed the licence among the open decisions; the `@license` tag of `includes/class-database.php` said `Proprietary (LicenseRef-Proprietary-WM-Suite)` since the first commit. They now state GPLv2 or later. The Known-issues item keeps the version number, which is still open. The 2026-09-15 entry below that marks the licence "under review" stays as the record of that day.
- The test counts in the READMEs and `DEVELOPERS.md` were those of 2026-09-15 (88 assertions, 131 mutations); measured today: 129 and 171.
- Plugin Check was not re-run for this change.
- Gate: suite 129 PASSED / 0 FAILED, mutations 171 / 171 red with the control green.

### Fixed — the badge claimed a translation the catalogs do not hold (2026-09-20)

- The badge row carried **`Translations — 24 EU Languages` in green**, which reads as a finished translation of the plugin. Measured: **1,407 real translations across 25,116 catalog entries**, and they sit in five locales — `ar` 448, `bg_BG` 303, `cs_CZ` 301, `da_DK` 299, `el` 46, out of 897 per catalog. The other 23 catalogs are filled with entries whose `msgstr` repeats the English source word for word. `msgfmt` counts those copies as translated; a visitor reading the page does not.
- The badge now reads `Translated READMEs — 24 (headings only)` in grey, the label three other plugins of the fleet already use, and the section it links to gives the five locales and their counts instead of a total that flattered them. It also says what the translated READMEs are: translated headings over English bodies.
- A badge is read before the prose, so an honest paragraph does not cancel a misleading badge. The fleet now carries a check that fires only when all three hold: the label claims the *plugin* is translated, it wears a success colour, and the catalogs hold under 50 % real translations.

### Translation catalog (2026-09-20)

- **307 of the 885 translatable strings had no entry in the `.pot`** — the ones the 2026-09-15 rewrite added, the waiting room among them. A string the code wraps in `__()` but that the catalog does not carry cannot be translated by anyone, to any language, ever; it just shows in German. The template is now generated from the code and `tests/test-pot-freshness.php` plus a CI step keep it that way.
- **The "15 819 translations lost" figure that made this an open decision was counting the wrong thing.** Of the 22 819 entries with a non-empty `msgstr`, **20 932 (91 %) were literal copies of the source string**; `msgfmt` counts those as translated because it only checks that `msgstr` is not empty. Real translations were **1 887**, and the regeneration moved **480** of them to obsolete `#~` entries — `ar` 619 → 448, `bg_BG` 399 → 303, `cs_CZ` 397 → 301, `da_DK` 394 → 299, `el` 68 → 46. Nothing was deleted.
- The other stated benefit, repairing 20 double-encoded catalogs, no longer applied: the mojibake pass of 2026-09-17 had already cleaned them and all 29 measure 0 today.
- The template comes from `wp i18n make-pot`, not from `wp-plugin-fleet-catalog-regenerate.sh`: that script finds 716 msgids here against make-pot's 890, because it scans only `includes/` and `src/` while this plugin also has strings in `blocks/`, `admin/` and `public/`. It found none make-pot missed.

### The WordPress security suite measures the code again (2026-09-17)

- **`tests/test-security.php` passed 4 of 6, and both failures were the test, not the plugin.** Test 4 went through `create_invoice()`, which since 2026-09-15 refuses while the seller settings are incomplete; it read a property of null and never looked at the XML. It now calls `generate_zugferd_xml()` directly, where the escaping lives — no invoice number consumed from the gapless sequence, no row stored. Test 5 expected the `/session-auswahl/` fallback removed on 2026-09-15; a foreign host now falls back to the front page and the test expects that. Test 2 deletes, by id, exactly the events, sessions, registration and booking it inserts: every runtime pass had left them behind, and **28 "Event Alpha/Beta" events and 8 probe registrations** had piled up on a local WordPress install. Measured after the fix: **6/6**, and those counts did not move. The 28 existing rows were left in place — deleting demo data waits for Arnold.

### The version this file opens on matches the version the plugin ships (D15, 2026-09-17)

- **The plugin was 1.0.0 on that date, and the changelog opened on 1.0.0 too.** This file used to open on `[1.1.0]`, a release that `git tag` (0) and `gh release list` (0) both denied on 2026-09-17. That 2026-08-29 draft moved **verbatim and complete** to an appendix at the foot of this file: nothing was deleted, because a changelog is a record and the record is that those entries were written. Moved by the fleet changelog helper, which refuses to write if a single non-blank line would be lost.

### Added (2026-09-16) — seat map with a live stream

The third vector the specification names for this plugin: an "interactive SVG seat map (Gutenberg):
visual seat selector with state updates over WebSocket / Server-Sent Events". The atomic row lock was
there, the waiting room is new above, and this is what people actually look at.

- **What it draws.** One cell per seat, one row per session, from `CyberEvents_Seat_Map::render()`
  through the block *Cyber Events Sitzplan* or `[cybevents_seat_map event_id="…"]`. The plugin stores
  capacity, not furniture — a session has `kapazitaet` and `belegt`, and no table knows seat 14 in row
  F — so the map draws what exists and invents no numbered chairs the booking code could not honour.
  A session over 400 seats becomes a bar; a table beside the drawing carries the same numbers, because
  a screen reader gets nothing from a rectangle.
- **How it stays current.** `GET /cybevents/v1/seat-stream?event_id=…` writes `text/event-stream`,
  reads the occupancy every two seconds, sends an event **only when the digest changed**, a keep-alive
  comment every ten seconds, and closes after thirty with a `retry`. One connected viewer occupies one
  PHP process for the life of the stream, which is exactly why the life is short and the browser is
  told to come back. `X-Accel-Buffering: no` keeps nginx from holding the bytes.
- **There is no WebSocket.** WordPress has no socket server. The specification offers both; this is
  the one that can be built, and the README says which.
- **What the stream carries.** Occupancy numbers per session. No names, no addresses, no tokens —
  which is why it needs none to be read. The participant token is not written into the markup either:
  it travels in the URL, the script reads it from there, and a copy in an attribute would sit in
  whatever cache holds the page. That is the defect this plugin already had to fix on `/ticket`.
- **Clicking books.** A row of a confirmed participant toggles through `POST /book-sessions`, so the
  conditional UPDATE still decides the last seat and the waiting room in front of it still applies.
- Group L of `tests/test-suite.php` (22 assertions) and 19 entries in `tests/mutations.php`. Four of
  the first attempt's mutations survived: two because the test used an unconfirmed registration that
  held no booking at all, one because the mutation put the string `data-token` in the markup without
  the token in it, and one because nothing asserted where the script reads the token from.

### Added (2026-09-16) — admission waiting room

The specification of this plugin names a "Redis memory buffer / drop queue: a virtual waiting queue
for traffic peaks of more than 10,000 requests per second without overloading MySQL". The row-level
lock that decides who gets the last seat was already there; what was missing was anything that keeps
the losers from costing as much as the winners.

- **What it does.** `CyberEvents_Waiting_Room::admit()` counts requests to `POST /register` and
  `POST /book-sessions` per minute, per gate and per event, and answers everything over
  `admission_per_minute` with HTTP 503 and a `Retry-After` **before any booking query runs**.
  Measured on a local WordPress install on 2026-09-16 with a real token and the database backend: an admitted booking
  costs 16 queries, a refused one 2 — the transient the counter itself is. With a persistent object
  cache the refused one costs none. The first wording of this entry said "none" for both, because the
  harness keeps transients in memory; the number that counts is the one from the deployment.
- **What it does not do.** It is a throttle, not a queue. A refused request holds no seat and the
  message says so; the `Retry-After` is when the next window opens, not when a seat is reserved. The
  figure of 10,000 requests per second is not claimed anywhere: what the plugin can say is how many
  admissions per minute it lets through and that the rest cost no query.
- **Where the counter lives.** With a persistent object cache (Redis, Memcached) it is
  `wp_cache_add()` plus an atomic `wp_cache_incr()` and the refusal touches no database at all.
  Without one it is a transient, a row in the options table: the heavy work is skipped, the counter
  itself is not free. `backend()` reports which, the settings screen shows it and warns about the
  second, and the hub carries `admission_limit` and `admission_backend`.
- **Off by default** (`admission_per_minute` = 0), because it changes how public routes answer. The
  settings save keeps a stored limit when the form posts none, as the invoice counter does.
- `wp cybevents admission [--event=<id>] [--reset]` reports the window and clears it.
- Group K of `tests/test-suite.php` (16 assertions) and 16 entries in `tests/mutations.php`. Three of
  the first attempt's mutations survived and were not papered over: one needed a cache that evicts a
  key between `add()` and `incr()` (the harness can now do that), one needed a refusal measured with
  a persistent cache rather than transients, and one needed the settings-save assertion the invoice
  counter already had.

### Fixed (2026-09-16) — deleting a registration by id did nothing

`CyberEvents_GDPR_Manager::delete_registration()` and `release_sessions()` are documented as taking a
registration row. Passing an id — which a caller did while probing the waiting room on a local WordPress install —
read a property off an integer: two PHP warnings, no seats released, nothing deleted, `false`
returned. Both now take a row or an id, and an id that names no registration is refused instead of
half-running. Assertions K14, K14b and K14c, three mutations.

### Family sheets (2026-09-16)

- Re-synced from the hub: the badge variants carry the state tokens on body surfaces, where the command-banner inks measured 1.49:1 to 2.21:1 against the composed background (hub PR #19), and the canonical sheet dropped five classes that belonged to a single plugin (hub PR #21). `tests/test-ui-family.php` re-pins both md5s.

### Documentation (audit 2026-09-15, section 7)

- `README.md`, `README.de.md`, `README.es.md`, the 24 translated READMEs, `DEVELOPERS.md`, `docs/ARCHITECTURE.md`, `docs/GUTENBERG-BLOCKS.md`, `docs/DSGVO-COMPLIANCE.md`, `docs/ACCESSIBILITY.md`, `docs/DEVELOPER-GUIDE.md`, `readme.txt`, `llms.txt`, `CLAUDE.md`, `CONTRIBUTING.md` and `SECURITY.md` describe what the plugin ships, with Known issues and open decisions. Until now they described an unregistered `wm_cyber_calendar` shortcode, WP-CLI `wp cybevents status|verify|export`, a REST namespace `wm-events/v1` with `/status`, `/submit`, `/export`, `/confirm-doi`, `/event-status` and `/refresh-nonce`, a hook `wm_cybevents_ticket_booked`, classes `WM_Cyber_Events_*` and `class-invoice-manager.php`, ZUGFeRD 2.2/2.3 hybrid PDF/A-3 invoices with Leitweg-ID, SVG QR codes, Levenshtein and Soundex matching, transactions with rollback, salted SHA-256 pseudonymisation, PHP 8.1 support and conformity with GoBD, BFSG 2025, BITV 2.0 and WCAG 2.2; none of those exist in the code. `docs/ARCHITECTURE.md` was written for a client. The translated READMEs keep their headings; the fleet translations sync check fails while any translation differs from what README.md produces.
- The historical entries **[1.1.0]** (2026-08-29 draft) and **[1.0.0]** below list a "Decoupled REST API Controller `/wp-json/wm-events/v1`", a "Zero-CDN Frontend Shortcode `wm_cyber_calendar`", "WP-CLI management commands under `wp cybevents`" (only `list`, `queue-run`, `verify-audit`, `admission` and `demodaten` exist), a "Revisionssicheres GoBD cryptographic ledger" and conformity statements that never shipped; the entries are kept as the record of what was written at the time.
- The licence lines disagree with the WM Suite rule for WordPress plugins (GPLv2 or later): the header says `Proprietary`, `readme.txt` says `LicenseRef-Proprietary-WM-Suite`, and the README said GPLv2. The documentation now quotes the header and marks the licence as under review; the lines themselves are unchanged pending a decision.

### Security

- **Brevo webhook fails closed (HIGH, fleet audit 2026-09-13 §6.2 #5):** with `brevo_webhook_secret` empty — the activation default — `validate_brevo_webhook_permission()` returned `true`, so any anonymous POST to `cybevents/v1/brevo-webhook` could mark registrations as bounced, release their session seats and write the payload into the audit chain. Permission callback and handler now answer `401` until a secret is configured; a notice on the plugin's admin screens says so. Tests: `tests/test-suite.php` test 9 (standalone) and `tests/test-security.php` 1d (WP-CLI).

### Fixed — registration flow and privacy (audit 2026-09-15, `docs/AUDIT-2026-09-15.md`)

Until this entry no public registration could be completed: the form and the session selector posted to routes that did not exist, the queue event that sends the confirmation mail was never scheduled, and nothing confirmed a registration.

- **Registration flow.** The script root is `rest_url( 'cybevents/v1' )` (was `rest_url()`, so `/register`, `/session-selection` and `/book-sessions` answered 404). The `every_minute` recurrence is registered at file load (nothing registered it, WordPress refused the event: measured absent on a local WordPress install before this change, scheduled after); `init` schedules missing events on existing installs and deactivation clears both events. Registrations queue `brevo_doi` (the job type `brevo_send_doi` was handled by no runner; queued jobs of that type are still processed) with a link to the new `GET /cybevents/v1/confirm` route, which confirms a pending registration, spends the DOI token and redirects to the published page with the session selector, passing the participant token only when it belongs to the registration. The session selection answers the fields the script reads (`success`, `participant.first_name`/`last_name`, `booked_sessions`, `verfuegbar`; it read `booked_ids`, `participant.name` and `freie_plaetze`).
- **Brevo without an API key** returns an error (`cybevents_brevo_not_configured`); it returned a simulated success, so jobs were marked sent. The queue claims a job only while it is still pending (cron and `wp cybevents queue-run` could send the same mail twice), hands back jobs left in `processing` for 15 minutes, and keeps only the registration id in the payload once a job is completed or finally failed (the link with both tokens stayed in the table). Hub health is `NEEDS_CONFIG` without a key or webhook secret, `DEGRADED` without the queue event or with failed jobs, `ERROR` with a broken audit chain; it was always `HEALTHY`. Telemetry reports measured values instead of the literals `INNODB_ROW_LOCKS_ACTIVE` and `ZUGFeRD_2.3_READY`; the SBOM lists features instead of standards the code does not establish.
- **Captcha.** `public/js/friendly-captcha.min.js` renders no widget, so with the setting on and both keys stored the server demanded a solution no browser could produce and every registration answered 403. The check and the script are skipped until a widget exists; an admin notice says the setting has no effect.
- **Status checks.** Session selection, booking, check-in and the ticket (REST and block) require a confirmed registration; unconfirmed, cancelled and bounced registrations could book seats, check in and print a pass labelled "BESTÄTIGT". Check-in is decided in the `WHERE` of one `UPDATE` (two simultaneous scans both answered 200). A session swap books the new seats first and releases them again if one fails; it cancelled the old sessions first and left the participant with neither.
- **Privacy.** The ticket is sent `private, no-store` (was `public, max-age=86400` with name, e-mail and invoice number). The registration form no longer stores a posted `avatar_url` (any external URL was rendered in the admin and on the pass). Audit entries of registration, check-in, self-cancellation and the Brevo webhook carry no e-mail address or payload; booking entries carry the session id in the details (it was passed as the user id). The privacy eraser anonymised invoiced registrations through a column that does not exist (`dietary_requirements`) while reporting success; it now deletes or anonymises with a checked result, releases seats, deletes the avatar file, pseudonymises older audit entries naming the address, drops queued mail for it and returns booleans. The exporter joined the registration's event id against WordPress post ids and exported an unrelated post title; it joins the plugin's events table and adds consents, DOI and check-in times, the avatar, booked sessions and invoices. The retention purge and the admin delete release seats and delete the avatar file (they left seats counted as taken). Self-service cancellation releases seats through the atomic path and logs a failed credit note.
- **Audit chain.** A row marked `pseudonymized_at` skipped the content check, and that column is outside the HMAC: anyone who could write the table could edit an entry and set it. Pseudonymisation now stores a keyed residual MAC (`residual_mac`, schema version 3) and verification reports a pseudonymised row without a valid one. A failed insert no longer moves the chain tip. Schema bumps run `CyberEvents_Activator::migrate()` (schema and cron only); they ran `activate()`, which seeds demo data into installs whose demo data had been deleted.
- **CSV import** stores imported participants as confirmed without privacy consent and without a DOI time (both were recorded as given for people who never saw the form). CSV parsing and the attendee export pass enclosure and an empty escape explicitly: PHP 8.4 deprecates the implicit backslash escape, and with it a quoted field ending in a backslash swallowed the rest of the line.
- **Admin.** Five `log_audit()` calls passed the user id as object id; the DATEV links pointed to `cybevents-datev`, a page that does not exist; the demo, delete-demo and factory-reset buttons also saved the settings form (reset, then the old values written back); every settings save reset the invoice counter to 1000 and the account length to 4, which have no field; the manual DOI confirmation confirmed any registration and left the token usable; the registrations list embedded token hashes in its JSON data attribute. The ticket pass shows the event title, location and ticket number instead of a client's name and venue, a former minister as placeholder and a QR image that encoded nothing.

### Fixed — invoices, credit notes and exports (audit 2026-09-15, section 5)

- **Seller.** Every invoice named a client's company, address and a VAT ID with an invalid check digit as seller; the item text named that client's summit. The seller now comes from new settings (name, street, postcode, city, country, VAT ID or tax number, e-mail, optional IBAN checked mod 97); without them no invoice or credit note is created and the settings tab says what is missing. Demo seeding records a warning instead of an invoice. The item text is "Teilnahme: <event title>".
- **XML.** The guideline ID is `urn:cen.eu:en16931:2017` (the old ID is rejected by validators, FX-SCH-A-000556); `ApplicableHeaderTradeDelivery` was missing (schema error) and now carries the event date as date of supply; amounts are computed in cents with the tax rounded once, so totals satisfy BR-CO-15 (net 45.50 at 19 % gave a total of 54.14); the buyer address no longer falls back to "Musterstraße 1"; SEPA payment means when an IBAN is set; only standard-rated VAT with a rate above 0 is written. Mustang-CLI 2.26.0 found 3 errors in the old XML and reports invoices at 19 % and 7 % and their credit notes as valid. No PDF is generated: the XML is not a ZUGFeRD/Factur-X hybrid file.
- **Credit notes** had no XML and took the next invoice number with "-STORNO" appended, which the next invoice then received as well. They now get their own number and a type 381 XML with positive amounts and a reference to the original invoice (BG-3); the row keeps negative amounts.
- **Numbering.** Numbers were `MAX(id) + invoice_counter + 1`, so deleting invoices (demo data, factory reset) issued numbers again. A counter in the option `cybevents_invoice_sequence`, advanced under a database lock, continues after the highest number already issued and is never lowered; factory reset and uninstall leave it in place.
- **DATEV export.** "Erzeugt am" is written as YYYYMMDDHHMMSSmmm (was a date), the fiscal year start comes from the export period (was the current year) and a period across two years is refused, the batch label is "Rechnungen <from>-<to>" (named a client), the file is converted to Windows-1252 (`utf8_decode()` is deprecated and wrote "?" for the euro sign), and text fields drop quotes and line breaks, are cut by characters and neutralise a leading formula character. Accounts and the BU key 3 are unchanged: whether a BU key may accompany an Automatikkonto is for the tax advisor (see the audit, section 9).
- **iCalendar.** Event and session times are stored in the site's time zone but were written as UTC, so entries moved by the site's offset; they are converted now, also in the Google and Outlook links of the calendar block. The session location was escaped twice, the description carried a literal "\n", and missing values were filled with "Halle (Saale)", a client's name as speaker and, in the block, a client's venue and a fixed "Europe/Berlin" label.
- **Dashboard.** Invoice count and revenue included the negative credit notes and left out the cancelled originals, so every cancellation was subtracted twice; they count standard invoices in force.
- **Removed** `includes/class-epc-qr.php`: the "GiroCode" drew three squares and the word "EPC" with a fake IBAN and encoded nothing; nothing used it since the ticket pass correction above.

### Fixed — texts, claims and client content in the code (audit 2026-09-15, 1.8, 6.3, 6.4)

- **Conformity statements removed.** The registration form showed badges "Offizielle Konferenz" and "DSGVO & BFSG 2025 Konform"; the trust block stated DSGVO, BFSG 2025 / BITV 2.0 / WCAG 2.2 AA with 48 px targets, BSI IT-Grundschutz, EN 16931 with GiroCode, credited a client's standards and showed a captcha card although no captcha widget ships; the admin dashboard showed a static "System Nominal · InnoDB Row-Locks Aktiv · BSI IT-Grundschutz" status, "10 Jahre Aufbewahrung", "Salted SHA-256", "ISO-8859-1 Konform" and "ZUGFeRD Validiert"; the handbook and help tabs promised PDF/A-3b embedding, Leitweg-ID, GiroCode, automatic anonymisation after a configurable retention period and a developer hook (`cybevents_registration_created`) the plugin never fires; the venue screen labelled a checkbox "Barrierefrei nach BFSG 2025" and said it activated an "official BFSG badge" nothing renders. Each text now says what the code does (the handbook lists the WP-CLI commands instead of the hook). Block descriptions, the plugin header and the high-contrast preset ("WCAG AAA", renamed after its measured 21:1 text contrast) follow.
- **Client content in real output.** Event JSON-LD named a client, with its URL, as organizer of every event, put every event in "Halle (Saale)" with a client's venue as fallback, stamped local times as UTC and set `validFrom` to the moment of rendering; it now uses the seller name from the settings as organizer (or none), the stored location (or none), the site's offset, and `SoldOut` when the capacity is reached. Location fallbacks ("Halle (Saale)" and two client venues) are gone from the form, hero, event cards, agenda and admin table; the hero badge defaulted to the client's name; form placeholders used the client's domain and name; the attendee CSV carried the client's name. Theme presets and demo content named after the client stayed until 2026-11-19 (see 1.1.7).
- **Promises the plugin does not keep.** "Ausgebucht (Warteliste)" without a waiting list, "Inkl. Catering & Tagungsunterlagen", "Kostenfrei & verbindlich anmelden" and "Jetzt kostenfrei anmelden" on paid events, "Wir haben Ihnen eine E-Mail … gesendet" before the queue sent anything, "transaktionssicher" and "InnoDB Row-Lock" on the session selector, "Ajustes y Pasarelas de Pago" for a plugin without a payment gateway, an .ics note about "24h & 2h" alarms (the file has one alarm a day before).
- **Form fields nobody stored.** Position, phone and dietary notes (possibly health data) could be enabled on the form, but the registration route never read them; they are no longer rendered (the block options are kept so saved blocks still parse). The hidden honeypot field was never checked; a filled one is now refused.
- **Venue accessibility.** A venue without the wheelchair flag counted as accessible in the admin list and in `GET /cybevents/v1/venues`, and the add form pre-checked the box; an unknown value is now "not specified".
- **Dashboard.** The quick guide and jump navigation were in Spanish (German admin); three of five jump links pointed to missing anchors; a capacity of 0 was displayed as 100 seats; the audit table header said UTC for local times. The hero countdown ran to the site's wall-clock time read as UTC.
- **Emoji replaced** in the frontend blocks, form, session selector, agenda and public script by inline SVG icons drawn for the plugin (`includes/class-icons.php`, aria-hidden, currentColor), and removed from admin labels. Calendar Views and the demo seeder still carry emoji and invented demo figures (decision pending).

### Fixed — block editor and the renderers behind it

- **Controls that changed nothing.** The editor offered settings no renderer reads: position, phone and dietary fields on the registration form; track filter buttons, track badges, a capacity bar and per-session export on the agenda; a display style, heading, description, overlap warnings ("Echtzeit-Konfliktwarnungen") and a seat counter on the session selector, whose renderer receives only the registration; and the captcha card toggle, whose card needs a widget the plugin does not ship. They are gone from the editor; the attributes stay in `block.json`, so saved blocks still parse. The calendar block read `showOffice365` but had no toggle for it; it has one now.
- **Style options without CSS.** About half of the style choices had no rule in `public/css/cybevents-public.css` and looked exactly like the default: all agenda layouts, the card styles of the speaker grid, calendar widget, ticket pass and events grid, the avatar styles (the sheet styles `circle-dark`, which no option sets), two form styles, two trust layouts and two trust themes. They are removed; the selects that kept a second working choice stay.
- **Events grid filter.** Events are `active`, `draft` or `archived`. "Alle Konferenzen (Inkl. Archiv)" asked for a status `completed` that no event carries and "Nur vergangene Konferenzen" fell through to the active events, so both showed only the active ones. "all" now shows active and archived events, "archived" the archived ones (newest first), with their own empty message; the labels say "archiviert" instead of "vergangen", since archiving is set by hand.
- **Editor texts.** Emoji (60) removed; labels and preview texts stated BITV 2.0 / BFSG 2025, BSI IT-Grundschutz, "DSGVO Art. 32 Siegel", ZUGFeRD 2.3, "Made in Germany", "Row-Level InnoDB Lock", an "SVG-QR Matrix Code", "24h/2h Erinnerungsalarm" and the client as organiser fallback, and "Behörden-Design" on a colour preset. They describe what the renderer outputs now, with the renderer's own defaults (the event title as form and pass heading, the ticket number where the QR label was). Preview counts use `sprintf` with translator comments.
- **Ticket pass default title** "OFFIZIELLER TEILNEHMER-PASS" is "TEILNEHMERPASS".
- **Privacy link.** Without a URL in the block, the consent on the registration form linked to no privacy policy. The WordPress privacy page (`get_privacy_policy_url()`, empty when none is published) is the default; a URL in the block still wins.

### Fixed — block.json defaults, CPT editors, demo post meta

- **The two sections above were incomplete for real blocks.** WordPress fills attributes that a block comment does not carry with the `block.json` default before the render callback runs, and 16 wording defaults there overrode the corrected PHP defaults. Measured on a local WordPress install with `render_block()` before this change: the trust block still printed "Souveräne Datensicherheit & Compliance" and a sentence crediting the client's standards; the hero of a paid event said "Jetzt kostenfrei anmelden" and carried a badge with the client's name; every ticket pass was headed with the demo conference's name and "OFFIZIELLER TEILNEHMER-PASS"; the registration button, the agenda heading ("parallele Tracks") and the speaker grid ("… Köpfe aus … KRITIS-Behörden") came from there too. The tests of those sections called the render callbacks without the defaults. All wording defaults are now empty, so the renderers compute them from the event (title, price) and translate them; blocks saved with the old defaults pick this up, text typed into a block stays.
- **Hero agenda button.** Its default target `#cybevents-agenda` matched no element; it points to `#cybevents-agenda-<event id>`, the id the agenda of the same event carries.
- **Event and session editors.** A new event was pre-filled with a client's venue, saved into the event unless someone cleared it; the event list showed "Halle (Saale)" for events without a location; the paid checkbox said "(ZUGFeRD E-Invoice)" although no invoice is created; a new session was pre-filled with a room and suggested a client's person as speaker. Now empty fields, "—" and neutral placeholders.
- **Demo post meta.** The seeder wrote `_cybevents_event_id`, `_cybevents_raum` and `_cybevents_kapazitaet`, the session editor reads `_cybevents_parent_event_id`, `_cybevents_room` and `_cybevents_capacity`: saving a demo session in the editor detached it from its event and reset room and capacity. The demo event lacked `_cybevents_ticket_price` and `_cybevents_is_paid` and became free on its first save. Demo data seeded before this change keeps the old keys.
- **Attendee modal.** Saving a registration whose status the form did not offer stored `confirmed`, and an unknown payment status stored `free`: the status select had no `bounced`, so opening and saving a bounced registration confirmed it. An unknown value now keeps the stored one, and the select offers "Unzustellbar (Bounced)".

### Tests

- `tests/test-suite.php`: 88 assertions (was 13; test 1 counted the mock's own `UPDATE` and test 8 updated a mock row, neither called plugin code). The harness mock refuses columns the schema does not have, fills column defaults, evaluates `LIKE`, `IN`, comparisons and `OR`, and throws on a condition it cannot evaluate instead of matching every row. A PHP warning, notice or deprecation counts as a failure, and an uncaught error still prints the results line. I11 and I12 read `blocks/editor.js` against the renderers and the frontend stylesheet, so a control without effect or a style option without a rule fails the suite; group J renders the blocks with their `block.json` defaults, as WordPress does.
- `tests/run-mutations.sh` with `tests/mutations.php`: 131 mutations, each undoing one correction; every one turns the suite red (control run first; a search string that no longer matches counts as a failure). The runner matches the results line with a here-string: `printf | grep -q` under `pipefail` misses the line when the output exceeds the pipe buffer (grep exits, printf gets SIGPIPE), which classified two red mutations as "no result" on CI; a self-check with 1 MB of output guards it.
- `tests/validate-invoice-xml.sh`: generates invoices and credit notes with the plugin's code (`tests/generate-invoice-samples.php`) and validates them with Mustang-CLI, pinned by SHA-256; it reports the old guideline ID as invalid.
- `tests/test-registration-flow.php` (19 checks) and `tests/test-invoicing-flow.php` (8 checks), `wp eval-file`, real MySQL: registration to erasure, and invoice, credit note, numbering, DATEV and iCalendar in Europe/Berlin, each with temporary fixtures and settings restored. Run on a local WordPress install on 2026-09-15: all pass, and a forced failure exits 1. The form, confirmation link, session selector and booking were also driven in a browser there.
- CI runs PHP 8.3 and 8.4 (the header requires 8.3; it ran 8.1 to 8.3), lints every PHP file, checks the ABSPATH guards, requires the results line, runs the mutations and validates the invoice XML.

### Design family (hackaton phase 3, 2026-09-14)

- The seven admin screens and the dashboard widget render from the two WM Suite family sheets (`assets/css/wm-admin-tokens.css`, `assets/css/wm-admin-ui.css`, byte-identical copies of the hub's canonical files, pinned by `tests/test-ui-family.php`) plus the plugin's own layer, which moved from `admin/css/` to `assets/css/cybevents-admin.css` so the family gate measures it. Before this the layer styled the banner, the tabs and the quick guide only: of the 116 `cybevents-*` classes the templates use, 8 had a rule. The quick-edit, attendee, DSGVO-export and XML modals rendered inline at the end of the page (`position: static`), the telemetry grid was a column, tables, badges, capacity meters, the donut, the filter bar, the settings tabs, the theme preset cards, the demo-data boxes and the handbook cards were unstyled. Every colour is a token, no focus suppression.
- Family header with the hub/standalone badge on every screen, family menu icon, native `.wrap.wm-admin`, `aria-current` on the active tab, dashicons instead of emoji (tabs, jump links, pills, quick actions, row actions, icon-only buttons now carry a text name, modal close glyphs, section headings, demo boxes, plugin action link). Inline hex styles in the dashboard, registrations, settings and handbook screens moved to tokenised classes; the sparkline and donut SVGs take their colours from tokens.
- **Invented telemetry removed:** the "Atomic Transaction Rate" card printed a fixed `145.8 ops/sek`, `+2.1%`, `42ms` and `0 Race Conditions`; the Brevo card a fixed `Zustellrate: 99.2%` and fell back to `89450` / `1180` when the queue was empty; the donut's three arcs were fixed at 94 / 4 / 2 % whatever the invoices; two sparklines were static curves. The card now counts registrations of the last 24 hours and sessions, the queue card shows real queue figures, the donut arcs come from issued / cancelled / pending counts, and the widget's third KPI is the pending DOI count instead of the static text "DATEV / EXTF 700".
- The audit-log screen closed the page wrapper right after the integrity notice (two stray closing tags), so the log table rendered outside the page. Fixed. `.gitignore` carries the `assets/` negation; the README banner in `assets/images/` was never committed (global ignore rule of the development machine).
- Modals move keyboard focus into the dialog on open and back to the opener on close (Escape included); before, focus stayed on the page behind the overlay. The ZUGFeRD XML viewer carries `role="dialog"` and a labelled title like the other three. The avatar preview in the attendee modal no longer stretches into an ellipse. The public sheet replaces its four `outline: none` with a transparent outline plus a visible focus ring (the ticket input had no focus style at all), so forced-colors mode keeps a focus indicator. Measured on a local WordPress install (WordPress 7.1, Playwright): sheets served from `assets/css/` with `filemtime` versions, modal `position: fixed`, telemetry grid 4 columns, donut arcs 175.9 / 41.4 / 20.7 of 238 from live counts, 0 inline hex styles, 0 emoji, 0 PHP notices on all seven screens and the widget. Captures in `docs/screenshots/design-family/`.

### Fixed

- Plugin Check (dist view, 2026-09-14): 11 → 2 errors, the two licence codes (header and readme) that wait for a decision. Translators comments and ordered placeholders on the demo-data notices and the legacy-audit note; the avatar upload goes through `wp_handle_upload()` into the protected `cybevents-avatars` directory (was `move_uploaded_file()` + `chmod()`); the custom theme CSS is removed with `wp_delete_file()`; the CSV export's `php://output` close carries its reason.

---

## [1.0.0] — 2026-08-25

### Initial Enterprise Release

- Core architectural implementation for Enterprise Events & Billing.
- WP-CLI management commands under `wp cybevents`.
- Revisionssicheres GoBD cryptographic ledger and telemetry hooks.
- Strict compliance with Wachstumschancengesetz 2025 (E-Rechnungspflicht), EN 16931-1:2017, § 14 UStG, GoBD & BFSG 2025.

---

## Appendix — historical draft written on 2026-08-29 before the real 1.1.0 release

> **Historical record of the 2026-08-29 draft (superseded by the actual 1.1.0 release on 2026-11-18 above).** Kept as the record of what was written on 2026-08-29.

### Added

- **24 Official European Union Languages:** Full binary `.mo` and source `.po` translation packages in `languages/` (`bg_BG`, `cs_CZ`, `da_DK`, `de_DE`, `el_GR`, `en_GB`, `es_ES`, `et_EE`, `fi_FI`, `fr_FR`, `ga_IE`, `hr_HR`, `hu_HU`, `it_IT`, `lt_LT`, `lv_LV`, `mt_MT`, `nl_NL`, `pl_PL`, `pt_PT`, `ro_RO`, `sk_SK`, `sl_SI`, `sv_SE`).
- **Developer Documentation Suite:** Created `DEVELOPERS.md` with complete architecture diagrams, hook references, database schema, and REST API guides.
- **Decoupled REST API Controller:** Registered namespace `/wp-json/wm-events/v1` with strict nonce and permission validation.
- **Zero-CDN Frontend Shortcode:** Implemented `wm_cyber_calendar` with inline SVG icons and CSS design tokens.
- **CycloneDX v1.6 SBOM Engine:** Dynamic SHA-256 entry file integrity hashing and spoke adapter integration for **WM Suite Hub**.
- **Enterprise Showcase Assets:** High-fidelity 16:9 marketing banner and cockpit showcase mockups conditioned on real screenshot topology.
- **Clean Uninstallation Lifecycle:** Comprehensive `uninstall.php` handler for clean database and transient cache purging.

### Security

- **CWE-1236 Mitigation:** Active sanitization against formula injection attacks on all CSV exports and dynamic text inputs.
- **Constant-Time Cryptography:** Enforced `hash_equals()` verification on all HMAC-SHA256 signature chains.
- **SQL Parameterization:** Refactored all queries to use strict `$wpdb->prepare()` with parameterized placeholders.

### Quality & Tests

- Expanded standalone deterministic test suite (`tests/test-suite.php`) with full green pass rate and zero external dependencies.
