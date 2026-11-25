## 📝 Beschreibung der Änderung
<!-- Bitte beschreiben Sie kurz, was diese Änderung bezweckt und welche Probleme sie löst. -->

## 🔗 Relevante Issues / Tickets
<!-- z.B. Schließt #123 -->

## 🛡️ Qualitäts- & Sicherheits-Checkliste
- [ ] **Tests:** Alle 5 Test-Suiten in `tests/` laufen fehlerfrei durch (`VERDICT: PASS`).
- [ ] **WordPress Plugin Check:** `wp plugin check` besteht mit 0 Fehlern und 0 Warnungen.
- [ ] **Atomare Concurrency:** Keine ungeschützten Read-Modify-Write-Operationen. Der Konflikt wird im `WHERE` einer einzigen `UPDATE`-Anweisung entschieden, nicht in PHP zwischen zwei Abfragen.
- [ ] **Barrierefreiheit (BFSG 2025):** 4.5:1 Textkontrast & ≥48px Touch-Targets eingehalten.
- [ ] **DSGVO / Zero-CDN:** Keine externen Font-/Script-Aufrufe hinzugefügt.
- [ ] **Internationalisierung:** Alle neuen Strings verwenden Gettext (`__('...', 'wm-cyber-events-core')`).
- [ ] **Dokumentation:** `README.md` oder `docs/` aktualisiert (falls zutreffend).
