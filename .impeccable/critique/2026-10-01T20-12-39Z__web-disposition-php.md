---
target: web/disposition.php
total_score: 26
max_score: 36
na_heuristics: 10
p0_count: 1
p1_count: 2
target_identity: "file:/Users/hamunhirbod/claude_code/myseat/web/disposition.php"
target_fingerprint: "sha256:a0dd646eec34d97de041c5c36a21ae8c5592c5e55328db7b70d2e686b532f968"
target_path: /Users/hamunhirbod/claude_code/myseat/web/disposition.php
timestamp: 2026-10-01T20-12-39Z
slug: web-disposition-php
---
## Design Review: Disposition-Board (web/disposition.php)

Method: dual-agent (A: acee03ba28b768119 · B: a4b3db860b2865212)

Limitation: no live browser access (admin login wall); source-based review.

### Design Health Score (26/36, heuristic 10 n/a)

| # | Heuristik | Score | Kernproblem |
|---|---|---|---|
| 1 | Sichtbarkeit des Systemstatus | 3 | Kein "zuletzt aktualisiert" fuer den Poll. |
| 2 | Uebereinstimmung mit Realitaet | 4 | Spaltennamen treffen Kuechenprozess. |
| 3 | Benutzerkontrolle & Freiheit | 2 | Kein Zurueck nach Statuswechsel. |
| 4 | Konsistenz & Standards | 2 | is-late/is-failed identisch gestylt. |
| 5 | Fehlervermeidung | 3 | 6s-Rerender kann armed-Zustand zuruecksetzen. |
| 6 | Wiedererkennung | 4 | Alles Noetige auf der Karte. |
| 7 | Flexibilitaet & Effizienz | 2 | ETA-Schnellwahl gut. |
| 8 | Aesthetik & Minimalismus | 3 | Karten zu dicht fuer Distanzblick. |
| 9 | Fehlerdiagnose | 3 | notify() statt alert() gut, Texte generisch. |
| 10 | Hilfe & Dokumentation | n/a | Kiosk-Tool, keine sinnvolle Achse. |

### Design-Spezifitaets-Verdikt
Bewusst groessere Schrift (18/36/28/22px) ist echte, begruendete Abweichung vom Backend. Aber Karten so dicht wie Admin-Zeilen, kein Ambient-Modus; neue-Bestellung-Signal (Rand+Glow) ist schwaecher als Schwesterseite kitchen_screen.php's Puls-Animation.

### Detector-Abgleich
26 advisory Funde, alle in kitchen.css, korrekt als eigenes Distanz-Design-System eingestuft (kein Fix noetig). Echte Erkenntnis: kitchen.css:4 definiert eigene --danger/--success Hex-Werte abweichend von den am 29.09. konsolidierten DESIGN.md-Werten.

### Priority Issues
- [P0] Spalten-Ueberlauf nur per Scroll sichtbar, kein Signal (kitchen.css:31) -> /impeccable adapt
- [P1] is-late und is-failed optisch identisch (kitchen.css:35,37) -> /impeccable clarify
- [P1] 6s-Full-Rerender resettet Confirm-State/Scroll (disposition.js:67-76,133) -> /impeccable harden
- [P2] Leerer Zustand untertreibt "alles erledigt" (kitchen.css:32) -> /impeccable clarify
- [P2] kitchen.css eigene Farb-Tokens abweichend von DESIGN.md (kitchen.css:4) -> /impeccable extract

### Persona Red Flags
Casey: neue-Bestellung-Signal kaum sichtbar, Ueberlauf unsichtbar.
Riley: leerer Zustand wirkt kaputt; Verspaetung eskaliert nie visuell, identisch mit Fehlschlag.
Sam: Amber/Gold/Gold-strong schwer unterscheidbar auf Distanz; late/failed-Verwechslung ohne Text nicht aufloesbar.

### Minor Observations
- Amber fuer drei Konzepte wiederverwendet
- Fail-reason-Notiz 18px statt 25px wie bei kitchen_screen.php
- ETA-Buttons ohne Mindestbreite
- Fahrer-Karte-Panel schrumpft Board ohne Split/Resize

### Questions to Consider
1. Puls-Animation fuer neue Bestellungen statt kaum sichtbarem Rand?
2. Neu-Spalte strukturell nicht ueberlaufbar, mit "+N weitere"-Chip?
3. Verspaetet ueber eigenen visuellen Kanal (Icon) statt Farb-Wiederverwendung?
