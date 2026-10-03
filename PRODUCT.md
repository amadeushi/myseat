# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users
- **Gäste** des Amadeus Café Restaurant Bar in Hildesheim: reservieren online (Gästewidget in `api/`) und bestellen Lieferung oder Abholung im Bestellshop (`order/`). Meist am Handy, oft spontan, deutschsprachig, Anrede "du".
- **Personal** (Service, Disposition, Küche, Fahrer): arbeitet im Backend (`web/`) und auf den Monitoren für Disposition, Küche und Fahrer. Tempo und Scanbarkeit vor Atmosphäre.
- **Inhaber/Administratoren:** pflegen Speisekarte, Zonen, Sperrzeiten, Einstellungen im Backend.

## Product Purpose
mySeat ist das eigene Reservierungs- und Betriebssystem des Amadeus (PHP 8 mit mysqli, ein Fork von mySeat): Tischreservierungen, Tischplan, SMS- und Mailbestätigungen und ein eigener Liefer- und Abholdienst mit Bestellshop, Kasse für telefonische Bestellungen, Küchenmonitor, Disposition und Fahrerseite. Erfolg: Gäste bestellen und reservieren ohne Umweg über Portale, das Personal sieht alles an einer Stelle.

## Positioning
Ein Restaurant betreibt Reservierung und Lieferdienst in einem eigenen System, ohne Provision an Portale (die Speisekarte stammt ursprünglich aus Resmio und wurde übernommen). Eine zutatenbasierte Wunschpizza direkt im eigenen Shop ist etwas, das die Portale so nicht anbieten.

## Operating Context
- Bestellshop unter `/order/`: Speisekarte nach Kategorien, Warenkorb im Browser, Kasse mit Zeitfenster, Zahlung (Mollie, bar, Karte an der Tür), Statusseite mit Karte.
- Speisekarte im Backend (Seite "Speisekarte", Editor): Kategorien, Gerichte, Varianten (z. B. Größen), Optionsgruppen mit Optionen und Preisen, Gutscheine.
- Bestellungen laufen als Positionen mit Optionen in Disposition und Küchenmonitor. Eine Wunschpizza muss dort als lesbare Zutatenliste ankommen.
- Deployment per scp auf kasserver, kein Build-Schritt.

## Capabilities and Constraints
- Stack: PHP 8, mysqli, einfaches JavaScript ohne Framework, eigenes CSS; Gästeseiten laden keine externen Bibliotheken.
- Tabellen des Shops: `tp_shop_products`, `tp_shop_variations`, `tp_shop_modgroups`/`tp_shop_modifiers`/`tp_shop_group_items`, Bestellpositionen in `tp_shop_order_items`.
- Gäste sehen den Shop nur bei gesetzter Einstellung "sichtbar"; Mitarbeiter sehen immer eine Vorschau.
- Wunschpizza-Konfigurator (bestätigt für diese Arbeit): wird pro Gericht im Speisekarten-Editor zugeschaltet; Abrechnung als Grundpreis je Größe plus Aufpreis je Zutat; Zutatenbilder zeichnet Claude als stilisierte SVGs (Draufsicht, ca. 15 bis 20 Zutaten, erweiterbar).
- Offen: Datenmodell der Zutatenliste (neue Tabelle oder bestehende Optionsgruppen), Größen, wie die Bestellung als Text in Küche und Bon erscheint, maximale Zutatenzahl.

## Brand Commitments
- Name: Amadeus Café Restaurant Bar, Hildesheim. Deutsch, Anrede "du".
- Das bestehende System ist dunkel mit Kerzengold (siehe `DESIGN.md`). Für den Konfigurator hat der Betreiber ausdrücklich eine eigene Optik gefordert, die an das Videospiel Pizza Connection erinnert (warmer Holztisch, gemalte Zutaten, Teigling auf Schaufel). Die Abweichung vom restlichen Shop ist beabsichtigt.

## Evidence on Hand
- Referenzbild des Spiels (Pizza Connection 3): Pizza auf Holzbrett, rechts Zutatenraster mit Symbolen, Verteilungs-Regler, Reiter für Rezepte.
- Keine Zutatenfotos, keine fertigen Illustrationen, keine Zutatenpreise im System. Nichts davon erfinden.

## Product Principles
- Das Personal zuerst: Jede Bestellung muss in Küche und Disposition ohne Deutung lesbar sein.
- Der Gast bestellt in Sekunden: auf dem Handy mit einer Hand bedienbar, Preis immer sichtbar.
- Eine Quelle der Wahrheit: Preise, Größen und Zutaten werden im Editor gepflegt, nicht im Code.
- Funktioniert ohne Bibliotheken und Build, bleibt schnell auf schwachem Mobilfunk.

## Accessibility & Inclusion
- Bedienung per Tastatur und Screenreader für alles, was Geld kostet (Zutatenliste, Warenkorb), unabhängig von der gemalten Optik. Kein Verlass auf Drag und Drop allein.
