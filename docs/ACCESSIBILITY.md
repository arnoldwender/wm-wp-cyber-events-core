# Barrierefreiheit — WM Cyber Events Core

> Stand 2026-09-15, gemessen am Code. Die frühere Fassung erklärte das Plugin für konform mit BFSG 2025, BITV 2.0 und WCAG 2.2 AA, nannte 48 × 48 px für alle Bedienelemente, Kontrastwerte fester Farben und einen Bot-Schutz ohne Bilderrätsel. Keine dieser Aussagen ist gemessen; eine Prüfung der Barrierefreiheit gegen einen Standard wurde nicht durchgeführt. Ob eine Website mit dem Plugin die Anforderungen des BFSG erfüllt, hängt vom Theme, den Inhalten und der Gesamtseite ab und ist dort zu prüfen.

---

## Was im Code umgesetzt ist

**Anmeldeformular** (`includes/class-accessibility.php`)
- Jedes Eingabefeld hat ein sichtbares `<label for="…">`; Pflichtfelder tragen `required`, `aria-required="true"` und ein sichtbares Sternchen (für Screenreader ausgeblendet, das `required` trägt die Information).
- `autocomplete` für Vorname, Nachname, E-Mail und Organisation.
- Einwilligungen als Checkbox innerhalb eines `<label>`, die ganze Karte ist anklickbar.
- Rückmeldungen nach dem Absenden erscheinen in `<div role="alert" aria-live="polite">`.
- Die Schrittanzeige ist `aria-hidden`, weil sie keine Bedienfunktion hat; das versteckte Honeypot-Feld ist `aria-hidden` und mit `tabindex="-1"` aus der Tab-Reihenfolge genommen.

**Session-Auswahl** (`includes/class-sessions-manager.php`)
- Jede Session ist eine beschriftete Checkbox; ausgebuchte Sessions sind `disabled` und mit „Ausgebucht“ beschriftet.
- Rückmeldungen in einer Live-Region wie beim Formular.

**Icons**
- Dekorative Icons sind inline-SVG mit `aria-hidden="true"` und `focusable="false"` und nehmen die Textfarbe an (`includes/class-icons.php`); bis 2026-09-15 standen dort Emoji, die Screenreader mit ihrem Unicode-Namen vorlesen.

**Fokus**
- `public/css/cybevents-public.css` ersetzt `outline: none` durch einen transparenten Umriss plus sichtbaren Fokusrahmen, damit der Rahmen auch im Modus „Erzwungene Farben“ erscheint (Eingaben, Checkboxen, Absende- und Dock-Button, sekundäre Buttons).
- Die Admin-Dialoge setzen den Tastaturfokus beim Öffnen in den Dialog und beim Schließen (auch mit Escape) zurück auf das auslösende Element.

**Design**
- Preset „Hochkontrast (Schwarz auf Weiß)“: schwarzer Text auf Weiß (Kontrast 21:1), gelber Akzent, 2-px-Rahmen an Eingaben und Buttons.
- Das Standard-Preset übernimmt Farben und Schriften des aktiven Themes; der Kontrast hängt dann vom Theme ab.

## Nicht umgesetzt oder nicht gemessen

- Kein Captcha-Widget: der Bot-Schutz besteht aus Honeypot und Ratenbegrenzung.
- Keine gemessenen Kontrastwerte für die übrigen Presets und keine gemessenen Mindestgrößen der Klickflächen.
- Calendar Views steuert Ansichten über `onclick`/`onchange`-Attribute an Buttons und Auswahlfeldern und nutzt native `hidden`-Attribute zum Umschalten von Tages- und Raum-Panels; vollständige Tastatur- und Screenreader-Prüfungen dieser Ansicht stehen noch aus.
- Kein Test mit Screenreadern oder automatisierten Prüfwerkzeugen im Repository.
