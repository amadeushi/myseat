#!/bin/bash
# Küchenmonitor: Browser im Vollbild, startet nach einem Absturz von selbst neu.
# the session needs its own message bus for the on-screen keyboard
if [ -z "$DBUS_SESSION_BUS_ADDRESS" ]; then exec dbus-run-session -- "$0" "$@"; fi
URL="${KITCHEN_URL:-https://reservierung.amds.at/web/kitchen_screen.php}"
xset s off; xset -dpms; xset s noblank
unclutter-xfixes --timeout 3 --hide-on-touch &
openbox-session &
setxkbmap de
# onboard & (Bildschirmtastatur: bei Bedarf wieder einschalten)
# the start page waits for the network (a WLAN that comes up late after a power cut) and then opens the kitchen monitor by itself; without the page the URL is opened directly
START="$URL"
if [ -f "$HOME/kitchen-wait.html" ]; then
  START="file://$HOME/kitchen-wait.html?to=$(python3 -c 'import sys, urllib.parse; print(urllib.parse.quote(sys.argv[1], safe=""))' "$URL")"
fi
while true; do
  chromium --kiosk --lang=de-DE --accept-lang=de-DE,de --noerrdialogs --disable-infobars --disable-session-crashed-bubble --disable-features=Translate,TranslateUI \
    --password-store=basic --user-data-dir="$HOME/.config/kitchen-chromium" \
    --disable-background-networking --disable-component-update --autoplay-policy=no-user-gesture-required \
    "$START"
  sleep 3
done
