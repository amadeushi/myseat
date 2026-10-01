---
target: order/status.php
total_score: 26
max_score: 32
na_heuristics: 7,10
p0_count: 1
p1_count: 1
target_identity: "file:/Users/hamunhirbod/claude_code/myseat/order/status.php"
target_fingerprint: "sha256:aa712c0e43cf1929a05f68fe750a3cf368f7033faf263ac726be9304fe622826"
target_path: /Users/hamunhirbod/claude_code/myseat/order/status.php
timestamp: 2026-10-01T20-53-28Z
slug: order-status-php
---
## Design Review: Gaeste-Tracking-Seite (order/status.php)

Method: dual-agent (A: a98c1a6458731b220 · B: aa5cd88005a7938e0)

Live rendering: real production invalid-link state reached (no admin login wall - public guest page). H1 "Bestellung nicht gefunden", confirmed desktop+mobile.

### Design Health Score (26/32, heuristics 7+10 n/a)

| # | Heuristik | Score |
|---|---|---|
| 1 | Sichtbarkeit des Systemstatus | 3 |
| 2 | Uebereinstimmung mit Realitaet | 4 |
| 3 | Benutzerkontrolle & Freiheit | 2 |
| 4 | Konsistenz & Standards | 4 |
| 5 | Fehlervermeidung | 3 |
| 6 | Wiedererkennung | 4 |
| 7 | Flexibilitaet & Effizienz | n/a |
| 8 | Aesthetik & Minimalismus | 3 |
| 9 | Fehlerdiagnose | 3 |
| 10 | Hilfe & Dokumentation | n/a |

### Design-Spezifitaets-Verdikt
Gaestewidget-Stimme stark in Copy + Fahrer-Puls-Interaktion, aber Chrome (Box/Typo/Farbe) bleibt identisch egal ob gute oder schlechte Nachricht - Operate-Grade-Statusmodell in Gaestewidget-Typografie.

### Detector-Abgleich
0 Funde (Exit 0). Echte Kleinigkeit: .st-mapbox (status.php:118) ist tote CSS-Klasse ohne Regel.

### Priority Issues
- [P0] Keine visuelle Unterscheidung fuer fehlgeschlagen/storniert (status.php:55-56,65) -> /impeccable clarify
- [P1] Ungueltig-Link-Sackgasse bietet nur Telefonnummer, ~1000px Leere (status.php:46-48) -> /impeccable onboard
- [P2] Schrittanzeiger bewegt sich nicht zwischen neu/angenommen (status.php:60,100) -> /impeccable clarify
- [P3] Geliefert-Zustand ohne Freude, flaches "fertig" (status.php:70,107) -> /impeccable delight

### Persona Red Flags
Haungriger Nachschauer: P2 trifft ihn am haertesten.
Abgelenkter Einmal-Blick-Gast: versagt bei fehlgeschlagen (P0).
Schlechte Verbindung: Leaflet-Fallback zeigt leeres schwarzes Rechteck statt Graceful Degradation.
Fehlgeschlagen/sehr spaet: keine Zahlungs-/Rueckerstattungsinfo.

### Minor Observations
- role=img aria-label=Karte zu generisch
- Polling schluckt Fehler lautlos, kein Reconnecting-Hinweis
- .st-mapbox tote Klasse
- 52px ETA-Heldenzahl gute Display-Hero-Nutzung

### Questions to Consider
1. Eigene Illustration/Icon fuer fehlgeschlagen/storniert statt froehlichem Layout?
2. Ungueltig-Link-Zustand nach Fehlerursache differenzieren?
3. Trinkgeld-/Bewertungs-Hinweis im Geliefert-Erfolgs-Zustand?
