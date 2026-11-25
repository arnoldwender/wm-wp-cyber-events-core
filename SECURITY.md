# Security Policy — WM Cyber Events Core

## Supported Versions

| Version | Supported |
| --- | --- |
| `1.2.x` (`1.2.3`) | Current release branch |

---

## Reporting a Vulnerability

Please **do not** open a public GitHub issue for security vulnerabilities, and **do not** send private inquiries or support emails to `arnoldwender.com`, email addresses, or social/direct messages (private channels are unmonitored for open-source plugins).

Submit security reports **exclusively** via GitHub Private Vulnerability Reporting:

- **Security Advisories:** [github.com/arnoldwender/wm-wp-cyber-events-core/security/advisories/new](https://github.com/arnoldwender/wm-wp-cyber-events-core/security/advisories/new)

> **Best-Effort Only / Zero Warranty / Zero Support:** This open-source repository carries **zero technical or user support** ([SUPPORT.md](SUPPORT.md)) and **zero response-time SLA**. Advisory reports may be reviewed at the author's sole discretion without obligation, timeline, or liability ("AS IS" under LICENSE).

### What to include

1. Steps to reproduce or a proof of concept.
2. Affected component (REST route, block or shortcode, admin screen, WP-CLI command).
3. Impact (for example privilege escalation, SQL injection, stored XSS, CSV formula injection, access to another participant's data through a token).

### Response targets

- **Acknowledgement:** within 24 hours.
- **Triage and fix:** within 72 hours for critical and high findings.
- **Disclosure:** coordinated with the reporter, with credit.

---

## Security-relevant design

- Participant and DOI tokens are stored only as SHA-256 hashes; the queue keeps the confirmation link only until the mail job ends.
- Public REST routes enforce transient-based HMAC-SHA256 blinded-IP rate limiting (zero raw IP addresses stored); `/upload-avatar` requires an active registration token or an administrator with a valid REST nonce.
- The Brevo webhook refuses every request (401) until a shared secret is configured; check-in requires an administrator or the staff key.
- Ticket data is sent with `Cache-Control: private, no-store`.
- The audit log is chained with HMAC-SHA256; without `AUTH_KEY` in `wp-config.php` the key comes from the database, which the admin flags.
- CI runs a security audit (`.github/scripts/security-audit.py`) on pushes and pull requests to `main`.
