# WM Cyber Events Core — Developer Manual

> Everything below is measured against the code in this repository (2026-09-15). The previous edition of this file documented a REST namespace `wm-events/v1` with `/status`, `/submit` and `/export`, a hook `wm_cybevents_ticket_booked` and a `wm_suite_hub_register_spoke` registration; none of those existed.

---

## 1. Layout

| Path | Role |
| --- | --- |
| `wm-cyber-events-core.php` | Bootstrap: constants, requires, activation and deactivation hooks, `cron_schedules` filter, hub registration, `init` (schema migration, text domain, post types, taxonomy, blocks, privacy tools, cron handlers, missing cron events, Block Bindings, GenerateBlocks Dynamic Tag, `wm_cybevents_ready` action), Abilities API, REST routes, admin hooks, asset enqueueing, shortcodes `cybevents_registration`, `cybevents_session_selector`, `cybevents_agenda`, `cybevents_seat_map`, template loader. |
| `includes/class-activator.php` | `activate()` (schema, default settings, cron events; demo data opt-in), `migrate()` (schema and cron only), `register_cron_schedules()` (`every_minute`), `ensure_cron_events()`, `create_schema()` (7 tables, `DB_VERSION` 3). |
| `includes/class-deactivator.php` | Clears both cron events. |
| `includes/class-database.php` | `book_session_atomic()`, `cancel_session_atomic()`, `get_registration_by_token()`, `get_next_invoice_number()` (option counter under `GET_LOCK`), `handle_avatar_upload()`, `create_registration()`, `confirm_doi_registration()`, audit chain (`log_audit()`, `verify_audit_chain()`, `pseudonymize_audit_entry()`, residual MAC), event and session getters. |
| `includes/class-seat-map.php` | `CyberEvents_Seat_Map`: occupancy snapshot and digest, the SVG, and the Server-Sent Events stream `/seat-stream`. |
| `includes/class-waiting-room.php` | `CyberEvents_Waiting_Room`: admissions per minute in front of `/register` and `/book-sessions`, counted in a persistent object cache or, without one, in transients. |
| `includes/class-rest-api.php` | Routes (§3), rate limit, waiting room, admin notices for missing Brevo key, webhook secret and captcha widget. |
| `includes/class-queue-runner.php` | `push()`, `process_batch()`: claims pending jobs, handles `brevo_doi` (and the old `brevo_send_doi`) and `brevo_update_attributes`, five attempts with exponential backoff, stale `processing` jobs handed back after 15 minutes, payload reduced to the registration id when done. |
| `includes/class-brevo-api.php` | `send_double_optin()` (`/v3/contacts/doubleOptinConfirmation`), `update_contact_attributes()`, `is_configured()`; errors without an API key. |
| `includes/class-accessibility.php` | `render_registration_form()`; captcha helpers (`captcha_widget_available()` returns false). |
| `includes/class-events-manager.php` | `cybevent` post type, meta box, list columns, row action, post-to-table sync, `render_agenda()`, `render_schema_json_ld()`. |
| `includes/class-sessions-manager.php` | `cybsession` post type, meta box, list columns, post-to-table sync, `render_session_selection_ui()`. |
| `includes/class-venues-manager.php` | `cybvenue` taxonomy, term meta fields and columns, `get_all_venues()`. |
| `includes/class-blocks-manager.php` | Block category and registration of all 11 blocks, editor script/style handles and JED script translations, render callbacks of ten blocks. |
| `includes/class-calendar-views.php` | Calendar Views shortcodes and block renderer (7 dynamic views over active events and sessions). |
| `includes/class-calendar-exporter.php` | `generate_ics()`, `site_time_to_timestamp()`, line folding and escaping. |
| `includes/class-invoicing.php` | `seller()`, `seller_problems()`, `iban_is_valid()`, `amounts_in_cents()`, `create_invoice()`, `cancel_invoice()`, `generate_zugferd_xml()` (CII XML, EN 16931). |
| `includes/class-datev-exporter.php` | `generate_export()` (EXTF 700, category 21), `text_field()`, `to_windows_1252()`. |
| `includes/class-csv-importer.php` | `import_csv()`. |
| `includes/class-gdpr-manager.php` | Privacy exporter and eraser, daily retention (`purge_expired_pending_doi()`, `rotate_audit_logs()`), `delete_registration()`, `release_sessions()`, `cancel_registration_self_service()`. |
| `includes/class-theme-manager.php` | Presets, generated CSS variables, uploaded theme file, `template_loader()`. |
| `includes/class-seeder.php` | Demo data: `seed_demo_data()`, `delete_demo_data()`, `reset_to_factory_defaults()`. |
| `includes/class-admin.php` | Menu and screens, export and form handlers on `admin_init`, dashboard widget, help tabs, handbook, ability registration. |
| `includes/class-cli.php` | `wp cybevents` commands. |
| `includes/class-icons.php` | Inline SVG icons (aria-hidden, `currentColor`). |
| `includes/class-cyber-events-spoke-adapter.php` | WM Suite Hub contract (loaded only when the hub's interface exists). |
| `blocks/*/block.json`, `blocks/editor.js`, `blocks/editor.css` | Block metadata, editor controls and preview cards. |
| `public/js/cybevents-public.js`, `public/css/cybevents-public.css` | Registration form, session selector, countdown, Calendar Views handlers; frontend styles. |
| `public/js/cybevents-seatmap.js` | Seat map: EventSource on `/seat-stream` (polling fallback), repaint from the snapshot, booking through `/book-sessions`; reads the token from the URL. |
| `public/js/friendly-captcha.min.js` | Stub without a widget; enqueued only when a widget exists (never, today). |
| `admin/js/cybevents-admin.js`, `assets/css/` | Admin script; family sheets `wm-admin-tokens.css`, `wm-admin-ui.css` (byte-identical to the hub) and the plugin layer `cybevents-admin.css`. |
| `languages/` | `.pot` and 28 precompiled `.po`/`.mo`/JED `.json` catalogs. |
| `uninstall.php` | Opt-in uninstaller (`D-UNINSTALL`): preserves all tables and options by default; drops the 7 tables, deletes options and clears cron events only when `wm_cybevents_delete_data_on_uninstall` is enabled. |

---

## 2. Data

| Table | Written by | Notes |
| --- | --- | --- |
| `wp_cybevents_events` | event sync, dashboard quick edit, seeder | `status` `active`, `draft`, `archived`; times in site time |
| `wp_cybevents_sessions` | session sync, seeder | `kapazitaet`, `belegt` (changed only by the atomic booking functions) |
| `wp_cybevents_registrations` | `create_registration()`, CSV import, admin dialog | `token_hash`, `doi_token_hash` (SHA-256), `status` `pending_doi`, `confirmed`, `cancelled`, `bounced`; unique `(event_id, email)` |
| `wp_cybevents_registration_sessions` | `book_session_atomic()` | unique `(registration_id, session_id)` |
| `wp_cybevents_invoices` | `create_invoice()`, `cancel_invoice()` | `invoice_type` `standard` or `storno` (credit note), `status` `issued` or `cancelled`, `zugferd_xml`, `storno_reference_id` |
| `wp_cybevents_queue` | `CyberEvents_Queue_Runner::push()` | `pending`, `processing`, `completed`, `failed` |
| `wp_cybevents_audit_log` | `log_audit()` | `content_digest`, `prev_hash`, `entry_hash`, `pseudonymized_at`, `residual_mac` |

Options: `cybevents_settings` (keys in [README.md](README.md#options)), `cybevents_invoice_sequence`, `cybevents_audit_chain_tip`, `cybevents_db_version`. Post meta of events: `_cybevents_location`, `_cybevents_start_date`, `_cybevents_end_date`, `_cybevents_max_capacity`, `_cybevents_ticket_price`, `_cybevents_is_paid`; of sessions: `_cybevents_parent_event_id`, `_cybevents_speaker`, `_cybevents_room`, `_cybevents_start_time`, `_cybevents_end_time`, `_cybevents_capacity`. Uploads: `cybevents-avatars/` (profile photos), `cybevents-themes/custom-theme.css`.

---

## 3. REST routes (`/wp-json/cybevents/v1`)

| Method | Route | Permission | Does |
| --- | --- | --- | --- |
| `POST` | `/register` | public, waiting room then 15/min per IP | Validates, stores a `pending_doi` registration, queues the Brevo DOI job |
| `GET` | `/confirm?doi=…&token=…` | public | Confirms once, redirects to the session selector page (participant token only if it belongs to the registration) |
| `GET` | `/session-selection?token=…` | public, confirmed only | `success`, `participant.first_name/last_name/status`, `sessions[]` with `verfuegbar`, `booked_sessions` |
| `POST` | `/book-sessions` | public, waiting room, confirmed only | Books new sessions first, releases deselected ones; 409 with `errors[]` when a seat is not available; 503 with `Retry-After` when the admission limit for this minute is used up, before any query runs |
| `POST` | `/checkin` | `manage_options` or `X-CyberEvents-Staff-Key` | Confirmed only; decided in the `WHERE` of one `UPDATE`; 409 on a second scan |
| `GET` | `/calendar?event_id=…` or `?token=…` | public | `.ics` download |
| `POST` | `/cancel-registration` | public (token) | Self-service cancellation |
| `POST` | `/upload-avatar` | token, or `manage_options` with `registration_id` | Replaces the profile photo |
| `GET` | `/ticket?token=…` | public, confirmed only | Pass data, `Cache-Control: private, no-store, max-age=0` |
| `GET` | `/seat-stream?event_id=…` | public | Server-Sent Events with the occupancy of every session; writes only when the digest changed, keep-alive every 10 s, closes after 30 s with `retry`. Occupancy numbers only — no names, no tokens |
| `GET` | `/venues` | public | Venue list with metadata |
| `POST` | `/brevo-webhook` | `X-Brevo-Webhook-Secret` equal to the stored secret; 401 without one | Bounces, spam and unsubscriptions mark registrations `bounced` and release seats |

---

## 4. Shortcodes and blocks

| Shortcode | Renders |
| --- | --- |
| `[cybevents_registration event_id="…"]` | Registration form |
| `[cybevents_session_selector]` | Session selector for `?token=` |
| `[cybevents_seat_map event_id="…" heading="…" description="…" live="1"]` | Seat map (SVG plus live stream) |
| `[cybevents_agenda event_id="…"]` | Agenda (also `eventId`, `heading`, `subheading`, `showSearchBar`, `showRoomInfo`, `showSpeakerAvatars`, `showExportButton`) |
| `[cybevents_calendar]`, `[cybevents_calendar_views]`, `[wm_events_views]` | Calendar Views (dynamic, optional `month="YYYY-MM"`) |

Blocks (`wm-cyber-events/*`, category "Cyber Events Suite"): `event-hero`, `registration-form`, `agenda-matrix`, `speaker-grid`, `calendar-widget`, `session-selector`, `seat-map`, `ticket-pass-verifier`, `security-trust-badge`, `events-grid`, `calendar-views`. All are dynamic (`save` returns null). Attributes, what each renderer does with them and what stays in `block.json` for saved blocks only: [docs/GUTENBERG-BLOCKS.md](docs/GUTENBERG-BLOCKS.md).

Wording defaults in `block.json` are empty on purpose: WordPress passes block.json defaults to the render callback, and a wording default there overrides the renderer's own, event-based and translated default (tests J1, J2).

---

## 5. Cron, CLI, abilities

- **Cron:** `cybevents_process_queue_cron` every minute (recurrence `every_minute`, registered at file load), `cybevents_gdpr_retention_cron` daily. Scheduled on activation and on `init` when missing; cleared on deactivation and uninstall.
- **WP-CLI:** `wp cybevents list`, `wp cybevents queue-run`, `wp cybevents verify-audit` (non-zero exit on a broken chain), `wp cybevents admission [--event=<id>] [--reset]`, `wp cybevents demodaten --import|--delete|--reset` (`--reset` asks for confirmation).
- **Abilities API:** category `cybevents-events`, ability `cybevents/manage-registrations` (permission `edit_posts`); its callback returns `array( 'status' => 'success' )` and nothing else.

---

## 6. Hooks the plugin attaches to

| Hook | Where | Effect |
| --- | --- | --- |
| `init` | main file | migration, text domain, post types, taxonomy, Calendar Views shortcodes, blocks, privacy tools, cron handlers, missing cron events |
| `cron_schedules` | main file | `every_minute` |
| `rest_api_init` | `CyberEvents_REST_API` | routes |
| `wp_enqueue_scripts`, `admin_enqueue_scripts`, `enqueue_block_editor_assets` | main file, blocks manager | public, admin and editor assets |
| `admin_menu`, `admin_init`, `admin_notices`, `current_screen`, `wp_dashboard_setup`, `parent_file`, `submenu_file` | admin | screens, handlers, notices, help, widget, menu highlight |
| `add_meta_boxes`, `save_post`, `manage_*_posts_columns`, `manage_*_posts_custom_column`, `post_row_actions` | events and sessions managers | editors, sync, list columns |
| `cybvenue_add_form_fields`, `cybvenue_edit_form_fields`, `created_cybvenue`, `edited_cybvenue`, `manage_edit-cybvenue_columns`, `manage_cybvenue_custom_column` | venues manager | venue fields and columns |
| `wp_privacy_personal_data_exporters`, `wp_privacy_personal_data_erasers` | GDPR manager | privacy tools |
| `block_categories_all` | blocks manager | category |
| `template_include` | theme manager | theme template overrides |
| `wp_abilities_api_categories_init`, `wp_abilities_api_init` | admin | ability |
| `wm_register_suite_module` | main file | hub spoke |
| `plugin_action_links_*`, `plugin_row_meta` | main file | Dashboard, Handbuch, documentation and GitHub links |

**Filters, actions, and ecosystem bridges the plugin offers:**

- `do_action( 'wm_cybevents_ready', string $version )` — fired at the end of `cybevents_init()`.
- `apply_filters( 'wm_cybevents_metric_value', string $value, string $metric, array $args )` — filters resolved metric values (`active_events`, `confirmed_registrations`, `checked_in`, `pending_doi`, `sessions_total`, `event_title`, `event_capacity`, `event_free_seats`).
- `apply_filters( 'wm_cybevents_public_rest_rate_limit', int $limit, ?WP_REST_Request $request )` — filters the per-minute blinded-IP rate limit on public REST endpoints (default `60`).
- `apply_filters( 'wm_cybevents_ics_output', string $ics_content, object $event )` — filters exported `.ics` content before download.
- WordPress 6.5+ Block Bindings source `wm-cybevents/metrics` (`key`, optional `event_id`).
- GenerateBlocks 2.x Dynamic Tag `wm_cybevents_metric` (`key`, optional `event_id`).

---

## 7. Flows

**Registration.** Form → `POST /register` → `create_registration()` (`pending_doi`, two token hashes, audit entry without the address) → `CyberEvents_Queue_Runner::push( 'brevo_doi' )` with the confirmation URL → cron or `wp cybevents queue-run` → `send_double_optin()` → Brevo mail → `GET /confirm` → `confirm_doi_registration()` (only `pending_doi`, spends the DOI token) → redirect to the session selector page with `?token=`.

**Booking.** Selector → `GET /session-selection` → `POST /book-sessions` → `CyberEvents_Waiting_Room::admit()` (refuses with 503 and `Retry-After` before the token lookup when the minute's admissions are used up) → `book_session_atomic()` per new session (idempotent; `UPDATE … WHERE belegt < kapazitaet`; `true` or `WP_Error`, never false) → on a refusal the seats taken in this request are released → `cancel_session_atomic()` for deselected sessions.

**Cancellation and bounces.** `cancel_registration_self_service()` and the webhook release seats through `cancel_session_atomic()`; the retention purge, the eraser and the admin delete go through `delete_registration()`, which also deletes the photo.

**Invoices.** `create_invoice()` refuses without complete seller data or with a tax rate of 0 or less; `cancel_invoice()` writes a credit note with its own number, type 381 and a reference to the original, marks the original cancelled and stores negative amounts in the row. Callers today: the seeder and self-service cancellation.

---

## 8. WM Suite Hub spoke

`CyberEvents_Spoke_Adapter` (only when `ArnoldWender\SuiteHub\Contracts\WM_Plugin_Module_Interface` exists): slug `wm-cyber-events-core`, version from `CYBEREVENTS_VERSION`, health `ERROR` / `NEEDS_CONFIG` / `DEGRADED` / `HEALTHY` as in the README, SBOM component with the SHA-256 of the main file, the licence as the SPDX id `GPL-2.0-or-later` alone (until 2026-11-05 `Proprietary`, with an id and a name) and feature properties, settings URL `admin.php?page=cybevents-settings`, telemetry `total_events`, `total_sessions`, `total_registrations`, `pending_doi`, `pending_queue_tasks`, `failed_queue_tasks`, `queue_event_scheduled`, `brevo_configured`, `audit_key_source`, `admission_limit`, `admission_backend`, actions `self_test` and `audit_verify`.

---

## 9. Known drift (documented, not fixed)

1. Optional `[Demo]` seeding (*Demo-Daten auffrischen* on the dashboard) appends another fictional demo copy when triggered explicitly; activation itself does not seed demo data.
2. The registration flow creates no invoice for paid events (open decision).
3. Post types use `capability_type` `post`; the session sync ignores the post status; the event sync maps `archived` back to `active`/`draft`.
4. Deleting an event on the dashboard leaves its sessions, registrations and invoices.
5. CSV-imported participants have no usable token.
6. The ability `cybevents/manage-registrations` returns no data.
7. `public/js/friendly-captcha.min.js` renders no widget.
8. DATEV rows carry tax key 3 whatever the rate; accounts and keys need a tax adviser.

---

## 10. Tests

```bash
php tests/test-suite.php          # 154 assertions, standalone harness (tests/bootstrap-harness.php)
bash tests/run-mutations.sh       # 215 mutations from tests/mutations.php, control run first
bash tests/validate-invoice-xml.sh   # Mustang-CLI 2.26.0 (pinned SHA-256) on generated invoices and credit notes
# In a WordPress install:
wp eval-file wp-content/plugins/wm-cyber-events-core/tests/test-registration-flow.php --allow-root   # 19 checks
wp eval-file wp-content/plugins/wm-cyber-events-core/tests/test-invoicing-flow.php --allow-root      # 8 checks
```

The harness mock of `$wpdb` knows the real schema, refuses unknown columns and conditions, and counts PHP warnings as failures. CI (`.github/workflows/ci.yml`) runs PHP 8.3 and 8.4: lint, `ABSPATH` guards, the suite (results line required), the family test, the mutations, the invoice XML validation and the security audit. The other files in `tests/` (`test-audit-chain.php`, `test-concurrency.php`, `test-csv-dedup.php`, `test-deep-features.php`, `test-i18n.php`, `test-rest-booking.php`, `test-security.php`, `test-theme-manager.php`, `test-zugferd.php`, `k6-load-test.js`) are older scripts; they are not run in CI and were not re-verified on 2026-09-15.

---

## 11. Security disclosure

See [SECURITY.md](SECURITY.md).
