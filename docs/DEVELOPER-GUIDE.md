# Developer Guide — WM Cyber Events Core

The developer documentation lives in two files, both measured against the code on 2026-09-15:

- **[DEVELOPERS.md](../DEVELOPERS.md)** — layout, tables and options, REST routes, shortcodes and blocks, cron, WP-CLI, the hooks the plugin attaches to, the hub adapter, known drift and the tests.
- **[ARCHITECTURE.md](ARCHITECTURE.md)** — how registration, confirmation, booking, check-in, cancellation, invoices and the audit chain work together.

This file used to be a "master guide" of its own. It described classes that do not exist (`WM_Cyber_Events_Core`, `WM_Cyber_Events_Invoice_Manager` and seven more), methods such as `reserve_seat()`, `generate_pdf_invoice()` and `export_extf_csv()`, the routes `/confirm-doi`, `/event-status` and `/refresh-nonce`, ZUGFeRD 2.2 hybrid PDF/A-3 invoices with a Leitweg-ID, Levenshtein and Soundex matching of attendees, a 24-hour DOI token expiry and `ISO-8859-1` DATEV files. None of that is in the code; the content was removed rather than kept next to the measured manual.
