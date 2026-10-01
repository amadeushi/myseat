---
target: web/content/orders.page.php
total_score: 19
max_score: 40
na_heuristics: 
p0_count: 2
p1_count: 2
target_identity: "file:/Users/hamunhirbod/claude_code/myseat/web/content/orders.page.php"
target_fingerprint: "sha256:a082855d614e0a67b50d6ab20ecba779157d089b238c10dc9a623f74353e261f"
target_path: /Users/hamunhirbod/claude_code/myseat/web/content/orders.page.php
timestamp: 2026-10-01T19-04-25Z
slug: web-content-orders-page-php
---
## Design Review: Bestellungen-Dashboard (`main_page.php?p=9`)

Method: dual-agent (A: aeebffd7cab8fa0c6 · B: aab2596405f5e18b5)

Limitation: no live browser access (admin login wall); review is source-based (web/content/orders.page.php, web/js/orders.js, web/css/theme-dark.css .orders-* rules, DESIGN.md).

### Design Health Score

| # | Heuristik | Score | Kernproblem |
|---|---|---|---|
| 1 | Sichtbarkeit des Systemstatus | 2 | Kein Loading-Indikator zwischen 20s-Polls; Fehler in leiser <p> oder alert(). |
| 2 | Übereinstimmung mit Realität | 4 | Status-Vokabular trifft Küchenworkflow genau. |
| 3 | Benutzerkontrolle & Freiheit | 1 | Statuswechsel nur vorwärts; nur "Stornieren" als Rückfallebene. |
| 4 | Konsistenz & Standards | 2 | Datumsnav nutzt Unicode-Pfeile statt DESIGN.md SVG-Chevron-Regel. |
| 5 | Fehlervermeidung | 1 | "Annehmen" sendet stillschweigend eta:30, nie bestätigt/sichtbar. |
| 6 | Wiedererkennung statt Erinnern | 3 | Status immer Text+Farbe. |
| 7 | Flexibilität & Effizienz | 1 | Kein Bulk-Accept, kein Keyboard-Pfad, Filter resettet bei Reload, 3x prompt() für Testbestellung. |
| 8 | Ästhetik & Minimalismus | 2 | 12 Toolbar-Controls ohne Gruppierung. |
| 9 | Fehlerdiagnose & -behebung | 2 | Generischer Fallback-Text; fail_reason erst nach Aufklappen sichtbar. |
| 10 | Hilfe & Dokumentation | 1 | Einziger Hilfetext: ein title-Tooltip. |
| **Gesamt** | | **19/40** | **Poor-Band (12–19)** |

### Design-Spezifitäts-Verdikt
Vokabular authentisch, aber Interaktionsmodell fällt auf generische Admin-Dialoge (native confirm/alert/prompt) zurück. Detector fand keine Token-/Farb-Drift (Exit 0, []) - bestätigt, dass es ein IA-/Interaktionsproblem ist, kein Styling-Problem.

### Detector-Abgleich
CLI-Scan leer (Exit 0, []). Zusätzliche manuelle Evidenz (Assessment B): ungeescaptes o.status/o.type in orders.js:27,30; Testbestellungs-Buttons ohne current_user_can()-Check (orders.page.php:24-26); tote CSS-Klasse .orders-nav.wide; Mobile-Grid bei 900px wahrscheinlich umbrechend (6 Kinder in 4-Spalten-Grid).

### Priority Issues
- [P0] Annehmen sendet unsichtbare, nie bestätigte ETA (orders.js:81) → /impeccable clarify
- [P0] Native confirm/alert/prompt für jede Bestätigung + Testbestellungs-Flow (orders.js:61-67,80,84) → /impeccable harden
- [P1] Toolbar ohne Gruppierung, 12 Controls (orders.page.php:12-29) → /impeccable layout
- [P1] .orders-type.pickup zweckentfremdet --success (Salbeigrün) als Typ-Farbe, verletzt One-Accent-Rule (theme-dark.css:1826-1827) → /impeccable colorize
- [P2] 900px-Breakpoint versteckt .orders-type (Lieferung/Abholung) + wahrscheinlich brechendes Grid (theme-dark.css:1841-1845) → /impeccable adapt

### Persona Red Flags
Alex: Filter resettet bei Reload; 3x prompt() für Testbestellung; kein Bulk-Accept.
Sam: aria-label des Toggle-Buttons bleibt statisch "Details"; volles innerHTML-Replace alle 20s wirft Tastaturfokus ohne Refokussierung.

### Minor Observations
- o.status/o.type ungeescaped in HTML-Attributen (orders.js:27,30)
- Testbestellungs-Buttons ohne Berechtigungsprüfung
- tote CSS-Klasse .orders-nav.wide
- Test-/Telefon-Badges optisch nicht unterscheidbar
- keine Suche/Sortierung bei vielen Bestellungen

### Questions to Consider
1. Race condition bei gleichzeitigem Annehmen durch zwei Mitarbeiter?
2. Ab welcher Bestellungszahl wird die Liste unpraktikabel?
3. Gibt es einen Weg, eine falsch gesendete ETA zu korrigieren?
