Raspberry Pi in der Küche (Küchenmonitor und Bondrucker). Stand 2026-10-04, Pi 3B mit Raspberry Pi OS Lite (Debian 13), NCR 7197 per USB.

kiosk.sh              Startskript des Küchenmonitors: Chromium im Vollbild auf web/kitchen_screen.php (nach Absturz neu gestartet).
kitchen-kiosk.service systemd-Dienst dazu (startet X ohne Desktop mit kiosk.sh als Benutzer hamun).
print-agent.py        Druckdienst: fragt web/ajax/print_agent.php alle 2 Sekunden nach dem nächsten Bon und druckt ihn per USB (ESC/POS, pyusb).
bon-agent.service     systemd-Dienst dazu; liest AGENT_URL und AGENT_KEY aus /etc/bon-agent.env (nicht im Projekt, Rechte 640 root:hamun).

Pakete: xserver-xorg xinit openbox chromium unclutter-xfixes x11-xserver-utils python3-usb (optional onboard als Bildschirmtastatur).
USB-Zugriff ohne Root: /etc/udev/rules.d/99-ncr7197.rules mit
  SUBSYSTEM=="usb", ATTR{idVendor}=="0404", ATTR{idProduct}=="0312", GROUP="plugdev", MODE="0664"
Chromium ohne Übersetzungsleiste: /etc/chromium/policies/managed/kiosk.json mit {"TranslateEnabled": false}
Den Schlüssel des Druckdienstes erzeugt shop_print_agent_key() (Einstellung print_agent_key) beim ersten Aufruf.
