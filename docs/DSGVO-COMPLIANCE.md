# Datenverarbeitung — WM Cyber Events Core

> Stand 2026-09-15, gemessen am Code. Diese Datei beschreibt, welche Daten das Plugin wo speichert, weitergibt und löscht, als Grundlage für das Verzeichnis von Verarbeitungstätigkeiten und die Datenschutzerklärung der Website. Sie ist keine Rechtsberatung und keine Aussage, dass eine Website mit dem Plugin die DSGVO erfüllt. Die frühere Fassung beschrieb eine „Salted SHA-256“-Pseudonymisierung, SVG-QR-Codes, einen Storno-Link in jeder Bestätigungs-E-Mail und eine automatische Rechnungskorrektur bei jeder Stornierung; das trifft so nicht zu.

---

## 1. Welche Daten

| Daten | Quelle | Speicherort |
| --- | --- | --- |
| Vorname, Nachname, E-Mail, Firma | Anmeldeformular, CSV-Import, Admin-Dialog | `wp_cybevents_registrations` |
| Profilfoto (optional, JPEG/PNG/WebP bis 5 MB) | Formular, REST-Route `/upload-avatar`, Admin-Dialog | `wp-content/uploads/cybevents-avatars/`, URL in der Anmeldung |
| Einwilligung Datenschutz, Einwilligung Fotoaufnahmen | Formular | `consent_privacy`, `consent_photo` |
| Zeitpunkte: Anmeldung, DOI angefordert, DOI bestätigt, Check-in | Ablauf | Anmeldung |
| Gebuchte Sessions | Session-Auswahl | `wp_cybevents_registration_sessions` |
| Rechnungsdaten (Name, Firma, E-Mail, Adresse, Beträge, XML) | `create_invoice()` | `wp_cybevents_invoices` |
| IP-Adresse, Benutzer-ID, Ereignis | Audit-Log | `wp_cybevents_audit_log` |
| IP-Adresse (als HMAC-SHA256-Hash im Namen eines Transients, kein Klartext) | Ratenbegrenzung öffentlicher REST-Routen, 60 Sekunden | WordPress-Transients |
| Teilnehmer- und DOI-Token | Anmeldung | nur als SHA-256-Hash; im Klartext nur im Bestätigungslink und bis zum Ende des Warteschlangen-Jobs |

Das Plugin setzt keine eigenen Cookies und bindet keine Tracking- oder Analysedienste ein.

## 2. Weitergabe an Dritte

- **Brevo** (API v3, nur mit hinterlegtem API-Schlüssel): für die Bestätigungs-E-Mail gehen E-Mail, Vorname, Nachname, Firma und Veranstaltungstitel an Brevo, zusammen mit der Listen-ID aus den Einstellungen (`includeListIds`) und der Vorlagen-ID. Bei einer Selbststornierung wird zusätzlich das Kontaktattribut `EVENT_STATUS` auf `CANCELLED` gesetzt. Brevo meldet über den Webhook Bounces, Spam-Meldungen und Abmeldungen zurück.
- **Kalenderdienste**: die Links zu Google Kalender, Outlook Web und Microsoft 365 übertragen Titel, Zeit, Ort und Beschreibung der Veranstaltung erst, wenn Besucher sie anklicken; personenbezogene Daten enthalten sie nicht.
- **Friendly Captcha**: der Code enthält eine Prüfung gegen `api.friendlycaptcha.com`, sie wird aber nie ausgeführt, weil das Plugin kein Captcha-Widget enthält.
- **Steuerberatung**: der DATEV-Export enthält Rechnungsnummern, Beträge und Buchungstexte; der CSV-Export Namen, Firmen, E-Mail-Adressen, Rechnungsnummern und Check-in-Status. Beide Exporte verlangen eine Bestätigung und werden im Audit-Log vermerkt.

## 3. Löschung und Pseudonymisierung

| Fall | Was geschieht |
| --- | --- |
| Anmeldung nach 14 Tagen nicht bestätigt | täglicher Cron löscht Anmeldung, Session-Buchungen (Plätze werden frei) und Foto |
| Audit-Einträge älter als 90 Tage | Benutzer, IP und Details werden geleert, die Kette bleibt prüfbar; Rechnungsereignisse sind ausgenommen |
| Bestätigte Anmeldungen, Rechnungen | werden nicht automatisch gelöscht; eine Frist ist nicht einstellbar |
| Löschung über *Werkzeuge › Personenbezogene Daten löschen* | Anmeldungen ohne Rechnung werden gelöscht, Anmeldungen mit Rechnung anonymisiert (Name, E-Mail, Firma, Foto, Tokens); Rechnungen bleiben unverändert; Audit-Einträge mit der Adresse werden pseudonymisiert, wartende E-Mails verworfen |
| Selbststornierung (`POST /cancel-registration` mit Token) | Status „storniert“, Plätze frei, Gutschrift bei vorhandener Rechnung; die Daten bleiben gespeichert. Das Plugin versendet selbst keinen Stornierungslink |
| Löschen im Backend | Anmeldung, Buchungen und Foto werden gelöscht |
| Werkseinstellungen | alle Plugin-Tabellen werden geleert, Beiträge und Orte gelöscht; hochgeladene Fotos bleiben |
| Deinstallation | Standardmäßig bleiben Tabellen und Optionen erhalten (`D-UNINSTALL`); nur wenn `wm_cybevents_delete_data_on_uninstall` in den Einstellungen aktiviert wurde, werden Tabellen und Optionen entfernt (Beiträge, Orte, Fotos und die hochgeladene Theme-Datei bleiben) |

## 4. Auskunft

*Werkzeuge › Personenbezogene Daten exportieren* liefert je Anmeldung Veranstaltung, Status, Name, E-Mail, Firma, Foto-URL, Einwilligungen, DOI- und Check-in-Zeitpunkte, gebuchte Sessions, Rechnungsnummer und Anmeldedatum sowie die an die Adresse gerichteten Rechnungen.

## 5. Offene Punkte

- Die Einwilligung wird ohne Versionsstand der Datenschutzerklärung gespeichert.
- CSV-importierte Personen werden als bestätigt gespeichert, ohne Einwilligung und ohne Double Opt-in; die Rechtsgrundlage dafür liegt beim Betreiber.
- Optionale Demodaten werden nicht bei der Aktivierung angelegt, sondern nur auf ausdrückliche Anforderung importiert (`Einstellungen › Demodaten & Reset` oder `wp cybevents demodaten --import`); sie enthalten seit 2026-11-19 ausschließlich erfundene Personen und reservierte `example.*`-Domains.
- Aufbewahrungsfristen für Rechnungen und das Audit-Log gegenüber Werkseinstellungen und Deinstallation sind nicht festgelegt.
