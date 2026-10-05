Raspberry Pi in der Küche (Küchenmonitor und Bondrucker). Stand 2026-10-04, Pi 3B mit Raspberry Pi OS Lite (Debian 13), NCR 7197 per USB.

kiosk.sh              Startskript des Küchenmonitors: Chromium im Vollbild auf web/kitchen_screen.php (nach Absturz neu gestartet).
kitchen-kiosk.service systemd-Dienst dazu (startet X ohne Desktop mit kiosk.sh als Benutzer hamun).
print-agent.py        Druckdienst: fragt web/ajax/print_agent.php alle 2 Sekunden nach dem nächsten Bon und druckt ihn (ESC/POS) über den Treiber des Rechners (io_edgeport, /dev/ttyUSB0). Seit 2026-10-05:
                      der Treiber bleibt an. Früher wurde er für jeden Bon gelöst (pyusb); danach startete sich der Drucker nach 5 bis 25 s selbst neu und schnitt den Rest des Bons ab.
bon-agent.service     systemd-Dienst dazu; liest AGENT_URL und AGENT_KEY aus /etc/bon-agent.env (nicht im Projekt, Rechte 640 root:hamun).

Pakete: xserver-xorg xinit openbox chromium unclutter-xfixes x11-xserver-utils (optional onboard als Bildschirmtastatur; python3-usb braucht der Druckdienst seit 2026-10-05 nicht mehr).
Zugriff auf /dev/ttyUSB0: der Benutzer hamun ist in der Gruppe dialout. (Die frühere udev-Regel für den direkten USB-Zugriff, /etc/udev/rules.d/99-ncr7197.rules, wird nicht mehr gebraucht:)
  SUBSYSTEM=="usb", ATTR{idVendor}=="0404", ATTR{idProduct}=="0312", GROUP="plugdev", MODE="0664"
Chromium ohne Übersetzungsleiste: /etc/chromium/policies/managed/kiosk.json mit {"TranslateEnabled": false}
Den Schlüssel des Druckdienstes erzeugt shop_print_agent_key() (Einstellung print_agent_key) beim ersten Aufruf.

lieferando-drucker/   Der Pi als Drucker "Lieferando" im WLAN (Stand 2026-10-05, noch nicht am Tablet getestet). Das Lieferando-Tablet druckt den Bestellbon im
                      Android-Druckfenster auf den Pi; der Bon geht als PDF an order/lieferando_import.php (derselbe Import wie vorher über n8n/Nextcloud).
  setup.sh            Einrichtung auf dem Pi:  sudo ./setup.sh  (installiert cups, cups-filters, avahi-daemon; fragt Adresse und X-Api-Key des Imports,
                      der Schlüssel steht nur in /etc/myseat-printer.conf, Rechte 600).
  myseat-pdf          CUPS-Backend: schickt das PDF an den Import. Server nicht erreichbar oder Fehler 5xx: CUPS versucht es später erneut; Ablehnung 4xx: Auftrag
                      verworfen. Jedes PDF bleibt in /var/spool/myseat-pdf (die letzten 200). Protokoll: journalctl -t myseat-pdf -f
  Prüfen: avahi-browse -rt _ipp._tcp zeigt den Pi; am Tablet erscheint "Lieferando Bestellungen (mySeat)" in der Druckerliste.
