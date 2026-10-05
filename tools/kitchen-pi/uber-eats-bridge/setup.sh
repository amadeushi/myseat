#!/bin/bash
# Installs the Uber Eats bridge on the Pi (see bridge.py): sudo ./setup.sh   (as root, from this folder)
# The key (setting in mySeat, Einstellungen > Lieferservice > "Uber Eats Bons") is entered here by the person who sets it up; it is written to /etc/uber-bridge.conf only (rights 640).
set -e
[ "$(id -u)" = 0 ] || { echo "Bitte mit sudo starten."; exit 1; }
cd "$(dirname "$0")"
USER_NAME=hamun

install -d -m 0755 /usr/local/lib/uber-bridge
install -m 0755 -o root -g root bridge.py /usr/local/lib/uber-bridge/bridge.py
install -d -m 0750 -o "$USER_NAME" -g "$USER_NAME" /var/spool/uber-bridge
install -m 0644 -o root -g root uber-bridge.service /etc/systemd/system/uber-bridge.service

if [ ! -f /etc/uber-bridge.conf ]; then
	read -r -p "IP-Adresse des echten Druckers (TM-m30II) im Netz: " printer
	read -r -p "Adresse des Imports [https://app.amds.at/order/uber_import.php]: " url
	url="${url:-https://app.amds.at/order/uber_import.php}"
	read -r -s -p "Schluessel (aus mySeat, leer lassen und spaeter eintragen): " key; echo
	umask 077
	printf 'PRINTER_IP=%s\nIMPORT_URL=%s\nIMPORT_KEY=%s\n' "$printer" "$url" "$key" > /etc/uber-bridge.conf
fi
chown root:"$USER_NAME" /etc/uber-bridge.conf; chmod 640 /etc/uber-bridge.conf

# the test setup had opened port 80 for everyone ("sysctl net.ipv4.ip_unprivileged_port_start=80"); the service has its own right for it now
sysctl -w net.ipv4.ip_unprivileged_port_start=1024 >/dev/null

systemctl daemon-reload
systemctl enable uber-bridge.service
systemctl restart uber-bridge.service
sleep 2
systemctl --no-pager --lines=5 status uber-bridge.service || true
echo
echo "Protokoll:  journalctl -u uber-bridge -f      Bilder: /var/spool/uber-bridge/{pending,sent}/"
echo "Schluessel spaeter eintragen:  sudo nano /etc/uber-bridge.conf   dann  sudo systemctl restart uber-bridge"
