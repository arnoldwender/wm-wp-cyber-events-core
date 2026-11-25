# Contributing to WM Cyber Events Core

Thank you for your interest in contributing to **WM Cyber Events Core**. Read [README.md](README.md) and [DEVELOPERS.md](DEVELOPERS.md) first: they describe what the code does and list the known issues and open decisions.

---

## Zero Support & Issue Scope

**Zero technical, configuration, or private support is provided.** See [SUPPORT.md](SUPPORT.md):

- Report reproducible code defects **exclusively** via public [GitHub Issues](https://github.com/arnoldwender/wm-wp-cyber-events-core/issues).
- **Do not** contact the author privately via email, `arnoldwender.com`, or direct messages — private inquiries are never answered.
- Issues and pull requests carry no response or maintenance SLA, and the software is provided strictly **"AS IS"** without warranty or liability.

---

## Development standards

1. **PHP 8.3+** and the WordPress Coding Standards. Every PHP file starts with `declare(strict_types=1);` and an `ABSPATH` (or `WP_UNINSTALL_PLUGIN` / `WP_CLI`) guard.
2. **No external resources** at runtime: no CDN, no remote fonts, no third-party telemetry. Scripts, styles and icons are served from the plugin.
3. **Seats** change only through `CyberEvents_Database::book_session_atomic()` and `cancel_session_atomic()`; the capacity check stays in the `WHERE` of one `UPDATE`.
4. **Audit log:** write through `CyberEvents_Database::log_audit( $event_type, $object_id, $user_id, $details )`; no e-mail addresses or request payloads in the details.
5. **CSV output** with user-supplied strings goes through `CyberEvents_Admin::sanitize_csv_cell()`.
6. **Blocks:** wording defaults in `block.json` stay empty; an editor control has to change something the renderer reads; a style option needs a rule in `public/css/cybevents-public.css`.
7. **Texts** state what the code does. No conformity statements (GDPR, BFSG, WCAG, GoBD) that the code cannot establish, no client names, no emoji.

---

## Workflow

```bash
git clone https://github.com/arnoldwender/wm-wp-cyber-events-core.git
cd wm-wp-cyber-events-core

php tests/test-suite.php            # must end with "0 FAILED"
bash tests/run-mutations.sh         # every mutation must turn the suite red
bash tests/validate-invoice-xml.sh  # when touching invoices (Java 11+)
```

A fix comes with an assertion in `tests/test-suite.php` that fails without the fix, and a mutation in `tests/mutations.php` that undoes the fix and turns that assertion red.

---

## Commit conventions

- Format: `[Fix] …`, `[Feature] …`, `[Security] …`, `[Docs] …`; the body explains why.
- Pull requests against `main`; CI (PHP 8.3 and 8.4) must be green.

For support and liability rules, read [SUPPORT.md](SUPPORT.md).
