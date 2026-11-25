# Blocks — WM Cyber Events Core

> Measured against `blocks/*/block.json`, `blocks/editor.js` and the render callbacks on 2026-09-15. The previous edition listed style values without CSS, a QR code, overlap warnings, track filters and conformity labels (BFSG 2025, BITV 2.0, BSI IT-Grundschutz, ZUGFeRD 2.3) that the blocks never rendered.

All blocks are dynamic (`save` returns `null`): the markup comes from PHP on every page view. They sit in the editor category "Cyber Events Suite". Editor controls and preview cards live in `blocks/editor.js`; the suite's tests I11 and I12 fail when a control changes nothing the renderer reads or a style option has no rule in `public/css/cybevents-public.css`.

**Defaults.** WordPress passes `block.json` defaults to the render callback for attributes a saved block does not carry. Wording defaults (headings, button labels, badges) are therefore empty in `block.json`: the renderer fills them from the event and translates them. An attribute listed under "kept for saved blocks" is not read; it stays in `block.json` so that older content still parses.

---

## `wm-cyber-events/event-hero`

Header section of one event (`eventId`, or the newest active event).

| Attribute | Effect |
| --- | --- |
| `presetStyle` | `dark-enterprise` (default), `federal-blue`, `cyber-glow`, `minimal-light`, `glassmorphism` |
| `borderRadius` | `none`, `md`, `lg` (default), `xl` |
| `enableGlow` | glow class |
| `badgeText` | badge above the title; none when empty |
| `customHeading`, `customLead` | replace event title and description |
| `showStatusPill` | "Anmeldung geöffnet" / "Ausgebucht" from confirmed registrations against capacity |
| `showCountdown` | countdown to the event start (site time converted to UTC); hidden once the event started |
| `showCapacityPill`, `showLocation`, `showDateTime`, `showPrice` | facts row (free seats only with a capacity above 0; location only when stored) |
| `ctaText`, `ctaUrl` | primary button; default text "Jetzt anmelden" for a paid event, "Jetzt kostenfrei anmelden" for a free one; default target `#cybevents-registration-form` |
| `showSecondaryCta`, `secondaryCtaText`, `secondaryCtaUrl` | second button; default "Programm ansehen" to `#cybevents-agenda-<event id>` |
| `showCalendarButton` | link to the event's `.ics` |

## `wm-cyber-events/registration-form`

Form of one active event (also `[cybevents_registration event_id="…"]`).

| Attribute | Effect |
| --- | --- |
| `formStyle` | `card-glass` (default), `clean-white` |
| `borderRadius` | `md`, `lg` (default), `xl` |
| `customTitle`, `customSubtitle` | default: event title, no subtitle |
| `showKeyFacts` | header with date, location (when stored) and admission note |
| `showStepper` | three-step indicator |
| `showCompanyField`, `showPhotoConsent` | optional company field, photo consent |
| `buttonText` | default "Verbindlich anmelden" (paid) or "Kostenfrei anmelden" (free) |
| `privacyPolicyUrl` | link next to the privacy consent; default: the WordPress privacy page, none when none is published |

Always rendered: first name, last name, e-mail, profile photo upload, privacy consent (required), hidden honeypot. Kept for saved blocks: `showJobTitle`, `showPhoneField`, `showDietary` (the route never stored these fields).

## `wm-cyber-events/agenda-matrix`

Sessions of one event in time order, followed by the event's JSON-LD (`BusinessEvent` with `subEvent`s; organizer from the seller settings, location only when stored). Also `[cybevents_agenda]`. The wrapper carries `id="cybevents-agenda-<event id>"`.

| Attribute | Effect |
| --- | --- |
| `heading`, `subheading` | default: event title, no subheading |
| `showSearchBar` | text filter over the session cards |
| `showRoomInfo`, `showSpeakerAvatars` | room tag; speaker initials |
| `showExportButton` | link to the event's `.ics` |

Kept for saved blocks: `showFilterButtons`, `showTrackBadges`, `showSessionExport`, `showCapacityProgress` (never rendered); `layoutStyle` is turned into a class without CSS and has no editor control.

## `wm-cyber-events/speaker-grid`

One card per session with a speaker.

| Attribute | Effect |
| --- | --- |
| `columns` | 2–6 |
| `heading`, `subheading`, `badgeText` | defaults "Referenten & Fachexperten", none, "Referenten" |
| `showTopics`, `showRoom`, `showTime` | session title, room, start time |

`cardStyle` and `avatarStyle` are read but only their defaults have CSS, so the editor offers no control for them.

## `wm-cyber-events/calendar-widget`

Date badge, time span, location, time zone and calendar actions of one event.

| Attribute | Effect |
| --- | --- |
| `customTitle` | default: event title |
| `showAppleIcs` | `.ics` download |
| `showGoogleCal`, `showOutlookWeb`, `showOffice365` | links with UTC times |
| `showTimezone` | the site's time zone string |
| `showReminderNotice` | "Die .ics-Datei enthält eine Erinnerung einen Tag vor Beginn." |

`cardStyle` is read but has no CSS beyond the default; no editor control.

## `wm-cyber-events/session-selector`

No settings. Renders the session selection for the registration of `?token=` (also `[cybevents_session_selector]`): a notice without a token, an error for an unknown token. The confirmation link leads to the first published page containing this block or shortcode. Kept for saved blocks: `eventId`, `style`, `heading`, `description`, `showConflictAlerts`, `showCapacityCounter` (the renderer receives only the registration).

## `wm-cyber-events/seat-map`

Occupancy of every session of the event as an SVG — one cell per seat, one row per session — with a
legend, a table carrying the same numbers for screen readers, and a live connection to
`GET /cybevents/v1/seat-stream` (Server-Sent Events). Also `[cybevents_seat_map event_id="…"]`.

| Attribute | Effect |
| --- | --- |
| `eventId` | event to draw; 0 takes the first active event |
| `heading` | heading above the map; default: the event title |
| `description` | line under the heading; omitted when empty |
| `live` | `false` draws the occupancy at page load and opens no stream |

A session larger than 400 seats is drawn as one bar instead of 400 rectangles. With `?token=` of a
confirmed registration the rows that participant holds are marked and clicking one books or releases
a seat through `POST /book-sessions`; the token is read from the URL and never written into the
markup. Without such a token the map is read-only.

## `wm-cyber-events/ticket-pass-verifier`

Without `?token=` a token entry form; with a token of a confirmed registration the pass; otherwise a notice.

| Attribute | Effect |
| --- | --- |
| `passTitle` | default "TEILNEHMERPASS" |
| `organizationName` | header line; default: event title |
| `showCheckinStatus` | "Bestätigt" / "Eingecheckt" |
| `showInvoiceRef` | invoice number or "Keine" |
| `showQrMatrix` | ticket number (the attribute name is historical; there is no QR code) |
| `showPrintButton` | `window.print()` button |

`cardStyle` is read but has no CSS beyond the default; no editor control.

## `wm-cyber-events/security-trust-badge`

Cards that describe what the plugin does; it makes no conformity statement.

| Attribute | Effect |
| --- | --- |
| `heading`, `subheading` | default "Datenschutz und Sicherheit", none |
| `layout` | `grid-4-cols` (default), `grid-2-cols` |
| `theme` | `dark-glow` (default), `federal-blue` |
| `showDsgvo` | "Double Opt-in und Löschfristen" |
| `showBitv` | "Beschriftete Formulare" |
| `showCdnPolicy` | "Eigene Skripte und Styles" |
| `showGrundschutz` | "Audit-Log und Tokens" |
| `showZugferd` | "Rechnungs-XML und DATEV" |
| `showFriendlyCaptcha` | captcha card, rendered only when a captcha widget exists (never, today); no editor control |

## `wm-cyber-events/events-grid`

| Attribute | Effect |
| --- | --- |
| `limit` | 1–24 |
| `columns` | 1–4 |
| `filterStatus` | `active` (default), `all` (active and archived), `archived` (newest first) |
| `showSearch` | text filter over the cards |
| `showDateBadge`, `showPriceTag`, `showLocation`, `showDescription` | card contents (location only when stored) |
| `showCalendarQuickLink` | `.ics` link |
| `ctaButtonText` | default "Zur Anmeldung"; the button links to `#cybevents-registration-form` on the same page for every card |

`cardStyle` is read but has no CSS beyond the default; no editor control.

## `wm-cyber-events/calendar-views`

Rendered by `CyberEvents_Calendar_Views::render_shortcode()` (also `[cybevents_calendar]`, `[cybevents_calendar_views]`, `[wm_events_views]`) with `defaultView`, `showToolbar`, `showFilter`, `showSearch`, `showTicker`, `theme`, `eventId`, `limit` and `month` (optional `YYYY-MM`, empty default so stored active events determine the initial month or current month when empty; overridable via `?cyb_month=YYYY-MM`). Registered in `blocks/editor.js` with `InspectorControls` and `ServerSideRender` (`editorScript: cybevents-blocks-editor-js`). All 7 views (`month`, `week`, `day`, `list`, `photo`, `map`, `tracks`) and the ticker read active events and their sessions dynamically from `wp_cybevents_events` and `wp_cybevents_sessions`, showing clean empty states when no records exist.
