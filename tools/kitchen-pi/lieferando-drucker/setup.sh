#!/bin/bash
# Sets the Pi up as a printer "Lieferando" in the WLAN: the Lieferando tablet finds it in the Android print dialog and prints the order
# receipt to it; the receipt goes to mySeat as an order (see myseat-pdf). Run on the Pi as root:  sudo ./setup.sh
# Asks for the import address and the key (setting lieferandoApiKey); the key is written to /etc/myseat-printer.conf only.
set -e
[ "$(id -u)" = 0 ] || { echo "Bitte mit sudo starten."; exit 1; }
cd "$(dirname "$0")"

apt-get update -qq
apt-get install -y cups cups-filters avahi-daemon python3

install -m 0755 -o root -g root myseat-pdf /usr/lib/cups/backend/myseat-pdf

if [ ! -f /etc/myseat-printer.conf ]; then
	read -r -p "Adresse des Imports [https://app.amds.at/order/lieferando_import.php]: " url
	url="${url:-https://app.amds.at/order/lieferando_import.php}"
	read -r -s -p "X-Api-Key (Einstellung lieferandoApiKey): " key; echo
	umask 077
	printf 'IMPORT_URL=%s\nIMPORT_KEY=%s\n' "$url" "$key" > /etc/myseat-printer.conf
fi
chmod 600 /etc/myseat-printer.conf; chown root:root /etc/myseat-printer.conf

# share the printers in the WLAN (CUPS announces them by DNS-SD/IPP, which is what the Android print service looks for)
systemctl enable --now cups avahi-daemon
cupsctl --share-printers --no-remote-any
lpadmin -x Lieferando 2>/dev/null || true
PPD=/usr/share/ppd/cupsfilters/Generic-PDF_Printer-PDF.ppd # accepts PDF as it is (Debian 13: the drv:// name of it does not exist)
[ -f "$PPD" ] || { echo "PPD fehlt: $PPD (Paket cups-filters)"; exit 1; }
lpadmin -p Lieferando -E -v myseat-pdf:/ -P "$PPD" \
	-D "Lieferando Bestellungen (mySeat)" -L "Kueche" -o printer-is-shared=true -o printer-error-policy=retry-job
cupsenable Lieferando; cupsaccept Lieferando
systemctl restart cups

echo
echo "Fertig. Der Drucker heisst 'Lieferando'. Am Tablet: Druckfenster oeffnen, Drucker auswaehlen, drucken."
echo "Protokoll:   journalctl -t myseat-pdf -f     (jeder Import steht dort)"
echo "Belege:      /var/spool/myseat-pdf/          (die letzten 200 PDFs)"
echo "Pruefen:     avahi-browse -rt _ipp._tcp      (der Pi muss dort erscheinen)"
