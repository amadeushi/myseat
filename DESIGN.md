---
name: mySeat
description: Reservierungs- und Betriebssystem für das Amadeus Café & Restaurant Hildesheim
colors:
  candlelight-gold: "#c9a259"
  candlelight-gold-strong: "#e2c07f"
  charcoal-anthracite: "#0c0b0a"
  surface: "#151312"
  surface-2: "#1c1a18"
  surface-3: "#242220"
  border: "rgba(201, 164, 89, 0.22)"
  border-soft: "rgba(245, 240, 230, 0.08)"
  ink: "#f3ede1"
  ink-muted: "#a89e8c"
  danger: "#e2867c"
  success: "#8fbf7a"
  ink-strong: "#1a1408"
  daypart-morning: "#d8c9a6"
  daypart-evening: "#7a5c2e"
  status-new: "#62b6cb"
  status-arrived: "#5fc5a2"
  status-seated: "#a99be8"
  status-parked: "#e2b56b"
  status-noshow: "#9ba6c0"
  status-pending: "#e0a458"
  status-cancelled: "#e0685f"
  capacity-full: "#c65a4f"
  capacity-low: "#6f9c5f"
typography:
  display:
    fontFamily: "Cormorant Garamond, Georgia, serif"
    fontWeight: 500
    fontSize: "22px"
  title:
    fontFamily: "Cormorant Garamond, Georgia, serif"
    fontWeight: 500
    fontSize: "18px"
  body:
    fontFamily: "Raleway, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
    fontSize: "14px"
  caption:
    fontFamily: "Raleway, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
    fontSize: "13px"
  emphasis:
    fontFamily: "Raleway, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
    fontSize: "15px"
    fontWeight: 700
  label:
    fontFamily: "Raleway, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif"
    fontSize: "12px"
    fontWeight: 600
    letterSpacing: "0.05em"
rounded:
  sm: "6px"
  md: "10px"
  pill: "999px"
spacing:
  xs: "6px"
  sm: "8px"
  md: "16px"
  lg: "22px"
components:
  button-primary:
    backgroundColor: "{colors.candlelight-gold}"
    textColor: "{colors.charcoal-anthracite}"
    rounded: "{rounded.pill}"
    padding: "0 34px"
  button-primary-hover:
    backgroundColor: "{colors.candlelight-gold-strong}"
  button-icon:
    backgroundColor: "{colors.surface-2}"
    textColor: "{colors.candlelight-gold}"
    rounded: "50%"
  card:
    backgroundColor: "{colors.surface}"
    rounded: "{rounded.md}"
  input:
    backgroundColor: "{colors.surface-2}"
    textColor: "{colors.ink}"
    rounded: "{rounded.sm}"
---

# Design System: mySeat

## Overview

**Creative North Star: "Das vergoldete Service-Handbuch"**

mySeat ist kein Marketing-Produkt, sondern das Arbeitswerkzeug eines Restaurants: ein Reservierungsbuch, das gelernt hat, online zu sein. Die Bildsprache ist die eines gehobenen, aber gearbeiteten Handbuchs — fast schwarzer Grund, gedämpftes Gold als einzige Auszeichnungsfarbe, eine seriöse Serife für Überschriften und eine klare Grotesk für alles, was gelesen und bedient werden muss. Nichts an der Oberfläche behauptet, ein Erlebnis zu sein; sie organisiert eines.

Das System spricht mit zwei Stimmen, die aus derselben Familie stammen, aber unterschiedliche Prioritäten haben: Das **Gästewidget** (`api/`) ist die einladende, warme Stimme — großzügigere Radien, präsenteres Gold, Fließtext, der zum Verweilen einlädt. Das **Backend** (`web/`) ist die knappe, präzise Stimme fürs Personal — Scanbarkeit, Tempo und Dichte schlagen Atmosphäre, Gold wird sparsamer und funktionaler eingesetzt (Aktionen, Status, Fokus). Beide teilen dieselben Farb-, Typo- und Formwerte; nur ihre Dosierung unterscheidet sich.

Bestätigte Ablehnung: keine bunten Statusfarben außerhalb des etablierten Ampel-Sets (Gold/Rosa-Rot/Salbeigrün), kein zweiter Akzentton, keine Gradient-Flächen.

**Key Characteristics:**
- Fast schwarzer Grund mit gestuften, warmgrauen Oberflächen (surface / surface-2 / surface-3) statt Schatten
- Gedämpftes Kerzengold als einzige Akzentfarbe — sparsam, nie flächig
- Serifen-Display (Cormorant Garamond) für Überschriften, Grotesk (Raleway) für alles Funktionale
- Formsprache: weiche 10px-Karten/-Felder, Pillenform für Haupt-Aktionen, Kreis für Icon-Buttons
- Flach bei Ruhe; Tiefe entsteht nur bei schwebenden Elementen (Overlays, Dropdowns), nie bei ruhenden Flächen

## Colors

Eine einzige Akzentfarbe auf gestuften, warmschwarzen Grundflächen — Farbe markiert Bedeutung, nicht Dekoration.

### Primary
- **Kerzengold** (`#c9a259`): der einzige Akzent — Haupt-Buttons, aktive Zustände, Icons mit Bedeutung, Fokus-Ringe, Preis-/Kennzahlen-Hervorhebungen. Wird bewusst sparsam eingesetzt.
- **Kerzengold, hell** (`#e2c07f`): Hover-/Strong-Variante von Kerzengold — nie eigenständig als Grundfarbe, nur als Zustandsverschiebung.

### Neutral
- **Kohle-Anthrazit** (`#0c0b0a`): Seitenhintergrund, dunkelste Stufe.
- **Oberfläche** (`#151312`): Karten, Panels — eine Stufe heller als der Hintergrund.
- **Oberfläche 2** (`#1c1a18`): Eingabefelder, sekundäre Flächen innerhalb einer Karte.
- **Oberfläche 3** (`#242220`): dritte, hellste Flächenstufe (selten, für verschachtelte Hervorhebung).
- **Rand** (`rgba(201,164,89,.22)`): Kartenumrandung, in Goldton getönt statt neutralem Grau — hält auch die Trennlinien in der Farbfamilie.
- **Rand, weich** (`rgba(245,240,230,.08)`): dezente Trenner innerhalb einer Karte.
- **Tinte** (`#f3ede1`): Haupttext, warmes Off-White statt reinem Weiß.
- **Tinte, gedämpft** (`#a89e8c`): Sekundärtext, Platzhalter, Labels.
- **Tinte, stark** (`#1a1408`): Text/Icons auf gold gefüllten Flächen (aktiver Tab, Primär-Button, Hover-Zustand von Icon-Buttons) — dunkler als jede Oberflächenfarbe, eigens für Kontrast auf Gold gedacht.

### Status (Ampel-Set, sparsam)
- **Warnrot** (`#e2867c`): Fehler, Stornierungen, volle Kapazität — gedämpft, nie signalrot.
- **Salbeigrün** (`#8fbf7a`): Erfolg, freie Kapazität, Bestätigung — gedämpft, nie giftgrün.
- Für Warnhinweise (`.alert_warning`, "neu eingetroffene Reservierung"-Hervorhebung) wird bewusst **kein** drittes Ampel-Signal eingeführt, sondern Kerzengold-hell wiederverwendet (Warnung liegt näher an "Hinweis" als an "Fehler").

### Tageszeit-Markierung (eigene, kleine Skala)
Am Rand jeder Reservierungszeile markiert eine Farbe die Tageszeit — kein Ampel-Ersatz, sondern eine rein informative Zusatzskala:
- **Morgens** (`#d8c9a6`), **Nachmittags** (Kerzengold `#c9a259`), **Abends** (`#7a5c2e`) — drei warme, goldverwandte Töne, keine neue Farbfamilie.

### Reservierungsstatus (kategoriale Ausnahme vom One-Accent-Prinzip)
Sieben Reservierungsstatus (`.status_dbox.st-*`) müssen auf einen Blick unterscheidbar sein — dafür sind sieben eigenständige Farbtöne nötig, keine Drift:
- **Neu** (`#62b6cb`), **Angekommen** (`#5fc5a2`), **Sitzt** (`#a99be8`), **Reserviert/geparkt** (`#e2b56b`), **No-Show** (`#9ba6c0`), **Ausstehend** (`#e0a458`), **Storniert** (`#e0685f`). Jede Farbe hat eine passende 35%-Alpha-Variante für Rahmen/Hintergrund.

### Kapazitäts-Zeitleiste (eigenes, kleines Ampel-Set)
Die stündliche Auslastungsanzeige (`.timeline`) nutzt ein eigenes, gedämpfteres Ampel-Set statt Warnrot/Salbeigrün direkt zu verwenden — es muss über 30+ nebeneinanderliegenden Balken ruhiger wirken als ein Formular-Fehler: **Voll** (`#c65a4f`), **Hoch** (Kerzengold), **Frei/niedrig** (`#6f9c5f`), **Keine Daten** (`#4a463f`, eine Oberflächenfarbe).

### Overlay
Modal-Hintergründe (jQuery UI, Fancybox) nutzen reines Schwarz bei reduzierter Deckkraft (`#000` bei 65–70% Opazität) statt einer Palettenfarbe — ein Overlay muss über beliebigem Inhalt funktionieren, nicht nur über der dunklen Oberfläche.

### Named Rules
**The One Accent Rule.** Gold ist die einzige *dekorative* Akzentfarbe im System. Neue Dekoration in einer zweiten Farbe ist immer falsch. Ausgenommen sind die hier benannten, bewusst begrenzten Bedeutungssysteme (Formular-Status, Reservierungsstatus, Kapazitäts-Ampel, Tageszeit) — die dürfen eigene Töne haben, weil sie echte, unterscheidbare Kategorien codieren, aber nie mehr Töne als die hier dokumentierten.

## Typography

**Display Font:** Cormorant Garamond (mit Georgia, serif als Fallback)
**Body Font:** Raleway (mit -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif als Fallback)

**Character:** Eine klassische Buchdruck-Serife für alles, was der Gast als "Überschrift eines Restaurants" liest, kombiniert mit einer neutralen, gut lesbaren Grotesk für alles Funktionale — Formulare, Tabellen, Navigation. Die Serife trägt Atmosphäre, die Grotesk trägt Arbeit.

### Hierarchy
Konsolidiert am 29.09.2026 (`/impeccable extract`, zweiter Durchgang): `theme-dark.css` streute vorher 15 verschiedene Schriftgrößen. Ein Teil davon war echte, beabsichtigte Stufung (Überschriften-Ebenen, Statistik-Heldenzahlen) und blieb unangetastet; der Rest war Zufalls-Drift und wurde auf die Stufen unten zusammengeführt (11px→12px, 17px/20px→18px als neue Title-Stufe).

- **Display** (500, 22–26px, Cormorant Garamond): Seitentitel, Kartentitel im Backend (`.header h2`/`h3`), große Überschriften im Gästewidget. Drei echte Stufen (22/24/26px je nach Ebene), keine Drift — nicht weiter vereinheitlichen.
- **Title** (500, 18px, Cormorant Garamond): neue, vorher fehlende Zwischenstufe — Dialog-Titel, Abschnittsüberschriften innerhalb einer Karte (z. B. "Bestellungen"-Leiste), hervorgehobene Tabellenwerte (Uhrzeit-Spalte), kompakte Kennzahlen in Stat-Kacheln.
- **Body** (400, 14px, Raleway): der primäre Lesefall — Formularfelder-Inhalt, Dropdowns, Hinweistexte, Menüpunkte. Im Gästewidget und bei fokussierbaren Formularfeldern bewusst 16px, damit iOS beim Fokussieren nicht hineinzoomt (siehe Named Rule).
- **Caption** (400, 13px, Raleway): sekundärer, gedämpfter Fließtext — eine Stufe leiser als Body, gleiche Familie. Basis-`body`-Textgröße des Backends.
- **Emphasis** (700, 15px, Raleway): kleine, fette Auszeichnung innerhalb einer Karte — Kategorienamen, Unterabschnitts-Label, anklickbare Zusammenfassungs-Zeilen. Kein Title (keine Serife), aber schwerer als Body.
- **Label** (600, 12px, Raleway, Versalien, 0.05em Sperrung): Feldbeschriftungen, Tab-Beschriftungen, Zeitstempel, Badges, Kennzahlen-Titel. Vorher 11–12px gemischt, jetzt einheitlich 12px.

### Named Rules
**The 16px Form Rule.** Jedes Eingabefeld, das auf einem Touchgerät fokussierbar ist, bleibt bei mindestens 16px Schriftgröße — kleiner löst den ungewollten iOS-Zoom aus.
**The Six-Step Rule.** Die gesamte Skala hat genau sechs Stufen, aufsteigend: Label (12px) → Caption (13px) → Body (14px) → Emphasis (15px) → Title (18px) → Display (22–26px). Ein neuer Wert dazwischen (z. B. "16,5px, weil es gerade so aussah") ist Drift, keine neue Stufe.

## Layout

Karten mit 16–22px Innenabstand, 16–22px Abstand zwischen verwandten Feldgruppen. Formulare nutzen ein Grid mit 16px Zeilen-/22px Spaltenabstand, das unterhalb von 820px auf eine einzelne Spalte fällt statt auf enger werdende Mehrspaltigkeit — ein schmales Feld (z. B. ein Zähler mit zwei Buttons daneben) verliert sonst zuerst seine Lesbarkeit, nicht zuletzt.

**Bekannte Schwäche:** Die Breakpoints sind historisch gewachsen und uneinheitlich (600px, 700px, 820px, 900px, 940px, 1100px je nach Komponente). Kein Wert ist falsch, aber neue Komponenten sollten sich an 820px (Formular-/Karten-Umbruch) oder 600px (kompakteste Stufe) orientieren statt einen weiteren Zwischenwert einzuführen.

## Elevation & Depth

Das System ist bei Ruhe flach: Tiefe entsteht durch gestufte Flächenfarben (surface / surface-2 / surface-3) und feine, goldgetönte 1px-Ränder, nicht durch Schatten. Schatten sind reserviert für Elemente, die buchstäblich über dem Inhalt schweben — Dropdown-Panels, Fancybox-Modale, das Sound-Einstellungs-Panel.

### Shadow Vocabulary
- **Schwebend** (`box-shadow: 0 16px 40px rgba(0,0,0,.5)`, kleinere Elemente wie Toasts `0 8px 28px rgba(0,0,0,.5)`): Dropdown-Panels, Popover und Toasts, die über dem Seiteninhalt liegen. Alpha am 29.09.2026 von drei leicht unterschiedlichen Werten (.6/.5/.45) auf einheitlich .5 konsolidiert (`/impeccable extract`).

### Named Rules
**The Flat-At-Rest Rule.** Eine Karte, ein Button, ein Eingabefeld im Ruhezustand bekommt nie einen Schatten — nur Fläche, Rand, Farbe. Schatten ist ausschließlich der Beweis, dass ein Element gerade über etwas anderem schwebt.

## Shapes

**10px als Standardradius**, konsolidiert am 29.09.2026 (`/impeccable extract`): `web/css/theme-dark.css` definiert jetzt `--radius-sm` (6px), `--radius-md` (10px) und `--radius-pill` (999px) im `:root`; alle ~50 vormals verstreuten Werte (8/10/11/12/14/17px) wurden auf diese drei Tokens migriert. Das Gästewidget (`api/style/style.css`) nutzt weiterhin sein eigenes, wertgleiches `--radius: 10px` in `.booking-shell` — beide Systeme sind damit im Radius bereits vereinheitlicht.

- **Klein (6px):** Chips, Badges, kleine Inline-Kapseln (z. B. Tisch-Nummern-Tags, Code-Snippets).
- **Mittel (10px):** Karten, Eingabefelder, die meisten Buttons, Panels — der Standardfall.
- **Pille (999px):** primäre Aktions-Buttons (Speichern, Weiter, Heute-Button), Status-Badges.
- **Kreis (50%):** reine Icon-Buttons (±-Stepper, Datums-Vor/Zurück-Pfeile).

Kanten sind durchgehend weich gerundet, nie scharf — passend zur warmen, einladenden Grundhaltung. Keine Formsprache mit hartem Versatz-Schatten oder eckigen Neobrutalismus-Anleihen.

## Components

### Buttons
- **Shape:** Pille (999px) für primäre Aktionen; Kreis (50%) für reine Icon-Buttons; 10px-Rechteck für sekundäre/Tab-artige Buttons.
- **Primary:** Hintergrund Kerzengold, Text Kohle-Anthrazit, fett (700), 0 34px Innenabstand, min. 46–48px hoch.
- **Hover / Focus:** Hover hellt auf Kerzengold-hell auf; Fokus zeigt einen 2px Kerzengold-Outline mit Versatz — nie nur eine Farbänderung ohne sichtbaren Ring.
- **Icon-Button:** Oberfläche-2-Hintergrund, Kerzengold-Icon, 1px Rand in Rand-Farbe; Hover kehrt zu Kerzengold-Hintergrund mit dunklem Icon um (Farbinversion, kein reines Aufhellen).
- **Sekundär/Ghost:** transparenter Hintergrund, 1px Rand, gedämpfte Tinte als Text; aktiv/ausgewählt wechselt zu vollem Kerzengold-Hintergrund.

### Chips
- **Style:** Oberfläche-2-Hintergrund, 1px Rand, 6px Radius, 12–14px Text.
- **State:** ausgewählt = voller Kerzengold-Hintergrund mit dunklem Text; "passt"/"Vorschlag"/"besetzt" werden über Randfarbe und -stil (durchgezogen/gestrichelt) unterschieden, nicht über zusätzliche Akzentfarben.

### Cards / Containers
- **Corner Style:** 10px (Ziel), aktuell 8–14px je nach Stelle (siehe Shapes).
- **Background:** Oberfläche (`#151312`), eine Stufe über dem Seitenhintergrund.
- **Shadow Strategy:** keiner im Ruhezustand (siehe Elevation & Depth).
- **Border:** 1px, goldgetönter Rand.
- **Internal Padding:** 16px (mobil) bis 22px (Desktop).

### Inputs / Fields
- **Style:** Oberfläche-2-Hintergrund, 1px weicher Rand, 8–10px Radius, min. 46px hoch, 16px Schrift.
- **Focus:** Randfarbe wechselt zu Kerzengold; kein Farbwechsel der Fläche.
- **Error:** Randfarbe Warnrot plus leicht rosa getönter Hintergrund (`rgba(226,134,124,.10)`); Fehlermeldung in Warnrot direkt unter dem Feld.

### Navigation
- **Style:** Flache Textlinks im Ruhezustand; aktiver Tab bekommt vollen Kerzengold-Hintergrund mit dunklem Text (nicht nur Unterstreichung). Datumsnavigation (Vor/Zurück) sind Kreis-Icon-Buttons mit SVG-Chevron, kein Unicode-Pfeil und kein `<<`/`>>`-Text.
- **Mobile:** Touch-Ziele mindestens 44px hoch (mit `box-sizing: border-box` — ein früherer Fehler ließ sie durch `content-box` auf 65px anwachsen, siehe Do's and Don'ts).

## Do's and Don'ts

### Do:
- **Do** Kerzengold als einzige Akzentfarbe behandeln — Status-Ampel (Warnrot/Salbeigrün) ausgenommen.
- **Do** neue rundliche Flächen auf 10px Radius bringen (6px für kleine Chips, Pille für Haupt-Aktionen, Kreis für Icon-Buttons) — siehe Shapes.
- **Do** `box-sizing: border-box` explizit setzen, wenn eine Komponente `min-height`/`height` mit Padding kombiniert — die Basis-Stylesheets setzen es nicht global.
- **Do** dichte Datenreihen (z. B. stündliche Auslastung) bei schmalen Bildschirmen horizontal scrollen lassen statt die Schrift unter die Lesbarkeitsgrenze zu skalieren.
- **Do** SVG-Icons in einem Strich (currentColor, ~1.6–2.2px Stroke) verwenden, nie Unicode-Pfeile/Emoji als Icon-Ersatz.

### Don't:
- **Don't** eine zweite Akzentfarbe einführen, auch nicht "nur für diese eine Kachel".
- **Don't** einem ruhenden Element (Karte, Button, Feld) einen Schatten geben — Schatten ist ausschließlich für schwebende Overlays reserviert.
- **Don't** ein mehrspaltiges Formular-Grid unterhalb von 820px beibehalten, wenn eine Spalte darin (z. B. ein Stepper mit zwei Buttons) auf unter ~50px Breite fällt — lieber auf eine Spalte stapeln.
- **Don't** eine feste HTML-`<table>` mit Pixel-`width`-Attributen für ein Kennzahlen-Layout verwenden, ohne eine mobile Reflow-Regel (`display:block`) danebenzustellen.
- **Don't** neue Radius-, Breakpoint- oder Abstandswerte "nach Gefühl" ergänzen — an den bestehenden Skalen in Shapes/Layout orientieren, auch wenn der Altbestand selbst noch driftet.
