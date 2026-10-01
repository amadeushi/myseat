---
target: web/content/orders_pos.page.php
total_score: 25
max_score: 40
na_heuristics: 
p0_count: 2
p1_count: 2
target_identity: "file:/Users/hamunhirbod/claude_code/myseat/web/content/orders_pos.page.php"
target_fingerprint: "sha256:6a5a00857f18268d666ea0f9e99994dc449af2d64d8dd0b75a90eccdc17bff0d"
target_path: /Users/hamunhirbod/claude_code/myseat/web/content/orders_pos.page.php
timestamp: 2026-10-01T19-38-38Z
slug: web-content-orders-pos-page-php
---
## Design Review: Bestellung erfassen / POS (main_page.php?p=12)

Method: dual-agent (A: a596941a706324a2b · B: ae82e537472577bf3)

Limitation: no live browser access (admin login wall); source-based review.

### Design Health Score

| # | Heuristik | Score | Kernproblem |
|---|---|---|---|
| 1 | Sichtbarkeit des Systemstatus | 2 | #pos-msg zeigt Fehler/Erfolg/Zwischenstatus identisch, nie .is-error/.is-ok. |
| 2 | Übereinstimmung mit Realität | 3 | Domänenbegriffe treffend. |
| 3 | Benutzerkontrolle & Freiheit | 2 | Variante/Option korrigieren = löschen + Dialog neu. |
| 4 | Konsistenz & Standards | 2 | Regression ggü. orders.js's bereits korrektem .is-error-Muster. |
| 5 | Fehlervermeidung | 3 | Guter Check-Satz; 60-Zeilen-Deckel still. |
| 6 | Wiedererkennung statt Erinnern | 4 | "Zuletzt bestellt"-Feature ist die Stärke der Seite. |
| 7 | Flexibilität & Effizienz | 2 | Kein Autofokus, kein Tastaturpfad Suche->Warenkorb. |
| 8 | Ästhetik & Minimalismus | 3 | Produktdialog importiert Gast-Shop-Bildsprache (28px Serife). |
| 9 | Fehlerdiagnose & -behebung | 2 | Blockierender Fehler sieht aus wie Zwischenstatus. |
| 10 | Hilfe & Dokumentation | 2 | Kaum Hinweise/Tooltips. |
| **Gesamt** | | **25/40** | **Acceptable (20-27)** |

### Design-Spezifitäts-Verdikt
Klar für Telefon-Bestellungsaufnahme gebaut (Anruf-Banner, Telefon-Historie, geteilter Produktdialog), aber eher "Gast-Shop-UI wiederverwendet" als "für 30-Sekunden-Anruf gebaut" - v.a. Feedback-/Aufmerksamkeitsschicht schwach.

### Detector-Abgleich
0 Treffer in orders_pos.page.php/orders_pos.js/.pos-* Block. 24 advisory-Funde in order/shop.css (Radius-/Font-Size-/Farb-Drift, vom Gast-Shop mitgebracht). Manuell: esc() konsequent korrekt verwendet, keine confirm/alert/prompt, aber .pos-item/.pos-cat/.pos-line-del ohne eigene :focus-visible (liegen außerhalb .shop-shell).

### Priority Issues
- [P0] Fehler/Erfolg/Zwischenstatus optisch identisch (orders_pos.js:408-416) -> /impeccable clarify
- [P0] Anruf-Banner unsichtbar sobald gescrollt/Dialog offen, kein aria-live (orders_pos.page.php:18, orders_pos.js:371-381) -> /impeccable clarify
- [P1] Sticky-Warenkorb verschwindet unter 900px, genau Tablet-Breite (theme-dark.css:2027) -> /impeccable adapt
- [P1] Stiller 60-Zeilen-Warenkorb-Deckel ohne Warnung (shop.class.php:815) -> /impeccable harden
- [P2] Keine Inline-Korrektur einer Warenkorbzeile, nur löschen+neu (orders_pos.js:130) -> /impeccable layout

### Persona Red Flags
Alex: kein Autofokus; Korrektur = Zeile neu aufbauen; Übernehmen nur komplett, nicht einzeln.
Casey: Sticky-Cart weg unter 900px; Produktdialog-Breakpoint vom Gast-Shop geerbt, nicht für Tablet getestet.
Riley: Historie bei 5 gedeckelt ohne Hinweis; Adresskandidat patcht nur PLZ, nicht sichtbares Straßenfeld.

### Minor Observations
- .pd-head h2 28px Serife zu ornamental für Operate-Modus
- fehlende :focus-visible für .pos-item/.pos-cat/.pos-line-del
- .pos-zone-pick ohne erkennbare 44px-Touchziel-Höhe
- Skip-Warnung und Submit-Meldung teilen sich #pos-msg ohne Koordination

### Questions to Consider
1. Auffälligeres Anruf-Signal (akustisch/schwebend) statt scroll-abhängigem Inline-Block?
2. Produktdialog direkt aus Warenkorbzeile erneut öffnen statt löschen-und-neu?
3. Braucht "Übernehmen" Einzelpositions-Granularität oder reicht ganze-Bestellung-dann-anpassen?
