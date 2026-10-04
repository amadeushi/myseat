=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
=-=                                       =-=
=-=           mySeat README               =-=
=-=                                       =-=
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
=-= Version: 4.5.0                         =-=
=-= Date:    30.09.2026                   =-=
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=


mySeat - Restaurant Reservation software.

Beautifully simple restaurant reservations.
Collaborate effortlessly on reservations.
mySeat will help you keep track of your reservations with ease.


News
====

 * Current fork (PHP 8 port, new booking form and backend theme) - http://github.com/amadeushi/myseat
 * Runs on PHP 8.x with mysqli (tested on PHP 8.5, MariaDB 12.3); no database changes since v0.2160
 * New Repo - http://github.com/apmuthu/myseat
 * Get the latest tarball at: https://nodeload.github.com/apmuthu/myseat/tar.gz/master
 * Add Property Vulnerability Workaround - rename and disable web/properties.php when not needed

 
VERSIONING
==========

From v1.0.0 this fork uses Semantic Versioning (MAJOR.MINOR.PATCH). The old 0.2xxx counter
came from the upstream project (mySeat, last seen at v0.2166) and was raised with every small
change; it said nothing about scope. The fork has since grown far beyond it, so the numbering
starts fresh. The 0.2xxx entries below stay as history.

 * MAJOR (X.0.0)  a large theme, or a change that needs action on the server: new required
                  settings in config.general.php, a new cron job, a database step
 * MINOR (1.X.0)  a new feature that needs nothing from you: a new mail, a new backend area
 * PATCH (1.0.X)  a fix or polish, nothing to do

Every release: update $sw_version in web/main_page.php, add a changelog entry here, tag the
commit (git tag vX.Y.Z). Based on mySeat by Bernd Orttenburger and contributors, GPL v3.


CHANGELOG
=========

Versions 0.2161 - 3.0.0 are maintained in http://github.com/amadeushi/myseat.
No manual database update is needed for any of them (the table plan (v0.2171, v0.2172) creates its own
tp_* tables on first use). Optional new settings for
config/config.general.php (defaults apply when missing):
  $settings['lastBookingMinutes'] = 60;   (v0.2165)  last online booking, minutes before closing
  $settings['brandName'] = 'Amadeus';     (v0.2166)  name shown in the backend header and login

2026-10-05 == mySeat v6.15.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Zwei Domains, eine Installation: app.amds.at für Gäste (Bestellseite, Konto, Bestellstatus, Fahrerseite), reservierung.amds.at
   für Backend, Monitore und Tischreservierung. Die Weiche steht in der .htaccess: auf der App-Domain leitet "/" auf die Bestellseite,
   Backend, Anmeldung, Reservierungs-API und Installer gehen an die Hauptdomain (308); alte Bestelllinks auf der Hauptdomain leiten
   mit ihrer Abfrage zur App-Domain weiter.
 * New: config/hosts.inc.php legt die Adressen zentral fest (app_url für Links an Gäste, admin_url für Stornierungs- und Bewertungslinks);
   leer gilt die Adresse der Anfrage.
 * Fix: Bilder der Speisekarte werden mit Pfad statt mit Domain gespeichert (/uploads/menu/...) und gelten auf beiden Domains; früher
   mit Domain gespeicherte Bilder werden beim Öffnen des Editors umgewandelt.

2026-10-05 == mySeat v6.14.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Feiertage, Ruhetage und abweichende Zeiten (Einstellungen > Lieferservice): ein Tag oder Zeitraum, geschlossen oder mit eigenen
   Zeiten, für Lieferung und Abholung oder nur eine von beiden, auf Wunsch jedes Jahr. Ein Eintrag ersetzt an dem Tag die Wochenzeiten,
   der nur für Lieferung oder Abholung geht vor, dann der kürzere Zeitraum. Gäste sehen "heute nicht möglich (Bezeichnung)", Bestellungen
   für diese Tage sind auch im Voraus nicht möglich. Ein Knopf trägt die gesetzlichen Feiertage Niedersachsens als "geschlossen" ein.
 * Fix: Der Warenkorb der Bestellseite läuft am Desktop beim Scrollen mit (eine Abstandsangabe wurde von "inset: auto" überschrieben).

2026-10-05 == mySeat v6.13.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Bestellzeiten für Lieferung und Abholung werden im Backend eingestellt (Einstellungen > Lieferservice): mehrere Zeitfenster pro Tag,
   "Auf alle Tage" kopiert einen Tag, geprüft auf Ende nach Beginn und Überschneidungen. Die Zeiten sind nicht mehr nur eine Anzeige der
   einmaligen Übernahme aus Resmio.
 * New: Bilder der Speisekarte liegen auf dem eigenen Server (uploads/menu): hochladen, ersetzen und entfernen im Gericht-Formular, die
   Bilder werden verkleinert und als WebP gespeichert, nicht mehr benutzte Dateien werden gelöscht.
 * New: "Alle auf den Server holen" übernimmt die bisher fremd verlinkten Bilder in einem Durchgang; fehlgeschlagene werden aufgelistet.

2026-10-05 == mySeat v6.12.1 == amadeushi - http://github.com/amadeushi/myseat

 * New: Lupe in jeder Spalte des Küchenmonitors: zeigt die ganze Bestellung ohne Scrollen, indem Kopf und Gerichte verkleinert werden
   (Gerichte höchstens bis 60 %, danach scrollt die Liste wieder). Ein zweiter Tipp auf die Lupe stellt die normale Größe wieder her.
 * New: Der Name des Gastes steht im Küchenmonitor auch bei Abholungen, um sie auseinanderzuhalten.

2026-10-05 == mySeat v6.12.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Küchenmonitor zeigt bei Lieferungen Name und Postleitzahl des Gastes (zum Durchsprechen der Touren); der Küchenbon trägt Name
   und bei Lieferungen die Postleitzahl, bei Abholungen den Namen.
 * New: Das Druckersymbol ersetzt den Knopf "Bon drucken" in der Küchenkarte und spart Platz für die Gerichte.
 * New: Handbuchseite "Handbuch Konfigurator" (Backend, Menüeditor): wie Symbole und Namen der Zutaten erkannt werden, mit Namens-Tester
   und Symbol-Galerie.
 * New: Tomatensoße ist auf den runden Pizzen im Konfigurator sichtbar.
 * New: Seitliche Leisten im Webshop und Konfigurator lassen sich am Desktop mit der Maus ziehen.
 * Fix: Kundenkonto-Fenster auf dem Handy wird nicht mehr abgeschnitten und springt beim Scrollen nicht mehr.

2026-10-05 == mySeat v6.11.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Disposition im Hochformat (Monitor auf der Seite, ab etwa 1000 x 1500 Pixel; kleinere Hochformat-Bildschirme behalten die
   scrollende Spalte, das Querformat bleibt bei drei Spalten). Bänder von oben nach unten nach Dringlichkeit: fehlgeschlagene Lieferungen
   als rote Zeile, "Neu" mit breiten Karten (Gerichte links, Annehmen-Knöpfe 20/30/45/60 Minuten rechts), die Küche als eine Zeile
   ("3 in der Küche, dringendste: #47 10 Min überfällig", ein Klick klappt die Liste auf), "Fertig, wartet auf Fahrer" (orange ab 5, rot ab
   10 Minuten Wartezeit, mit Text) und "Unterwegs" (Lieferzeit, "5 Min zu spät", unterwegs seit). Jedes Band blättert mit großen Knöpfen
   (Trackball); die Seitengröße richtet sich nach der Höhe des Monitors. Eine neue Bestellung bringt die Seite "Neu" von selbst dorthin,
   solange niemand am Bildschirm arbeitet, sonst blinkt die Leiste; nach 45 Sekunden Ruhe geht jedes Band zurück auf Seite 1. Nur die
   gezeigten neuen Bestellungen halten den Ton an. Die Pausenschalter für Lieferung und Abholung stehen in der Kopfzeile.
 * New: Fahrer zuteilen. Eine fertige Lieferung lässt sich in der Disposition einem Fahrer zuteilen (Fahrer mit aktuellem Standort, mit
   "fährt gerade"/"frei"/"vorgemerkt"; auf Wunsch auch Fahrer ohne Standort) oder umteilen. Sie landet wie bei der Fahrer-App in dessen
   Warteschlange und steht im Protokoll (Funktion shop_dispatch_assign_order). Das Board liefert dafür due_ts, ready_ts, updated_ts,
   driver_id, die Fahrerliste, den Pausenstand und die Fahrzeit.
 * New: Fehlgeschlagene Lieferung zurückholen. "Nochmal zustellen" zeigt den Grund des Fahrers und lässt Straße, PLZ, Ort, Hinweis für den
   Fahrer und Telefon korrigieren; eine geänderte Adresse wird gegen die Liefergebiete neu geprüft (neue Koordinaten und Zone). Danach
   ist die Bestellung wieder fertig und ohne Fahrer im Pool. Der Gutschein der Bestellung wird wieder gebucht (er war beim Fehlschlag
   freigegeben worden), der bezahlte Betrag bleibt, ein Unterschied der Liefergebühr steht nur im Protokoll (shop_dispatch_retry_order).
   Der bisherige Knopf "Zurück in den Pool" an fehlgeschlagenen Lieferungen hat nie funktioniert und ist ersetzt.
 * New: "Lieferschein drucken" und "Gast-Link kopieren" sind zwei gezeichnete Symbole (Drucker, Kettenglied) statt zwei Textknöpfen auf
   jeder Karte.
 * Fix: Die Kopfzeile der Bildschirme bricht um, wenn der Platz fehlt, statt die Zähler zusammenzudrücken.

 * New: Küchenbildschirm blättert. Eine Seite zeigt so viele Bestellungen wie Spalten (4 oder 5); die Reihenfolge richtet sich danach,
   wann eine Bestellung die Küche verlassen muss. Unten eine Leiste mit großen Knöpfen "Zurück" und "Weiter" (bedienbar mit einem
   Trackball, kein Wischen, keine Tastatur nötig; Pfeiltasten und Scrollrad blättern zusätzlich), "Seite 1 von 2" und je ein Kärtchen
   für jede Bestellung auf einer anderen Seite (Nummer, "Sofort", Zeit "raus bis"; orange bei knapper Zeit, rot mit "überfällig").
   Ein Klick auf ein Kärtchen springt auf die Seite. Eine neue Bestellung auf einer anderen Seite löst den Ton einmal aus und ihr
   Kärtchen blinkt, bis die Seite angesehen wurde; die gezeigte Seite springt nicht weg. Nach 45 Sekunden ohne Eingabe geht der
   Bildschirm zurück auf Seite 1, eine leere Seite wird übersprungen. Nur Bestellungen der gezeigten Seite halten den Ton an
   (vorher klingelte der Monitor bei "bis jemand reagiert" weiter, solange eine Bestellung im Überlauf wartete).
 * New: Küchenbildschirm zeigt deutlich, wenn der Gast es so schnell wie möglich will (orangefarbener Streifen "Sofort"), und bei
   Lieferungen die Zeit "Raus bis": Lieferzeit minus Fahrzeit, mit Zähler (orange ab 5 Minuten, rot und "überfällig" danach). Die
   Fahrzeit (Vorgabe 15 Minuten) steht unter Einstellungen > Lieferservice. Die Karten sind verdichtet, damit die Gerichte auch
   auf 768 Pixel Höhe Platz haben. Der Knopf heißt jetzt "Fertig" (vorher "Fertig, ausgegeben").
 * New: Bestellungen pausieren. Im Dashboard Bestellungen stehen zwei Schalter "Lieferung annehmen" und "Abholung annehmen". Aus
   heißt: Pause für 15 Minuten, 30 Minuten, 1 oder 2 Stunden oder bis zum Wiedereinschalten; eine Pause mit Dauer endet von selbst.
   Während der Pause sind neue Bestellungen dieser Art gesperrt (auch für eine spätere Zeit); der Shop und die Kasse zeigen
   "gerade pausiert bis etwa 19:42 Uhr", und eine schon offene Kasse bekommt beim Absenden die Meldung. Bestehende Bestellungen
   bleiben unberührt.
 * New: Jede Position im Warenkorb hat ein Feld "Hinweis für die Küche" (Gerichte ohne Auswahl hatten bisher keins, weil sie ohne
   Produktfenster direkt im Warenkorb landen).

2026-10-05 == mySeat v6.9.1 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: Die Bestellbestätigung an den Gast und die Kopie an das Restaurant gingen nicht raus, wenn im Shop keine Absenderadresse
   eingetragen war (Einstellungen > Lieferservice > E-Mail). Sie nehmen dann wie die Mails des Kundenkontos die E-Mail-Adresse
   aus den Stammdaten (shop_mail_from()). Datei: web/classes/shop_mail.class.php.

2026-10-05 == mySeat v6.9.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Kundenkonto im Bestellshop. Gäste melden sich ohne Passwort mit E-Mail-Adresse oder Handynummer an: Sie bekommen einen
   6-stelligen Code und einen Link (bei SMS ein Kurzlink über YOURLS, nur im GSM-Alphabet und in einer SMS; ohne YOURLS nur der
   Code). Code und Link gehören zu einer Anmeldung, wer einen nutzt, entwertet den anderen; beide gelten 10 Minuten, der Code
   hält 5 Fehlversuche aus. Die Seite hinter dem Link (order/anmelden.php) zeigt nur einen Knopf, damit Mail-Scanner und
   Linkvorschauen ihn nicht verbrauchen. Codes und Sitzungen liegen nur als Hash in der Datenbank, die Antwort verrät nie, ob es
   ein Konto gibt, und es gibt Grenzen je Adresse (3 in 15 Minuten), je Besucher (10 pro Stunde) und ein Tageslimit für Anmelde-SMS.
   Das Konto ist die bestätigte Nummer oder Adresse: Die früheren Bestellungen und die Stempel werden über dieselben Schlüssel
   gefunden wie bei der Stempelkarte, es ist nichts zu übernehmen. Eine zweite Angabe lässt sich später bestätigen und
   ergänzen. Im Konto: Bestellungen mit "Nochmal bestellen" (Preise von heute, nicht mehr verfügbare Gerichte und neue Preise
   werden genannt), Favoriten (Herz am Gericht und in jeder Warenkorbzeile, auch für eine selbst belegte Pizza, dazu eine
   Favoritenzeile über der Karte) und die Stempelkarte. Abmelden, auf allen Geräten abmelden und Konto löschen (Bestellungen
   bleiben aufbewahrt). In der Kasse füllt ein angemeldeter Gast Name, Telefon, E-Mail und Adresse der letzten Bestellung aus.
   Einstellungen: Einstellungen > Lieferservice > Kundenkonto (an/aus, Code auch per SMS, Anmelde-SMS pro Tag, Vorgabe 100).
   Neue Tabellen tp_shop_accounts, tp_shop_login_codes, tp_shop_sessions, tp_shop_favorites sowie die Spalten
   tp_shop_order_items.variation_id und tp_shop_orders.guest_key/guest_key2 legt das System beim ersten Aufruf selbst an.
   Bestellpositionen speichern jetzt zusätzlich die IDs von Variante und Optionen, damit sich eine Bestellung sicher wiederholen
   lässt (ältere Bestellungen werden über die Titel zugeordnet). Dateien: web/classes/shop_account.class.php,
   order/konto.js, order/konto.css, order/anmelden.php.
 * New: Stempel gibt es nur noch mit Kundenkonto. Eine Bestellung merkt sich das Konto, unter dem sie aufgegeben wurde
   (tp_shop_orders.account_id); nur solche Bestellungen bekommen einen Stempel, und zwar auf der Karte des Kontos, auch wenn in
   der Kasse eine andere Nummer steht. Der Stempel-Gutschein wird ebenfalls über die Schlüssel des Kontos geprüft. Im Warenkorb
   und in der Kasse steht für Gäste ohne Anmeldung ein dezenter Hinweis, wie viel Stempel-Guthaben ihnen bei dieser Bestellung
   entgeht. Ist das Kundenkonto ausgeschaltet, gilt die Stempelkarte wie bisher.
 * New: Reiter "Meine Daten" im Konto: Name, Telefonnummer (Kontakt, keine zweite Anmeldung) und Lieferadresse mit Hinweis für den
   Fahrer. Die Adresse wird nur gespeichert, wenn dorthin geliefert wird, und zeigt gleich Liefergebühr und Mindestbestellwert.
   Beim Anmelden landet sie im Feld "Liefert ihr zu mir" der Shopseite (Prüfung, Gebühr und Mindestbestellwert im Warenkorb) und
   in der Kasse. Die Kasse füllt außerdem Telefon, E-Mail und Name aus der Anmeldung vor. Das Konto lernt Name und Nummer aus
   der ersten Bestellung (Spalten tp_shop_accounts.contact_phone/contact_mail/addr_*, tp_shop_login_codes.target_plain).
 * Fix: Die Anmelde-Mail ging nicht raus, wenn im Shop keine Absenderadresse eingetragen war ("Senden nicht möglich"). Mails
   des Kontos und der Stempelkarte nehmen dann die E-Mail-Adresse aus den Stammdaten (shop_mail_from()).
 * Fix: Die Kasse zeigt den Stempelstand nur noch dem angemeldeten Gast. Wer eine fremde Nummer eintippt, sieht nicht mehr,
   wie viele Stempel sie hat (ein vorhandener Gutschein wird weiter abgezogen und angezeigt, denn er gilt für die Bestellung).
   Ohne Anmeldung zeigt die Kasse den allgemeinen Hinweis und einen Link zum Anmelden. Die Statusseite bekommt einen Hinweis
   auf das Konto.

2026-10-05 == mySeat v6.8.2 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: Der Wunschpizza-Konfigurator trägt jetzt die Farben des Shops. Die Schaltfläche "In den Warenkorb" ist Gold mit
   dunkler Schrift wie im Warenkorb, Kopf- und Fußleiste sind schwarz mit feiner Goldlinie, Zurück, "Neu belegen" und die
   Mengenwahl im Shop-Stil, gewählte Teigsorte, Zähler und Haken dunkel mit Gold, der Rahmen der gewählten Zutat ein gedecktes
   Gold. Pizza, Holztisch, Zutaten-Symbole, Soßen-Schälchen und das Pergament-Tablett sind unverändert, ebenso die rote
   Markierung bei fehlenden Pflichtangaben (order/pizza.css).

2026-10-05 == mySeat v6.8.1 == amadeushi - http://github.com/amadeushi/myseat

 * New: Stempelkarte, Mails und Gutschein-Regel überarbeitet. Die Mails nach jedem Stempel und bei voller Karte sprechen
   den Gast mit Vornamen an, zeigen die Stempelkarte mit den goldenen Zeus-Stempeln als Bild (order/mail/stempel-N-von-5.png,
   für fünf Stempel pro Karte) und haben für den Gutschein einen goldenen Betrags-Kasten, im Stil der Bestellbestätigung
   mit Gruß und Anbieterangaben. Der Gutschein wird jetzt immer komplett eingelöst: Er gilt ab einem Warenwert in Höhe
   seines Betrags (Mindestbestellwert des Gutscheins), einen Rest-Gutschein gibt es nicht mehr. Der Code steht in der Mail
   und lässt sich im Gutscheinfeld der Kasse von Hand eingeben; die Vorschau dort prüft persönliche Codes mit Telefon und
   E-Mail der Kasse, die Meldungen sagen, wenn ein Code zu einer anderen Nummer gehört. Die Karte in der Kasse zeigt, was
   bis zum Mindestwert fehlt. SMS-Text angepasst.
 * Fix: Der Knopf zum Shop in den Stempel-Mails fehlte, wenn die Mail aus dem Backend entstand (Personal setzt
   "erledigt"); die Adresse kommt jetzt aus shop_site_url().

2026-10-05 == mySeat v6.8.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Stempelkarte im Bestellshop (nach dem Vorbild der Lieferando-Stempelkarte). Jede abgeschlossene Bestellung
   ("erledigt" im Backend) ist ein Stempel; Test-, stornierte und fehlgeschlagene Bestellungen zählen nicht. Der Gast
   wird wie bei den Gutscheinen an Telefonnummer und E-Mail der Bestellung erkannt. Ist die Karte voll (5 Stempel),
   entsteht ein persönlicher Gutschein über 10 % der Warenwerte dieser Bestellungen: ein Gutschein des eigenen
   Gutscheinsystems (Code STEMPEL-..., fester Betrag, einmal einlösbar, an die Gast-Schlüssel gebunden). Stempel gelten
   12 Monate, der Gutschein 90 Tage. Er wird bei der nächsten Bestellung automatisch abgezogen, es sei denn, der Gast gibt
   einen eigenen Code ein; wird nur ein Teil gebraucht, entsteht für den Rest ein neuer Gutschein mit gleichem Ablauf, eine
   stornierte Bestellung stellt den ursprünglichen Gutschein wieder her. Für jeden Stempel geht eine Mail an den Gast, für
   eine volle Stempelkarte eine Mail oder (ohne E-Mail) eine SMS. Neu: Tabelle tp_shop_stamps, Spalten source, guest_key,
   guest_key2, parent_id an tp_shop_coupons (werden selbst angelegt); Schnittstelle order/api.php op=stamp_state;
   Einstellungen stamp_on, stamp_percent, stamp_goal, stamp_months, voucher_days (Backend: Einstellungen, Lieferservice,
   "Stempelkarte"). Optik: Zeus als goldener Stempel im Ring "AMADEUS DELIVERY" (order/stempel.css, stempel.js,
   zeus-stamp.png); in der Kasse erscheint die Karte, sobald Telefon oder E-Mail eingegeben sind, auf der Statusseite
   schlägt der neue Stempel einmal auf.

2026-10-05 == mySeat v6.7.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Kurzlink für die gemeinsame Bestellung. Sobald eine Gruppe läuft, legt der Server einmal einen Kurzlink über das
   eigene YOURLS an (wie beim Absagelink, Ablauf nach 24 Stunden) und speichert ihn am Korb (tp_shop_baskets.short_url,
   wird selbst angelegt). Das Teilen-Feld und der Teilen-Dialog zeigen ihn statt des langen Links; ist YOURLS aus oder
   antwortet nicht, bleibt der lange Link. Schnittstelle: order/api.php op=basket_link.

2026-10-05 == mySeat v6.6.1 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: Gemeinsame Bestellung: Hat jemand den Warenkorb geändert, während bei der bestellenden Person noch eine ältere
   Kasse offen war (zum Beispiel nach dem Wiedereröffnen), wurde mehr bestellt als angezeigt. Die Kasse merkt sich jetzt
   den Stand des Korbs (Fingerabdruck aus Status, Personen und Mengen, op=create mit rev); hat er sich geändert, wird
   nichts abgeschickt und die Person bekommt den Hinweis, die Seite neu zu laden und die Bestellung zu prüfen.

2026-10-05 == mySeat v6.6.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Gemeinsam bestellen im Bestellshop. Ein Gast startet im Warenkorb oder über den Knopf in der Kopfzeile eine
   gemeinsame Bestellung, gibt seinen Namen ein und teilt den Link. Wer den Link öffnet, nennt seinen Namen und füllt
   den gemeinsamen Warenkorb mit seinen Gerichten (auch mit dem Wunschpizza-Konfigurator). Der Warenkorb ist nach Personen
   getrennt, jede Person sieht ihre Zwischensumme, ändern kann sie nur die eigenen Gerichte, die bestellende Person alle.
   Die Ansicht aktualisiert sich alle paar Sekunden. Eine Person schließt den Warenkorb ab (danach ist er gesperrt),
   geht normal zur Kasse und bezahlt, auch online. Auf dem Bon steht bei jedem Gericht "für <Name>", die Bestellnotiz
   nennt alle Teilnehmer. Der Korb verfällt sechs Stunden nach der letzten Aktivität. Neue Tabellen (werden selbst
   angelegt): tp_shop_baskets, tp_shop_basket_members, tp_shop_basket_lines; Schnittstelle: order/api.php op=basket_*.

2026-10-04 == mySeat v6.5.1 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: Soßentöpfchen im Wunschpizza-Konfigurator waren zu klein. Die Schälchen (ca. 125 ml, rund 6 cm) werden jetzt im
   Verhältnis zur gezeichneten Pizza dargestellt (rund ca. 22 %, Flammkuchen ca. 18 % des Teigdurchmessers, begrenzt auf
   46 bis 96 px) und wachsen mit der Darstellung. Bei der runden Pizza stehen sie auch auf dem Handy als Spalte im freien
   Tischstreifen neben dem Brett, ohne die Pizza zu verkleinern; beim breiten Flammkuchen auf dem Handy bleiben sie als
   Reihe darunter. Die Größe passt sich bei Drehen und Größenänderung des Fensters an.

2026-10-04 == mySeat v6.5.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Flammkuchen im Wunschpizza-Konfigurator. Das Gericht bekommt im Speisekarten-Editor statt des Hakens die
   Auswahl "Wunschpizza-Konfigurator": Aus / Pizza (rund) / Flammkuchen (oval, extra dünn, Holzbrett); gespeichert
   als tp_shop_products.configurator 0 / 1 / 2. Beim Flammkuchen liegt der Teig als flaches Oval auf einem länglichen
   Holzbrett, darunter Tomatensoße, darüber die Käseschicht ("Inklusive: Tomatensoße, Käse"), und eine Portion
   legt vier gespiegelte Teile (links/rechts, oben/unten) statt sechs im Kreis.
 * New: Soßen zum Dippen stehen als Schälchen mit ihrer Soße neben dem Brett (Spalte rechts auf breitem Bildschirm,
   Reihe darunter auf dem Handy) statt auf der Pizza.
 * New: unter jeder Wunschpizza liegt die erste Käseschicht bereits (bei der veganen Pizza oder einem veganen Teig
   Pizzaschmelz); "Doppelt Käse" legt eine zweite Schicht darüber. Unter der Pizza steht "Inklusive: ...".
 * New: der Dinkel-Roggen-Teig (Variantenname mit Dinkel, Roggen oder Vollkorn) hat einen dunkleren Boden mit Kleieflocken
   und Haferkörnern, der beim Wechsel der Teigart sofort getauscht wird.
 * Fix: Soßenfarben nach den echten Soßen: Sambal Hollandaise gelb mit leichtem Rotstich und Sambal-Flecken, Barbecue
   rötlich braun, Sticky Korean BBQ dunkelbraun und glänzend mit Sesam.

2026-10-03 == mySeat v6.4.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Wunschpizza-Konfigurator im Bestellshop. Ein Gericht bekommt im Speisekarten-Editor den Haken "Als Wunschpizza
   anbieten (Konfigurator)"; dann steht auf der Karte "Belegen", und der Gast belegt einen rohen Teigling selbst
   (order/pizza.js, order/pizza.css): Holztisch, Teigling auf der Schaufel, gemalte Zutaten in einer Zutatenliste nach
   den Optionsgruppen des Gerichts. Antippen legt die Zutat auf den Teig, jede Portion verteilt sich in sechs Teilen
   im 60-Grad-Abstand (symmetrisch), Soßen als Wirbel. Der Konfigurator füllt dieselbe Warenkorbposition wie der
   normale Produktdialog (Optionen, Mengen, Teigart), Preise und Grenzen rechnet weiter der Server; die Küche liest
   die Zutaten wie bisher. Ohne den Haken, oder wenn pizza.js nicht lädt, bleibt der normale Dialog.
 * New: jede Option einer Zubehörgruppe hat ein Symbol für den Konfigurator (Spalte tp_shop_group_items.icon, leer =
   automatisch nach dem Namen über shop_item_icon(), "none" = kein Belag); das Gericht hat tp_shop_products.configurator.
   Beide Spalten legt shop_ensure_schema() an. Im Gruppen-Editor gibt es dafür eine Auswahl "Symbol".
 * New: PRODUCT.md (Impeccable-Produktkontext) mit Nutzern, Zweck und Randbedingungen des Systems.

2026-10-03 == mySeat v6.3.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Sperrzeiten des Tischplans wirken jetzt auch bei der Online-Verfügbarkeit "Nach Zählung". An einem Tag,
   an dem ein Bereich gesperrt ist, verkleinert maxCapacity() die Grenzen für Plätze und Tische um den Anteil,
   den dieser Bereich am Tischplan hat (tp_closed_share() in tableplan_assign.class.php). Anteilig und nicht
   absolut, weil die Outlet-Grenzen meist unter den Plätzen des Plans liegen. Gilt für die Online-Buchung, das
   Backend und das Dashboard. Bereits gebuchte Reservierungen werden nicht verschoben. Bei "Nach Tischplan"
   ändert sich nichts, dort entscheidet der Plan selbst.
 * New: im Backend-Formular für Reservierungen (resform.js) werden Tische eines an diesem Tag gesperrten Bereichs
   nicht mehr angeboten, auch nicht grau in der Ansicht "Alle", und ein Bereich, dessen Tische alle gesperrt
   sind, fehlt in der Bereichsauswahl. Ein bereits gewählter Tisch in einem gesperrten Bereich bleibt sichtbar
   und lässt sich jetzt abwählen (vorher war sein Chip deaktiviert).
 * Fix: der Hinweis über den Sperrzeiten im Tischplan sagte bei "Nach Zählung", Sperrzeiten würden nicht
   berücksichtigt. Er beschreibt jetzt die anteilige Verkleinerung der Grenzen.

2026-10-03 == mySeat v6.2.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Tischplan, Einstellung "Gesperrte Bereiche im Tischplan ausblenden". Ist sie an, fällt der Reiter eines
   Bereichs an den Tagen weg, an denen er gesperrt ist (z. B. der Außenbereich im Winter). Nur die Ansicht: im
   Bearbeitungsmodus bleiben alle Bereiche sichtbar, und sind an einem Tag alle gesperrt, bleiben auch alle
   sichtbar. Reservierungen, Sperrzeiten und die Online-Verfügbarkeit ändern sich nicht (Schlüssel
   hide_closed_areas in tp_settings, ajax/tp.php setting_save / load).
 * Fix: beim Anlegen eines Sperrzeitraums im Tischplan gab es keine sichtbare Rückmeldung: die Meldung stand
   unter dem gesamten Plan, außerhalb des Bildschirms. Fehler erscheinen jetzt direkt unter dem Button, die
   Seitenmeldung klebt am unteren Fensterrand. Zusätzlich wird geprüft, dass "bis" nicht vor "von" liegt.
 * Fix: Sperrzeiten wirken nur bei der Online-Verfügbarkeit "Nach Tischplan" (tp_online_fits() entscheidet im
   Modus "Nach Zählung" nicht), was nirgends stand. Über den Sperrzeiten erscheint jetzt ein Hinweis, solange
   der Modus "Nach Zählung" ist.
 * Fix: /order/ zeigte sich angemeldeten Mitarbeitern immer (Vorschau), auch ohne den Haken "Bestellseite für
   Gäste sichtbar", ohne dass es erkennbar war. Jetzt steht in dem Fall ein Banner "Vorschau für Mitarbeiter:
   Gäste sehen diese Seite nicht" auf der Seite, und die Einstellung erklärt es. Für Gäste gilt unverändert
   "Bestellen kommt bald" (HTTP 503).

2026-10-02 == mySeat v6.1.3 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: abgelaufene Absage-Kurzlinks wurden in YOURLS nie gelöscht. Das Plugin "Expiry" findet den Link, indem
   es YOURLS_SITE + "/" von der übergebenen Adresse abschneidet. Dieses YOURLS ist als http:// konfiguriert,
   unsere Links sind https://, also wurde die Adresse nicht gekürzt, und der Ablauf landete unter einem
   kaputten Keyword (z. B. "httpsamdsatabc12345"), das zu keinem Link gehört. Beim Ablauf löschte das Plugin
   dieses Phantom-Keyword, der echte Link blieb, und der Prune meldete trotzdem "success: pruned". Die
   Statusabfrage hatte denselben Fehler und zeigte deshalb immer einen Ablauf an. Jetzt bekommt das Plugin
   das nackte Keyword (sms_yourls_keyword()), abgelaufene Links werden beim Aufruf und beim stündlichen Prune
   wirklich gelöscht.
 * Fix: der stündliche Prune prüft jetzt selbst, ob er etwas getan hat (die ältesten Absage-Links dürfen
   danach nicht mehr "beyond expiration" sein) und schreibt andernfalls eine Zeile ins Fehlerlog.
 * Hinweis zum Betrieb: die bestehenden Absage-Links in YOURLS wurden einmalig neu gesetzt (Ablauf am Morgen
   nach dem Reservierungstag), Altlinks zu vergangenen oder stornierten Reservierungen wurden gelöscht.

2026-10-02 == mySeat v6.1.2 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: im Reservierungsformular (web/js/resform.js) konnte der Speichern-Button nach einem abgebrochenen
   Absenden gesperrt bleiben, bis die Seite neu geladen wurde. Die Formularprüfung sperrte den Button
   sofort, danach konnte jQuery Validate das Absenden noch abbrechen. Der Button wird jetzt erst gesperrt,
   wenn das Absenden wirklich durchgeht, und beim Zurück-Navigieren (bfcache) wieder freigegeben.

2026-10-02 == mySeat v6.1.1 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: auf den Gästeseiten api/cancel.php (Reservierungsseite aus der Bestätigungsmail, Notiz) und
   api/request.php (Freigabeseite fürs Personal: Name, Telefon, E-Mail, Notiz) erschienen Umlaute und
   Sonderzeichen als "&uuml;" usw. Gast-Text wird in der Datenbank entity-kodiert abgelegt
   (escapeInput() in database.class.php), und diese beiden Seiten escapten ihn beim Anzeigen ein zweites
   Mal. Die Werte werden dort jetzt erst dekodiert und dann escaped (wie schon bei Mail, SMS und
   Kalender-Export); der Schutz vor eingeschleustem HTML bleibt erhalten, bestehende Reservierungen sind
   sofort mitkorrigiert.

2026-10-02 == mySeat v6.1.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Sipgate-Webhook (order/sipgate_webhook.php) authentifiziert jetzt per geteiltem Schlüssel in der
   Webhook-URL statt per HTTP Basic Auth, die Sipgate offenbar nicht zuverlässig sendet - verlangt POST und
   liest event/direction/from nur noch aus dem authentifizierten Request-Body. Neue Diagnoseseite
   (order/sipgate_diagnose.php, hinter Login) zeigt den Status der letzten Anfrage.
 * New: Rufnummernübernahme (bisher nur in der Kasse) gibt es jetzt auch beim Anlegen einer neuen
   Reservierung (Einstellungen > neue Reservierung) - derselbe eingehende-Anruf-Banner mit
   "Übernehmen"-Button trägt die Nummer ins Telefonfeld ein, da ein und dieselbe Telefonleitung sowohl
   Bestellungen als auch Reservierungen entgegennimmt.
 * Fix: eingehende Anrufer-Rufnummern von Sipgate kamen teils ohne führendes "+" an (z. B. "4915123456789"),
   wodurch weder der Kassen-Abgleich "Zuletzt bestellt" noch die neue Übernahme bei Reservierungen die
   Nummer wiedererkannten. shop_record_incoming_call() rekonstruiert das "+" jetzt für deutsche/
   österreichische Nummern (Mobil und Festnetz gleichermaßen), inklusive der "00"-Präfix- und der
   doppelten-Vorwahl-Null-Falle.
 * Fix: Fahrer-App (order/driver.php) - ein Download-Rebuild bei jedem 20-Sekunden-Abruf konnte eine
   angefangene "Fehlgeschlagen"-Begründung oder einen scharfgestellten Bestätigen-Button lautlos verwerfen;
   rendert jetzt per Diff wie die übrigen Monitore. "Link ungültig" zeigt jetzt einen Anrufen-Button statt
   in eine Sackgasse zu führen. "Fehlgeschlagen" ist nicht mehr so prominent wie "Zugestellt", eine
   gesperrte "Starten"-Kachel ist jetzt klar als gesperrt erkennbar statt nur leicht abgedunkelt, und zwei
   Textgrößen wurden auf die dokumentierte Skala gebracht.
 * Fix: Speisekarten-Editor (Einstellungen > Speisekarte) - natives Browser-confirm()/beforeunload beim
   Verwerfen ungespeicherter Änderungen ist durch einen themenkonformen Dialog ersetzt; eine Kategorie zu
   löschen warnt jetzt mit der Anzahl betroffener Gerichte statt pauschal zu fragen. Das
   Gutschein-Formular ist in Abschnitte (Rabatt/Gültigkeit/Limits) gegliedert statt 14 Felder ohne
   Gliederung zu zeigen; der Zubehör-Preisfaktor bei Varianten hat jetzt eine sichtbare Erklärung statt nur
   eine Hover-Tooltipp. Eingabefelder sind jetzt 16px groß (vorher löste das Fokussieren auf iOS einen
   Zoom aus) und die kleinen Werkzeug-Buttons haben jetzt 44px Touch-Fläche statt 32px.
 * Fix: Sicherheitslücke in zwei Autovervollständigungs-Endpunkten (web/ajax/autocomplete.php,
   web/ajax/autocomplete_res.php) behoben - der Suchbegriff landete ungeprüft in der SQL-Abfrage.
 * Fix: an mehreren Stellen (Speisekarte, Kasse, Zonen-Editor, Gruppenbestellung) verschwand die Beschriftung
   eines Buttons beim Hovern, weil eine allgemeine Hover-Regel nur Hintergrund oder nur Textfarbe setzte und
   die jeweils andere Eigenschaft von einer globalen Button-Hover-Regel mit einer dazu passenden Farbe
   überschrieben wurde (z. B. goldener Text auf goldenem Hintergrund). Alle gefundenen Stellen korrigiert.

2026-10-01 == mySeat v6.0.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Fahrer-App erlaubt jetzt Mehrfach-Annahme aus dem Pool - ein Fahrer übernimmt mehrere Lieferungen
   in seine eigene Warteschlange und startet jede einzeln (shop_driver_start_order()), damit immer nur
   ein Gast gleichzeitig die Route live mitverfolgen kann. Neu: "Pausieren" legt eine gestartete Lieferung
   zurück in die eigene Warteschlange (shop_driver_pause_order()), ein "Fehlgeschlagen"-Button mit
   Freitext-Grund für Lieferungen, die nicht abgeschlossen werden können (neuer Status 'failed', eigene
   Spalte/Farbe in Disposition und Bestellungen-Liste, fail_reason-Spalte).
 * New: "Bestellungen" hat jetzt einen dritten Filter "Abgeschlossen" (erledigt/storniert/fehlgeschlagen
   getrennt ausgewiesen) und einen "Bestellung erfassen"-Link zur neuen POS-Oberfläche.
 * New: POS-Oberfläche (Bestellungen > "Bestellung erfassen") zum Eintippen telefonischer oder nicht
   technisch angebundener Bestellungen - nutzt dieselbe Speisekarte, Preislogik und Liefergebiets-Prüfung
   wie der Gast-Shop (shop_create_manual_order()), inklusive Produktdialog mit Pflicht-/Zusatzoptionen,
   Suche, Warenkorb mit editierbaren Notizen und Zeilen-Korrektur per Antippen. Bestellungen aus dieser
   Oberfläche laufen mit source='phone' normal in Disposition/Küchenmonitor ein.
 * New: Gäste-Bestellhistorie nach Telefonnummer (shop_guest_history()) mit "Diese Bestellung übernehmen"
   in der POS-Oberfläche - ordnet wiederkehrende Gäste anhand ihrer letzten Bestellungen automatisch zu,
   ohne eigene Gästedatenbank.
 * New: Sipgate-Anrufintegration (Einstellungen > Lieferservice) zeigt eingehende Anrufe als Banner in
   der POS-Oberfläche, inklusive Rufnummer zum direkten Übernehmen ins Telefonfeld.
 * Fix: Statusmeldungen (Bestellungen, POS, Disposition, Küchenbildschirm) unterscheiden jetzt sichtbar
   zwischen Fehler/Erfolg/Zwischenstatus statt immer gleich grau anzuzeigen; native confirm()/alert()/
   prompt()-Dialoge sind durch themenkonforme Dialoge ersetzt (unsichtbar im Vollbildbetrieb).
 * Fix: Disposition und Küchenbildschirm zeigen jetzt einen Hinweis, wenn eine Spalte über den sichtbaren
   Bereich hinaus Bestellungen enthält, statt sie lautlos verschwinden zu lassen; "verspätet" ist jetzt
   farblich und als Text von "fehlgeschlagen" unterschieden statt denselben roten Rahmen zu teilen.
   Beide Monitore rendern Karten jetzt per Diff statt bei jeder Aktualisierung alles neu aufzubauen,
   damit eine laufende Bestätigung oder die Scroll-Position nicht alle paar Sekunden zurückgesetzt wird.
 * Fix: die Gäste-Statusseite (order/status.php) zeigt "fehlgeschlagen"/"storniert" jetzt farblich abgesetzt
   statt in derselben Gestaltung wie jede andere Statusmeldung; "angenommen" hat jetzt einen eigenen
   Fortschrittsschritt (vorher keine sichtbare Bewegung gegenüber "neu"); ein ungültiger/abgelaufener Link
   zeigt jetzt einen deutlichen Anruf-Button statt nur eines unauffälligen Links.
 * Fix: alter Bootstrap-Fokus-Schimmer (blaues Leuchten auf Eingabefeldern) entfernt, betraf Installer,
   Bestellbestätigung und das Gäste-Reservierungswidget.
 * Fix: Radius-, Farb- und Schriftgrößen-Werte in order/shop.css auf die dokumentierten Design-Tokens
   konsolidiert (siehe DESIGN.md), analog zur bereits bestehenden Konsolidierung von theme-dark.css.

2026-10-01 == mySeat v5.1.0 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: der Fahrer-App-Annahme-Bug vom letzten Release ist behoben - shop_driver_claim_order() prüfte
   bisher nicht, ob das anschließende Setzen auf "unterwegs" wirklich geklappt hat. Schlug das fehl,
   blieb die Lieferung dem Fahrer zugewiesen, aber für Pool und Fahrer-App gleichzeitig unsichtbar.
   Jetzt wird der Status neu geprüft und ein klarer Fehler statt eines falschen Erfolgs gemeldet.
 * New: Disposition kann eine unterwegs befindliche Lieferung jetzt per Klick zurück in den offenen
   Pool legen, unabhängig vom zugewiesenen Fahrer - für den Fall, dass ein Fahrer nicht ausliefern kann.
 * New: Fahrer-Verwaltung (Einstellungen > Lieferservice) zeigt jetzt "zuletzt gesehen" je Fahrer an,
   um eine falsch eingetragene Traccar-Geräte-ID von einer Funkstille unterscheiden zu können.
 * New: "Annehmen" in der Fahrer-App verlangt jetzt eine doppelte Bestätigung (Verklicken passiert
   leicht); die Disposition hat einen "Gast-Link kopieren"-Button direkt an jeder Bestellung.
 * Fix: Testbestellungen (Bestellungen > "Test: Lieferung") bekamen bisher die Koordinate 0/0, wodurch
   die Gast-Statusseite nie eine Karte zeigen und die Bestellung nie eine Lieferzone auflösen konnte.
 * New: "Test: Lieferung" fragt jetzt optional nach einer echten Adresse (Straße, PLZ, Ort), die wie
   im echten Checkout geocodiert wird - leer lassen übernimmt wie bisher die Restaurant-Adresse.
 * Fix: shop_origin() gab seit der Mehrfachkandidaten-Umstellung (v4.9.0) versehentlich das volle
   5er-Array von shop_geocode() zurück statt nur [lat, lng]. order/track.js reichte das direkt als
   Leaflet-Koordinate durch, was beim ersten Kartenpin einen Fehler warf und die gesamte Karte auf
   der Gast-Statusseite leer ließ (weder Pins noch Kacheln) - betraf jede Lieferung mit konfigurierter
   Restaurant-Adresse.
 * Fix: die Kartenkacheln blieben in Firefox komplett weiß - ein CSS-filter (Dark-Mode-Invertierung)
   auf einem Container mit transform-animierten Kindern (Leaflets Kacheln) wird dort nicht sauber
   kompositiert. order/shop.css promotet den Kachel-Layer jetzt auf eine eigene Compositing-Ebene.
 * New: order/tile_proxy.php lädt und cached OpenStreetMap-Kacheln jetzt serverseitig, statt dass
   jeder Gast-Browser sie direkt von tile.openstreetmap.org holt - OSM sieht dadurch nicht mehr die
   IP-Adresse jedes Gasts, nur noch die des eigenen Servers. Schreibt atomar (Temp-Datei + rename),
   damit ein paralleler Request nie eine halb geschriebene Kachel zu sehen bekommt (das verursachte
   anfangs vereinzelt ebenfalls weiße Kacheln). Der dadurch überholte IP-Hinweistext auf der
   Gast-Statusseite ist entfernt.

2026-10-01 == mySeat v5.0.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Fahrer-App ohne eigenen Traccar-Server - order/driver_gps.php spricht das OsmAnd-Protokoll
   direkt, die Traccar-App auf dem Fahrer-Handy zeigt einfach auf diese URL. Kein Login: die in der
   App frei wählbare Geräte-ID identifiziert den Fahrer, einmalig in Einstellungen > Lieferservice
   einem Namen zugeordnet (neue Tabellen tp_shop_drivers, tp_shop_driver_positions).
 * ACHTUNG, ERSETZT das bisherige Modell: order/driver.php verschickte bisher einen Token-Link an
   EINEN Fahrer für EINE Bestellung (Disposition > "Fahrer-Link kopieren"/WhatsApp) - das entfällt.
   Jeder Fahrer bekommt stattdessen einmalig einen festen Link (order/driver.php?device=<Geräte-ID>)
   zum Speichern auf dem Homescreen; dort sieht er eine offene Auftragsliste (Gebiet/PLZ, Positionen,
   Betrag, Wunschzeit) und nimmt sich selbst eine Lieferung (atomar, kein Doppel-Annehmen möglich).
   Nach der Annahme wie bisher Adresse/Telefon/Bestellung/Kassieren/"Route öffnen", dazu "Zugestellt"
   und neu "Zurück in den Pool", falls er doch nicht ausliefern kann.
 * Die Disposition (disposition.php) hat jetzt eine Fahrer-Karte (Button oben rechts): alle aktiven
   Fahrerpositionen gleichzeitig, mit der Lieferung, die sie gerade haben. Setup erfordert, dass
   mindestens ein Fahrer mit Geräte-ID angelegt und die Traccar-App entsprechend konfiguriert ist -
   bis dahin bleibt die Liste leer, nichts bricht.
 * Umgesetzt mit /impeccable shape.

2026-10-01 == mySeat v4.10.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: Liefergebiete-Editor im Backend (Einstellungen > Lieferservice > "Liefergebiete-Editor öffnen",
   web/main_page.php?p=11) - Liefergebiete können jetzt direkt als Polygon auf einer Karte gezeichnet,
   in ihrer Form bearbeitet und gelöscht werden, statt nur einmalig aus Resmio übernommen zu werden.
   Alle aktiven Gebiete sind gleichzeitig sichtbar (je eigene Farbe), überschneiden sich zwei Gebiete,
   zeigt ein Hinweis, welches davon laut bestehender Regel (günstigere Liefergebühr gewinnt) tatsächlich
   greift - die Zuordnungsregel selbst bleibt unverändert. Name/Liefergebühr/Mindestbestellwert/aktiv
   bleiben wie bisher direkt bearbeitbar. Umgesetzt mit /impeccable shape.
 * Leaflet.draw (vendoriert in web/js/leaflet/, wie das schon vorhandene Leaflet selbst) treibt das
   Zeichnen/Editieren der Eckpunkte an; die neuen shop_zone_create()/shop_zone_save_shape()/
   shop_zone_delete()/shop_zones_overlaps() in web/classes/shop.class.php und der neue Endpunkt
   web/ajax/shop_zones_admin.php gehören dazu

2026-10-01 == mySeat v4.9.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: wenn die Adressprüfung (Menü-Schnellcheck und Kasse) mehr als eine echte, lieferbare Straße zu
   der eingegebenen Adresse findet (z.B. "Goschentor" trifft auch auf "Goschenstraße" zu), erscheint
   jetzt eine kurze Auswahlliste statt automatisch zu raten - ein Klick übernimmt PLZ und Liefergebiet
   des gewählten Treffers, die eingegebene Straße bleibt dabei unverändert. Ein einzelner Treffer wird
   weiterhin automatisch übernommen, kein zusätzlicher Klick im Regelfall. Umgesetzt mit /impeccable
   shape.
 * shop_geocode() fragt Nominatim jetzt mit mehreren Treffern ab (vorher nur der einzelne "beste");
   neue Spalte candidates auf tp_shop_geocache, angelegt automatisch wie die anderen Tabellen der App

2026-10-01 == mySeat v4.8.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: the delivery-zone address check (order/index.php quick check and order/checkout.php) now
   auto-fills the PLZ and corrects the street's spelling as soon as an address resolves - Nominatim/
   Google's own postcode and normalized road name come back with a successful zone check, so a guest
   only has to type Straße + Ort (PLZ is filled in for them, or can still be typed for a more precise
   match on an ambiguous street name). shop_find_zone() no longer requires a PLZ to attempt a lookup;
   placing an order still needs one, filled in either by the guest, by this auto-fill, or as a last
   resort from what the geocoder itself matched
 * New columns on tp_shop_geocache (postcode, road), created automatically like the app's other
   tables - nothing to do on the server

2026-09-30 == mySeat v4.7.1 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: the "Liefert ihr zu mir?" quick check on the menu page (order/index.php) now shows the
   delivery fee and minimum order of the resolved zone right away, instead of only a generic "Wir
   liefern zu dir" - the cart's Mindestbestellwert bar, the checkout-button hint and the delivery-fee
   line all switch to the zone's real numbers as soon as an address is checked, so a guest is not
   surprised by a different amount at checkout
 * The what3words and Google key cards in Einstellungen > Lieferservice each got a "Verbindung
   prüfen" button (v4.7.0, undocumented until now) that tests the stored key against the real API
   and shows its own error message (e.g. what3words' plan/quota errors, Google's REQUEST_DENIED /
   OVER_QUERY_LIMIT), instead of only surfacing a problem once a guest hits it live

2026-09-30 == mySeat v4.7.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: the delivery-zone address check (order/index.php quick check and order/checkout.php) now
   falls back to Google Geocoding when OpenStreetMap/Nominatim cannot find an address - a paid
   fallback only, never the default path, so a normal lookup Nominatim already answers costs
   nothing.
 * New: a what3words escape hatch for addresses with no real street (a field, an event site) -
   appears only after both Nominatim and Google fail to find a typed address, lets the guest enter
   a what3words code instead. The order stores the code plus the resolved coordinate; the driver's
   dispatch link opens a route to the coordinate directly, since Google Maps cannot search a
   what3words code as text.
 * The Google and what3words API keys above are entered in the backend (Einstellungen >
   Lieferservice), stored encrypted the same way as the Mollie key - config.general.php's
   $settings['googlemap_key'] / $settings['what3wordsApiKey'] still work as a fallback for an
   operator who prefers editing the file directly, but the backend value wins if both are set
 * Shaped with /impeccable shape

 * New: a "Teilen" button on the guest page (api/cancel.php), shaped with /impeccable shape - a
   guest can forward a warm, cancel-link-free summary (date, time, table, menu/drinks links,
   parking, accessibility) to people joining them, who never see the confirmation mail. Uses the
   Web Share API on mobile, falls back to copying the text to the clipboard on desktop. The
   confirmation mail gets the same text behind a "An Mitgäste weiterleiten" mailto link, since a
   mail can't run Web Share
 * New: the parking facilities and the public toilet mentioned in the confirmation mail and
   api/cancel.php now link to Google Maps by name (destination-only search links, no guessed
   coordinates), so a guest can tap through and navigate there directly

2026-09-30 == mySeat v4.5.0 == amadeushi - http://github.com/amadeushi/myseat

 * New: a "Liefert ihr zu mir?" quick delivery-zone check on the menu page (order/index.php),
   shaped with /impeccable shape - a guest can check their address before building a cart
   instead of finding out only at checkout that delivery isn't possible. Collapsed into a small
   link by default; opens a floating dropdown (address fields), locks the delivery toggle and
   switches to pickup if the address is rejected, and hands the checked address to checkout.php
   through the same "remember my details" storage it already reads
 * Guest-facing checkout page (order/checkout.php), following an /impeccable critique pass:
 * Fix: the submit button could look fully ready while name, phone, or a selected time were
   still missing - it now validates all three before enabling, matching the progress checklist
   that already tracked them
 * Fix: the custom tip amount field rejected the comma-decimal format its own placeholder asked
   for ("0,00") - a German guest could not enter a custom tip at all
 * Fix: a failed order submission's error message rendered ~600px away from the sticky submit
   button, invisible to a guest who had scrolled down to tap it - it now also appears next to
   the button and scrolls into view
 * Fix: an empty cart still showed a fully interactive, fillable address form underneath the
   "cart is empty" message, because the async server-state callback re-showed it regardless of
   cart contents
 * Fix: a rejected delivery address was told simultaneously "we don't deliver here" and "we're
   still checking" - the second message now has its own case for an address already checked and
   rejected
 * Reservation table signs (web/reservation_bon.php): the footer text "Wir freuen uns auf Sie"
   assumed the guest hadn't arrived yet, though the sign is printed and placed once they're
   already seated - changed to "Wir freuen uns, dass Sie da sind"
 * Fix: a two-line guest name on the table sign made the printed slip noticeably taller,
   overlapping the next sign on the roll - vertical spacing now tightens automatically when the
   name wraps to two lines, saving about 6mm of paper length in that case

2026-09-30 == mySeat v4.4.2 == amadeushi - http://github.com/amadeushi/myseat

 * Guest-facing food-ordering page (order/index.php), following an /impeccable critique pass:
 * Fix: the delivery/pickup toggle, category nav links, quantity/edit/remove cart buttons, and
   the search-clear button fell short of the 44x44px touch-target floor on the very first
   controls a guest touches - enlarged all of them (including a mobile media-query override
   that had undone the category-nav fix)
 * Fix: an optional "Extras" chip with a quantity stepper (e.g. Ketchup, Mayonnaise) forced its
   label to full width in a row that also needed the price and the stepper, causing one item's
   name to render on top of the next chip's price/stepper under real menu data - the chip now
   spans the full row instead of squeezing into one grid column
 * Fix: the order-note textarea was 15px, one pixel under the design system's own "16px Form
   Rule" - triggered iOS Safari's auto-zoom on focus in the flow's most-used free-text field
 * Fix: closing the product-choice dialog dropped keyboard/screen-reader focus to <body> instead
   of returning it to the button that opened it, forcing a full re-tab through the menu after
   every product interaction
 * Fix: the cart panel had no Escape-to-close, unlike the search input and the native product
   dialog - it's now dismissible with the same key as every other overlay on the page

2026-09-30 == mySeat v4.4.1 == amadeushi - http://github.com/amadeushi/myseat

 * Guest-facing cancellation page (api/cancel.php), following an /impeccable critique pass:
 * Fix: the "No, keep my reservation" button was fully translated in both languages but never
   rendered - the only control inside the cancel confirmation was the destructive one; added a
   secondary keep-it button next to it
 * Fix: the language-switcher pills, the close (X) link, and the "Back to website" link fell
   short of the 44x44px touch-target floor on a page opened almost exclusively from a phone -
   enlarged all three
 * Fix: the manual lookup form's booking-number/email fields were 15px, one pixel under the
   design system's own "16px Form Rule" - triggered iOS Safari's auto-zoom on focus; bumped to
   16px
 * Removed a redundant "Cancel reservation" jump-link that sat directly under the welcome
   message on every visit (not just cancellation visits) - it was meant as a shortcut to the
   cancel button further down, but front-loaded the page's only non-gold accent color ahead of
   the reassuring reservation details
 * Wrapped the page content in a <main> landmark for keyboard/screen-reader navigation

2026-09-30 == mySeat v4.4.0 == amadeushi - http://github.com/amadeushi/myseat

 * Guest-facing email templates (reservation, feedback, delivery order), following an
   /impeccable critique pass:
 * Fix: the feedback-request mail showed 5 pre-filled gold stars before the guest had rated
   anything, anchoring toward a positive answer despite the mail's own "honest feedback, good
   or bad" framing - removed
 * Fix: the declined-request mail shared its heading with the pending mail and still showed a
   bold "Buchungsnummer" for a table that was never booked, reading like a confirmed receipt -
   now has its own heading and a de-emphasized, booking-number-free fact box
 * Fix: an English-speaking guest saw dates in the system's day.month.year format (e.g.
   "05.10.2026", ambiguous as May 10th under US conventions) regardless of their chosen
   language - now rebuilt as "Monday, October 5, 2026" for English mails
 * Fix: four text colors (#777777, #8a8577 and the delivery mail's #888/#777) fell short of
   WCAG AA contrast on white - darkened to pass
 * Fix: the delivery/pickup order confirmation (web/classes/shop_mail.class.php) was a third,
   uncoordinated visual system - different page background, card radius and font-stack
   quoting than the reservation/feedback mails, no legal footer, and the restaurant
   notification wasn't a complete HTML document at all - brought in line with the other
   templates. Full bilingual support was not added: the delivery order flow itself
   (order/index.php, checkout.php) hardcodes German with no language switch, so a translated
   receipt would need that fixed first

2026-09-30 == mySeat v4.3.1 == amadeushi - http://github.com/amadeushi/myseat

 * Backend dashboard, two follow-up fixes reported after v4.3.0/v4.2.0 shipped:
 * Fix: the Woche/Monat/Statistik tab icons became invisible when a tab was active - .ui-ico sets
   its own text-muted color instead of inheriting, which read as gold-on-gold on the active tab's
   gold background
 * Fix: the occupancy sparkline under "Statistik" was unreadably small (20px tall, 4px-wide bars,
   an untouched leftover from the pre-redesign screen.css) - enlarged to 110px/10px bars on desktop
   and 72px/6px on narrow screens, with horizontal scroll instead of page overflow for floors with
   many time slots
 * Fix: today's date in the Monat calendar had a near-white cell background - screen.css's more
   specific "table .grey" selector (background-color: #F1F2F2) was overriding the themed ".grey"
   rule regardless of load order; added a matching dark-themed "table .grey" rule

2026-09-30 == mySeat v4.3.0 == amadeushi - http://github.com/amadeushi/myseat

 * Table plan editor (Tischplan), following an /impeccable audit:
 * Fix: moving/resizing a table was mouse/touch only - the selected table can now be moved with
   arrow keys and resized with Shift+arrow keys, matching the existing pointer-drag behavior
 * Fix: 4 destructive/consequential actions (delete table, delete area, switch online-availability
   mode, assign-with-warnings) used window.confirm() - replaced with the same inline confirmation
   pattern used for the dashboard's online-block toggle
 * Fix: several icon-only buttons (chip/closure remove, day-nav, area move) were below the 44px
   touch-target guideline - enlarged their hit areas
 * Fix: 4 undocumented border-radius values (8px, 3px, 12px, 4px) replaced with the existing
   --radius-sm/--radius-md/--radius-pill tokens
 * Fix: Unicode glyphs ('‹', '›', '←', '→', '×') used as icon buttons replaced with the same
   single-stroke SVG icon style used throughout the rest of the backend
 * Fix: the area tab's reservation count had a borderline-failing contrast ratio (~4.43:1) from an
   added opacity - removed, now uses the full text color

2026-09-30 == mySeat v4.2.0 == amadeushi - http://github.com/amadeushi/myseat

 * Backend dashboard (Startbildschirm nach dem Login), following an /impeccable critique pass:
 * Fix: the Statistics tab was styled as disabled (dimmed, "li.disabled") but was actually a fully
   working link - the richest per-timeslot view on the page looked unavailable
 * Fix: the Week/Month/Statistics tabs never showed which view was active, even though the CSS rule
   already existed and the same pattern is already used in the top navigation
 * Fix: "Online sperren" looked like an ordinary link before being clicked (the button_dark class it
   used has no effect on <a> elements) - it now has its own gold-outlined look before, danger-outlined
   after
 * Fix: blocking/unblocking online bookings used window.prompt()/window.alert() instead of the page's
   own alert system - replaced with an inline reason form and the existing .alert_error/.alert_success
   styling
 * Fix: the occupancy sparkline's numbers had no context (no time, and the metric silently switches
   between "tables free" and "seats free") - each cell now has a tooltip stating both
 * Fix: 8 hardcoded German strings around the online-block feature (including icon tooltips that never
   switched to English) now go through the translation system, in all 9 language files

2026-09-30 == mySeat v4.1.0 == amadeushi - http://github.com/amadeushi/myseat

 * Backend day view (Reservierungen), the last finding from the /impeccable critique pass:
 * Feature: the pax count in the reservation list can now be edited inline, the same click-to-edit
   pattern the table-number cell already used, instead of always requiring the full edit form
 * This builds on the SQL-injection fix already shipped separately for web/ajax/inline_edit.php
   (whitelisted column names); the pax field is validated server-side as an integer, 1-500,
   matching the bound already used for the guest-facing pax stepper

2026-09-29 == mySeat v4.0.5 == amadeushi - http://github.com/amadeushi/myseat

 * Backend day view (Reservierungen), following an /impeccable critique pass:
 * Fix: the delete-confirmation dialog's "Löschen" and "Alle Einträge löschen" buttons were
   unstyled and visually identical (a leftover .send-button class with no matching CSS anywhere) -
   the series-delete option is now styled as a clear warning, carries a plain-language consequence
   note, and stays disabled until a separate confirmation checkbox is ticked
 * Removed a dead placeholder ("This is a test.") left in the cancelled-reservations dialog markup
 * A routine status change (Angekommen, Platziert, An der Bar, Fertig, No-Show) now updates the row
   in place instead of reloading the whole page - Storniert and approving a pending request still
   reload, since those change more than the one row (visibility, footer totals, table assignment)
 * "Storniert" is now visually set apart from the routine statuses in the dropdown with a divider
   and a warning tint, instead of sitting in the list as if it were equally reversible
 * The reservation list now reads as cards up to 820px instead of only below 600px, closing a gap
   where the note/table/status column visually detached from the guest's name/time/pax at the
   tablet width staff actually use during service
 * Found but deliberately not touched: a dead but reachable endpoint (web/ajax/inline_edit.php)
   builds its SQL column name from unvalidated input - flagged as a separate security fix rather
   than folded into this design pass

2026-09-29 == mySeat v4.0.4 == amadeushi - http://github.com/amadeushi/myseat

 * Guest reservation widget (api/reserve.php), following an /impeccable critique pass:
 * Fix: the party-size "-" button could reach 0 and the booking would still go through the
   time-slot lookup with zero guests; the floor is now 1, matching the server-side check that was
   already there
 * Fix: a failed field on the contact step (Name/E-Mail/Telefon) turned solid light pink, a leftover
   from the old light admin theme that overrode the dark theme's own error styling; it now uses the
   theme's own muted red, plus a concrete message under the field instead of color alone
 * The submit button now says "Jetzt reservieren"/"Reserve now" instead of reusing the backend's
   generic "Anlegen"/"Create" label
 * The Personen/Datum fields in step 1 now have a real associated label, and every contact field
   shows a visible gold focus ring when tabbed to - both were silently unreachable for screen
   readers/keyboard users before
 * Changing the party size no longer silently drops an already-picked time slot; it's restored if
   still available, or the guest is told it's no longer free for the new group size
 * The time-slot grid is now grouped under Mittag/Nachmittag/Abend sub-headers instead of a single
   wall of ~36 identical buttons

2026-09-29 == mySeat v4.0.3 == amadeushi - http://github.com/amadeushi/myseat

 * Backend design hardening pass (DESIGN.md, .impeccable/design.json): documented the existing
   dark/gold theme and consolidated accidental value drift found while doing so, with no visual
   redesign - same look, fewer inconsistent values behind it
 * Fix: nav chevron buttons and dropdown toggles had an oversized 65px touch target on mobile
   instead of the intended 44px (missing box-sizing: border-box)
 * Fix: the reservation form's Personen-Stepper squeezed its number almost invisible on phones
   because Zeit and Personen shared one row below 820px - now one field per row on mobile
 * Fix: the table-occupancy timeline (Dashboard) shrank its labels down to an illegible ~8px on
   narrow screens - now a fixed readable size with horizontal scroll instead
 * Fix: the Statistik page's numbers table overflowed off-screen on mobile - now reflows into
   stacked cards below 820px
 * Consolidated ~50 hardcoded border-radius values into three tokens (--radius-sm/md/pill, 10px
   base), unified stray 11px/17px/20px font sizes into a six-step type scale, merged three
   independently-chosen near-duplicate amber tones into the existing gold-strong token, fixed an
   internal inconsistency in the Vormittag/Nachmittag/Abend colour markers, and normalized
   floating-shadow opacity to one value - all purely internal, no visible change intended beyond
   the mobile fixes above
 * Removed dead legacy font-family declarations (Trebuchet MS, DroidSansBold) in the guest
   widget's stylesheet, fully overridden already and unused
 * Fix: the new/edit reservation forms double-HTML-encoded the guest's name/phone/email when
   redisplayed (e.g. "O'Brien" showed up as "O&amp;#039;Brien" in the edit form)
 * Fix: the Speichern/save button on both reservation forms and the guest widget's booking
   button no longer accept a second click while the first save is still in flight
 * Fix: the day view showed a silently empty table with zero reservations instead of saying so
 * Added a maxlength to the name/phone/email fields matching the database column limits, and
   wrapped instead of overflowing an unusually long guest name in the reservation list
 * Fix: two bookings for the same last free slot (or two staff assigning the same table) at the
   same instant could both succeed, double-booking it - the capacity check and the table
   assignment now hold a short database lock for the moment they decide and write

2026-09-29 == mySeat v4.0.2 == amadeushi - http://github.com/amadeushi/myseat

 * Reservation widget (api/reserve.php): the date field now shows a small calendar icon so it reads
   as clickable, and a "Today"/"Heute" button appears next to it whenever another date is selected,
   jumping straight back to today
 * Backend date navigation (Reservierungen day view, Dashboard): the old "<<"/">>" text arrows are
   now proper round buttons with a hover/focus state, replaced with modern chevron icons instead of
   the "<<"/">>" characters, and a matching "Today" button (outside the date picker) appears next to
   them whenever another date is selected

2026-09-29 == mySeat v4.0.1 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: the minimum reservation lead time (v4.0.0) had no real effect - the minute rounding for
   "earliest bookable slot today" used the current time instead of the lead-adjusted time, so the
   widget kept offering the very next slot. Also fixes a related edge case where the old code could
   produce an invalid time like "15:60" across an hour boundary

2026-09-29 == mySeat v4.0.0 == amadeushi - http://github.com/amadeushi/myseat

 * Minimum lead time for online reservations: guests can no longer book a slot minutes before it
   starts. New setting in Einstellungen > Allgemein ("Mindestvorlauf für Online-Reservierungen"),
   default 15 minutes, applies only to the guest-facing widget (staff can still book any time from
   the backend). Needs a database step: `ALTER TABLE settings ADD COLUMN reservation_min_lead
   SMALLINT UNSIGNED NOT NULL DEFAULT 15;`
 * DSGVO/GDPR data minimization for reservations: a new daily cron job (web/cron/purge_reservation_data.php)
   wipes the guest-identifying fields (name, phone, email, address, city, notes, booking IP/referer) off
   reservations once their visit date is older than a configurable retention period - new setting in
   Einstellungen > Allgemein ("Aufbewahrungsfrist für Reservierungsdaten"), default 30 days. The
   reservation row itself (date, time, pax, table, status, billing figures) stays for statistics and the
   legally required bookkeeping retention (GoBD); guest feedback (tp_feedback) is explicitly excluded and
   kept. Also scrubs the guest's number from the SMS log and the organizer e-mail of a linked group order.
   Needs a database step (`ALTER TABLE settings ADD COLUMN reservation_retention_days SMALLINT UNSIGNED
   NOT NULL DEFAULT 30;`) and a new cron entry (webcron or shell, same key as the existing feedback/reminder
   crons, once a day)

2026-09-28 == mySeat v3.1.1 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: the new-reservation live-insert (web/ajax/reservations_new_rows.php, added in v3.1.0) called
   tp_table_cell() without including the file that defines it, which would have crashed with a fatal
   error the first time it actually had a new reservation to insert. Never hit in production; found
   and fixed the same day while investigating an unrelated, transient 502 from the hosting side

2026-09-28 == mySeat v3.1.0 == amadeushi - http://github.com/amadeushi/myseat

 * New-reservation highlighter (day view "Reservierungen" and the dashboard): a reservation for today
   less than an hour old gets a pulsing highlighted row so staff notice it needs attention, fading on
   its own an hour after it came in. While the page stays open, a background check announces genuinely
   new arrivals with a sound (the same settings panel as the kitchen monitor: choice of sound, volume,
   repeat until acknowledged) and a brief banner, and inserts a newly confirmed reservation into the
   table immediately, no click or page reload needed. A new waitlist reservation still rings the bell
   but appears in its own table on the next refresh, same as before this feature

2026-09-26 == mySeat v3.0.0 == amadeushi - http://github.com/amadeushi/myseat

 * Delivery service (new module): order page for guests at /order/ (menu by category, product dialog with sizes and
   options, cart, checkout with delivery address check against the delivery areas, time choice, pay online with
   Mollie or cash/card on delivery/pickup, tip), order status page for the guest (secret link), confirmation mails.
   Needs: web/classes/shop.class.php (tables tp_shop_*, created automatically), order/, web/disposition.php
 * Backend: "Bestellungen" (dashboard: numbers of the day, all orders, next step as a button, test orders deletable),
   Disposition (web/disposition.php: full screen, three columns new / in the kitchen / ready, accept with 20/30/45/60
   minutes, reject, hand over), Kitchen screen (web/kitchen_screen.php: for the cooks, 4 or 5 tall columns with one
   order each, no guest data, no rejecting, one button "Fertig, ausgegeben", slip on paper via web/bon.php: 72 mm kitchen
   slip and a delivery slip with guest data and the amount to collect; automatic slip for new orders optional),
   sound on both monitors (web/js/monitor_sound.js: six sounds, volume, repeat once/3/5 times/until somebody reacts), Einstellungen > Lieferservice (switches, times, minimum order value, order mail
   address, Mollie key stored encrypted, delivery areas with fee and minimum order, opening times)
 * Menu, delivery areas and opening times were taken over once from the old ordering system (Resmio public JSON);
   prices for delivery and pickup are Resmio's takeaway prices. Address check: OpenStreetMap Nominatim
   (cached) and the polygons of the delivery areas. Off by default: the shop is invisible until switched on in the settings
 * Order tracking: the status page of a delivery shows a map (OpenStreetMap tiles, Leaflet stored in order/vendor/leaflet)
   with the restaurant (address in Einstellungen > Lieferservice > Standort des Restaurants), the delivery address and, while
   the driver shares his position, the driver. The dispatch copies or sends (WhatsApp) a driver link (order/driver.php, key bound
   to the order) from the "Fertig" and "Unterwegs" cards; the driver opens it on his phone: "Losfahren und Standort teilen",
   sees address, phone and the amount to collect, "Zugestellt" ends the trip. The position is only visible while the phone
   shares it (screen on, page open) and disappears when the order is delivered. Pickup orders show where the restaurant is
 * Cart: lines with choices can be changed (dialog opens with the choices set), progress bar to the minimum order value,
   "Noch etwas dazu?" (drinks, sides, desserts), what is already in the cart is marked in the menu; checkout remembers the
   details on the device (optional), shows clock times and the amount on the button; the status page shows the estimate in
   big letters, a text per step and a note when it takes longer. Confirmation mail to the guest rewritten (time first,
   payment note, link to follow the order)
* Search on the order page ignores small typos (a letter swap, one missing/extra/wrong letter), word by word, no server round trip; a field above the categories filters the dishes already on the page by name and description
  (no server round trip), hides empty categories, shows a note when nothing matches. Esc or the × clears it
* Upsell "Noch etwas dazu?" now learns from real, finished orders (never test orders) which dishes were bought together
   with what is in the cart, and suggests those first; falls back to popular dishes, then to the previous guess by category
   name, until at least 15 real orders exist. Runs server-side (order/api.php op=upsell), no guest data leaves the order
 * Coupons ("Gutscheine", tab in the menu editor): code, percent or fixed euro amount off the goods (never fee or tip),
   optional highest discount, minimum goods value, only delivery / only pickup, valid from / until, once (one redemption in
   total), many times (limit or unlimited), once per guest (recognised by phone number or e-mail). The guest enters the
   code at the checkout (checkout.php?code=XYZ fills it from a link); the server checks it again with the order. A redemption is
   used up when the order is placed and given back when the order is cancelled or unpaid; test orders do not use it up.
   Shown on the status page, in the mail and in the dashboard
 * Menu editor (backend page "Speisekarte", needs Settings-General): create, change, move and delete categories and dishes
   (name, price, description, allergens, picture, visible, sizes/variants), and option groups ("Zubehörgruppen": rule
   "required / optional, at least / at most", options with surcharge) that are assigned to any number of dishes. The
   options of the import were converted once into shared groups (identical sets became one group). In the shop the
   required choices of a dish come first in a highlighted block, optional extras are collapsed below
   Server side: Mollie needs https and a reachable /order/mollie_webhook.php; no cron job needed
 * Table-sign printing fixed: printing the whole day's signs at once sent them as one multi-page job, so the printer
   never cut between signs ("endless roll"). Each sign now fires as its own separate print job
 * Lieferando order import (order/lieferando_import.php, web/classes/shop_lieferando.class.php): an n8n workflow
   watches a Nextcloud folder for the order receipt PDF that is printed when the order is accepted on the Lieferando
   tablet, and posts it to this endpoint (header X-Api-Key, $settings['lieferandoApiKey']). The PDF is parsed
   (needs the new vendor/ folder, Composer package smalot/pdfparser) for pickup/delivery, dishes with options and the
   guest's note, and turned into a normal order (source "lieferando") that shows on the kitchen monitor with its own
   badge; the exact delivery address is intentionally not read (only in a QR code) since delivery keeps going through
   Lieferando's own courier app. The same order code is never imported twice
 * Reservation widget: a progress bar above the three steps shows how many steps are left ("Noch 2 Schritte" / "Noch
   1 Schritt" / "Letzter Schritt"), in German and English
 * Checkout: a visible completion indicator ("Fast geschafft - noch X Angaben") shows what is still missing (address,
   time, contact, payment) before the order can be placed; below 900px width it floats above the bottom edge so it
   stays visible while filling in the form, not only once scrolled all the way down. Tip is now a percentage of the
   order (Keins/5/10/15/20 %) or a free amount ("Wunschbetrag"), no longer fixed euro steps

2026-09-26 == mySeat v2.6.0 == amadeushi - http://github.com/amadeushi/myseat

 * Guest page: a button "Zum Kalender hinzufügen" downloads the reservation as a calendar file (.ics), the same event as
   in the confirmation mail (same UID, so a second import updates it instead of duplicating it). Also for guests
   without an email address. Not offered for pending requests and past reservations
 * Calendar file (mail and page): the event's link (URL) now opens the reservation page (details, arrival info, cancelling)
   instead of the website, and the description says "Reservierungsdetails und Stornierung: <link>"; it carried a cancel
   link before, now the page behind it is more than a cancel button

2026-09-26 == mySeat v2.5.1 == amadeushi - http://github.com/amadeushi/myseat

 * The restaurant's logo (https://www.amadeus-hildesheim.de/images/logo.png, white on transparent) in the booking widget
   (as its title), on the guest page (next to the language picker), in the backend top bar and on the login page; the
   name is shown as text if the image cannot load. One helper for all (web/classes/brand.class.php);
   $settings['logoUrl'] in config.general.php can point to another image
 * Fix: a light outline around the text of buttons on the booking pages (the old admin theme's white text-shadow on every
   <button>); the cancel, offer and booking pages have no text-shadow now
 * SMS: the link is announced as "Reservierungsdetails: <short link>" (v2.5.0 said "Infos und Absage")

2026-09-26 == mySeat v2.5.0 == amadeushi - http://github.com/amadeushi/myseat

 * The guest page (api/cancel.php, the link in the SMS and the mails) now shows the whole reservation instead of
   only a cancel button: date, time, guests, booking number, the guest's note, menu buttons (food and drinks),
   how to get here (address with route link, bus with timetable, parking, accessibility), phone and email, and
   cancelling last, behind a "Reservierung stornieren" button with a confirmation step. So guests without an
   email address can read up on their visit too. Pending requests show "Deine Anfrage" without the arrival
   info and can be withdrawn; reservations in the past cannot be cancelled any more (also checked on the server)
 * The texts for the arrival info and the menu links come from one place (bm_guest_info in
   web/classes/booking_mail.class.php) for the mails and the page; the mails are unchanged, byte for byte
 * SMS: "Infos und Absage: <short link>" instead of "Absage: <short link>"
 * Fix: the language switch (EN/DE) on the guest page kept the booking number and email but dropped the signed token of
   the SMS link, so the guest fell back to the empty lookup form; it keeps the token now

2026-09-26 == mySeat v2.4.4 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: short cancel links never got an expiry. Newer YOURLS versions put a 'code' into every answer, and the
   Expiry plugin's hook for new links does nothing then. The expiry is now set with a second call
   (action=expiry, postx=none so an expired link is deleted), and "Verbindung prüfen" in Einstellungen >
   SMS-Versand asks the plugin (expiry-stats) whether the test link really has an expiry

2026-09-26 == mySeat v2.4.3 == amadeushi - http://github.com/amadeushi/myseat

 * Backend, new reservation: a confirmation by SMS also works with only a mobile number (the tick box was
   only usable with an email address before). With SMS on, the box "Bestätigung per E-Mail oder SMS senden"
   is ticked by default as soon as it is possible; unticking by hand is respected
 * Cancel link: the short link is created with the Expiry plugin (expiry=clock, age in minutes, ageMod=min) and
   the answer of YOURLS is checked; "Verbindung prüfen" in Einstellungen > SMS-Versand says whether an expiry
   was really set. No post-expiry redirect any more: an expired link is deleted by YOURLS, and the cron
   sms_flush.php asks YOURLS once an hour to prune all expired links (action=prune), so unclicked links do not pile up

2026-09-25 == mySeat v2.4.2 == amadeushi - http://github.com/amadeushi/myseat

 * Booking form: the phone number gets its country code when the guest leaves the field (017622726369 ->
   +49 176 22726369; German/Austrian mobile numbers are grouped, other numbers keep their spacing, 00 -> +).
   While SMS is on, a hint under the field says whether the number is a mobile number (SMS possible) or a
   landline (no SMS)
 * Settings > SMS-Versand: the result of "Verbindung prüfen" and "Test-SMS senden" now shows right below
   the buttons (it appeared above the form, out of sight, since the cancel-link section was added)

2026-09-25 == mySeat v2.4.1 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: a reservation cancelled online (link in the mail or SMS, api/cancel.php) now gets the status
   "cancelled" (CXL) like a cancellation in the backend; before it moved to the cancelled list but the
   status selector still said "confirmed"
 * Settings > SMS-Versand: the list of last errors shows the SMS text, its length and any non-ASCII
   characters, to find out quickly why the gateway refused a text

2026-09-25 == mySeat v2.4.0 == amadeushi - http://github.com/amadeushi/myseat

 * Cancel link in the SMS: the SMS carries a short link (own YOURLS with the plugin "Expiry") instead of the
   restaurant's phone number; it expires on the morning after the reservation day, with the random 8-character
   keyword nobody can guess. If YOURLS is down the SMS still goes out with the phone number. Settings:
   Einstellungen > SMS-Versand (YOURLS address, signature token stored encrypted, connection test)
 * api/cancel.php: a signed token (t=...) proves the right to cancel, so bookings entered by hand that only
   have a mobile number (or nothing) can be cancelled too; the lookup form now also takes the mobile number
   ("0151 ..." = "+49 151 ..."). The cancellation still needs the confirmation click (POST)
 * SMS for booking confirmation and day-before reminder, through the own SMS gateway (https://sms.amds.at,
   the same one the shift planner uses). OFF by default. web/classes/sms.class.php:
   - confirmation: right after a firm booking (online or in the backend) and when a pending large-party
     request is approved; the reminder: with the day-before reminder run, in addition to the mail. A guest
     with a phone number but no email address now gets the reminder by SMS too
   - only mobile numbers (Germany +49 15x/16x/17x, Austria +43 6xx); landlines are skipped; a leading 0
     means the default country +49
   - texts have up to 160 characters and use only the GSM 03.38 alphabet (the gateway sends 160 instead of 70
     characters then): umlauts and ß are fine, typographic quotes/dashes/"…" are made plain, accents outside
     the set are dropped, emoji and other characters are left out, ^ { } \ [ ~ ] | and € count twice. Long
     restaurant names are shortened first. E.g. "Amadeus: Deine Reservierung ist bestätigt! Fr 27.11. um 18:30
     Uhr, 4 Personen. Buchungsnummer VnZClq. Fragen oder Absage: 05121 69816060. Bis bald!" (147 characters);
     the reservations on the tp_mail_optout list get no SMS either
   - queue tp_sms_outbox (created automatically): the gateway takes 10 new jobs per minute and can be down
     for a moment, so an SMS that is not accepted stays queued and is retried with growing gaps and the
     same Idempotency-Key (never twice), dropped after 8 attempts or 6 hours; a wrong key (401) is not
     retried. The same SMS is never queued twice per reservation. Finished entries are deleted after 30 days
   - the booking form shows one line under the phone field ("Mit einer Mobilnummer ...") only while SMS is on
 * Settings tab "SMS-Versand" (?p=6&q=9, needs the right for general settings): switch SMS on/off, enter the
   gateway key, remove it, "Verbindung prüfen" (tests gateway and key, sends nothing), "Test-SMS senden",
   counters and the last errors. The key is stored ENCRYPTED (AES-256-GCM, secret derived from the database
   login in config.general.php, table tp_sms_settings), is never shown again (only the last 4 characters)
   and never logged; a key entered there wins over $settings['smsApiKey'] in config.general.php.
   The gateway is reachable from the hosting server (checked). To switch it on: (1) on the SMS Pi create a
   key of its own: sudo sms-project create-project myseat 100; (2) paste it in the settings tab, tick
   "SMS-Versand aktivieren", save, test with "Test-SMS senden"; (3) add a webcron job every minute:
   https://<domain>/web/cron/sms_flush.php?key=<feedbackCronKey> (retries; with &health=1 it only tests
   connection and key). Optional in config.general.php: smsBaseUrl, smsDefaultCountryCode. The privacy
   policy should mention SMS to the guest's mobile number for the reservation

2026-09-25 == mySeat v2.3.1 == amadeushi - http://github.com/amadeushi/myseat

 * Fix offer dialog in the widget: "Schließen" did nothing because the dialog sat inside the booking
   form (a form within a form is ignored, the button then acted on the booking form). It is now a
   plain button, and the dialog is moved out of the form when opened. It carries its own colors and
   fonts, since outside the widget container it lost its background and used a system font
 * Fix offer chip in the widget: the old system styles put a white text shadow on buttons, which read as
   a white outline around the title and time on the dark background. Removed for the chip

2026-09-25 == mySeat v2.3.0 == amadeushi - http://github.com/amadeushi/myseat

 * Offer times ("Angebotszeiten"): new tab in the settings (?p=6&q=8). An offer has weekdays, a time
   range (or all day), an optional date range, a title (emoji allowed, quick-pick buttons), an
   optional text (links only with http:// or https://) and an optional picture (JPG/PNG/WebP/GIF,
   up to 2 MB, stored in uploads/offers/). In the booking widget the matching time slots are
   highlighted and a chip above them shows the title and the time range; "Mehr erfahren" opens a
   dialog with text and picture. An offer can be switched off without deleting it. Table tp_offers
   (utf8mb4, created automatically), saving by web/ajax/save_offer.php. The end time of an offer
   counts as part of it (12:00 - 14:45 highlights 14:45 too). Applies to the radio-style time picker

2026-09-25 == mySeat v2.2.0 == amadeushi - http://github.com/amadeushi/myseat

 * Booking source tag: a link like https://reservierung.amds.at/api/reserve.php?outletID=1&quelle=google
   stores "google" as the source of the booking (reservation_referer), instead of the referring
   website. Only letters, digits, - and _ are kept (30 characters). Without the parameter the
   referring host is stored as before. The source is now shown in the reservation details
   ("Herkunft") and counted in the statistics. Meant for the reservation link in the Google business
   profile, newsletters, QR codes etc.

2026-09-25 == mySeat v2.1.1 == amadeushi - http://github.com/amadeushi/myseat

 * New table tp_mail_optout (created automatically): reservations listed there get neither the
   day-before reminder nor the feedback request. Used for the Resmio import: 59 upcoming bookings
   (25.09. - 30.12.2026) were imported from a Resmio CSV export, marked in reservation_referer as
   "resmio-import:<Resmio booking number> (<source>)" and in the change history as "Resmio-Import".
   Cancelled bookings and one duplicate were skipped. The reservations of the first two days
   (25.09. and 26.09.) are on the opt-out list because Resmio still mails those guests; all others
   are treated like any other reservation. To release the opt-out later:
   DELETE FROM tp_mail_optout WHERE reason LIKE 'resmio-import%'; To undo the whole import:
   delete the reservations whose reservation_referer starts with 'resmio-import:' (and their rows in
   tp_reservation_tables, res_history, tp_mail_optout)

2026-09-25 == mySeat v2.1.0 == amadeushi - http://github.com/amadeushi/myseat

 * Group pre-order: new button "Einladung erneut senden" in the reservation details sends the
   guest the same invitation once more (same links, no new group), for a lost mail. If creating a
   group finds one from an earlier attempt whose invitation never went out (for example because
   Gmail failed), mySeat sends it right away. n8n now remembers whether an invitation was sent
   (new interface action resend_invitation). Nothing to do on the server.

2026-09-25 == mySeat v2.0.0 == amadeushi - http://github.com/amadeushi/myseat

 * ACTION NEEDED: new setting $settings['groupOrderApiKey'] in config/config.general.php on the
   server. The n8n workflow "Gruppenbestellung" no longer lets anyone create groups: mySeat now
   uses its machine interface with the header X-Api-Key (n8n only keeps the key's SHA-256) and
   gets JSON back instead of a page. Without the key the block "Gruppenbestellung" reports that it
   is missing and creates nothing. The key belongs only in the server's copy of the file, not in git.
 * Group pre-order: the reservation details now show the participant link and the confidential
   organizer link the guest received. The guest's name is used for the greeting of the invitation;
   booking number and reservation id go along, so n8n never creates a second group for the same
   reservation (a retry after a lost answer links the existing group and sends no second mail).
 * Group pre-order: when a reservation with a group is moved to another date or time, the group
   moves with it; the order deadline moves by the same number of days and keeps its time of day.
   Groups created with v1.1.0 have no stored link and are not moved. tp_group_orders gets the
   columns group_token, participant_url and organizer_url automatically on first use.
 * n8n side (not part of this repo): the create form of the webhook only opens from the team's
   management link, and the invitation and the finished list for the restaurant now use the
   wording and layout of the mySeat mails; replies to the finished list go to the organizer.

2026-09-25 == mySeat v1.1.0 == amadeushi - http://github.com/amadeushi/myseat

 * Group pre-order from the reservation details: the new block "Gruppenbestellung" (right column,
   under the email) creates a group in the n8n workflow "Gruppenbestellung" for this reservation.
   The guest becomes the organizer and receives the participant link and the confidential organizer
   link by mail; the pickup time is the reservation's date and time. Available for every reservation
   with an email address, whatever the party size. When creating, staff are asked whether to set an
   order deadline (suggested: three days before at noon; must lie before the visit and in the
   future). A reservation gets only one group (table tp_group_orders, created automatically); the
   block then shows when it was created. The block is hidden for cancelled reservations and for
   visits that are already over (unless a group exists). web/ajax/group_order.php calls the webhook
   https://n8n.amds.at/webhook/gruppenbestellung; another address can be set with
   $settings['groupOrderUrl'] in config.general.php. The webhook itself is public: anyone who knows
   the address can create groups and trigger mails

2026-09-25 == mySeat v1.0.6 == amadeushi - http://github.com/amadeushi/myseat

 * SECURITY: the server had no .htaccess at all and a full .git folder (source code and history)
   was downloadable from the web root. Added .htaccess files: no directory listings and no .git
   or README.txt over the web (root); config/, plugins/, web/classes/, web/includes/ and install/
   are closed to browsers, only PHP includes them; uploads/ can no longer execute scripts; the
   root also sets X-Content-Type-Options and Referrer-Policy. The widget is unaffected (no
   X-Frame-Options, it may be embedded). To run the installer or updater again, temporarily
   remove install/.htaccess. On the server tmp_deploy/ (old deploy leftovers) is blocked too and
   can be deleted, as can the server's own .git folder (deployment is by copy, not by git)

2026-09-25 == mySeat v1.0.5 == amadeushi - http://github.com/amadeushi/myseat

 * SECURITY: web/properties.php could be opened without logging in (it granted every visitor a
   valid session and only turned people away when a non-admin was logged in). It now needs a real
   login as admin (role 1 or 2); only a fresh installation without any admin can still open it
 * SECURITY: these backend AJAX endpoints worked without any login and handed out guest data
   (name, email, phone) or changed data: activate_user, autocomplete, autocomplete_res,
   check_password, check_username, cxllist, delete, guest_detail, inline_edit, modify_entry,
   modify_plugins, process_reservation, realtime. All now answer 403 without a backend session
   (web/includes/require_login.inc.php). The guest widget (api/) and the user activation link
   (web/confirm.php) stay public on purpose
 * SECURITY: the login cookie was read with a plain unserialize() (PHP object injection risk). It
   is now read by flexibleAccess::cookie_data() in PLC/plc.class.php: no objects allowed, only the
   expected scalar fields, anything else counts as "not logged in". The cookie is now set with
   HttpOnly, SameSite=Lax and Secure (on HTTPS), is deleted with the same path on logout, and the
   session key comes from random_bytes() instead of uniqid()/rand(). Existing logins keep working
 * Backend property page (?p=6&q=5): removed the embedded Google static map (it showed a broken image,
   since Google answers 403 without an API key, and every visit contacted Google). Also fixed the
   mis-nested <p><strong> tags on that page
 * Plugin cleanup: removed the unused plugins email_send (predecessor of the booking mails) and
   debug_session, the dead second hook list web/includes/plugins.init.php and the debug_online call
   in the widget; installer/updater now only register local_email_send. The real hook list stays
   in config/plugins.init.php, now with a note where each hook fires

2026-09-24 == mySeat v1.0.4 == amadeushi - http://github.com/amadeushi/myseat

 * Booking widget: a single closed day set in the backend (day details, "Ruhetag") now shows
   "An diesem Tag haben wir geschlossen" like a weekly closing day, instead of the misleading
   "everything is booked". A day marked open there also opens a normally closed weekday for the
   time selection. Remember: the day setting applies to that one date only; recurring closing
   days belong in the outlet settings
 * Backend day details reworked: comment, extra seats/tables, passerby limit and the single-day
   "Ruhetag" are saved together with the Save button, by AJAX (web/ajax/save_maitre.php), with the
   result shown next to the button. After a successful save the panel closes and a short
   confirmation appears on the day. Before, the closed-day checkbox saved on every click and
   reloaded the page, always stored 'OFF' (so a closed day never got set) and could create
   duplicate rows. Now: one row per outlet and date, a plain comment is swapped in without
   reloading, and the page only reloads when the day list changes, i.e. closed
   day or capacity. Setting a closed day warns if reservations exist for that day. Removed
   web/ajax/modify_dayoff.php

2026-09-24 == mySeat v1.0.3 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: saving the day details in the backend (day comment, extra seats/tables, passerby limit)
   ended on a blank page and stored nothing when "extra seats" was left empty (PHP 8 TypeError in
   abs() in writeForm). An empty or non-numeric value now counts as 0

2026-09-24 == mySeat v1.0.2 == amadeushi - http://github.com/amadeushi/myseat

 * Imprint and privacy links (from $settings['imprintUrl'] / $settings['privacyUrl'], the same
   ones the mails use) now sit under every guest page: booking form, confirmation, cancel page
   and feedback page (api/legal_footer.php). Nothing is shown when a setting is empty
 * Widget notices (closed day, online reservations blocked, group too big, fully booked) reworked:
   a clear headline, a line saying what the guest can do, readable type, and buttons to mail or
   call (number from $settings['mailPhone'] or the property record). No buttons on a closed day.
   Waitlist and error texts now use the informal "du" like the rest
 * Backend day list: assigned tables show as small chips (with many tables only the first two plus
   "+N", full list in the tooltip) instead of one long line that pushed status and buttons out of
   the row. The hint boxes above the list (day comment such as "keine Passanten einbuchen",
   online block, events) share one layout; the passerby warning is one compact box listing all
   times instead of one bright yellow line per time slot

2026-09-24 == mySeat v1.0.1 == amadeushi - http://github.com/amadeushi/myseat

 * Backend search finds partial matches anywhere in the name, booking number, phone number or
   email (e.g. "wald" finds "Anja Finsterwalder"). Several words all have to match, in any
   order ("finster anja"). Before, it only matched the beginning of the name

2026-09-24 == mySeat v1.0.0 == amadeushi - http://github.com/amadeushi/myseat

 * First release under the new versioning scheme (see VERSIONING above). Content: everything up to
   and including v0.2204. Server actions since the old counter's last big steps: the two webcron
   jobs (feedback requests, day-before reminders, same key $settings['feedbackCronKey'])

2026-09-24 == mySeat v0.2204 == amadeushi - http://github.com/amadeushi/myseat

 * Backend datepicker: the "Heute" button now always opens the real current day (it used to jump to
   the selected date because of gotoCurrent); the date comes from the device clock

2026-09-24 == mySeat v0.2203 == amadeushi - http://github.com/amadeushi/myseat

 * The notification mail to the restaurant is now a proper HTML mail (plain-text fallback kept):
   badge "Neue Reservierung" or "Entscheidung nötig", date/time/guests at a glance, guest contact
   as tap-to-call and tap-to-mail links, the guest's note highlighted, and Reply-To set to the guest
   so a reply goes straight to them
 * For a large-party request the mail carries the button "Anfrage ansehen & entscheiden". It opens
   api/request.php, a signed page (HMAC token, no login needed) that shows the request and offers
   Bestätigen / Ablehnen; the decision is a POST, so mail scanners that open links cannot trigger
   it, and the guest gets the matching mail. Every mail also links to the day in the backend
 * Guest mails reordered by importance: reservation details and the cancel link first, then the
   food and drinks menus as two buttons (drinks now https://amds.at/drinks), the arrival info last
   and more compact, with a link to the Hildesheim bus timetable (Fahrplanauskunft)
 * Approve/decline logic and the guest decision mail moved from web/ajax/modify_status.php into
   web/classes/approval.class.php, shared with the new page

2026-09-24 == mySeat v0.2202 == amadeushi - http://github.com/amadeushi/myseat

 * Guest mails rewritten in a warmer, more personal tone, signed "Hamun vom Amadeus-Team"
   (optional $settings['mailSignName'] in config.general.php). The table confirmation now carries
   a "So kommst du gut an" block: bus, parking, accessibility (ramp on request) and links to the
   food and drinks menus. New mail variant "approved" for a request that staff confirmed
   ("Gute Nachrichten ..."); request received and decline mails reworded (decline offers to find
   another date). Feedback request and staff reply mails invite honest criticism instead of just
   praise
 * New "see you tomorrow" reminder mail (arrival, parking, menus) the day before a reservation, sent
   between 10:00 and 20:00, never twice (table tp_reminders, created automatically). Skips cancelled,
   no-show, waitlisted and undecided/declined requests, and bookings made less than 18 hours
   earlier. Needs a second webcron job, same key as the feedback cron, every 30-60 minutes:
   web/cron/send_reminders.php?key=<feedbackCronKey>
 * Feedback form: after 4-5 stars TripAdvisor is offered first (filled button), Google second;
   after 1-3 stars the guest gets a direct "write to us" mail link instead of a review push
 * Reservation requests for large parties (outlet setting "approval_pax_threshold", 0 = off):
   requests show up in the normal list as "Unbestätigt"; picking "Bestätigt" approves (confirmation
   mail, table assignment), picking "Storniert" declines (decline mail) and can be undone from the
   cancelled list. "Storniert" is now stored as status CXL for every cancelled or deleted
   reservation, so the cancelled list no longer shows a stale "Bestätigt"
 * Public reviews page paginated (50 per page), average always over all reviews; the site root and
   /web/ redirect to the guest booking form (outlet 1) instead of the backend login
 * Cormorant Garamond and Raleway are self-hosted in web/fonts/ - no request to Google servers
 * modify_status.php now checks login and the Reservation-Edit right

2026-09-23 == mySeat v0.2201 == amadeushi - http://github.com/amadeushi/myseat

 * New guest feedback/review feature: 24-96h after a reservation took place (not cancelled or a
   no-show) and only when a guest email is on file, a webcron-triggered mail asks the guest to
   rate Speisen & Getränke and Service (1-5 stars each, overall computed as their rounded average).
   A rating of 4 or 5 stars asks the guest to also leave a public review on Google or TripAdvisor
   (links configured per outlet); lower ratings stay in-house. The guest form also asks for
   explicit consent to display the review publicly - without that consent staff cannot publish it,
   enforced at the database level, not just in the UI
 * New backend "Feedback" tab (Page-Feedback capability): date-range filter, average rating with a
   1-5 star distribution chart, per-category averages, and a list of individual reviews where staff
   can reply (the reply is emailed to the guest) and, only once the guest has consented, mark a
   review as publicly visible
 * New public reviews page (api/reviews.php) and an embeddable widget (api/reviews_widget.php, for
   dropping into the restaurant's own website via an iframe) that list every review that both the
   guest (consent) and the restaurant (publish toggle) agreed to show, including the restaurant's
   reply
 * New outlet settings fields for the Google Places and TripAdvisor review page URLs used above
 * Needs a webcron job (this host has no shell crontab) hitting
   web/cron/send_feedback_requests.php?key=<see file> every 30-60 minutes for requests to actually
   go out
 * No manual database update needed - the new tp_feedback table and the outlets/reservations
   columns it depends on are created/migrated automatically on first use
 * Existing resmio guest feedback (1718 historical reviews, 2019-2026) imported into the new
   tp_feedback table and published on the new public reviews page/widget; no guest name or email
   is available from that source, so these show as "Verifizierter Gast" and cannot receive a
   mailed staff reply

2026-09-23 == mySeat v0.2200 == amadeushi - http://github.com/amadeushi/myseat

 * The guest booking form (api/reserve.php) now actually detects a non-German browser and shows
   the English form by default - the existing code compared the browser's whole raw
   Accept-Language header (e.g. "en-US,en;q=0.9,de;q=0.8") against the literal string "en", which
   a real browser's header is never equal to, so the auto-detection never fired in practice. It
   now reads only the first, highest-priority language subtag and falls back to English for any
   browser language other than German (the form only has these two)
 * Fix: once the language was set for the session, a later step of the same booking (choosing a
   table, entering the name) could still send the confirmation mail in German regardless, because
   the "email_type" field the guest mail's language is based on was taken from a request-local
   variable that resets to the German default on every request instead of the session's own,
   already-decided language

2026-09-23 == mySeat v0.2199 == amadeushi - http://github.com/amadeushi/myseat

 * Booking mails (web/classes/booking_mail.class.php, plugins/local_email_send.plugin.php): the
   guest confirmation now comes with a calendar invite (.ics) attached - date, time (using the
   outlet's average stay as the end time), location (property address) and, in the description,
   the booking number, the one-click cancel link and the restaurant's website. Works for both the
   native mail() path (multipart/mixed around the existing plain/HTML alternative) and the
   PHPMailer/SMTP path (AddStringAttachment). The restaurant's own notification mail is unchanged

2026-09-23 == mySeat v0.2198 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: the "+Neu" tab (day view) had a glowing box-shadow ring around it, meant to call attention
   to it as the primary action; but the tab strip's tabs sit flush against each other with shared
   borders, so the ring visibly bled onto the two neighbouring tabs instead of framing "+Neu" on
   its own - it does not look like a highlight there, it looks broken. The ring is gone, the gold
   fill and bold weight already make it stand out on their own

2026-09-23 == mySeat v0.2197 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: the staff-member field next to Save (added in v0.2196) had two problems - on desktop
   "justify-content: space-between" stretched it away from the button, leaving a wide empty gap
   between them; on the phone it was pulled into the same sticky bottom bar as the Save button,
   so a much taller block stayed permanently pinned over the bottom of the screen while scrolling
   through the rest of the form (name, phone, notes, tables), hiding content behind it. The field
   now sits directly next to Save on desktop with no gap, and on the phone only the Save button
   itself is sticky - the staff field is a normal block right above it, scrolling with the rest of
   the form

2026-09-23 == mySeat v0.2196 == amadeushi - http://github.com/amadeushi/myseat

 * The staff member field moves once more: not next to date/time/guests but right next to the Save
   button - naming who is entering the reservation is the last thing you do before saving it, not
   something that belongs with the booking's own facts at the top of the form

2026-09-23 == mySeat v0.2195 == amadeushi - http://github.com/amadeushi/myseat

 * The staff member field (who took the booking) moves out of the collapsed "Details" section into
   the main part of the reservation form (new and edit), next to date/time/guests - no need to open
   Details to see or set it
 * Fix: the recurring-reservation row ("Serienreservierung") - "Wiederholen bis" - had an 18px-tall
   Bootstrap-era label box (a leftover from before the dark redesign) that squeezed its own text
   onto two lines and, on the phone, an unused empty element sitting in the middle of the row;
   the label is now a proper pill matching the rest of the form, the dead element is gone, and the
   whole row gets the full width of the Details grid instead of sharing half of it with the
   checkbox above it

2026-09-23 == mySeat v0.2194 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: the outlet (and reservation) detail/edit pages - Objekt/Name/Kuechenrichtung/Beschreibung
   next to Saison/Ruhetag/Oeffnungszeit/Pause - are a fixed 47%+47% two-column layout with a
   450px-wide input for the online booking links. On the phone the two floated columns did not fit
   side by side, so the right column visually climbed up next to the long description text on the
   left instead of following underneath it, and the page overflowed sideways on top of that. Both
   columns now stack full-width, one after another, on a narrow screen

2026-09-23 == mySeat v0.2193 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: the reservation-list "screen scroll" rule from v0.2190 unintentionally un-hid a print-only
   table (blank manual-entry lines at the bottom of the day view) on the phone - excluded it
 * Fix: the reservation cards opened with an empty box before the first card (the list's own empty
   spacer row, invisible as a table row, was rendered as an empty card)
 * Fix: the "recent reservations" card had its own fixed 450px desktop width instead of following
   the width of the other cards, sticking out on a narrow screen
 * The tab row (Reservierungen/+Neu/Storniert, Outlet/Benutzer/..., Erdgeschoss/Obergeschoss...) is
   a strip of flush, flat-bottomed tabs on desktop, made to visually merge into the box it opens
   right underneath; once wrapped onto several lines on the phone that illusion looked like
   floating, clipped rectangles instead. It is now a row of separate rounded pill buttons on the
   phone, the same pattern already used for the table-plan's own area tabs

2026-09-23 == mySeat v0.2192 == amadeushi - http://github.com/amadeushi/myseat

 * Reservation list on the phone (dashboard and the day view) now reads as a stack of cards
   instead of a 9-column table that needed sideways scrolling to reach the status dropdown or the
   edit/delete icons. Each reservation becomes one self-contained card: time + party size, guest
   name, table, status, who booked it and the actions - in the table's own row order, so the
   reading order for screen readers matches what is shown. The shift colour code (morning /
   afternoon / evening) that already marks each row becomes the card's left edge accent, the same
   colour language the table-plan reservation cards already use
 * The settings/dashboard tab row (Outlet/Benutzer/..., Erdgeschoss/Obergeschoss/...) now wraps via
   flexbox instead of relying on floats plus a manual clearfix, so it cannot start overlapping
   again just because a page happens to omit the clearfix

2026-09-22 == mySeat v0.2191 == amadeushi - http://github.com/amadeushi/myseat

 * Fix: on the phone, a card's header (date navigation, page title) had a fixed 30px height with
   floated children (desktop layout); past a certain content width the floated buttons ("Online
   sperren"/"Zurueck" on the dashboard, "Aktiv"/"InAktiv"/"Anlegen"/"Zurueck" in the settings, ...)
   spilled out past that 30px box and overlapped the date field or the table underneath. The header
   is now an auto-height row on the phone, and its action buttons always get their own full-width
   row below the title/date-nav instead of trying to share a line with it

2026-09-22 == mySeat v0.2190 == amadeushi - http://github.com/amadeushi/myseat

 * Backend responsive on the phone: a table wider than the screen (reservation lists, weekly
   occupancy, outlet list, statistics numbers, ...) now scrolls inside itself instead of dragging
   the whole page sideways, so status dropdowns and the edit/delete icons at its right edge stay
   reachable without first scrolling the page back and forth to find them
 * Bigger tap targets on the phone: nav links, the Outlet dropdown, the settings tabs, the
   edit/delete icons in a reservation row and the status dropdown all get a larger tappable area
   (the icons themselves keep their size)
 * Form fields (including the status dropdown) are 16px on the phone so iOS no longer zooms the
   whole page in when a field gets focus
 * The guest-search field gets an aria-label in addition to its title, for screen readers

2026-09-22 == mySeat v0.2189 == amadeushi - http://github.com/amadeushi/myseat

 * Backend login (PLC/index.php) rebuilt as a responsive page in the dark/gold look: a centred card
   that fits phones (16px inputs, no zoom on iOS, safe-area aware) up to desktop, proper labels,
   autocomplete hints for password managers, visible focus, DE/EN switch (?lang=de|en, default = browser
   language, German otherwise)
 * New welcome text ("Willkommen zurück - Melde dich an, um deine Reservierungen zu verwalten." /
   "Welcome back - Sign in to manage your reservations."), all messages (wrong login with the attempts
   left, blocked, password changed) are German or English instead of English only
 * Fix: the entered user name was written into the form unescaped (XSS); it is escaped now

2026-09-22 == mySeat v0.2188 == amadeushi - http://github.com/amadeushi/myseat

 * Reservation lists (dashboard, day view): the guest type (Hausgast / Passant / Walk-in) is no longer
   shown, and a missing salutation prints nothing instead of "--"
 * The table column no longer breaks "Tisch 128" into two lines: it is as wide as its content (and
   never narrower than 150px), the fixed 20% / 30% widths of the name and note columns are gone so the
   table fits the page and every entry stays on one line

2026-09-21 == mySeat v0.2187 == amadeushi - http://github.com/amadeushi/myseat

 * Table plan: a floor that is deep rather than wide is shown zoomed onto its tables (bounding box
   of the tables plus margin, at most 1.2 screens tall and 1.4x) instead of the fixed 1200x700
   canvas, so the tables get bigger on screen. Editing and closed floors still show the whole canvas
 * Table labels no longer wrap: the name stays on one line and name, seats and the occupancy line
   scale with the size of the table (CSS container units), so small tables with a booking stay legible

2026-09-21 == mySeat v0.2186 == amadeushi - http://github.com/amadeushi/myseat

 * Booking mails rewritten (web/classes/booking_mail.class.php, used by the active plugin
   local_email_send): friendly "du" text in German and English (English when the guest used the
   English form), subject with weekday, date and time, a clear reservation box (date, time, guests,
   booking number, the guest's note), one-click cancel link, contact line, a real sign-off. No images
   (no logo, dividers or background images) and no attachments (the old code tried to attach
   /data/*.pdf menus that do not exist). The notification mail for the restaurant has a useful
   subject ("Neue Reservierung: name, guests, date, time") and readable lines
 * Legal footer of the mail from config/config.general.php: $settings['mailLegal'] (imprint lines),
   ['imprintUrl'], ['privacyUrl'], ['mailPhone']; empty = property data of the system. Filled for
   Amadeus from https://www.amadeus-hildesheim.de/impressum.html
 * Technical: the text part contained HTML (<br />, &auml;) - now proper plain text; subject and
   sender name are RFC 2047 encoded (umlauts in the subject were sent raw), both parts are UTF-8
   base64; values of the forms are cleaned of SQL/HTML escaping (backslashes, \n, entities); a mail
   error can no longer break a booking; the salutation by title ("Sehr geehrter Herr ...") is gone
   in the mail

2026-09-21 == mySeat v0.2185 == amadeushi - http://github.com/amadeushi/myseat

 * Online booking form: the title ("Anrede") is no longer asked. The confirmation mails (both
   mail plugins) greet with "Guten Tag <name>" / "Hello <name>" when there is no title; before,
   every guest without a title got "Sehr geehrter Herr ...". Existing titles still work
 * Online booking form: the arrow icon behind the consent text is gone. The consent text links to
   the terms / privacy page of the restaurant when $settings['termsLink'] is set in
   config/config.general.php (full https address, opens in a new tab); without it the text has no
   link. Before, the link was hard-coded to the terms of the original mySeat project
   (myseat.us/terms.htm)

2026-09-21 == mySeat v0.2184 == amadeushi - http://github.com/amadeushi/myseat

 * New reservation form (backend) redesigned and simplified: date, time, guests, name, phone,
   email, note and a table picker on one page; everything else sits under "Details" (advertising
   consent, staff member - prefilled with the logged in user -, recurring booking). The title
   ("Anrede") is no longer asked. Email: the confirmation mail is an opt-in checkbox ("Bestätigung
   per E-Mail senden", off by default, only active with a valid address); there is no choice of
   language any more, the mail goes out in the local language (German). The address is checked
   in the browser and on the server. Guest type (house guest / passer-by / walk-in) is no longer asked (stored
   as PASS, like the online form). The old fields address, postcode/city, discount ("GdH"),
   parking, paid and paid by are gone from the new and the edit form; their data in old
   reservations is kept and shown in the detail view only when a reservation has values there
 * Phone: "Telefon/Zimmer" is now "Telefon" and is checked (digits, + ( ) - / . and spaces,
   6 - 15 digits) in the browser and again on the server; empty is allowed
 * Table picker: chips of the tables of the table plan for the chosen day and time, with
   seats, area filter, "available / all", closed areas and taken tables marked; several tables
   per reservation; tables that fit the group are outlined, the automatic suggestion is dashed;
   the message under the chips warns about too few seats or a taken table (a manual choice may
   overrule it, like in the table plan). Without a choice a new reservation is placed
   automatically. The edit form has the same picker with the current tables preselected;
   removing all tables there clears the assignment. For a recurring booking only the first day
   gets the chosen tables, the others are placed automatically
 * Responsive: the form is a grid that goes to one column on phones with a fixed save bar, and
   the backend top bar and page container follow the window width below 940px
 * Security: ajax/process_reservation.php only writes known reservation columns (form field
   names were used as column names before); tp.php lets the "new reservation" right read the
   table list (action free_tables)

2026-09-21 == mySeat v0.2183 == amadeushi - http://github.com/amadeushi/myseat

 * Table plan, linking tables: the "Verbinden" mode of the editor now works as a chain - click
   the tables in the order they stand (131, 132, 133, 134, 135): every click links the table to
   the one before and continues from there (before, the first table stayed the starting point,
   so this created a star around it, not a chain). Linking again removes the link, a click on the
   current starting table ends the chain
 * Automatic assignment: groups of up to 6 linked tables (was 4, so 5 chained tables of 4 could
   not seat 20 guests). The search lists every connected group exactly once (it repeated the
   same combinations before and could stop at its limit with larger groups); a chain counts as
   connected in any direction. Least waste first, then fewest tables

2026-09-21 == mySeat v0.2182 == amadeushi - http://github.com/amadeushi/myseat

 * Reservation status dropdown (dashboard and day view): each status has a vector icon and its
   own colour, in the closed field and in the list, with a tick at the current status:
   Bestätigt (calendar, blue), Angekommen (pin, green), Platziert (seated guest, purple),
   An der Bar (glass, amber), Fertig (check, grey), No-Show (dashed guest, slate). The texts
   were "NYA / Angekommen / Platziert / an Bar / Gegangen / No Show" (German) and are changed in
   the language files (de and en); the stored values (NYA, ARR, STD, PKD, DEP, NSW) and the
   change handler are untouched. Browsers with customizable selects (Chrome 135+) show the
   icons and colours in the list, other browsers show the coloured texts in their native list

2026-09-21 == mySeat v0.2181 == amadeushi - http://github.com/amadeushi/myseat

 * Table plan: occupied tables are highlighted for the whole day. Gold fill = one reservation,
   gold with a double ring and "2x" badge = several reservations one after another, red with a
   warning badge = overlap (double booking within the stay). Every table with reservations shows
   a strip over the opening hours with one bar per reservation (red where they overlap; times
   outside the opening hours stay visible at the edge), the tooltip lists all reservations of
   the table. Small tables show one compact line (the times when there are several). A legend
   sits above the plan. The selected-reservation view (fits / too small / taken) works together
   with the strips. The day data of tp.php now carries the opening hours

2026-09-21 == mySeat v0.2180 == amadeushi - http://github.com/amadeushi/myseat

 * Shift limits: noon shift 12:00 - 16:00 (sun), from 16:00 evening shift (moon); config values
   $daylight_noon = '12:00' and $daylight_evening = '16:00' in config/config.general.php (were
   14:00 / 18:00 - please adjust the file of your installation). Times after midnight of an
   outlet that closes after midnight count as evening (a 00:00 reservation was counted as
   noon before), exactly 18:00 was counted differently in the week view and in the list.
   The week view sums are computed with one rule (daytimeKind() / daytimeSums())
 * The colour marker at the start of a reservation row is distinguishable now: noon bright
   gold, evening dark bronze (another rule overrode both with the same colour)
 * All remaining pixel icons of the backend are vector icons (uiIcon() in
   web/classes/business.class.php): table, edit, delete, recurring, allow, notices (info,
   warning, error, success, special event), logout, user, dashboard view switch, mail
   (advertise yes/no), outlet help "i", plugins play/pause, user enable/disable, list arrows.
   They follow the theme colours (gold on hover, red for delete) and are sharp at any size.
   Not changed: the login-adjacent pages confirm.php and register/success.inc.php

2026-09-21 == mySeat v0.2179 == amadeushi - http://github.com/amadeushi/myseat

 * Dashboard week view: the sun / moon symbols in front of the noon and evening numbers were
   10px raster images (blurry, white, out of proportion); now crisp vector icons in the gold of
   the theme, aligned with the numbers (daytimeIcon() in web/classes/business.class.php)
 * Reservation list: the clock symbol next to the guest name (booking older than "old days")
   is a vector icon too, muted grey with the tooltip kept

2026-09-21 == mySeat v0.2178 == amadeushi - http://github.com/amadeushi/myseat

 * Online booking form in English: the texts that were hard-coded in German are translated
   (subtitle, opening hours block with weekday names, "Closed", step titles, Next/Back, "please
   select a time", the notices for closed days / online block / large groups / no tables left,
   the whole confirmation, waiting-list and error page). They come from bt() in
   api/business.class.php (German and English; other languages fall back to English, like
   cancel.php). Emails and the cancel page were already bilingual

2026-09-21 == mySeat v0.2177 == amadeushi - http://github.com/amadeushi/myseat

 * Backend: the "Änderungen" history dropdown in the reservation detail was a white box (legacy
   .option / .option_xl widgets = white background image with an invisible select on top, black
   text); now a dark field with a gold arrow
 * Backend: dropdown lists are drawn by the page in browsers with customizable selects
   (appearance: base-select, e.g. Chrome 135+): dark list, gold highlight for the current
   choice, gold arrow - the native macOS list stayed white despite color-scheme: dark. Other
   browsers keep the native list

2026-09-21 == mySeat v0.2176 == amadeushi - http://github.com/amadeushi/myseat

 * Reservation lists (day view, dashboard, short view): the "table" column now shows the tables
   assigned in the table plan (e.g. "Tisch 4 + Tisch 5", link to the plan of the day). It used
   to show only the old free text field, so plan assignments were invisible there. Without an
   assignment the free text stays as before and can still be edited inline

2026-09-21 == mySeat v0.2175 == amadeushi - http://github.com/amadeushi/myseat

 * Datepicker (online form on mobile and backend): the cell of today and the hovered cell had a
   light grey background from the old jQuery UI styles - white frame / light on light text;
   now transparent like the other days
 * Backend: the open list of a dropdown (time, title, type ... in the edit form) was drawn in
   the browser's light style; the dark theme now declares color-scheme: dark

2026-09-21 == mySeat v0.2174 == amadeushi - http://github.com/amadeushi/myseat

 * Online availability by table plan (switch in the plan editor, box "Einstellung": "Online-
   Verfügbarkeit: Nach Zählung (bisher) / Nach Tischplan"; default stays the old counter logic).
   With "Nach Tischplan" a time slot is bookable when the party finds free tables: the smallest
   free table that is big enough, otherwise up to four tables marked as linkable, outside closed
   areas, respecting the average stay. Reservations without a table are placed on tables in
   memory first so they block their tables. Full slots are greyed out in the form; a booking
   for a full slot goes to the waiting list, as before. An explicit passer-by limit of the day
   still applies; the seat/table limits of the outlet do not. Falls back to the counter logic
   when there are no tables or on any error. The backend day view keeps its own counters
 * "Online-Vorschau (Tischplan)" in the day view shows, for a party size, which slots the table
   plan would offer (or why the day is not bookable: closed weekday, day off, online block),
   so the switch can be tried in parallel before it is turned on
 * Times after midnight (e.g. open 14:30 - 00:00) are treated as belonging to the same evening
   when checking overlaps at a table

2026-09-21 == mySeat v0.2173 == amadeushi - http://github.com/amadeushi/myseat

 * Block online bookings for a single day (closed party, sold out): button "Online sperren" in
   the dashboard header for the selected day, and "online sperren" / "freigeben" per day in the
   week view, with an optional internal reason. The public form shows "online reservations are
   not possible on this day, contact us", greys the day out in the datepicker, and the booking
   is also refused server-side. Staff can still enter reservations in the backend - unlike the
   existing "day off" of the daily settings, which closes the day completely. A notice is shown
   in the dashboard and day view. New table online_blocks (created automatically)
 * Security: ajax/modify_dayoff.php (day off checkbox) now requires a logged in user with the
   Daily-Outlet-Edit right; it could be called without login before

2026-09-21 == mySeat v0.2172 == amadeushi - http://github.com/amadeushi/myseat

 * Table plan: day view with the reservations of a date (date navigation, "occupancy at time"
   filter). Assign a reservation to one or more tables by clicking tables or dragging the
   reservation card onto a table; a table can be given to several reservations at different
   times. Conflicts (capacity too small, table taken within the average stay of the outlet)
   are shown and can be overridden after a confirmation; closed areas can not be used.
   Cancelled, waiting-list, departed and no-show reservations are ignored
 * Automatic assignment (best fit: smallest free table that is big enough, otherwise tables
   that were marked as linkable, up to four): button for the whole day, per reservation, and
   for every new booking (online form and backend form; setting "automatically assign new
   reservations" in the plan editor, on by default). The hook can never break a booking.
   Parties that fit nowhere stay unassigned and are marked in the list
 * Area closures ("Sperrzeiten"): an area can be closed for a date range, open ended, and
   optionally repeating every year (e.g. terrace 30.09. - 01.03.) without deleting it. Closed
   areas are marked on the tab and skipped by the automatic assignment
 * New table tp_area_closures (created automatically). Online availability still uses the
   old counter logic; switching it to table capacity is planned for a later version

2026-09-21 == mySeat v0.2171 == amadeushi - http://github.com/amadeushi/myseat

 * New backend page "Tischplan" (main_page.php?p=7), first step towards table based capacity:
   administrators (Page-System) draw the floor plan of the outlet - add tables, drag them on a
   10px grid, resize, rotate (0/45/90/135 degrees), round or rectangular, seats per table, and mark
   which tables may be pushed together for larger parties. Reservation staff can view it
 * An outlet has several areas (floors, terrace, ...), each with its own plan shown as a tab; areas
   can be added, renamed, reordered and deleted (only when empty), tables can be moved between
   areas, and tables can only be linked inside one area
 * Storage in own tables tp_areas, tp_tables, tp_table_links, tp_reservation_tables, tp_settings (InnoDB,
   created automatically); JSON endpoint web/ajax/tp.php with session, CSRF token and role checks
   and prepared statements. Nothing changes for bookings yet - the existing counter based
   availability stays active (assignment of reservations to tables follows in later versions)

2026-09-21 == mySeat v0.2170 == amadeushi - http://github.com/amadeushi/myseat

 * Online booking form: the datepicker showed "&laquo;" / "&raquo;" as text (jQuery UI 1.13 no
   longer renders HTML in the arrow labels) and was transparent - its theme variables were only
   defined inside .booking-shell, but the calendar is appended to <body>; they are now defined on
   the calendar itself, which also lifts it above the time slots

2026-09-21 == mySeat v0.2169 == amadeushi - http://github.com/amadeushi/myseat

 * Backend (web/) runs on jQuery 3.7.1, jQuery UI 1.13.3 and jquery-validate 1.19.5 (was
   jQuery 1.4.4 / UI 1.8.10 / validate 1.7). The new scripts live in web/js/v3/; footer.html.php
   loads them by default. The old stack is still in web/js/ and can be loaded per browser tab
   with ?jq3=0 (?jq3=1 switches back) - it will be removed in a later version
 * plugins.js for jQuery 3: jQuery.browser and jQuery.support.opacity are re-created (needed by
   the bundled WYSIWYG editor and Fancybox 1.3), .live() -> delegated .on(), .unload() and
   .size() replaced; custom.js uses .prop() for checkboxes
 * Datepicker arrows use the real characters (jQuery UI 1.13 no longer renders HTML there);
   autocomplete/menu styling follows the new jQuery UI markup
 * Fixed: the day view crashed with a PHP 8 fatal error (getAvailability, no average duration)
   whenever the outlet data was not loaded yet, e.g. right after a fresh login
 * Note: the WYSIWYG description editor of the outlet form could not be inspected automatically
   during the upgrade - please check it once in the browser (outlet edit page)

2026-09-21 == mySeat v0.2168 == amadeushi - http://github.com/amadeushi/myseat

 * Online booking form (api/reserve.php) runs on jQuery 3.7.1 and jQuery UI 1.13.3 (was
   jQuery 1.4.4 / UI 1.7.3, both with known vulnerabilities); event handlers use .on(),
   form validation submits the form natively, the easing plugin is replaced by the one
   built into jQuery UI. The old api/js libraries were removed.

2026-09-21 == mySeat v0.2167 == amadeushi - http://github.com/amadeushi/myseat

 * Backend: editing a reservation works again - the detail page was cut off by a PHP 8
   fatal error (unquoted $_SESSION key in reservation_form.inc.php)
 * Backend: fixed a regression of the dark theme - preloadCssImages() threw a SecurityError
   because of the Google Fonts stylesheet and stopped all page scripts after it (edit button,
   datepicker, realtime updates); the call is now guarded
 * Backend: "+ Neu" tab highlighted as the primary action of the reservation view; the
   "Amadeus" brand link opens the reservation view
 * Backend: table row hover via CSS class (search results no longer turn white), dark
   autocomplete highlight, invalid fields get a red edge instead of a pink background,
   readable guest card (h5/h6), dashboard rows with larger type and a slim time-of-day marker

2026-09-20 == mySeat v0.2166 == amadeushi - http://github.com/amadeushi/myseat

 * One-click cancel link: api/cancel.php?nr=<booking number>&email=<address>
   opens a confirmation page and cancels only after an explicit click; works without a
   session, German/English, themed. Link added to the confirmation page and the emails
   (local_email_send and email_send plugins); manual lookup form as fallback
 * Cancel history entry is written with the correct reservation id, debug output removed
 * Security: processBooking() no longer takes column names from POST (field whitelist,
   plain INSERT - reservation_id can no longer overwrite other bookings); server-side
   checks for name, email, party size (max_menu) and time format; selectedDate, pax and
   outlet id validated at the public entry points; referer escaped in the form
 * Backend (web/): dark/gold theme in web/css/theme-dark.css (screen only, print stays
   light), brand name instead of the logo image, larger centred occupancy bar that scales
   down to a half-width / portrait window, dark modal windows (CXL list, details, confirmations)

2026-09-20 == mySeat v0.2165 == amadeushi - http://github.com/amadeushi/myseat

 * Booking confirmation page redesigned (dark/gold card) with confirmed / waitlist / error
   states; waitlist bookings were shown as an error before
 * Last-booking cutoff: $settings['lastBookingMinutes'] (default 60) hides late time slots,
   also for closing times after midnight, and is enforced server-side
 * Info boxes (.alert_info) in the booking form follow the dark/gold theme
 * style.css is cache-busted with the file time so deployments reach visitors immediately

2026-09-18 == mySeat v0.2164 == amadeushi - http://github.com/amadeushi/myseat

 * Mobile booking form: no nested cards, 3-column time grid, "Online-Reservierung" subtitle,
   centred headings
 * Party size is free text from 1 guest; groups above max_menu and fully booked or closed
   days show a "contact us" message with the property email (closed weekdays now also
   enforced server-side)
 * Date field shows "Today"/"Heute" for the current day; EN/DE picker instead of text links;
   correct active language; fonts from amadeus-hildesheim.de (Cormorant Garamond, Raleway)
 * Wizard keeps its step and all entered data when the language is switched
 * property id is set when entering with ?outletID=

2026-09-18 == mySeat v0.2163 == amadeushi - http://github.com/amadeushi/myseat

 * Public reservation form (api/reserve.php) redesigned in a dark/gold two-column layout
   with sidebar (back link, weekly opening hours, address, contact)
 * Three-step wizard: date/time/guests, notes, contact details with live summary
 * Guest count updates the time slots via AJAX (api/ajax_timeslots.php) without reload;
   time-slot fragment shared in api/timeslot_fragment.inc.php
 * Time slots as clickable pills in a scrollable grid; the last slot of the day (e.g. 00:00)
   is no longer dropped

2026-09-18 == mySeat v0.2162 == amadeushi - http://github.com/amadeushi/myseat

 * Day-specific opening hours work when only the opening or only the closing time is set
 * Midnight (00:00) can be used as a day-specific closing time
 * Confirmation page after booking no longer stays blank (wrong PHPMailer path in
   local_email_send plugin)
 * "Entry added" message no longer reappears on every outlet page view
 * Datepicker language script no longer returns a 500 (wrong session key)

2026-09-18 == mySeat v0.2161 == amadeushi - http://github.com/amadeushi/myseat

 * PHP 8 compatibility: mysql_* functions provided on top of mysqli
   (web/classes/mysql_compat.php), each() and PHP4 constructors replaced,
   get_magic_quotes_gpc() shim, parse errors fixed, deprecations cleared
 * Strict SQL mode (MySQL 5.7+ / MariaDB 10.2+): missing NOT NULL columns added to the
   default settings insert
 * Login works again (constructor of flexibleAccess in PLC/plc.class.php)
 * Plugin hook system no longer fatals (phphooks.class.php)
 * Further PHP 8 fatals fixed in the public form, reservation detail and the translation files

2012-12-08 == mySeat v0.2160 == Ap.Muthu - http://github.com/apmuthu/myseat

 * Multi Property Enabled - when plc_user role = 1
 * Forum Fixes and features incorporated
 * Code cleanup
 * $settings['mailCharset'] in config.general.php
 * Typos corrected
 * PHP Notices fixed - missing variable checks done
 * TimeZone values updated
 * Asia/Singapore TimeZone incorporated
 * tooltip over day off in online reservation datepicker
 * property_grid zip field display
 * export_page, hooks fixed

2012-08-06 == mySeat v0.2150 == Sebastien Fanals - http://github.com/fanals/myseat

 * DB Table Prefixes enables multiple mySeat installs in one DB
 * mail fixed
 * local_mail plugin - GMail and HotMail SMTP enabled and tested working
 * $settings['emailSMTP'] in config.general.php

2012-01-18 == mySeat v0.2134 == Bernd Orttenburger - http://github.com/myseat/myseat
- Dutch language file improvements
- small bugfixes

There is a database update necessary for 0.195 version and up!


INSTALLATION
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
SEE ONLINE DOCUMENTATION FOR MORE DETAILS: http://www.myseat.us/API 
mySeat is easy to install.
Under most circumstances installing mySeat is a very simple process
and takes less than ten minutes to complete.

Before starting the automatic installer follow these instructions:
Create a database for mySeat on your web server.
Create a MySQL user who has all privileges for accessing and modifying it.
Open file WEBROOT/config/config.general.php in a text editor and fill in your database details.
Browse to your new mySeat site to the directory http://IP.OR.DOMAIN/PATH/install
This will take you to the mySeat automatic installer with small explanations.


UPDATE
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
To update mySeat to a newer version, it is not necessary to do a full install.
Just replace the old files on the web server, except the WEBROOT/config folder.
If there is a need to change or extend the database, it is clearly stated.
To update the database, point your web browser to mySeat update script at
http://IP.OR.DOMAIN/PATH/install/update.php


MULTILINGUAL
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
mySeat is actually translated into 9 languages:
English
German
Spanish
French
Dutch
Swedish
Italian
Chinese
Danish


GNU LICENSE
=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=-=
Copyright
mySeat is free software: you can redistribute it and/or modify it under the terms of the
GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or any later version.
mySeat is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied
warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more details.
You should have received a copy of the GNU General Public License along with mySeat? 
If not, see <http://www.gnu.org/licenses/>.


mySeat? 
If not, see <http://www.gnu.org/licenses/>.


