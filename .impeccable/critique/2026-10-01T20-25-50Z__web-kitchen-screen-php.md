---
target: web/kitchen_screen.php
total_score: 22
max_score: 32
na_heuristics: 7,10
p0_count: 1
p1_count: 2
target_identity: "file:/Users/hamunhirbod/claude_code/myseat/web/kitchen_screen.php"
target_fingerprint: "sha256:f34e3fa46b42eff629f00944082bb3a8c263e3fa1266c8228ccfdbcddced0e12"
target_path: /Users/hamunhirbod/claude_code/myseat/web/kitchen_screen.php
timestamp: 2026-10-01T20-25-50Z
slug: web-kitchen-screen-php
---
## Design Review: Kuechenbildschirm (web/kitchen_screen.php)

Method: dual-agent (A: a6d3e1d9f51fb2045 · B: ab332b23da244f634)

Limitation: no live browser access; source-based review.

### Design Health Score (22/32, heuristics 7+10 n/a)

| # | Heuristik | Score |
|---|---|---|
| 1 | Sichtbarkeit des Systemstatus | 2 |
| 2 | Uebereinstimmung mit Realitaet | 3 |
| 3 | Benutzerkontrolle & Freiheit | 2 |
| 4 | Konsistenz & Standards | 2 |
| 5 | Fehlervermeidung | 3 |
| 6 | Wiedererkennung | 3 |
| 7 | Flexibilitaet & Effizienz | n/a |
| 8 | Aesthetik & Minimalismus | 4 |
| 9 | Fehlerdiagnose | 3 |
| 10 | Hilfe & Dokumentation | n/a |

### Design-Spezifitaets-Verdikt
Trennung von disposition.php konzeptuell verdient (eine Spalte pro Bestellung, groessere Typo), aber beginnt zu Duplikations-Drift zu verrotten: kitchen_screen.js reimplementiert Polling/Rendering unabhaengig und hat 3 der 4 gerade an disposition.js vorgenommenen Robustheits-Fixes verpasst.

### Detector-Abgleich
27 advisory Funde, alle in kitchen.css (.ks-* Block), gleiches bereits adjudiziertes Distanz-Design-System. Echte Luecke: o.type ungeescaped in Klassen-Attribut (kitchen_screen.js:31).

### Priority Issues
- [P0] Rueckstau jenseits sichtbarer Spalten komplett unsichtbar (kitchen_screen.js:41,47) -> /impeccable adapt
- [P1] is-fresh-Puls durch Reload vorgetaeuscht statt aus echten Daten (kitchen_screen.js:5,61,75) -> /impeccable harden
- [P1] Kompletter innerHTML-Rebuild statt Diff-Rendering (kitchen_screen.js:46) -> /impeccable harden
- [P2] Keine Verspaetungs-Eskalation (kein Aequivalent zu disposition.js VERSPAETET) -> /impeccable clarify
- [P2] Angenommen vs. wird-gekocht unsichtbar (o.status nie gerendert) -> /impeccable clarify

### Persona Red Flags
Casey: muss Wandbildschirm beruehren um Alarm stummzuschalten, Haende oft belegt.
Riley: P0-Rueckstau ist der Fehlerfall, fuer den diese Persona existiert.
Sam: is-fresh gut (Bewegung+Rand), aber nichts fuer Verspaetung wahrnehmbar.

### Minor Observations
- Bon-drucken-Button hat aehnliches Gewicht wie Fertig-Button
- Keine eigene Bestaetigung beim Ack-Tap, nur Puls stoppt
- .k-warn fuer zwei verschiedene Warnungsarten (Rueckstau vs. kuenftig Verspaetung) vorgesehen
- Spalten-Button zeigt Kapazitaet nicht aktuelle Anzahl

### Questions to Consider
1. Ueberfaelligste Bestellung nach Dringlichkeit statt Ankunftsreihenfolge befoerdern?
2. Positive Bestaetigung (Flash/Ton) statt lautlosem Verschwinden bei Fertig?
3. Gemeinsames Polling-/Diff-Render-Modul fuer beide Kuechenseiten?
